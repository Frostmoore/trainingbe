<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * 🍽️ **Il diario lascia il server** — A1 di `plan_blocco_A.md`, 22/09/2026.
 *
 * Il diario alimentare e i preferiti vivono sul telefono dal 03/09/2026 (Parte I):
 * l'app li ha portati via col trasloco e da allora **non chiama più** nessuna
 * rotta che li legga o li scriva. 🚨 L'informativa dice che le tabelle che li
 * contenevano *«sono state cancellate»*: questa migrazione la rende vera.
 *
 * ⛔ **Non controlla chi ha traslocato.** Il committente, 02/09/2026: *«Non ci
 * sono utenti, sono solo io»* — la «cerimonia» che avrebbe confrontato i conteggi
 * (I3.3) non è mai stata costruita, quindi il server non lo sa. La rete di
 * sicurezza è il backup che `deploy-staging.sh` fa prima di ogni deploy.
 *
 * ⚠️ `down()` **non ricrea niente**: le righe non tornerebbero, e due tabelle
 * vuote farebbero credere il contrario.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('food_favorites');
        Schema::dropIfExists('food_entries');
    }

    public function down(): void
    {
        // Voluto: vedi il commento in cima.
    }
};
