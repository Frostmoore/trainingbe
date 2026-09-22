<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\PlanKind;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\AiCreditMovement;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\PortafoglioGettoni;
use App\Services\Privacy\LeLiberatorie;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Le tre righe che ogni test di questo progetto deve scrivere prima di poter
 * verificare qualcosa: una palestra, i suoi ruoli, le persone dentro.
 *
 * Sta in un trait e non copiato in ogni file perche' il `setUp` corretto e'
 * pieno di dettagli facili da sbagliare — i ruoli spatie vanno creati **dentro**
 * il contesto della palestra, l'assegnazione idem, e il super admin va creato
 * **fuori** da ogni contesto. Ogni copia a mano e' un'occasione di scriverne una
 * versione leggermente diversa, e a quel punto i test verificano ambienti
 * diversi senza dirlo.
 */
trait CreaAmbiente
{
    protected function ctx(): TenantContext
    {
        return app(TenantContext::class);
    }

    /** Una palestra attiva, con i suoi tre ruoli spatie gia' creati. */
    protected function creaPalestra(string $nome = 'Demo', string $slug = 'demo'): Tenant
    {
        $tenant = Tenant::create([
            'name' => $nome,
            'slug' => $slug,
            'contact_email' => $slug.'@esempio.test',
            'status' => TenantStatus::Active,
        ]);

        $this->ctx()->runAs($tenant, function () use ($tenant): void {
            foreach (UserRole::tenantScoped() as $ruolo) {
                Role::create([
                    'name' => $ruolo->value,
                    'guard_name' => 'web',
                    'tenant_id' => $tenant->id,
                ]);
            }
        });

        /*
         * 🚨 **Una palestra creata qui è una palestra che PAGA** — F4.
         *
         * Da F4 l'AI passa da `RequirePlanWithAi`, che la nega a chi non ha un
         * abbonamento con `ai_enabled`. Una palestra di prova senza abbonamento
         * non è «una palestra normale»: è una palestra **morosa**, e provare
         * l'AI contro di lei vorrebbe dire provarla nell'unico stato in cui è
         * giusto che non funzioni.
         *
         * ⚠️ Il piano si cerca **se esiste**: molti test non seminano il
         * listino, e pretenderlo qui li farebbe fallire tutti con un errore che
         * non c'entra niente con quello che stanno verificando.
         *
         * 💡 Un test che vuole provare il caso «senza piano» toglie
         * l'abbonamento — `PlanSubscription::withoutGlobalScopes()->delete()` —
         * ed è la strada esplicita: chi la legge capisce che sta costruendo una
         * palestra in difetto, invece di ottenerla per omissione.
         */
        $gym = Plan::query()->where('code', Plan::GYM)->first();

        if ($gym !== null) {
            PlanSubscription::withoutGlobalScopes()->create([
                'tenant_id' => $tenant->id,
                'plan_id' => $gym->id,

                /*
                 * 🚨 **Cinque anni fa, e non «da ieri».**
                 *
                 * Con `subDay()` questa riga rompeva `AiApiTest`: quel test
                 * torna indietro a `2026-08-12 09:00` con `Carbon::setTestNow()`
                 * per provare che il consiglio del giorno non si rigenera, e
                 * un abbonamento cominciato «ieri» rispetto all'ora **vera**
                 * risultava non ancora iniziato rispetto all'ora **finta**.
                 * Effetto: `403 plan_without_ai` in un test che parla d'altro.
                 *
                 * ⚠️ Non è una toppa: è anche più realistico. Una palestra di
                 * prova rappresenta un cliente di vecchia data, non uno che ha
                 * firmato ieri — e una data lontana rende questo helper immune
                 * a qualunque viaggio nel tempo di qualunque test futuro.
                 */
                'starts_at' => now()->subYears(5),
            ]);
        }

        return $tenant;
    }

    /** Un utente dentro la palestra, con il ruolo assegnato nel contesto giusto. */
    /**
     * @param  bool  $liberatoriaFirmata  🆕 20/09/2026 — vedi sotto.
     */
    protected function creaUtente(
        Tenant $tenant,
        UserRole $ruolo,
        string $email,
        array $extra = [],
        bool $liberatoriaFirmata = true,
    ): User {
        return $this->ctx()->runAs($tenant, function () use ($tenant, $ruolo, $email, $extra, $liberatoriaFirmata): User {
            $u = User::create(array_merge([
                'name' => ucfirst(explode('@', $email)[0]),
                'email' => $email,
                'password' => TestCase::FAKE_PASSWORD,
            ], $extra));

            /*
             * ⚠️ **La maggiore eta' e le condizioni d'uso si danno per scontate
             * qui, i consensi facoltativi NO** (S9).
             *
             * Chi arriva da `creaUtente()` e' una persona gia' iscritta: nella
             * vita reale la dichiarazione l'ha data al momento dell'iscrizione,
             * e farla mancare renderebbe ogni test un test sullo sbarramento.
             *
             * 🚨 `health_consent_at` e `ai_consent_at` restano invece **`null`**,
             * ed e' deliberato: e' lo stato di serie di chiunque si iscrive, e
             * un test che chiama l'AI **deve** concederlo esplicitamente. Se li
             * mettessimo qui, il giorno in cui il cancello dell'AI si rompesse
             * nessun test se ne accorgerebbe.
             */
            $u->forceFill([
                'age_confirmed_at' => now(),
                'terms_accepted_at' => now(),
            ])->save();

            $u->assignRole($ruolo->value);

            /*
             * 🆕 ✍️ **Chi allena ha già firmato la liberatoria** — 20/09/2026.
             *
             * 🚨 Dal 20/09 il pannello non si apre finché palestra o trainer
             * non hanno accettato la nomina a responsabile (art. 28). ⛔ Senza
             * questa riga **ogni** test sul pannello diventerebbe un test sullo
             * sbarramento: si fermerebbero tutti alla stessa schermata, e non
             * proverebbero più quello per cui esistono.
             *
             * 💡 È la stessa logica di `terms_accepted_at` qui sopra: nella
             * vita vera la firma c'è già. Lo **sbarramento** si prova dove
             * deve, in `LaLiberatoriaSiFirmaTest`, passando
             * `liberatoriaFirmata: false`.
             */
            if ($liberatoriaFirmata) {
                foreach ([LeLiberatorie::PALESTRA, LeLiberatorie::TRAINER] as $tipo) {
                    if (app(LeLiberatorie::class)->riguarda($u, $tipo)) {
                        app(LeLiberatorie::class)->firma($u, $tipo);
                    }
                }
            }

            /*
             * 🆕 **Il primo `gym_admin` diventa il proprietario del tenant**
             * — 3b-AH, Passo 0.
             *
             * 💡 Non è una comodità per i test: è **la stessa invariante** che
             * la migration di riempimento scrive sui tenant già esistenti —
             * *«il proprietario di una palestra è il suo `gym_admin`»*. Senza,
             * ogni test su chi comanda l'abbonamento verificherebbe un
             * ambiente che in produzione non esiste.
             *
             * ⚠️ **Solo se non c'è già**: un secondo amministratore non porta
             * via la proprietà al primo. Chi vuole provare il passaggio di
             * mano lo scrive a mano, e si vede leggendo.
             *
             * 🚨 **E oggi in produzione nessuna strada assegna `GymAdmin`**:
             * le palestre e i loro amministratori nascono a mano o da seeder.
             * Il giorno in cui il pannello lo farà, deve fare anche questo —
             * e finché non lo fa, se ne accorge
             * `abbonamenti:tenant-senza-proprietario`.
             */
            if ($ruolo === UserRole::GymAdmin && $tenant->proprietario_id === null) {
                $tenant->forceFill(['proprietario_id' => $u->getKey()])->save();
            }

            return $u;
        });
    }

    /** Il super admin: fuori da ogni palestra, con la colonna e non con un ruolo. */
    /**
     * 🆕 Mette gettoni nel portafoglio **di una persona** — 3b-AH.8, 16/09/2026.
     *
     * ⛔ Fino al 15/09 prendeva un `Tenant`, perche' il portafoglio era uno solo
     * per spazio. 📌 Adesso i gettoni sono di chi li compra: per riempire i
     * portafogli di tutti gli abitanti di uno spazio c'e' `gettoniATutti()`.
     *
     * ── la ragione per cui questo aiutante esiste — 3b-AE, 31/08/2026 ─────
     *
     * ══ 🚨 PERCHE' SERVE, DA OGGI ═════════════════════════════════════════
     *
     * 📌 *«tutte le richieste all'ai non automatiche devono costare GETTONI»*.
     *
     * ⛔ Prima le funzioni a richiesta pescavano dalla quota inclusa, quindi un
     * utente di prova con il portafoglio vuoto stimava alimenti tutto il
     * giorno. Da 3b-AE quello stesso utente prende **402**.
     *
     * 💡 Chi scrive un test sull'AI **a richiesta** deve chiamarlo, e chi
     * scrive un test sul cancello commerciale deve NON chiamarlo: e' proprio
     * quella la differenza che si sta provando.
     *
     * ⚠️ Passa da `PortafoglioGettoni` e non da un `update` sul tenant: il
     * saldo ha un registro (`ai_credit_movements`), e una riga scritta a mano
     * lascerebbe un saldo senza movimento — cioe' un portafoglio che nel test
     * si comporta diversamente da come si comporta in produzione.
     */
    protected function dagliGettoni(User $chi, int $quanti = 500): void
    {
        app(PortafoglioGettoni::class)->accredita(
            $chi,
            $quanti,
            nota: 'test',
            causale: AiCreditMovement::RETTIFICA,
        );
    }

    /**
     * 🆕 Il saldo di **tutti** gli abitanti di uno spazio — 16/09/2026.
     *
     * ⛔ Fino al 15/09 questi test scrivevano `ai_credits` sul `Tenant`, perche'
     * il portafoglio era uno solo per spazio. 📌 Da 3b-AH.8 i gettoni sono
     * della **persona**, e quei test volevano dire una cosa sola: *«qui dentro
     * c'e' (o non c'e') di che pagare l'AI»*.
     *
     * ⚠️ **Imposta, non somma**, come faceva il `forceFill` che sostituisce: un
     * `setUp` che ne da' 500 e un test che ne vuole 50 devono finire a 50, non a
     * 550. 💡 Ed e' per questo che azzera prima.
     *
     * ⚠️ Sta qui, e non e' un `dagliGettoni()` travestito: chi legge un test
     * deve vedere subito che i portafogli sono **tanti**, uno per persona.
     */
    protected function gettoniATutti(Tenant $tenant, int $quanti = 500): void
    {
        $abitanti = User::withoutGlobalScopes()->where('tenant_id', $tenant->getKey())->get();

        foreach ($abitanti as $chi) {
            /*
             * ⚠️ **L'azzeramento passa dal database e non dal portafoglio**, ed
             * e' l'unica scorciatoia ammessa qui: `accredita()` rifiuta lo zero,
             * e una rettifica negativa scriverebbe nel registro un movimento
             * che nel test non e' mai avvenuto.
             */
            DB::table('users')->where('id', $chi->getKey())->update(['ai_credits' => 0]);

            if ($quanti > 0) {
                $this->dagliGettoni($chi->fresh(), $quanti);
            }
        }
    }

    /** 🆕 Nessuno qui dentro ha gettoni da spendere. */
    protected function senzaGettoni(Tenant $tenant): void
    {
        $this->gettoniATutti($tenant, 0);
    }

    protected function creaSuperAdmin(string $email = 'god@piattaforma.test'): User
    {
        $u = $this->ctx()->runWithoutTenant(fn (): User => User::create([
            'name' => 'God',
            'email' => $email,
            'password' => TestCase::FAKE_PASSWORD,
        ]));

        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    /**
     * 🆕 Un piano **da trainer** sullo spazio di questo trainer indipendente —
     * giro A6, 22/09/2026.
     *
     * 🚨 Da `InvitiDelTrainer::puoAvereAllieviSuoi()` gli allievi propri li ha
     * solo chi ha un piano di tipo `trainer`: un trainer sul piano gratuito da
     * persona non invita nessuno. ⚠️ È anche l'unico caso reale: senza piano la
     * rotta degli inviti rispondeva già 403 (`TrainerController::trainerOrAbort`).
     */
    protected function conPianoDaTrainer(User $trainer, string $codice = Plan::TRAINER_30): User
    {
        $piano = Plan::query()->where('code', $codice)->first()
            ?? Plan::create([
                'code' => $codice,
                'name' => 'Trainer di prova',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => false,
                'max_members' => 30,
                'price_cents' => 0,
                'is_public' => true,
            ]);

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => $piano->id,
            'starts_at' => now()->subDay(),
        ]);

        return $trainer;
    }
}
