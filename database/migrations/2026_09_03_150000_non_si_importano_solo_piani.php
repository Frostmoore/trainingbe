<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'importazione non e' piu' «di un piano»: e' di un documento — Parte K, K2.
 *
 * ══ 🚨 PERCHE' SI RINOMINA INVECE DI FARE UN GEMELLO ═════════════════════
 *
 * 📌 Il committente, il 03/09/2026: *«L'import di pdf per **le schede** e per i
 * piani alimentari»*. Nell'app l'import delle schede **non esisteva**: c'era solo
 * nel pannello palestra.
 *
 * ⛔ La strada breve era `ImportazioneScheda`, gemella di `ImportazionePiano`.
 * 🚨 Sarebbero state **due implementazioni della stessa cosa** — carica, metti in
 * coda, trascrivi, consegna la bozza, chiudi — e quella che diverge per prima e'
 * sempre la copia meno provata. E' il difetto che tutta la Parte K esiste per
 * chiudere: non si apre qui.
 *
 * 💡 Quindi una tabella sola, con una colonna che dice **cosa** si sta
 * importando. Il meccanismo e' uno; cambiano il prompt, lo schema e dove finisce
 * la bozza.
 *
 * ══ ⚠️ E IL NOME SI CAMBIA ADESSO, NON DOPO ══════════════════════════════
 *
 * 🚨 Una tabella che si chiama `importazioni_piani` e contiene schede fa
 * sbagliare **chi la legge per primo**: e' la stessa lezione di
 * `gettoni_mensili`, del 4,99 e di «Parte I mai partita». 💡 Il momento giusto
 * per cambiare un nome e' quello in cui cambia il significato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('importazioni_piani', 'importazioni_da_documento');

        Schema::table('importazioni_da_documento', function (Blueprint $table): void {
            /*
             * 🚨 **`piano` di serie, e non `scheda`.**
             *
             * ⚠️ Le righe che esistono adesso sono tutte piani alimentari — la
             * tabella nasce per quelli — e un valore di serie sbagliato le
             * trasformerebbe in schede **senza dare nessun errore**: la bozza
             * verrebbe letta con lo schema sbagliato e uscirebbe vuota.
             */
            $table->string('genere', 16)->default('piano')->after('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::table('importazioni_da_documento', function (Blueprint $table): void {
            $table->dropColumn('genere');
        });

        Schema::rename('importazioni_da_documento', 'importazioni_piani');
    }
};
