<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il testo del consiglio esce dal server — Parte I, I5.3, 03/09/2026.
 *
 * ══ 🚨 PERCHE' ═══════════════════════════════════════════════════════════
 *
 * 📌 Il committente, il 16/08/2026: *«perche' dovremmo salvare il consiglio del
 * giorno? L'utente lo vede quel giorno e via»*.
 *
 * 🚨 **E' il testo piu' intimo che il server contenga.** Un consiglio dice *«hai
 * mangiato 1.400 kcal, ti mancano proteine, ieri non ti sei allenato»*: e' un
 * ritratto di una persona in tre righe, scritto in italiano e leggibile da
 * chiunque apra la tabella.
 *
 * 💡 Dopo D9 e la Parte I il server non ha piu' ne' il peso, ne' il sonno, ne'
 * gli allenamenti, ne' il diario. ⛔ Il consiglio era rimasto **l'unico posto in
 * cui tutte e quattro le cose tornavano insieme**, riassunte — cioe' il dato piu'
 * sensibile di tutti, ricostruito da quelli che avevamo tolto.
 *
 * ══ ⚠️ COSA RESTA, E PERCHE' NON SI CANCELLA LA TABELLA ══════════════════
 *
 * `ai_advices` **serve ancora**, e non come cache del testo: e' il **registro
 * delle fasce**. Dice *«per questa persona, in questa fascia, il consiglio e'
 * gia' stato generato e pagato»*, ed e' il primo dei due cancelli di 3b-AB —
 * quello che tiene il tetto a tre chiamate al giorno.
 *
 * ⛔ Cancellarla vorrebbe dire generare a ogni apertura dell'app.
 *
 * 💡 Restano `date`, `fascia`, `kind`, `context_hash`, `model` e i timestamp:
 * **quando** e' successo e **con che modello**, non **cosa e' stato detto**.
 *
 * ══ 🚨 LE RIGHE VECCHIE PERDONO IL TESTO, ED E' IL PUNTO ═════════════════
 *
 * ⚠️ `down()` rimette la colonna ma **non il contenuto**, e non e' una
 * dimenticanza: un rollback che riportasse indietro i testi vorrebbe dire che
 * non erano mai stati cancellati davvero.
 *
 * 💡 Al massimo si perde il consiglio della fascia in corso, e solo per chi non
 * ha ancora la versione dell'app che se lo tiene: alla fascia dopo se ne genera
 * uno nuovo. E' esattamente la vita che il committente gli aveva dato.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_advices', function (Blueprint $table): void {
            $table->dropColumn('body');
        });
    }

    public function down(): void
    {
        Schema::table('ai_advices', function (Blueprint $table): void {
            /*
             * ⚠️ **Nullable, mentre prima era obbligatoria.** Le righe che
             * esistono adesso un testo non ce l'hanno e non lo riavranno: una
             * colonna `NOT NULL` le rifiuterebbe, e il rollback fallirebbe
             * proprio sui dati che dice di voler recuperare.
             */
            $table->text('body')->nullable()->after('context_hash');
        });
    }
};
