<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Services\Billing\ContoDegliIscritti;
use App\Services\Billing\RegistroDeiPagamenti;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 📊 **I conti di una palestra, visti da dentro** — 23/09/2026, fase F5.
 *
 * 📌 Il committente, per il cruscotto del pannello palestra: *«trainer attivi,
 * slot allievo e trainer (occupati/totali), costo per allievo, prezzo per
 * allievo»* e *«grafici spesa/guadagno, allievi nel tempo, trainer nel tempo»*.
 *
 * ══ 🚨 DUE NUMERI CHE VENGONO DA DUE MONDI ══════════════════════════════
 *
 * | | Da dove | Attendibile? |
 * |---|---|---|
 * | **Costo per allievo** | il **nostro** canone diviso i suoi iscritti | ✅ è un dato nostro, e lo conosciamo al centesimo |
 * | **Prezzo per allievo** | `tenants.prezzo_per_allievo_cent`, scritto **da loro** | ⚠️ ✅ **D9**: *«lo scrive la palestra e noi non lo verifichiamo nemmeno»* |
 *
 * ⛔ Il secondo **non va usato per fatturare niente**: è un numero che il
 * cliente scrive a mano su se stesso, e serve solo a far tornare il **suo**
 * conto nel **suo** cruscotto.
 *
 * ══ ⚠️ LA CURVA DEGLI ISCRITTI NON È UNA STORIA ═════════════════════════
 *
 * 🚨 Si costruisce da `users.created_at` di chi **oggi** ha il ruolo nella
 * palestra. ⛔ Chi se n'è andato non c'è più: la curva dice *«le persone che
 * ho adesso, quando sono arrivate»*, **non** *«quante ne avevo in marzo»*.
 *
 * 💡 È una distinzione che conta: una palestra che ha perso metà degli iscritti
 * vede una curva che sale lo stesso. ⚠️ Sta scritto anche nel riquadro, non
 * solo qui — un grafico che mente in silenzio è peggio di nessun grafico.
 *
 * ⛔ **Per avere la storia vera servirebbe un registro delle iscrizioni**
 * (`model_has_roles` non ha nemmeno i timestamp). È debito dichiarato: si
 * affronta il giorno che una palestra chiede «quanti ne ho persi», non prima.
 */
class IContiDellaPalestra
{
    /** Quanti mesi indietro guardano i grafici. */
    public const MESI = 12;

    public function __construct(
        private readonly TenantContext $context,
        private readonly ContoDegliIscritti $conto,
        private readonly RegistroDeiPagamenti $registro,
    ) {}

    // ───────────────────────── i numeri di oggi ─────────────────────────

    /**
     * 💶 **Quanto costa alla palestra ogni iscritto, al mese.**
     *
     * ⚠️ `null` quando non c'è un abbonamento, o quando non ha ancora nessun
     * iscritto: ⛔ dividere per zero darebbe «infinito», e mostrare `0 €`
     * direbbe «gratis» a chi sta pagando un canone.
     */
    public function costoPerAllievoCent(Tenant $palestra): ?int
    {
        $piano = $palestra->abbonamentoAttivo?->plan;

        if ($piano === null) {
            return null;
        }

        $iscritti = $this->conto->quanti($palestra);

        if ($iscritti === 0) {
            return null;
        }

        return (int) round($piano->quantoCostaAlMese($iscritti) / $iscritti);
    }

    /** 💶 Quanto la palestra fa pagare a ognuno, se l'ha scritto (D9). */
    public function prezzoPerAllievoCent(Tenant $palestra): ?int
    {
        return $palestra->prezzo_per_allievo_cent;
    }

    /**
     * 📈 Quanto le resta in tasca per ogni iscritto.
     *
     * ⚠️ `null` se manca uno dei due numeri: ⛔ un margine calcolato su un
     * prezzo che nessuno ha scritto sarebbe **meno il costo**, cioè un rosso
     * inventato da noi.
     */
    public function marginePerAllievoCent(Tenant $palestra): ?int
    {
        $prezzo = $this->prezzoPerAllievoCent($palestra);
        $costo = $this->costoPerAllievoCent($palestra);

        if ($prezzo === null || $costo === null) {
            return null;
        }

        return $prezzo - $costo;
    }

    // ───────────────────────── i grafici ─────────────────────────

    /**
     * 👥 Quante persone con questo ruolo, mese per mese (**cumulativo**).
     *
     * ⚠️ Vedi il blocco in testa: è «chi c'è oggi, per data d'arrivo».
     *
     * @return array<string, int> `YYYY-MM` → quanti
     */
    public function personePerMese(Tenant $palestra, UserRole $ruolo = UserRole::Member, int $mesi = self::MESI): array
    {
        $arrivi = $this->context->runWithoutTenant(
            fn (): array => DB::table('model_has_roles as mhr')
                ->join('roles as r', function ($join): void {
                    $join->on('r.id', '=', 'mhr.role_id')->on('r.tenant_id', '=', 'mhr.tenant_id');
                })
                ->join('users as u', 'u.id', '=', 'mhr.model_id')
                ->whereNull('u.deleted_at')
                ->where('mhr.tenant_id', $palestra->getKey())
                ->where('r.name', $ruolo->value)
                ->selectRaw("DATE_FORMAT(u.created_at, '%Y-%m') as mese, COUNT(*) as quanti")
                ->groupBy('mese')
                ->pluck('quanti', 'mese')
                ->map(static fn ($v): int => (int) $v)
                ->all(),
        );

        $serie = [];
        $totale = 0;

        /*
         * 🚨 **Il cumulato parte da chi c'era già prima della finestra.** ⛔
         * Senza questa somma, una palestra con cento iscritti da due anni
         * vedrebbe la curva partire da zero a gennaio — come se avesse appena
         * aperto.
         */
        $primoMese = $this->mesi($mesi)[0];

        foreach ($arrivi as $mese => $quanti) {
            if ($mese < $primoMese) {
                $totale += $quanti;
            }
        }

        foreach ($this->mesi($mesi) as $mese) {
            $totale += $arrivi[$mese] ?? 0;
            $serie[$mese] = $totale;
        }

        return $serie;
    }

    /**
     * 💸 Quanto ha pagato **a noi**, mese per mese, in centesimi.
     *
     * ⚠️ Parte dal 22/09/2026: prima di `pagamenti_incassati` i pagamenti a
     * canone non li registrava nessuno.
     *
     * @return array<string, int>
     */
    public function spesaPerMese(Tenant $palestra, int $mesi = self::MESI): array
    {
        $pagati = $this->registro->perMese($palestra, $mesi);

        $serie = [];

        foreach ($this->mesi($mesi) as $mese) {
            $serie[$mese] = $pagati[$mese] ?? 0;
        }

        return $serie;
    }

    /**
     * 💰 Quanto ha incassato **lei**, mese per mese, in centesimi.
     *
     * 🚨 **È una stima, e il riquadro lo dice**: iscritti di quel mese ×
     * prezzo dichiarato. ⛔ Non sappiamo chi ha pagato davvero e chi era in
     * ferie — quei soldi non passano da noi.
     *
     * @return array<string, int>
     */
    public function guadagnoPerMese(Tenant $palestra, int $mesi = self::MESI): array
    {
        $prezzo = $this->prezzoPerAllievoCent($palestra);

        if ($prezzo === null) {
            return array_fill_keys($this->mesi($mesi), 0);
        }

        $serie = [];

        foreach ($this->personePerMese($palestra, UserRole::Member, $mesi) as $mese => $quanti) {
            $serie[$mese] = $quanti * $prezzo;
        }

        return $serie;
    }

    /**
     * I mesi della finestra, dal più vecchio.
     *
     * @return list<string>
     */
    public function mesi(int $mesi = self::MESI): array
    {
        $out = [];
        $cursore = Carbon::now()->startOfMonth()->subMonths($mesi - 1);

        for ($i = 0; $i < $mesi; $i++) {
            $out[] = $cursore->format('Y-m');
            $cursore = $cursore->addMonth();
        }

        return $out;
    }
}
