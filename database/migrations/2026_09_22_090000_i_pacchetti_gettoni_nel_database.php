<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎟️ **I pacchetti di gettoni escono dal file ed entrano nel database** —
 * 22/09/2026, fase F2 di `plan_UX_pannello.md`.
 *
 * 📌 Il committente: *«mi serve una sezione per impostare i prezzi dei
 * pacchetti di token»*, e poi *«Ah certo, ovviamente devono essere nel
 * database, non in un file»*.
 *
 * ── 🚨 Perché è una decisione e non un travaso ─────────────────────────────
 *
 * Fino a ieri i prezzi stavano in `config/listino.php`, ed era **la regola**:
 * un solo listino, versionato, uguale per sito, app e Stripe. ⛔ Quella regola
 * è nata da un difetto vero (§86.1): i prezzi in due posti che dicevano due
 * cose diverse — 4,99 € nel database e 7,99 € sul sito.
 *
 * 💡 Spostarli qui non riapre quel buco **a una condizione**: che il file
 * smetta di essere la verità e diventi il **seme**. Da oggi:
 *
 * | | Prima | Adesso |
 * |---|---|---|
 * | La verità | `config/listino.php` | **questa tabella** |
 * | Il file | la sorgente | il **seme** della prima installazione |
 * | Il seeder al deploy | riscriveva tutto | ⛔ **non tocca** ciò che è stato cambiato a mano |
 *
 * 🚨 `modificato_a_mano` è la colonna che regge tutto il ragionamento: senza,
 * il primo deploy dopo un cambio di prezzo **riporterebbe indietro il prezzo,
 * in silenzio** — e lo si scoprirebbe da un incasso sbagliato. È la stessa
 * difesa di `plans.cucito_a_mano`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pacchetti_gettoni', function (Blueprint $tabella): void {
            $tabella->id();

            /**
             * 🚨 Il codice, stabile: è lui che lega la riga al seme del file e
             * ai `metadata` di Stripe, non il nome — che il marketing cambia.
             */
            $tabella->string('codice', 40)->unique();

            $tabella->string('nome', 60);

            /** Quanti gettoni accredita. ⚠️ Non cambia dopo la vendita. */
            $tabella->unsignedInteger('gettoni');

            $tabella->unsignedInteger('prezzo_cent');

            $tabella->string('nota', 120)->nullable();

            /**
             * ⛔ **Spento ≠ cancellato.** Un taglio che non si vende più sparisce
             * dal listino, ma resta in tabella: `ai_credit_movements` racconta
             * acquisti fatti con quel codice, e cancellarlo renderebbe
             * illeggibile la storia di chi l'ha comprato.
             */
            $tabella->boolean('attivo')->default(true);

            /** L'ordine in cui si mostrano: dal più piccolo al più grande. */
            $tabella->unsignedSmallInteger('ordine')->default(0);

            /**
             * 🚨 **Chi comanda su questa riga.** `false` = la scrive il seeder
             * dal file; `true` = l'ha toccata una persona dal pannello, e il
             * seeder da quel momento **la lascia stare**.
             */
            $tabella->boolean('modificato_a_mano')->default(false);

            $tabella->timestamps();

            $tabella->index(['attivo', 'ordine']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pacchetti_gettoni');
    }
};
