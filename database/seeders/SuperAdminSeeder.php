<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * L'amministratore della piattaforma.
 *
 * Credenziali da ambiente (`SEED_SUPERADMIN_EMAIL` / `_PASSWORD`), mai scritte
 * nel codice: un seeder è versionato, e una password nel repository è una
 * password pubblica.
 *
 * Idempotente: rieseguirlo non duplica l'utente e **non ne riscrive la
 * password**. Un `updateOrCreate` con la password dell'ambiente riporterebbe
 * indietro un cambio fatto dal pannello, in silenzio, al primo `db:seed`.
 */
class SuperAdminSeeder extends Seeder
{
    public function __construct(private readonly TenantContext $context) {}

    public function run(): void
    {
        $email = strtolower((string) env('SEED_SUPERADMIN_EMAIL'));
        $password = (string) env('SEED_SUPERADMIN_PASSWORD');

        if ($email === '' || $password === '') {
            $this->command?->warn(
                'SuperAdminSeeder saltato: SEED_SUPERADMIN_EMAIL e SEED_SUPERADMIN_PASSWORD non impostate.'
            );

            return;
        }

        // Fuori da ogni palestra: il super admin ha tenant_id = null, e il
        // global scope a contesto vuoto non filtra, quindi lo troviamo davvero
        // anche se il seeder gira dopo altri che hanno impostato un contesto.
        $this->context->runWithoutTenant(function () use ($email, $password): void {
            $user = User::withoutGlobalScopes()->where('email', $email)->first();

            if ($user !== null) {
                // Ci si limita ad alzare il flag, se per qualche motivo mancasse.
                if (! $user->is_super_admin) {
                    $user->forceFill(['is_super_admin' => true])->save();
                    $this->command?->info("Flag di super admin ripristinato su {$email}.");
                } else {
                    $this->command?->info("Super admin {$email} già presente.");
                }

                return;
            }

            $user = User::create([
                'tenant_id' => null,
                'name' => 'Amministratore piattaforma',
                'email' => $email,
                'password' => $password,
                'locale' => 'it',
            ]);

            // `is_super_admin` non è fillable di proposito (sarebbe una scalata
            // di privilegi in attesa di un controller distratto): si valorizza
            // esplicitamente, e solo qui.
            $user->forceFill(['is_super_admin' => true])->save();

            $this->command?->info("Super admin creato: {$email}");
        });
    }
}
