<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
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
    protected function creaPalestra(string $nome = 'Demo', string $slug = 'demo', string $codice = 'DEMO2345'): Tenant
    {
        $tenant = Tenant::create([
            'name' => $nome,
            'slug' => $slug,
            'join_code' => $codice,
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
    protected function creaUtente(Tenant $tenant, UserRole $ruolo, string $email, array $extra = []): User
    {
        return $this->ctx()->runAs($tenant, function () use ($ruolo, $email, $extra): User {
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

            return $u;
        });
    }

    /** Il super admin: fuori da ogni palestra, con la colonna e non con un ruolo. */
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
}
