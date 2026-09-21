<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎯 **I destinatari delle promozioni** — 24/09/2026, P5 di `plan_promozioni.md`.
 *
 * - `promozione_bersagli.escludi`: una riga che **toglie** — «tutti tranne la
 *   palestra X» (opzione 26).
 * - `promozione_condizioni`: filtri in **AND** sopra i bersagli — abbonati o no,
 *   nuovi iscritti, inattivi, chi non ha mai comprato gettoni… (opzioni 20-24).
 *
 * ⚠️ I bersagli restano in **OR** (F2): «i trainer e la palestra X» = chi è
 * trainer **oppure** sta nella palestra X. Le condizioni sono in **AND**
 * perché esistono per restringere: in OR non restringerebbero niente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promozione_bersagli', function (Blueprint $tabella): void {
            $tabella->boolean('escludi')->default(false)->after('tipo');
        });

        Schema::create('promozione_condizioni', function (Blueprint $tabella): void {
            $tabella->id();
            $tabella->foreignId('promozione_id')->constrained('promozioni')->cascadeOnDelete();

            /** `App\Enums\TipoDiCondizione`. */
            $tabella->string('tipo', 40);

            /** Per tipo: `{"stato": …}`, `{"giorni": 30}`, `{"data": "2026-10-01"}`, `{"mesi": 12}`, `{"plan_ids": […]}`, `{"gettoni": 500}`. */
            $tabella->json('parametri')->nullable();

            $tabella->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promozione_condizioni');

        Schema::table('promozione_bersagli', function (Blueprint $tabella): void {
            $tabella->dropColumn('escludi');
        });
    }
};
