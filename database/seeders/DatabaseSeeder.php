<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Punto d'ingresso di `artisan db:seed`.
 *
 * L'ordine conta: il super admin non dipende da nessuna palestra e va creato
 * per primo, così esiste anche se il seeder dimostrativo fallisce.
 *
 * ⚠️ `DemoGymSeeder` **non gira in produzione**: creerebbe palestre finte con
 * password note in un ambiente vero. La guardia è qui e non dentro al seeder
 * perché si veda leggendo questo file, che è il primo che si apre.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(SuperAdminSeeder::class);

        // 🚨 La libreria esercizi gira SEMPRE, produzione compresa: non contiene
        // dati finti, e senza di lei l'onboarding di ogni palestra comincia con
        // due ore di data entry che nessuno fa. E' idempotente.
        $this->call(ExerciseLibrarySeeder::class);

        if (app()->environment('production')) {
            $this->command?->warn('Ambiente di produzione: DemoGymSeeder saltato.');

            return;
        }

        $this->call(DemoGymSeeder::class);
    }
}
