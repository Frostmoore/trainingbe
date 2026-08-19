<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * I messaggi «una volta sola» — N16.2.
 *
 * ── 🚨 Cosa il server impara, e cosa continua a non sapere ─────────────────
 *
 * Impara **che** un messaggio è usa e getta, e quando è stato visto. Non impara
 * niente di cosa contiene: `body` resta la busta `crypto_box` che non ha nessuna
 * chiave per aprire.
 *
 * ⚠️ **È un metadato in più, ed è il prezzo della funzione.** Senza, il server
 * non saprebbe quale busta smettere di consegnare, e la cancellazione dipenderebbe
 * **solo** dall'obbedienza del programma sul telefono dell'altro — cioè da niente.
 *
 * ── 🚨 Due orologi diversi, di proposito ──────────────────────────────────
 *
 * | | Chi riceve | Chi manda |
 * |---|---|---|
 * | Fino a quando | Alla **prima apertura** (`visto_il`) | **24 ore dall'invio** |
 * | Poi | Traccia | Traccia |
 *
 * 💡 Chi manda deve potersi ricordare cosa ha mandato e a chi, per una giornata.
 * Legarlo all'apertura dell'altro avrebbe prodotto un comportamento
 * imprevedibile: la stessa foto sparisce dopo dieci secondi o dopo tre giorni, a
 * seconda di quando l'altro apre l'app.
 *
 * ── ⚠️ La conseguenza, dichiarata ─────────────────────────────────────────
 *
 * Un messaggio usa e getta che nessuno apre entro 24 ore **si perde**: la busta
 * viene svuotata dal comando notturno. È lo stesso patto degli allegati di chat
 * (N14) e va detto nell'interfaccia, non solo qui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            /*
             * 🚨 **Default `false`, e non è una formalità.** Un messaggio a cui
             * qualcuno dimenticasse di passare il campo dev'essere **normale**,
             * non effimero: il difetto in quella direzione fa vedere un messaggio
             * più a lungo del previsto, nell'altra lo cancella a chi non aveva
             * chiesto niente.
             */
            $table->boolean('usa_e_getta')->default(false)->after('media_id');

            /*
             * Quando il destinatario l'ha aperto. Da quel momento il server
             * smette di consegnargli la busta.
             *
             * ⚠️ **Non è `read_at`**, che esiste già e vuol dire un'altra cosa:
             * `read_at` è «la lista è stata guardata», questo è «il contenuto è
             * stato scoperto». Confonderli avrebbe bruciato ogni messaggio
             * effimero nell'istante in cui la conversazione si apre — cioè prima
             * che qualcuno lo leggesse davvero.
             */
            $table->dateTime('visto_il')->nullable();

            /*
             * 🚨 **L'unico pezzo di contenuto che il server impara, e va
             * detto.**
             *
             * A busta spenta il contenuto non c'e' piu': senza questo campo non
             * si potrebbe distinguere «Foto effimera» da «Messaggio effimero»,
             * perche' la distinzione stava dentro la busta che abbiamo appena
             * svuotato.
             *
             * 💡 **Non e' un metadato nuovo in pratica**: una foto in chat
             * passa da `POST /allegati` un istante prima del messaggio, quindi
             * il server sapeva gia' che quella busta portava un'immagine. Qui lo
             * si scrive esplicitamente invece di lasciarlo dedurre dai tempi.
             *
             * ⚠️ Vale **solo** per i messaggi usa e getta: sugli altri resta
             * `false` e non si guarda, perche' il contenuto e' ancora nella
             * busta e a dirlo e' lei.
             */
            $table->boolean('era_foto')->default(false);

            /*
             * 💡 Quando la busta è stata svuotata: `body` e `nonce` diventano
             * stringhe vuote e non tornano più.
             *
             * 🚨 La riga **resta**. Cancellarla farebbe sparire un messaggio dal
             * mezzo di una conversazione senza spiegazione, e chi guarda
             * penserebbe a un guasto. Resta la traccia — «Messaggio effimero» —
             * che è il contrario di un buco.
             */
            $table->dateTime('svuotato_il')->nullable();

            /*
             * ⚠️ L'indice serve al comando notturno, che cerca **le buste
             * effimere ancora piene**. Senza, ogni passata leggerebbe l'intera
             * tabella dei messaggi.
             */
            $table->index(['usa_e_getta', 'svuotato_il'], 'messages_effimeri_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropIndex('messages_effimeri_index');
            $table->dropColumn(['usa_e_getta', 'visto_il', 'era_foto', 'svuotato_il']);
        });
    }
};
