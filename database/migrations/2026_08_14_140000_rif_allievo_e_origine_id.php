<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Il «Rif. Allievo» e l'identita' stabile del piano — G4.4 (D3, D15).
 *
 * ── D3 — «Rif. Allievo», e il compromesso che porta con se' ──────────────
 *
 * 🚨 L'anonimato dei piani serve a impedire che il server sappia **chi segue
 * quale programma**: da un programma post-infortunio si capisce cos'e' successo
 * a chi lo esegue, e quello e' dato sanitario. Un campo di testo libero in cui
 * il trainer scrivera' «Mario Rossi» **rimette esattamente quel legame sul
 * server**.
 *
 * ✅ La decisione del committente: si tiene in chiaro — serve al pannello — ma
 * lo vede **solo chi l'ha scritto** (`created_by`), l'interfaccia suggerisce le
 * iniziali, e il campo **non entra mai nella busta cifrata** quando il piano
 * parte verso l'allievo.
 *
 * ── D15 — `origine_id`, e perche' il titolo non basta ────────────────────
 *
 * Il telefono deve riconoscere che una scheda arrivata e' **la versione nuova**
 * di una che ha gia', per sostituirla invece di affiancarla.
 *
 * 🚨 **Il titolo non e' un'identita'**: due piani possono chiamarsi uguale, e un
 * piano puo' cambiare nome restando lo stesso. Sbagliare qui vuol dire
 * **cancellare un piano diverso** — il danno esatto che D15 doveva evitare.
 *
 * ⚠️ **Backfill obbligatorio**: una colonna `unique` lasciata a `null` su piu'
 * righe e' un indice che non protegge niente — MariaDB ammette piu' `null` in un
 * indice unico.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['workout_plans', 'nutrition_plans'] as $tabella) {
            Schema::table($tabella, function (Blueprint $table): void {
                // D3 — il promemoria privato di chi scrive il piano.
                $table->string('rif_allievo', 120)->nullable()->after('name');

                // D15 — nullable adesso, popolata dal backfill, unica in fondo.
                $table->ulid('origine_id')->nullable()->after('rif_allievo');
            });

            $this->assegnaUnOrigineId($tabella);

            Schema::table($tabella, function (Blueprint $table): void {
                $table->unique('origine_id');
            });
        }
    }

    /**
     * ⚠️ Riga per riga e non con una sola `UPDATE`: serve un ULID **diverso** per
     * ciascuna. Un `update()` di massa scriverebbe lo stesso valore ovunque, e
     * l'indice unico che segue fallirebbe — a meta' migrazione, con un errore di
     * chiave duplicata che non dice niente su cosa sia andato storto.
     */
    private function assegnaUnOrigineId(string $tabella): void
    {
        DB::table($tabella)->whereNull('origine_id')->orderBy('id')
            ->each(function (object $riga) use ($tabella): void {
                DB::table($tabella)->where('id', $riga->id)->update([
                    'origine_id' => (string) Str::ulid(),
                ]);
            });
    }

    public function down(): void
    {
        foreach (['workout_plans', 'nutrition_plans'] as $tabella) {
            Schema::table($tabella, function (Blueprint $table): void {
                $table->dropUnique([$table->getTable().'_origine_id_unique']);
                $table->dropColumn(['rif_allievo', 'origine_id']);
            });
        }
    }
};
