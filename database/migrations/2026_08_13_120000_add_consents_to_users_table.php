<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * S9.1 / S9.2 — i consensi e lo sbarramento dei maggiorenni.
 *
 * ── 🚨 Perche' sono DATE e non booleani ────────────────────────────────────
 *
 * Un consenso non e' «si'/no»: e' «si', **il giorno tale**». L'art. 7(1) GDPR
 * chiede di poter **dimostrare** che il consenso e' stato dato, e un `true`
 * senza data non dimostra niente — non dice quando, quindi non dice nemmeno a
 * quale versione dell'informativa si riferisse.
 *
 * ⚠️ E il `null` dice la cosa giusta da solo: **non e' stato dato**, che e'
 * diverso da «e' stato negato» solo nella forma ma non negli effetti. Un
 * booleano `false` di serie avrebbe invece confuso «non ha ancora scelto» con
 * «ha detto di no».
 *
 * ── 🚨 Perche' sono SEPARATI ───────────────────────────────────────────────
 *
 * Una sola casella «accetto tutto» **non e' consenso esplicito** ai sensi
 * dell'art. 9(2)(a). Il consenso ai dati sanitari e quello a mandare il diario
 * all'AI sono due decisioni diverse, e vanno prese due volte.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * 🚨 **La dichiarazione di maggiore eta'.**
             *
             * ⚠️ **Non e' un controllo su `profiles.birthdate`**: dopo S5 il
             * profilo sta sul telefono, e il server **non sa quanti anni ha la
             * persona**. E' una dichiarazione, conservata con data e ora.
             *
             * 💡 **Perche' una dichiarazione basta**: 18+ e' la scelta che
             * *chiude* il problema. Sotto i 14 servirebbe raccogliere e
             * verificare il consenso genitoriale; fra i 14 e i 17 la soglia
             * cambia da Stato a Stato dell'Unione. Una riga sola copre tutti.
             */
            $table->timestamp('age_confirmed_at')->nullable()->after('email_verified_at');

            /** Le condizioni d'uso: obbligatorie per usare il servizio. */
            $table->timestamp('terms_accepted_at')->nullable()->after('age_confirmed_at');

            /*
             * ⚠️ **Facoltativo davvero: l'app funziona senza.** Se fosse
             * necessario per usare il servizio non sarebbe «liberamente dato»
             * (art. 7(4)), e quindi non sarebbe consenso.
             */
            $table->timestamp('health_consent_at')->nullable()->after('terms_accepted_at');

            /*
             * 🚨 **Separato dal precedente, e non e' pignoleria.**
             *
             * Mandare il diario alimentare a un modello AI e' un trasferimento
             * a un terzo fuori dall'Unione, e da cio' che si mangia si inferisce
             * lo stato di salute (CGUE C-184/20). Chi accetta di tenere i propri
             * dati sanitari **da noi** non ha per questo accettato di mandarli
             * **ad Anthropic**: sono due domande, e vanno fatte due volte.
             */
            $table->timestamp('ai_consent_at')->nullable()->after('health_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn([
                'age_confirmed_at', 'terms_accepted_at',
                'health_consent_at', 'ai_consent_at',
            ]);
        });
    }
};
