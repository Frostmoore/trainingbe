<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Profile;
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
            ['name' => 'Palestra Demo', 'slug' => 'palestra-demo', 'join_code' => 'DEMO2345',
                'color_primary' => '#0F766E', 'color_accent' => '#F97316'],
            // La seconda esiste per un motivo preciso: con una sola palestra
            // nessun test di isolamento prova niente.
            ['name' => 'Fitness Prova', 'slug' => 'fitness-prova', 'join_code' => 'PROVA234',
                'color_primary' => '#7C3AED', 'color_accent' => '#22C55E'],
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

            $this->context->runAs($tenant, function () use ($tenant, $i): void {
                $admin = $this->utente($tenant, "admin@{$tenant->slug}.test",
                    'Anna Amministratrice', UserRole::GymAdmin);

                $trainer = $this->utente($tenant, "trainer@{$tenant->slug}.test",
                    'Tommaso Trainer', UserRole::Trainer);

                // Un paio di iscritti, con profilo compilato: senza, il calcolo
                // del fabbisogno calorico (B5) non avrebbe su cosa girare.
                foreach ([
                    ['Marco Iscritto', 'm', '1990-04-12', 178, 'moderate', 'lose_weight', 75.0],
                    ['Giulia Iscritta', 'f', '1995-09-30', 165, 'light', 'maintain', 58.0],
                ] as $k => [$nome, $sesso, $nascita, $altezza, $attivita, $obiettivo, $peso]) {
                    $m = $this->utente($tenant, "iscritto{$k}@{$tenant->slug}.test", $nome, UserRole::Member);

                    Profile::firstOrCreate(['user_id' => $m->id], [
                        'tenant_id' => $tenant->id,
                        'sex' => $sesso,
                        'birthdate' => $nascita,
                        'height_cm' => $altezza,
                        'activity_level' => $attivita,
                        'goal' => $obiettivo,
                        'target_weight_kg' => $peso,
                        'meal_hours' => ['colazione' => '08:00', 'pranzo' => '13:00', 'cena' => '20:00'],
                    ]);

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

            $this->command?->info("Palestra «{$tenant->name}» pronta — codice {$tenant->join_code}");
        }
    }

    /** Crea l'utente se manca e gli assegna il ruolo, senza duplicare nulla. */
    private function utente(Tenant $tenant, string $email, string $nome, UserRole $ruolo): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'tenant_id' => $tenant->id,
                'name' => $nome,
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
