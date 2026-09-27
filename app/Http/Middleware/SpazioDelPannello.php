<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\ChiSiVede;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🆕 **Lo spazio in cui si sta lavorando, nel pannello** — 3b-AH.1-quater.8,
 * 16/09/2026.
 *
 * ══ 🚨 PERCHE' ESISTE ══════════════════════════════════════════════════════
 *
 * 📌 Da AH.1-quater una persona può avere **due cappelli**: un giro di allievi
 * suo — dove vive il suo `users.tenant_id` — e il ruolo di `trainer` **dentro
 * una palestra**. Sono indipendenti, ed è il caso che il prodotto vuole.
 *
 * ⛔ `ResolveTenant` mette nel contesto **sempre e solo** `$user->tenant`, cioè
 * lo spazio suo. Per quella persona il pannello della palestra era quindi una
 * porta che si apriva su una **stanza vuota**: non risultava un trainer di
 * quella palestra, e ogni scoping la trattava come «nessuno dei due».
 *
 * ══ ⚖️ E PERCHE' NON VIOLA ADR-04 ══════════════════════════════════════════
 *
 * 🚨 ADR-04 dice che il tenant **non arriva mai dal client**: né header, né
 * parametro, né corpo. ⚠️ Qui la scelta la fa la persona, e va detto chiaro —
 * ma **non è il client a decidere il permesso**:
 *
 * 1. la scelta vive in **sessione**, non in un parametro di richiesta;
 * 2. ogni richiesta la **ricontrolla** contro `ChiSiVede::spaziDi()`, cioè il
 *    proprio spazio più le palestre dove quella persona ha davvero il ruolo;
 * 3. se il controllo non passa — ruolo revocato, palestra chiusa, sessione
 *    copiata — si ricade in silenzio sul proprio spazio.
 *
 * 💡 Cioè: il client può **chiedere**, mai **stabilire**. Il giorno in cui la
 * palestra gli toglie il ruolo, la scelta smette di valere da sola, senza che
 * nessuno debba ricordarsi di ripulire la sessione.
 *
 * ══ ⚠️ DOVE STA NELLA CATENA, E PERCHE' ════════════════════════════════════
 *
 * **Dopo `ResolveTenant` e prima di `Authenticate`.** Dopo il primo perché lo
 * deve poter sovrascrivere; prima del secondo perché Filament chiama
 * `canAccessPanel()` dentro `Authenticate`, e quel metodo guarda i ruoli — che
 * adesso si leggono nello spazio del contesto. Invertirli vorrebbe dire
 * decidere chi entra guardando lo spazio sbagliato.
 *
 * ⛔ **E non si registra nell'API**: lì il contesto deve restare quello
 * dell'utente e basta, senza nessuna scelta da nessuna parte.
 */
class SpazioDelPannello
{
    /** La chiave in sessione. */
    public const CHIAVE = 'spazio_del_pannello';

    public function __construct(
        private readonly TenantContext $contesto,
        private readonly ChiSiVede $chiSiVede,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $utente = $request->user();

        if (! $utente instanceof User || ! $request->hasSession()) {
            return $next($request);
        }

        $scelto = (int) $request->session()->get(self::CHIAVE, 0);

        /*
         * ══ 🆕 🏠 SENZA UNA SCELTA, NELLA PALESTRA — decisione 2, 18/09/2026 ══
         *
         * 🚨 **Chi a casa non ha niente da fare deve atterrare dove lavora.**
         * Da quando *«ogni trainer ha sempre uno spazio suo»*, un trainer di
         * palestra ha come casa uno spazio personale **vuoto**: senza questo
         * ramo, al primo accesso si ritrovava davanti un pannello senza iscritti,
         * senza schede, senza niente — e avrebbe pensato che la migrazione gli
         * avesse cancellato il lavoro.
         *
         * ⚠️ **Solo se a casa non ha un ruolo da pannello.** Un free trainer con
         * un giro suo ha il suo lavoro lì, e deve partire da lì come prima: si
         * sceglie la palestra solo quando la casa non offre niente.
         */
        if ($scelto === 0) {
            $predefinita = $this->palestraDiChiNonHaNienteACasa($utente);

            if ($predefinita !== null) {
                $this->contesto->set($predefinita);
            }

            return $next($request);
        }

        if ($scelto === $utente->tenant_id) {
            return $next($request);
        }

        /*
         * ⚠️ `withoutGlobalScopes()`: il `TenantScope` su `tenants` filtra sul
         * contesto, che in questo istante è ancora quello vecchio — la palestra
         * che sto per scegliere non si troverebbe.
         */
        $palestra = Tenant::withoutGlobalScopes()->find($scelto);

        /*
         * 🚨 **Due ragioni per lasciar perdere la scelta, e una sola uscita.**
         *
         * ══ ⛔ E UNA TERZA CHE E' STATA TOLTA — 28/09/2026, 63.A ═══════════
         *
         * Qui c'era anche `! $palestra->isActive()`, e la sua ragione era vera:
         * `EnsureTenantActive` girava **dopo** di qui e chiudeva il pannello a
         * chi aveva in mano un tenant inattivo, quindi con la scelta in sessione
         * quella persona restava chiusa fuori da tutto.
         *
         * 🔑 Dal 28/09 `EnsureTenantActive` **non è più nel pannello**: chi non
         * ha pagato entra e trova `AvvisoDaPagare`. ⛔ Quel rifiuto ha perso il
         * suo motivo, e ne era rimasto solo il danno — **e il danno era grosso**:
         *
         * 🚨 Il trainer indipendente che lavora anche in una palestra sospesa
         * scegliendo la palestra veniva **riportato a casa sua senza un
         * messaggio**. Il titolare gli diceva *«guarda l'avviso nel pannello
         * della palestra»*, lui cliccava, si ritrovava nel proprio spazio dove
         * tutto funziona, e concludeva che l'avviso non esistesse.
         *
         * ⚠️ **Non era un errore: era una bugia silenziosa**, che è peggio.
         * `IlPannelloSiApreDavveroTest::chi_ha_due_spazi_entra_anche_in_quello_
         * della_palestra_sospesa` la rimette in piedi se qualcuno la riscrive.
         */
        if ($palestra === null
            || ! $this->chiSiVede->spaziDi($utente)->contains($scelto)) {
            /*
             * ⚠️ **Si dimentica**, invece di lasciarla lì a fallire a ogni
             * richiesta. 💡 Chi ha perso il ruolo si ritrova nel proprio spazio
             * e basta: è la cosa giusta e non ha bisogno di spiegazioni.
             */
            $request->session()->forget(self::CHIAVE);

            return $next($request);
        }

        $this->contesto->set($palestra);

        return $next($request);
    }

    /**
     * 🆕 La palestra in cui far atterrare chi, a casa, non ha niente da fare.
     *
     * ⚠️ **Si legge `model_has_roles` a mano**, e non con `hasAppRole()`: quello
     * mette in cache la risposta per spazio, e chiederlo qui — prima che il
     * contesto sia deciso — la scriverebbe per lo spazio sbagliato.
     *
     * 💡 La **prima** palestra, in ordine di id: stabile fra un accesso e
     * l'altro. Chi ne ha più d'una sceglie le altre da «Cambia spazio».
     */
    private function palestraDiChiNonHaNienteACasa(User $utente): ?Tenant
    {
        $haUnRuoloACasa = DB::table('model_has_roles as mhr')
            ->join('roles as r', function ($join): void {
                $join->on('r.id', '=', 'mhr.role_id')
                    ->on('r.tenant_id', '=', 'mhr.tenant_id');
            })
            ->where('mhr.model_id', $utente->getKey())
            ->where('mhr.model_type', $utente->getMorphClass())
            ->where('mhr.tenant_id', $utente->tenant_id)
            ->whereIn('r.name', [
                UserRole::GymAdmin->value,
                UserRole::Trainer->value,
                UserRole::FreeTrainer->value,
            ])
            ->exists();

        if ($haUnRuoloACasa) {
            return null;
        }

        $prima = $this->chiSiVede->palestreDoveAllena($utente)->sort()->first();

        if ($prima === null) {
            return null;
        }

        /*
         * ⛔ **Qui c'era `&& $palestra->isActive()`** — tolto il 28/09/2026, 63.A.
         *
         * 🚨 Era il difetto peggiore dei due, perché colpiva chi **non ha
         * nessun'altra strada**: il trainer che a casa non ha un ruolo da
         * pannello e lavora solo in quella palestra. Con la palestra sospesa
         * questo metodo rispondeva `null`, il contesto restava il suo spazio
         * personale vuoto, e lui apriva un pannello **senza niente dentro** —
         * senza iscritti, senza schede, e **senza l'avviso** che spiegava perché.
         *
         * 💡 Ora ci atterra comunque, e ci trova scritto cosa è successo. ⚖️ Uno
         * spazio sospeso in cui si legge la verità è meglio di uno spazio vuoto
         * che non la dice.
         */
        return Tenant::withoutGlobalScopes()->find($prima);
    }
}
