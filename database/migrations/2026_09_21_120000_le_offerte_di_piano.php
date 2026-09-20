<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🔗 **Le offerte: un piano su misura, e il link per pagarlo** — 21/09/2026.
 *
 * 📌 Il committente, sul piano cucito a mano: *«certo che il piano cucito a
 * mano deve essere fatto con stripe»*.
 *
 * ── ⛔ Cosa c'era stamattina, e perché era sbagliato ──────────────────────
 *
 * Il piano si cuciva dal pannello e si **assegnava** a un cliente: nasceva un
 * abbonamento senza `stripe_subscription_id`, cioè **un servizio acceso che
 * nessuno fattura**. 🚨 Per i piani a canone voleva dire emettere la fattura
 * fuori e ricordarsi ogni mese di incassarla — il modo migliore di regalare un
 * abbonamento senza accorgersene.
 *
 * ── 💡 Perché una riga e non un semplice «rendi comprabile questo piano» ──
 *
 * Perché un piano su misura **non è del listino**: è di **quel** cliente. Una
 * tabella di offerte dice tre cose che una spunta su `plans` non direbbe:
 *
 * | | |
 * |---|---|
 * | **A chi** | il tenant, se è già cliente; l'email, se è ancora un preventivo |
 * | **Fino a quando** | `scade_il` — un prezzo concordato a settembre non si paga a maggio |
 * | **Se è stata usata** | `accettata_il`, scritta dal webhook di Stripe |
 *
 * 🚨 **E soprattutto: il token.** È lui che rende il piano comprabile, e solo a
 * chi ha il link. ⛔ Senza, l'unica alternativa sarebbe mettere il piano a
 * listino — cioè far comprare a chiunque il prezzo cucito per uno.
 *
 * ⚠️ `tenant_id` è **nullable**, e `null` vuol dire «offerta a chi non è ancora
 * cliente»: il modello usa `BelongsToTenantOrGlobal`, come `exercises`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offerte_di_piano', function (Blueprint $tabella): void {
            $tabella->id();

            /*
             * ⛔ `cascadeOnDelete` e non `nullOnDelete`: un'offerta senza piano
             * non è un'offerta, è un link che apre una pagina vuota. 💡 I piani
             * comunque non si cancellano (`PlanResource::canDelete()` è sempre
             * falso); questo è il vincolo che lo dice anche al database.
             */
            $tabella->foreignId('plan_id')->constrained()->cascadeOnDelete();

            /**
             * 🚨 Il segreto: 32 caratteri, come i token degli inviti.
             *
             * ⚠️ È l'unica cosa che autorizza l'acquisto di un piano fuori
             * listino, quindi si genera con `Str::random()` — che usa il
             * generatore crittografico — e non con qualcosa di indovinabile.
             */
            $tabella->string('token', 32)->unique();

            /*
             * A chi è stata fatta. ⚠️ `null` = a qualcuno che non ha ancora uno
             * spazio: si iscrive dal link, e lo spazio nasce lì.
             */
            $tabella->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();

            /** L'indirizzo a cui l'abbiamo mandata: serve a ritrovarla. */
            $tabella->string('email', 255)->nullable();

            /*
             * 💡 Da quale richiesta nasce, quando nasce da una. Chiude il giro:
             * scrivono dal modulo → si concorda → si manda il link.
             */
            $tabella->foreignId('richiesta_di_contatto_id')->nullable()
                ->constrained('richieste_di_contatto')->nullOnDelete();

            /** Chi l'ha creata, dal pannello. */
            $tabella->foreignId('creata_da_id')->nullable()->constrained('users')->nullOnDelete();

            /**
             * 🚨 Quando smette di valere.
             *
             * ⚠️ `null` = non scade, ed è ammesso ma è la scelta peggiore: un
             * prezzo concordato resta comprabile per sempre, e fra due anni
             * qualcuno attiva un piano a un listino che non esiste più.
             */
            $tabella->timestamp('scade_il')->nullable();

            /**
             * Quando il pagamento è andato a buon fine.
             *
             * 🚨 La scrive **il webhook di Stripe**, non chi apre il checkout:
             * un'offerta segnata come accettata perché qualcuno ha *aperto* la
             * pagina di pagamento sarebbe un'offerta bruciata senza un euro
             * incassato.
             */
            $tabella->timestamp('accettata_il')->nullable();

            $tabella->timestamps();

            $tabella->index(['tenant_id', 'accettata_il']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offerte_di_piano');
    }
};
