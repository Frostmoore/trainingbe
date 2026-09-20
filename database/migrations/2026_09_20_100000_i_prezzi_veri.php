<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 💰 **I prezzi veri** — 20/09/2026.
 *
 * 📌 Il committente: *«penso che sia anche il momento di definire i prezzi veri
 * delle cose»*, con l'elenco completo. ⛔ Fino a ieri erano segnaposto
 * dichiarati, e `config/listino.php` diceva «non si vende a nessuno su questi
 * numeri».
 *
 * ── 🚨 Perché i valori stanno QUI e non si chiama `PlanSeeder` ─────────────
 *
 * Perché un `db:seed` in produzione non lo lancia nessuno, e un piano col
 * prezzo vecchio è un **incasso sbagliato**, non un'etichetta sbagliata: Stripe
 * addebita `plans.price_cents`. La migrazione gira col deploy, il seeder no.
 * ⚠️ `PlanSeeder` resta allineato per gli ambienti nuovi: i due devono dire la
 * stessa cosa, ed è la ragione per cui questo file ripete i numeri.
 *
 * ── 💡 Cosa cambia, in breve ──────────────────────────────────────────────
 *
 * | | Prima (segnaposto) | Adesso |
 * |---|---|---|
 * | Singolo | 7,99 € | **8,00 €** |
 * | Trainer | 19,90 / 49,90 / 69,90 (5/15/30 allievi) | **30 / 70 / 100 €** |
 * | Palestra | 99,90 / 199,90 / 349,90 (20/50/100 iscritti) | **60 / 130 / 200 €** (10/30/60 iscritti) |
 * | Su misura | solo palestre, 5,99 a iscritto, min. 100 | **anche trainer**; 2,80 a iscritto + canone; min. 30 (trainer) e 60 (palestra) |
 * | Gettoni | 100/500/2000 | **anche 5.000**, e prezzi nuovi |
 *
 * 🚨 **I codici delle palestre cambiano** (`gym_20` → `gym_10`, `gym_50` →
 * `gym_30`, `gym_100` → `gym_60`): portavano nel nome il numero di iscritti, e
 * quel numero è cambiato. ⚠️ Si **rinominano**, non si ricreano: gli abbonamenti
 * puntano all'`id`, e cancellare le righe li lascerebbe senza piano.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * 🆕 Il **canone** del piano su misura: si paga anche a zero allievi.
         *
         * 💡 Prima non serviva, perché il piano a consumo non ne aveva uno.
         */
        Schema::table('plans', function (Blueprint $tabella): void {
            $tabella->unsignedInteger('canone_cent')
                ->nullable()
                ->after('prezzo_iscritto_cent');
        });

        // I codici che cambiano nome, prima di riscriverne i valori.
        foreach (['gym_20' => 'gym_10', 'gym_50' => 'gym_30', 'gym_100' => 'gym_60'] as $vecchio => $nuovo) {
            DB::table('plans')->where('code', $vecchio)->update(['code' => $nuovo]);
        }

        $comune = [
            'ai_enabled' => true,
            'ai_monthly_calls_per_member' => 150,
            'ai_monthly_photo_calls_per_member' => 15,
            'is_public' => true,
            'updated_at' => now(),
        ];

        $piani = [
            ['code' => 'plus', 'name' => 'Plus', 'kind' => 'person', 'price_cents' => 800, 'max_members' => null, 'max_trainers' => null],

            ['code' => 'trainer_5', 'name' => 'Trainer Basic', 'kind' => 'trainer', 'price_cents' => 3000, 'max_members' => 5, 'max_trainers' => null],
            ['code' => 'trainer_15', 'name' => 'Trainer Advanced', 'kind' => 'trainer', 'price_cents' => 7000, 'max_members' => 15, 'max_trainers' => null],
            ['code' => 'trainer_30', 'name' => 'Trainer Pro', 'kind' => 'trainer', 'price_cents' => 10000, 'max_members' => 30, 'max_trainers' => null],

            ['code' => 'gym_10', 'name' => 'Palestra Basic', 'kind' => 'gym', 'price_cents' => 6000, 'max_members' => 10, 'max_trainers' => 5],
            ['code' => 'gym_30', 'name' => 'Palestra Advanced', 'kind' => 'gym', 'price_cents' => 13000, 'max_members' => 30, 'max_trainers' => 10],
            ['code' => 'gym_60', 'name' => 'Palestra Pro', 'kind' => 'gym', 'price_cents' => 20000, 'max_members' => 60, 'max_trainers' => 20],
        ];

        foreach ($piani as $piano) {
            DB::table('plans')->updateOrInsert(
                ['code' => $piano['code']],
                $piano + $comune + ['created_at' => now()],
            );
        }

        /*
         * I due piani **su misura**. 🚨 `price_cents = 0` e non è gratis: il
         * canone sta in `canone_cent`, e chi legge solo `price_cents` deve
         * trovarci zero canone **fisso di listino**, non un prezzo sbagliato.
         */
        foreach ([
            ['code' => 'trainer_consumo', 'name' => 'Trainer su misura', 'kind' => 'trainer', 'canone_cent' => 1000, 'minimo_iscritti' => 30, 'max_trainers' => null],
            ['code' => 'gym_consumo', 'name' => 'Palestra su misura', 'kind' => 'gym', 'canone_cent' => 1500, 'minimo_iscritti' => 60, 'max_trainers' => null],
        ] as $misura) {
            DB::table('plans')->updateOrInsert(
                ['code' => $misura['code']],
                $misura + [
                    'price_cents' => 0,
                    'prezzo_iscritto_cent' => 280,
                    'max_members' => null,
                ] + $comune + ['created_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('plans')->where('code', 'trainer_consumo')->delete();

        foreach (['gym_10' => 'gym_20', 'gym_30' => 'gym_50', 'gym_60' => 'gym_100'] as $nuovo => $vecchio) {
            DB::table('plans')->where('code', $nuovo)->update(['code' => $vecchio]);
        }

        Schema::table('plans', function (Blueprint $tabella): void {
            $tabella->dropColumn('canone_cent');
        });
    }
};
