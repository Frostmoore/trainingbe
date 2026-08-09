<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Crea i ruoli di palestra.
 *
 * ⚠️ In modalità teams i ruoli **non sono globali**: ogni palestra ha i suoi tre
 * (`roles.tenant_id`). Questo seeder va quindi eseguito **per ogni palestra**,
 * non una volta sola — ed è il motivo per cui accetta un `Tenant` invece di
 * girare a vuoto.
 *
 * `UserRole::SuperAdmin` è escluso di proposito: non è un ruolo spatie ma la
 * colonna `users.is_super_admin`. `tenantScoped()` filtra già per noi, così
 * aggiungendo un ruolo all'enum non bisogna ricordarsi di toccare anche qui.
 */
class RoleSeeder extends Seeder
{
    public function __construct(private readonly TenantContext $context) {}

    public function run(?Tenant $tenant = null): void
    {
        if ($tenant === null) {
            $this->command?->warn('RoleSeeder richiede un tenant: i ruoli sono per palestra.');

            return;
        }

        $this->context->runAs($tenant, function () use ($tenant): void {
            foreach (UserRole::tenantScoped() as $role) {
                Role::firstOrCreate([
                    'name' => $role->value,
                    'guard_name' => 'web',
                    'tenant_id' => $tenant->id,
                ]);
            }
        });

        // La cache dei permessi di spatie non sa nulla dei ruoli appena creati:
        // senza questo, un `assignRole()` nella stessa esecuzione fallirebbe
        // con «There is no role named ...» pur essendo la riga a database.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
