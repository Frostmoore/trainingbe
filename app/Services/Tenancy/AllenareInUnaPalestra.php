<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

/**
 * 🏋️ **Un trainer comincia a lavorare in una palestra, senza traslocare** —
 * regola 8.1.1, 18/09/2026.
 *
 * ══ 📌 LA REGOLA ══════════════════════════════════════════════════════════
 *
 * > *«Naturalmente, assicurati che un trainer possa lavorare per infinite
 * > palestre.»*
 *
 * ══ 🚨 PERCHE' QUESTA CLASSE NON ESISTEVA, E COSA SUCCEDEVA SENZA ═════════
 *
 * ⛔ **Non c'era nessuna strada nel prodotto per far lavorare un trainer in una
 * palestra senza spostarlo dentro.** L'invito da trainer di una palestra
 * passava da `UnisciAUnaPalestra::dentroQuestoTenant()` — il **trasloco** — che
 * rifiuta chiunque abbia qualcun altro nel proprio spazio:
 *
 * | Chi | Cosa succedeva |
 * |---|---|
 * | Un trainer con **un solo** allievo suo | ⛔ rifiutato: *«Nel tuo spazio ci sono altre persone»* |
 * | Un trainer già dentro una palestra | ⛔ rifiutato: il suo spazio non è più suo |
 * | Un trainer senza allievi | Entrava — ma **traslocato**, perdendo lo spazio suo |
 *
 * 🚨 **Cioè: nessun trainer poteva lavorare in due palestre, e nessun trainer
 * con un giro suo poteva lavorare in nessuna.** Il 16/09 il pannello aveva
 * imparato a *leggere* i ruoli nella palestra guardata (AH.1-quater.8) — ma
 * quello stato esisteva solo se qualcuno lo costruiva a mano nel database.
 *
 * ══ 💡 COSA FA INVECE: UN RUOLO, NON UN TRASLOCO ══════════════════════════
 *
 * 📌 Il committente, decisione del 18/09: *«ogni trainer ha sempre uno spazio
 * suo»*, e le palestre diventano **solo ruoli**. Quindi qui:
 *
 * - ⛔ `users.tenant_id` **non cambia** — resta lo spazio suo;
 * - ⛔ nessuna riga si sposta;
 * - ✅ si aggiunge il ruolo `trainer` **dentro il tenant della palestra**.
 *
 * 💡 E così le palestre possono essere infinite: ognuna è un ruolo in più, e
 * nessuna tocca le altre né la casa.
 *
 * ══ 🎟️ E SE LI' DENTRO CI SONO GIA' SUOI ALLIEVI ═════════════════════════
 *
 * 📌 Il committente, fase 8.3.3: *«assicurati che al trainer compaia la modale
 * che mostra gli allievi che ha che sono anche iscritti in quella palestra
 * (potrebbero essere più di uno) per scegliere se mantenere lo slot o
 * sacrificarlo»*.
 *
 * ⚠️ È il **rovescio** di AH.1-ter.6: là l'allievo entra dove lavora il
 * trainer, qui il trainer entra dove sta l'allievo. La domanda è la stessa, e
 * la fa la stessa classe — `LoSlotDelTrainer`.
 */
class AllenareInUnaPalestra
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly LoSlotDelTrainer $slot,
    ) {}

    /**
     * Dà a questa persona il ruolo di trainer **dentro** la palestra.
     *
     * ⚠️ **Il posto trainer lo controlla chi chiama** (`InvitiInPalestra`
     * pretende un posto libero prima): qui non si rifà, perché un controllo
     * scritto in due posti diverge sempre.
     *
     * 💡 **Idempotente**: se il ruolo c'è già non succede niente. Un invito
     * riscattato due volte, o una richiesta ripetuta, non devono duplicare
     * niente — e spatie, in modalità teams, sul duplicato risponderebbe **500**.
     *
     * @return int quante sovrapposizioni ha fatto nascere con i suoi allievi
     */
    public function assumi(User $trainer, Tenant $palestra): int
    {
        if ($palestra->ePersonale()) {
            /*
             * ⛔ **Uno spazio personale non assume nessuno.** Un trainer
             * indipendente non ha dipendenti: arrivare qui con uno spazio
             * personale vorrebbe dire aver sbagliato invito, e va detto invece
             * di creare un ruolo che nessuna pagina saprebbe leggere.
             */
            throw new \InvalidArgumentException('Uno spazio personale non assume trainer.');
        }

        return DB::transaction(function () use ($trainer, $palestra): int {
            $this->context->runAs($palestra, function () use ($trainer, $palestra): void {
                /*
                 * 🚨 **`roles` si svuota prima di chiedere.** Spatie mette in
                 * cache la relazione e, in modalità teams, risponde da quella
                 * senza guardare il tenant: senza questa riga `hasRole()`
                 * risponderebbe con i ruoli di **un altro** spazio.
                 */
                $fresco = User::withoutGlobalScopes()->findOrFail($trainer->getKey());
                $fresco->unsetRelation('roles');

                if ($fresco->hasRole(UserRole::Trainer->value)) {
                    return;
                }

                /*
                 * ⚠️ Il ruolo si cerca **nel tenant della palestra**: in
                 * modalità teams ogni tenant ha i suoi, e un `assignRole()` per
                 * nome pescherebbe quello del contesto — cioè questo. Lo si
                 * pretende esplicitamente perché una palestra senza il ruolo
                 * `trainer` è un difetto del seme, e va detto subito.
                 */
                Role::query()
                    ->where('tenant_id', $palestra->getKey())
                    ->where('name', UserRole::Trainer->value)
                    ->firstOrFail();

                $fresco->assignRole(UserRole::Trainer->value);
                $fresco->unsetRelation('roles');
            });

            $sovrapposti = $this->slot->segnalaLeSovrapposizioniDelTrainer($trainer, $palestra);

            Log::info('Trainer al lavoro in una palestra, senza trasloco', [
                'trainer' => $trainer->getKey(),
                'palestra' => $palestra->getKey(),
                'resta_a_casa_in' => $trainer->tenant_id,
                'sovrapposizioni' => $sovrapposti,
            ]);

            return $sovrapposti;
        });
    }
}
