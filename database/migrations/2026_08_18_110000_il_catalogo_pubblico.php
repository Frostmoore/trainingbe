<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Il catalogo pubblico di palestre e trainer — 18/08/2026. **M2.1.**
 *
 * ── 🚨 Perche' NON ha `BelongsToTenant` ────────────────────────────────────
 *
 * Perche' esiste **apposta** per essere visto da fuori. Il `TenantScope` filtra
 * per la palestra di chi guarda: applicato qui, ogni palestra vedrebbe soltanto
 * la propria scheda, cioe' il catalogo non mostrerebbe niente a nessuno.
 *
 * ⚠️ E' l'unica tabella del progetto per cui questo e' vero, quindi va detto: la
 * regola «tutto ha `tenant_id` e un global scope» qui e' **rotta di proposito**,
 * e l'isolamento lo fa `visibile` invece dello scope.
 *
 * ── 🚨 `tenant_id` XOR `user_id` ───────────────────────────────────────────
 *
 * Una scheda e' **o** di una palestra **o** di un trainer indipendente, mai
 * entrambi e mai nessuno dei due. ⚠️ Imposto con un `CHECK`, non con la buona
 * volonta' del codice: una scheda con tutti e due i campi pieni non e'
 * sbagliata in modo visibile — funziona, e comincia a mostrare la palestra
 * quando qualcuno cerca il trainer.
 *
 * 💡 **Nessun `referente_id`**: per una palestra il destinatario e' il
 * **proprietario** (`UserRole::GymAdmin` del tenant), che si ricava. Una colonna
 * in meno e' una colonna che non puo' diventare incoerente — e sceglierlo a mano
 * avrebbe voluto dire che un dipendente che cambia lavoro si porta via la
 * casella della palestra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profili_pubblici', function (Blueprint $tabella): void {
            $tabella->id();

            /*
             * La palestra, oppure il trainer indipendente. Uno dei due, mai due.
             *
             * 🚨 `cascadeOnDelete` su entrambi, e qui e' giusto: se la palestra
             * sparisce, la sua scheda nel catalogo **deve** sparire. ⚠️ Il caso
             * opposto — una scheda orfana che continua a comparire nelle ricerche
             * e a ricevere messaggi che nessuno leggera' mai — sarebbe peggio di
             * un errore: sarebbe un annuncio che risponde a vuoto.
             */
            $tabella->foreignId('tenant_id')->nullable()->constrained('tenants')->cascadeOnDelete();
            $tabella->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();

            /*
             * Dove sta. ⚠️ **Obbligatorio**: una scheda senza citta' non
             * comparirebbe in nessuna ricerca per vicinanza, cioe' sarebbe
             * invisibile pur essendo `visibile = true`. Meglio impedirla che
             * lasciare qualcuno a chiedersi perche' non lo trova nessuno.
             *
             * 🚨 `restrictOnDelete` e non `nullOnDelete`: la colonna e' `NOT
             * NULL`, e un `nullOnDelete` su una colonna obbligatoria fallisce al
             * momento della cancellazione con un errore che parla di vincoli e
             * non di comuni.
             */
            $tabella->foreignId('comune_id')->constrained('comuni')->restrictOnDelete();

            /** Come si presenta: «Palestra Olimpo — sala pesi e functional». */
            $tabella->string('titolo', 120);

            $tabella->text('descrizione')->nullable();

            /*
             * 🚨 **L'interruttore, e parte spento.**
             *
             * ⚠️ `default(false)` non e' timidezza: comparire in un catalogo
             * pubblico e' una decisione commerciale che deve prendere il
             * titolare, non un effetto collaterale di aver compilato un modulo.
             * Un default `true` vorrebbe dire pubblicare la scheda di qualcuno
             * che non ha ancora capito che esiste il catalogo.
             */
            $tabella->boolean('visibile')->default(false);

            /*
             * La campagna pubblicitaria, quando ce n'e' una attiva — M5.
             *
             * 💡 `nullable` e aggiunta piu' avanti (`campagne` non esiste
             * ancora): la colonna sta qui perche' il catalogo la legge fin da
             * subito per l'ordinamento, e aggiungerla dopo avrebbe voluto dire
             * una seconda migrazione per una colonna gia' decisa.
             */
            $tabella->unsignedBigInteger('campagna_id')->nullable();

            $tabella->timestamps();

            /*
             * ⚠️ Una sola scheda per palestra e una sola per trainer.
             *
             * 🚨 Senza, la stessa palestra potrebbe comparire tre volte nella
             * stessa ricerca — che oltre a essere brutto e' un modo per occupare
             * i risultati di tutti gli altri senza pagare la pubblicita'.
             *
             * 💡 In MySQL un indice unico **ignora le righe `NULL`**, quindi i
             * due indici convivono: le schede dei trainer hanno `tenant_id` nullo
             * e non si disturbano fra loro.
             */
            $tabella->unique('tenant_id');
            $tabella->unique('user_id');

            /*
             * L'indice della ricerca: `visibile` per primo perche' e' il filtro
             * che si applica sempre, poi il comune, che e' il criterio con cui si
             * restringe.
             */
            $tabella->index(['visibile', 'comune_id']);
        });

        /*
         * 🚨 Il vincolo XOR, in SQL e non in PHP.
         *
         * ⚠️ Un controllo scritto solo nel modello vale finche' tutti passano dal
         * modello. Non ci passano: le migrazioni di manutenzione, gli import, un
         * `DB::table()` scritto di fretta, e il pannello `/god` il giorno che
         * qualcuno aggiunge una scheda a mano. 💡 Il database e' l'unico posto
         * in cui una regola vale **per tutti**.
         *
         * ⚠️ `CHECK` funziona da MySQL 8.0.16 e da MariaDB 10.2. Su versioni
         * precedenti verrebbe accettato e ignorato in silenzio — che e' il modo
         * peggiore di fallire — quindi qui si scrive con `DB::statement` e chi
         * legge sa che dipende dalla versione.
         */
        DB::statement(
            'ALTER TABLE profili_pubblici ADD CONSTRAINT chk_profili_pubblici_xor '.
            'CHECK ((tenant_id IS NULL) <> (user_id IS NULL))',
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('profili_pubblici');
    }
};
