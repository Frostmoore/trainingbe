<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 📱 **I dati del corpo lasciano il server anche come colonne** — 19/09/2026.
 *
 * 📌 Il committente: *«assicurati che non ci scriva niente di personale
 * (esattamente come peso altezza e data di nascita)»* e poi, a trasloco fatto:
 * *«puoi rimuovere i dati di tutti dal server, quella roba non serve più»* ·
 * *«Ok fallo»*.
 *
 * 💡 L'ordine è stato: `be v24.1.0` smette di scriverli, `fe v18.1.0` li porta
 * sul telefono e li fa cancellare, poi si svuotano quelli di tutti (8 → 0).
 * Solo adesso si tolgono le colonne: prima avrebbe voluto dire perderli.
 *
 * ⚠️ **Resta `notes`**: sono le note dello staff, le scrive la palestra.
 *
 * ⛔ Il `down()` rimette le colonne **vuote**: i dati non esistono più da nessuna
 * parte sul server (il backup è `predeploy-v24.1.0-20260919-163306.sql.gz`).
 */
return new class extends Migration
{
    private const COLONNE = [
        'sex', 'birthdate', 'height_cm', 'activity_level', 'goal', 'target_weight_kg', 'meal_hours',
    ];

    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $tabella): void {
            $tabella->dropColumn(self::COLONNE);
        });
    }

    public function down(): void
    {
        Schema::table('profiles', function (Blueprint $tabella): void {
            $tabella->enum('sex', ['m', 'f'])->nullable()->after('user_id');
            $tabella->date('birthdate')->nullable()->after('sex');
            $tabella->unsignedSmallInteger('height_cm')->nullable()->after('birthdate');
            $tabella->string('activity_level', 20)->nullable()->after('height_cm');
            $tabella->string('goal', 20)->nullable()->after('activity_level');
            $tabella->decimal('target_weight_kg', 5, 2)->nullable()->after('goal');
            $tabella->json('meal_hours')->nullable()->after('target_weight_kg');
        });
    }
};
