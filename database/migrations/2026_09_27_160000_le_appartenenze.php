<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🏠 **Le appartenenze** — F2.1 di `memory/plan_spazi_personali.md`, 27/09/2026.
 *
 * ══ 🚨 IL DIFETTO CHE QUESTA TABELLA COMINCIA A CHIUDERE ══════════════════
 *
 * 📌 Il committente: *«io devo avere il mio account, posso iscrivermi a una (e
 * una sola) palestra e 15849325 trainers insieme»*.
 *
 * ⛔ Oggi `users.tenant_id` è **una colonna, non un elenco**: si sta in una
 * scatola sola, e «iscriversi» è un **trasloco** dei dati
 * (`UnisciAUnaPalestra`). 🚨 Da lì discendono tutti i guai: non si può stare
 * in una palestra **e** in N trainer, e se la scatola smette di pagare si
 * perde quello che paga un altro.
 *
 * 💡 Qui l'appartenenza diventa **una riga**, che si può avere tante volte.
 *
 * ══ ⚠️ QUESTA MIGRAZIONE NON CAMBIA NIENTE, DI PROPOSITO ══════════════════
 *
 * 🔑 È **additiva**: crea la tabella e la riempie dallo stato attuale.
 * ⛔ Nessun dato si sposta, nessun comportamento cambia, e il codice non la
 * legge ancora. 💡 Così la si può mettere in produzione senza rischio e
 * verificare che descriva il mondo com'è, **prima** che qualcuno ci si
 * appoggi.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appartenenze', function (Blueprint $t): void {
            $t->id();

            $t->foreignId('user_id')->constrained()->cascadeOnDelete();

            /*
             * ⚠️ **Nessun `constrained()` su `tenant_id`.** I tenant si
             * cancellano a soft-delete, e una foreign key vera impedirebbe di
             * conservare la storia di un'appartenenza a una palestra che non
             * c'è più — che è invece la cosa che serve per «se avevo un
             * abbonamento prima, deve ripartire quello».
             */
            $t->unsignedBigInteger('tenant_id')->index();

            // 'gym' | 'trainer' — chi sei per questa persona.
            $t->string('tipo', 16);

            $t->timestamp('iniziata_il')->useCurrent();

            /*
             * 🔑 `null` = **viva**. ⛔ Non si cancella una riga quando si esce:
             * la storia serve, e un `DELETE` la butterebbe via proprio nel
             * momento in cui diventa interessante.
             */
            $t->timestamp('finita_il')->nullable()->index();

            $t->timestamps();

            $t->index(['user_id', 'tipo', 'finita_il']);
            $t->unique(['user_id', 'tenant_id', 'tipo', 'finita_il'], 'appartenenza_unica');
        });

        /*
         * ══ ⚖️ «UNA PALESTRA SOLA», IMPOSTA DAL DATABASE ══════════════════
         *
         * 🚨 **MariaDB non ha gli indici parziali** (`UNIQUE ... WHERE`): sono
         * di PostgreSQL. ⛔ Quindi il vincolo non si può scrivere come si
         * scriverebbe altrove.
         *
         * 💡 Si usa una **colonna generata**: vale `user_id` solo quando
         * l'appartenenza è a una palestra **ed è viva**, e `NULL` in tutti gli
         * altri casi. ⚠️ In un indice UNIQUE i `NULL` si ripetono liberamente,
         * quindi restano permessi infiniti trainer e infinite palestre
         * **passate** — e al massimo **una** palestra attiva.
         *
         * 🔑 **Perché nel database e non in un `if`**: un `if` lo si dimentica
         * in uno dei percorsi (invito, registrazione dal sito, pannello god,
         * import), e il giorno che succede nessuno se ne accorge. Qui invece
         * la seconda palestra **non si scrive**, e il server dà errore.
         */
        DB::statement(
            'ALTER TABLE appartenenze
             ADD COLUMN palestra_attiva_per BIGINT UNSIGNED
             AS (CASE WHEN tipo = \'gym\' AND finita_il IS NULL THEN user_id END) VIRTUAL,
             ADD UNIQUE INDEX una_palestra_sola (palestra_attiva_per)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('appartenenze');
    }
};
