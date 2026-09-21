<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * 🧮 **Quante persone stanno davvero dentro una palestra** — 3b-AH.6.9
 * (17/09/2026).
 *
 * ══ 🚨 PERCHE' NASCE UN SERVIZIO PER UN `count()` ═════════════════════════
 *
 * Perche' questo numero adesso **decide due cose diverse**: quanti posti
 * restano (`InvitiInPalestra::postiRimasti()`) e **quanto si paga a fine mese**
 * (`AddebitoDelMese`). ⛔ Due conteggi scritti in due posti divergono sempre, e
 * qui divergere vuol dire che il pannello dice «38 iscritti» e la fattura ne
 * addebita 41. 🚨 Nessuno dei due da' errore: sono due numeri plausibili.
 *
 * 💡 Sta sotto `Billing` e non sotto `Tenancy` perche' la domanda difficile e'
 * quella dei soldi: contare per riempire una barra di progresso perdona uno
 * sbaglio, contare per emettere una fattura no.
 *
 * ══ ⛔ IL DIFETTO CHE HA FATTO NASCERE QUESTA CLASSE ══════════════════════
 *
 * 🚨 **Il conteggio non guardava mai la tabella `users`.** Leggeva
 * `model_has_roles` e basta — e li' dentro la riga di un iscritto **resta anche
 * dopo che quella persona e' stata spenta o cancellata**: lo dice a chiare
 * lettere `ChiEsceDallaPalestraOsservatore`, che alla cancellazione chiude i
 * rapporti e i canali, ma **il ruolo non lo toglie**.
 *
 * ⚠️ Conseguenza sui posti: una palestra da venti che nel tempo ha cancellato
 * quindici persone si ritrova **cinque posti** e nessuna spiegazione, perche'
 * nel pannello quelle quindici non ci sono piu'. ⛔ Conseguenza sui soldi, dal
 * giorno in cui il piano a consumo fattura davvero: **le paga tutte**.
 *
 * 💡 Si escludono sia i cancellati (`deleted_at`) sia gli spenti
 * (`is_active = false`), ed e' la stessa coppia che l'osservatore tratta allo
 * stesso modo: *«`is_active = false` non e' una cancellazione, ed e'
 * reversibile»* — ma finche' dura, quella persona non usa niente e non deve
 * costare niente.
 */
class ContoDegliIscritti
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Quante persone con questo ruolo stanno **in piedi** dentro la palestra.
     *
     * ⚠️ **Si conta dai ruoli spatie**, non da `users.tenant_id`: da
     * `AH.1-quater` un trainer con un portfolio suo lavora nella palestra senza
     * avere li' il proprio `tenant_id`, e contarlo dalla colonna lo lascerebbe
     * fuori dal tetto che la palestra paga.
     *
     * 🚨 `model_has_roles` si legge a mano e non con `hasAppRole()`: quello
     * risponde sul tenant **della persona**, che per un trainer con portfolio e'
     * un altro — e direbbe di no a tutti, in silenzio.
     *
     * ⛔ Il `join` su `roles` va su **due** colonne, `id` e `tenant_id`: spatie
     * in modalita' teams tiene un ruolo per tenant, e un join sul solo `id`
     * pescherebbe il ruolo omonimo di un'altra palestra.
     */
    /**
     * 🆕 **Lo stesso conto, per tutte le palestre in una query** — 21/09/2026,
     * fase F1.
     *
     * ══ 🚨 PERCHÉ NON SI CHIAMA [quanti] IN UN CICLO ═════════════════════════
     *
     * Perché il pannello della piattaforma elenca le palestre con accanto
     * quanto pagano, e quanto pagano dipende da quanti iscritti hanno: con una
     * query per riga la pagina fa venti interrogazioni per disegnare una
     * colonna.
     *
     * ⛔ **E perché non si riscrive il conteggio**: le regole difficili — il
     * `join` su `users` per non contare i cancellati, il doppio `join` su
     * `roles` per non pescare il ruolo omonimo di un'altra palestra — stanno
     * scritte una volta sola qui sopra, e qui sotto si ripetono **identiche**.
     * 🚨 Il giorno che divergono, il pannello dice un numero e la fattura ne
     * addebita un altro.
     *
     * @return array<int, int> id della palestra → quante persone
     */
    public function perOgniPalestra(UserRole $ruolo = UserRole::Member): array
    {
        return $this->context->runWithoutTenant(
            fn (): array => DB::table('model_has_roles as mhr')
                ->join('roles as r', function ($join): void {
                    $join->on('r.id', '=', 'mhr.role_id')
                        ->on('r.tenant_id', '=', 'mhr.tenant_id');
                })
                ->join('users as u', 'u.id', '=', 'mhr.model_id')
                ->whereNull('u.deleted_at')
                ->where('u.is_active', true)
                ->where('mhr.model_type', (new User)->getMorphClass())
                ->where('r.name', $ruolo->value)
                ->groupBy('mhr.tenant_id')
                ->selectRaw('mhr.tenant_id, COUNT(DISTINCT mhr.model_id) as quanti')
                ->pluck('quanti', 'mhr.tenant_id')
                ->map(fn ($n): int => (int) $n)
                ->all(),
        );
    }

    public function quanti(Tenant $palestra, UserRole $ruolo = UserRole::Member): int
    {
        return $this->context->runWithoutTenant(
            fn (): int => (int) DB::table('model_has_roles as mhr')
                ->join('roles as r', function ($join): void {
                    $join->on('r.id', '=', 'mhr.role_id')
                        ->on('r.tenant_id', '=', 'mhr.tenant_id');
                })
                /*
                 * 🚨 **E' QUESTO IL `join` CHE MANCAVA.** Senza, si contano le
                 * righe di un'altra tabella e si chiamano «persone».
                 */
                ->join('users as u', 'u.id', '=', 'mhr.model_id')
                ->whereNull('u.deleted_at')
                ->where('u.is_active', true)
                ->where('mhr.tenant_id', $palestra->getKey())
                ->where('mhr.model_type', (new User)->getMorphClass())
                ->where('r.name', $ruolo->value)
                ->distinct()
                ->count('mhr.model_id'),
        );
    }
}
