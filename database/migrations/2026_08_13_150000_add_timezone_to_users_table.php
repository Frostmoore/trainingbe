<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A3 — il fuso orario si sposta sulla PERSONA.
 *
 * ── 🚨 Il difetto, riprodotto sul server ───────────────────────────────────
 *
 * ```
 * config app.timezone = UTC          <- config/app.php, scritto a mano
 * now()   = 2026-08-10T22:22:49+00:00
 * today() = 2026-08-10               <- ma a Roma sono le 00:22 dell'11
 * ```
 *
 * Dopo mezzanotte «Oggi» mostrava **ieri**: il diario si apriva sul giorno
 * sbagliato, e chi registrava una cena tardi la vedeva finire nel giorno prima.
 *
 * ⚠️ **`config/app.php` NON si tocca, ed e' deliberato.** I timestamp a database
 * devono restare in **UTC**: cambiare il fuso dell'applicazione mescolerebbe
 * dati vecchi e nuovi in modo irreparabile. Quello che deve cambiare e' il
 * **confine del giorno**, cioe' dove si taglia la giornata di chi guarda.
 *
 * ── 🚨 Perche' sull'utente e non sulla palestra ────────────────────────────
 *
 * `tenants.timezone` esiste gia' — ⚠️ ed e' **una colonna che oggi non legge
 * nessuno**, la stessa trappola di `profiles.meal_hours`.
 *
 * Ma non basta: nella **Parte B** arrivano i *free user*, che una palestra non
 * ce l'hanno. Mettere il fuso solo sul tenant vorrebbe dire riscrivere questa
 * stessa cosa fra un mese, con il codice gia' sparso in sei servizi.
 *
 * 💡 **`null` non e' un buco: e' «usa quello della palestra».** La catena e'
 * `users.timezone` → `tenants.timezone` → `Europe/Rome`, e ogni anello ha un
 * senso proprio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // 64 caratteri: gli identificativi IANA piu' lunghi
            // (`America/Argentina/ComodRivadavia`) stanno abbondantemente sotto.
            $table->string('timezone', 64)->nullable()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('timezone');
        });
    }
};
