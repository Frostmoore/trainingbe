<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_id` sui media.
 *
 * 🚨 **Perche' serve, visto che ogni media appartiene gia' a un modello?**
 * Perche' la relazione e' polimorfa: da una riga di `media` si arriva al
 * proprietario solo con una join che dipende da `model_type`. Senza la colonna,
 * la domanda «tutte le foto di questa palestra» — che serve per lo spazio
 * occupato, per la cancellazione di un cliente e per qualunque controllo di
 * accesso diretto al file — diventa una unione di query, una per tipo di
 * modello, che qualcuno prima o poi dimentichera' di aggiornare.
 *
 * Nullable perche' esistono media della piattaforma (i loghi caricati dal
 * pannello /god): e' lo stesso caso di `exercises`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->foreignId('tenant_id')->nullable()->after('id')->constrained()->nullOnDelete();
            $table->index(['tenant_id', 'collection_name']);
        });
    }

    public function down(): void
    {
        Schema::table('media', function (Blueprint $table): void {
            $table->dropIndex(['tenant_id', 'collection_name']);
            $table->dropConstrainedForeignId('tenant_id');
        });
    }
};
