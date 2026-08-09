<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
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
