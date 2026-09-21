<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎁 **Le promozioni** — 22/09/2026, fase F2 di `plan_UX_pannello.md`.
 *
 * ══ 📌 COME SONO STATE CHIESTE ════════════════════════════════════════════
 *
 * > *«mi serve una sezione per impostare i prezzi dei pacchetti di token, con
 * > possibili promozioni e/o sconti attivabili con link o in automatico (per
 * > uno o più utenti, per uno o più ruoli, per una o più palestre), e se deve
 * > essere a termine o per sempre. Ovviamente, mi servirà anche una lista di
 * > promozioni attive, sospese, disattivate, scadute e tutto quanto»*
 *
 * > *«Valgono su tutto, devo poter scegliere se farle cumulabili o no e il link
 * > deve portare a una pagina che spiega l'offerta, ma nel pannello non sul
 * > sito»*
 *
 * ══ 🚨 DUE TABELLE, E PERCHÉ NON UNA ═════════════════════════════════════
 *
 * Una promozione ha **più bersagli insieme** — «tutti i trainer **e** gli
 * allievi della palestra Tal dei Tali **e** queste tre persone». ⛔ Con quattro
 * colonne nullable sulla stessa riga se ne può esprimere **uno solo**, e la
 * seconda richiesta del committente («per uno o più…») sarebbe già impossibile.
 *
 * ══ ⚠️ LO SCONTO È UN NUMERO CHE TOCCA I SOLDI ═══════════════════════════
 *
 * 🚨 `percentuale` e `importo` sono due cose diverse e non si mescolano: 20 in
 * una promo percentuale è **un quinto**, in una a importo è **venti
 * centesimi**. Per questo il tipo è una colonna sua e non si deduce dal valore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promozioni', function (Blueprint $tabella): void {
            $tabella->id();

            /**
             * 🚨 **Il codice è il link.** Chi lo ha può aprire la pagina che
             * spiega l'offerta, quindi non è un nome: si genera, e si guarda
             * come si guarda una password.
             */
            $tabella->string('codice', 40)->unique();

            $tabella->string('nome', 80);

            /** Quello che legge il cliente nella pagina dell'offerta. */
            $tabella->text('descrizione')->nullable();

            /** `percentuale` o `importo`. */
            $tabella->string('tipo_sconto', 20);

            /**
             * ⚠️ Percentuale: 1-100. Importo: **centesimi**.
             *
             * 🚨 Non si deduce dal valore: 20 vuol dire due cose diverse nei
             * due casi, e indovinare sarebbe sbagliare su un prezzo.
             */
            $tabella->unsignedInteger('valore');

            /**
             * Su cosa vale: `canone`, `gettoni`, `consumo` — una o più.
             *
             * 📌 *«Valgono su tutto»*: il default le accende tutte e tre, ma
             * restano scelte una per una perché una promozione sui gettoni non
             * deve scontare per sbaglio l'abbonamento di una palestra.
             */
            $tabella->json('su_cosa');

            /**
             * 🚨 **La cumulabilità è una proprietà della singola promozione**,
             * non una regola di sistema — 📌 *«devo poter scegliere se farle
             * cumulabili o no»*.
             */
            $tabella->boolean('cumulabile')->default(false);

            /** `automatica` (chi rientra nel bersaglio ce l'ha) o `link`. */
            $tabella->string('attivazione', 20)->default('automatica');

            /**
             * `attiva`, `sospesa`, `disattivata`.
             *
             * ⚠️ **«Scaduta» non è uno stato**: si ricava dalle date, e tenerlo
             * in colonna vorrebbe dire un lavoro notturno che lo aggiorna — e
             * una promozione che resta «attiva» finché quel lavoro non gira.
             */
            $tabella->string('stato', 20)->default('attiva');

            /** ⚠️ `null` su tutte e due = vale da subito e per sempre. */
            $tabella->timestamp('inizia_il')->nullable();
            $tabella->timestamp('scade_il')->nullable();

            /**
             * Quante volte si può usare in tutto. `null` = senza limite.
             *
             * 💡 `usata_volte` si alza **quando il pagamento è andato a buon
             * fine**, non quando qualcuno apre il link: un contatore che scende
             * guardando una pagina è un'offerta che si esaurisce senza vendere
             * niente.
             */
            $tabella->unsignedInteger('massimo_usi')->nullable();
            $tabella->unsignedInteger('usata_volte')->default(0);

            /*
             * 💳 Quello che Stripe conosce di questa promozione. ⚠️ Si crea
             * **alla prima volta che serve**, non alla creazione della riga:
             * una promozione scritta e mai usata non deve lasciare rifiuti
             * nell'account Stripe.
             */
            $tabella->string('stripe_coupon_id', 80)->nullable();

            $tabella->foreignId('creata_da_id')->nullable()->constrained('users')->nullOnDelete();

            $tabella->timestamps();

            $tabella->index(['stato', 'scade_il']);
        });

        Schema::create('promozione_bersagli', function (Blueprint $tabella): void {
            $tabella->id();

            $tabella->foreignId('promozione_id')->constrained('promozioni')->cascadeOnDelete();

            /**
             * `tutti`, `ruolo`, `palestra`, `allievi_di_trainer`, `utente`.
             *
             * 💡 Cinque casi espliciti invece di una relazione polimorfica:
             * «gli allievi di questo trainer» non è un record, è una
             * **domanda**, e un morph non saprebbe farla.
             */
            $tabella->string('tipo', 30);

            /** Per `ruolo`: il nome del ruolo (`member`, `free_trainer`…). */
            $tabella->string('ruolo', 40)->nullable();

            /** Per `palestra`: quale. ⚠️ `cascadeOnDelete`: se sparisce lei, sparisce il bersaglio. */
            $tabella->foreignId('tenant_id')->nullable()->constrained()->cascadeOnDelete();

            /** Per `utente` e `allievi_di_trainer`: chi. */
            $tabella->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();

            $tabella->timestamps();

            $tabella->index(['promozione_id', 'tipo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promozione_bersagli');
        Schema::dropIfExists('promozioni');
    }
};
