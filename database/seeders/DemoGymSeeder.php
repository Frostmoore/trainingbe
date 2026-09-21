<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Una palestra dimostrativa con dentro un po' di vita.
 *
 * Serve a due cose: avere qualcosa da guardare nei pannelli senza doverlo
 * creare a mano ogni volta, e avere dati veri su cui provare l'isolamento
 * (B1.9) — un solo tenant non dimostrerebbe niente.
 *
 * ⚠️ **Non va eseguito in produzione.** `DatabaseSeeder` lo esegue solo se
 * l'ambiente non è `production`.
 */
class DemoGymSeeder extends Seeder
{
    public function __construct(private readonly TenantContext $context) {}

    public function run(): void
    {
        $palestre = [
            ['name' => 'Palestra Demo', 'slug' => 'palestra-demo', 'color_primary' => '#0F766E', 'color_accent' => '#F97316'],
            // La seconda esiste per un motivo preciso: con una sola palestra
            // nessun test di isolamento prova niente.
            ['name' => 'Fitness Prova', 'slug' => 'fitness-prova', 'color_primary' => '#7C3AED', 'color_accent' => '#22C55E'],
        ];

        foreach ($palestre as $i => $dati) {
            $tenant = Tenant::firstOrCreate(
                ['slug' => $dati['slug']],
                $dati + [
                    'status' => TenantStatus::Active,
                    'contact_email' => "info@{$dati['slug']}.test",
                    'plan' => $i === 0 ? 'pro' : 'starter',
                ],
            );

            // I ruoli sono per palestra: vanno creati dentro ciascuna.
            $this->callWith(RoleSeeder::class, ['tenant' => $tenant]);

            $this->context->runAs($tenant, function () use ($tenant): void {
                $admin = $this->utente($tenant, "admin@{$tenant->slug}.test",
                    'Anna Amministratrice', UserRole::GymAdmin);

                $trainer = $this->utente($tenant, "trainer@{$tenant->slug}.test",
                    'Tommaso Trainer', UserRole::Trainer);

                // Un paio di iscritti. 🆕 19/09/2026: niente profilo — sesso, eta',
                // altezza e il resto stanno sul telefono, non sul server.
                foreach (['Marco Iscritto', 'Giulia Iscritta'] as $k => $nome) {
                    $m = $this->utente($tenant, "iscritto{$k}@{$tenant->slug}.test", $nome, UserRole::Member);

                    // Il trainer segue entrambi: serve a provare lo scoping
                    // delle viste del trainer (B3.5).
                    if (! $trainer->assignedMembers()->where('member_id', $m->id)->exists()) {
                        $trainer->assignedMembers()->attach($m->id, [
                            'tenant_id' => $tenant->id,
                            'assigned_at' => now(),
                            'assigned_by' => $admin->id,
                        ]);
                    }
                }
            });

            $this->command?->info("Palestra «{$tenant->name}» pronta");
        }
    }

    /** Crea l'utente se manca e gli assegna il ruolo, senza duplicare nulla. */
    private function utente(Tenant $tenant, string $email, string $nome, UserRole $ruolo): User
    {
        // Nome utente derivato dall'indirizzo: `admin@palestra-demo.test`
        // diventa `admin.palestra-demo`. Deve essere unico su tutta la
        // piattaforma, quindi ci va dentro anche la palestra.
        $username = str_replace('@', '.', str_replace('.test', '', $email));

        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'tenant_id' => $tenant->id,
                'name' => $nome,
                'username' => $username,
                // Dall'ambiente, cosi' nel repository non c'e' nessuna stringa
                // che assomigli a una credenziale. Serve nota perche' il
                // quick-access di sviluppo (B2.1) possa usarla.
                'password' => (string) env('DEMO_USER_PASSWORD', 'demo'),
                'locale' => $tenant->locale,
            ],
        );

        if (! $user->hasRole($ruolo->value)) {
            $user->assignRole($ruolo->value);
        }

        return $user;
    }
}
