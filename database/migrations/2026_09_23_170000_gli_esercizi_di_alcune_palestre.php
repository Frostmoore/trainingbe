<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🏋️ Un esercizio della piattaforma per **alcune** palestre — F9.5, 23/09/2026.
 *
 * 📌 Il committente: *«Palestra deve essere possibile metterne più di una (se è
 * vuoto diventa tutti)»*.
 *
 * | `exercises.tenant_id` | righe qui | Chi lo vede |
 * |---|---|---|
 * | una palestra | — | quella palestra (esercizio suo, come prima) |
 * | `null` | nessuna | **tutte** (come prima) |
 * | `null` | una o più | **solo quelle** |
 *
 * 💡 Una tabella di collegamento e non una colonna JSON: il filtro sta dentro
 * ogni ricerca di esercizi (`TenantOrGlobalScope`, `ExerciseMatcher`), e un
 * `EXISTS` su un indice costa niente.
 *
 * 🗄️ Dove vive · backup · quando muore: qui · col database · con l'esercizio o
 * con la palestra (`cascadeOnDelete`: una palestra che sparisce non deve
 * lasciare un esercizio «per nessuno», che diventerebbe «per tutti»).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('esercizi_delle_palestre', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('exercise_id')->constrained('exercises')->cascadeOnDelete();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->unique(['exercise_id', 'tenant_id']);
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('esercizi_delle_palestre');
    }
};
