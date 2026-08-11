<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S6 — la chat diventa illeggibile per il server.
 *
 * 🚨 **Il guadagno non e' astratto.** Fino a ieri «le palestre non leggono le
 * chat dei loro trainer» era garantito da policy e gate, cioe' da codice che
 * puo' avere un bug. Da qui in avanti e' impossibile **per costruzione**: il
 * server conserva byte di cui non ha la chiave.
 *
 * Tre cose, e ognuna serve a una parte diversa dello schema:
 *
 * 1. `recovery_keys` — il **pacchetto incartato** della chiave maestra. Sta qui
 *    perche' e' l'unico modo di ritrovare il proprio account da un telefono
 *    nuovo. E' cifrato con una chiave derivata dalla password di recupero, che
 *    **non transita mai** da noi.
 * 2. `chat_keys` — il registro delle chiavi **pubbliche**. Pubbliche davvero:
 *    servono a scrivere a qualcuno, non a leggere.
 * 3. `messages` — il corpo smette di essere testo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recovery_keys', function (Blueprint $table): void {
            $table->id();

            // Uno per persona: la chiave maestra e' una sola, e un secondo
            // pacchetto vorrebbe dire due password valide contemporaneamente —
            // cioe' non sapere piu' quale sia quella vera.
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // 🚨 I parametri del KDF stanno **nella riga**, non nel codice.
            // Il costo di Argon2id andra' alzato col tempo: se i parametri
            // fossero costanti dell'app, alzarli renderebbe illeggibile ogni
            // pacchetto gia' scritto — e nessuno se ne accorgerebbe fino al
            // primo utente che prova a recuperare l'account.
            $table->unsignedTinyInteger('version');
            $table->string('kdf', 32);
            $table->unsignedInteger('ops_limit');
            $table->unsignedBigInteger('mem_limit');

            // base64 di 16 byte di salt, 24 di nonce, 48 di chiave incartata.
            $table->string('salt', 64);
            $table->string('nonce', 64);
            $table->string('wrapped_key', 255);

            $table->timestamps();
        });

        Schema::create('chat_keys', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();

            // base64 di 32 byte X25519.
            $table->string('public_key', 64);

            // ⚠️ Serve all'app per accorgersi che la chiave dell'altra persona
            // e' **cambiata**: succede quando qualcuno perde la chiave maestra e
            // ne genera una nuova, ma e' anche l'unico segnale visibile di un
            // server che prova a mettersi in mezzo. L'app lo dice, non lo
            // nasconde.
            $table->timestamps();
        });

        /*
         * 🚨 **I messaggi in chiaro si cancellano, non si convertono.**
         *
         * Non c'e' modo di cifrarli: le chiavi non esistevano quando sono stati
         * scritti, e nessuno tranne i due interessati potrebbe produrle. Le
         * alternative erano lasciarli leggibili accanto a quelli cifrati —
         * cioe' tenere in piedi per sempre proprio la cosa che questa fase
         * esiste per togliere — oppure toglierli.
         *
         * ⚠️ E' distruttivo e va detto: qualunque conversazione precedente a
         * questa migrazione sparisce da entrambi i lati. E' accettabile **solo
         * perche' il sistema non e' ancora in mano a nessun utente vero**. Il
         * giorno in cui lo sara', una migrazione come questa non si potra' piu'
         * fare.
         */
        DB::table('messages')->delete();

        Schema::table('messages', function (Blueprint $table): void {
            // La versione della busta: senza, l'unico modo di capire come sono
            // stati prodotti i byte e' indovinare.
            $table->unsignedTinyInteger('envelope_version')->after('sender_id');

            // base64 di 24 byte, nuovo a ogni messaggio.
            $table->string('nonce', 64)->after('envelope_version');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table): void {
            $table->dropColumn(['envelope_version', 'nonce']);
        });

        Schema::dropIfExists('chat_keys');
        Schema::dropIfExists('recovery_keys');

        // ⚠️ I messaggi cancellati sopra **non tornano**, e le buste cifrate
        // rimaste diventano testo illeggibile in una colonna che dice «testo».
        // Questo `down()` serve a far girare i test, non a tornare indietro
        // davvero: dopo questa migrazione non si torna indietro.
    }
};
