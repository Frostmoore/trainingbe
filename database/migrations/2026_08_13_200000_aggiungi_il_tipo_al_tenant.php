<?php

declare(strict_types=1);

use App\Enums\TenantKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenants.kind` — F1.1 della Parte B, 13/08/2026.
 *
 * ── 🚨 Il default **è** il backfill ──────────────────────────────────────────
 *
 * `default('gym')` non è una comodità: è la migrazione dei dati esistenti. Ogni
 * riga già presente in `tenants` è una palestra vera — al 13/08/2026 non esiste
 * ancora un solo tenant personale, perché il servizio che li crea nasce in
 * questa stessa fase — quindi il valore di serie assegna a tutte quelle righe
 * l'unico valore che per loro sia vero.
 *
 * ⚠️ **Non serve un `UPDATE` esplicito** e non va aggiunto: MySQL scrive il
 * default in ogni riga esistente nel momento dell'`ADD COLUMN`. Aggiungere un
 * `DB::table('tenants')->update(...)` darebbe l'impressione che senza di esso le
 * righe resterebbero vuote, che è falso, e la prossima persona lo copierebbe.
 *
 * ── L'indice, e perché ce ne vuole uno ──────────────────────────────────────
 *
 * 💡 Dopo F1 **ogni** listato del pannello `/god` filtra per `kind` (F1.4), e
 * questa tabella è destinata a crescere come `users`, non come il numero di
 * clienti: con diecimila utenti gratuiti, «mostrami le palestre» diventa una
 * scansione completa a ogni caricamento di pagina.
 *
 * ── ⚠️ Perché `string` e non `enum` di MySQL ────────────────────────────────
 *
 * La stessa scelta già fatta per `tenants.status` e `tenants.plan`, e per lo
 * stesso motivo: un `ENUM` nativo obbliga a una migrazione con `ALTER TABLE`
 * per aggiungere un valore, mentre l'insieme dei valori leciti è già imposto in
 * PHP da `App\Enums\TenantKind` e dal cast sul modello. La fonte di verità è
 * l'enum; la colonna la conserva e basta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->string('kind', 16)
                ->default(TenantKind::Gym->value)
                ->after('slug');

            $table->index('kind');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->dropIndex(['kind']);
            $table->dropColumn('kind');
        });
    }
};
