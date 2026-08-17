<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il catalogo degli alimenti — 17/08/2026.
 *
 * ── 🚨 Perche' NON ha `tenant_id` e non ha `user_id` ───────────────────────
 *
 * Perche' **un alimento non e' di nessuno**. «Petto di pollo, 165 kcal per 100
 * g» non e' un dato personale: e' un fatto, e vale uguale per tutte le
 * palestre.
 *
 * ⚠️ Se ci fosse `user_id`, questa tabella diventerebbe un archivio di **chi
 * mangia cosa** — cioe' esattamente la categoria di dati che il progetto sta
 * togliendo dal server (Parte I). Il legame persona ↔ alimento esiste gia', e
 * sta dove deve stare: in `food_entries`, che un giorno si sposta sul telefono
 * e si porta via anche quello.
 *
 * 💡 **Da qui discende l'ordinamento dei suggerimenti**: «i miei» e «i piu'
 * usati da me» si ricavano da `food_entries` con una `JOIN`, non da una colonna
 * qui dentro. Il conteggio globale (`usi`) invece e' un'aggregazione senza
 * nomi, e quello puo' stare qui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('foods', function (Blueprint $tabella): void {
            $tabella->id();

            /*
             * 🚨 **La chiave normalizzata, ed e' l'intera deduplicazione.**
             *
             * ⚠️ Confrontare i nomi cosi' come sono scritti non serve a niente:
             * `Pasta al pomodoro`, `pasta al pomodoro`, `Pasta  al  pomodoro` e
             * `Pasta al pomodoro ` sono quattro righe diverse per il database e
             * lo stesso alimento per una persona.
             *
             * 💡 La marca ci finisce dentro: la whey alla fragola di un
             * produttore non e' quella di un altro, e fonderle darebbe a
             * entrambe i valori della prima arrivata.
             */
            $tabella->string('chiave', 200)->unique();

            $tabella->string('nome', 120);
            $tabella->string('marca', 60)->nullable();

            /*
             * 🚨 **Sempre per 100**, mai per porzione.
             *
             * ⚠️ Senza questa regola lo stesso nome avrebbe due verita': chi
             * registra «Pasta 300 g / 450 kcal» e chi registra «Pasta 80 g /
             * 120 kcal» scriverebbero due volte «Pasta» con numeri diversi, ed
             * entrambi avrebbero ragione.
             */
            $tabella->decimal('kcal_100', 7, 2);
            $tabella->decimal('protein_100', 6, 2);
            $tabella->decimal('carbs_100', 6, 2);
            $tabella->decimal('fat_100', 6, 2);

            /** `g` per i solidi, `ml` per i liquidi: 100 ml di olio non sono 100 g. */
            $tabella->string('basis', 2)->default('g');

            /*
             * Da dove viene il valore.
             *
             * 🚨 Serve alla regola dei conflitti: **il manuale batte l'AI**, una
             * volta sola. Una stima del modello e' un'ipotesi; un numero
             * digitato e' qualcuno che ha letto l'etichetta.
             */
            $tabella->string('origine', 8)->default('manuale');

            /** La fonte, quando l'alimento arriva da una banca dati esterna. */
            $tabella->string('fonte', 40)->nullable();

            /*
             * Quante **persone diverse** l'hanno inserito.
             *
             * ⚠️ Persone, non volte: chi mangia lo yogurt ogni mattina conta
             * uno. E' la soglia che decide la pubblicazione, e contare le volte
             * la renderebbe un modo per pubblicarsi da soli quello che si vuole.
             */
            $tabella->unsignedInteger('conferme')->default(1);

            /*
             * Quante volte e' stato scelto **in totale**, da chiunque.
             *
             * 💡 E' il terzo livello dell'ordinamento dei suggerimenti. E' un
             * numero aggregato senza nomi: non dice chi, dice quanto.
             */
            $tabella->unsignedInteger('usi')->default(0);

            /*
             * 🚨 **Privato finche' non lo inserisce una seconda persona.**
             *
             * ── Perche' una soglia, e perche' proprio le persone ───────────
             *
             * Il nome di un alimento e' testo libero: qualcuno scrivera'
             * «cena da mia sorella dopo l'operazione». ⚠️ In un catalogo
             * pubblico immediato, quella riga finirebbe nel suggerimento di
             * sconosciuti — ed e' il tipo di fuga che non si ritira piu'.
             *
             * 💡 Con la soglia, quello che una persona sola ha scritto resta
             * suo. «Petto di pollo» diventa pubblico in un giorno perche' lo
             * scrivono in tanti; un'etichetta privata non lo diventa mai.
             * La stessa regola fa da filtro di qualita' senza costare niente.
             */
            $tabella->boolean('pubblico')->default(false);

            $tabella->timestamps();

            /*
             * ⚠️ L'indice per la ricerca: `pubblico` per primo perche' e' il
             * filtro piu' selettivo, poi `chiave` per il confronto a prefisso.
             *
             * 🚨 Su MySQL `LIKE 'x%'` usa questo indice, `LIKE '%x%'` **no**:
             * la seconda forma scansiona la tabella a ogni tasto premuto.
             */
            $tabella->index(['pubblico', 'chiave']);
            $tabella->index('usi');
        });

        Schema::table('food_entries', function (Blueprint $tabella): void {
            /*
             * Il legame fra una voce di diario e l'alimento del catalogo.
             *
             * 🚨 `nullOnDelete` e non `cascade`: cancellare un alimento dal
             * catalogo **non deve cancellare quello che una persona ha
             * mangiato**. Il pasto resta, perde solo il collegamento.
             *
             * 💡 `nullable` perche' la stragrande maggioranza delle voci
             * esistenti non ne ha uno, e perche' una voce puo' benissimo non
             * corrispondere a nessun alimento del catalogo (un pasto composto,
             * una descrizione vaga).
             */
            $tabella->foreignId('food_id')->nullable()->after('nutrition_plan_id')
                ->constrained('foods')->nullOnDelete();

            /*
             * ⚠️ L'indice composto, e l'ordine conta: serve a due domande
             * diverse e le copre entrambe.
             *
             *   «questo utente ha gia' usato questo alimento?» → conferme
             *   «quali alimenti usa di piu' questo utente?»    → ordinamento
             */
            $tabella->index(['user_id', 'food_id']);
        });
    }

    public function down(): void
    {
        Schema::table('food_entries', function (Blueprint $tabella): void {
            $tabella->dropForeign(['food_id']);
            $tabella->dropIndex(['user_id', 'food_id']);
            $tabella->dropColumn('food_id');
        });

        Schema::dropIfExists('foods');
    }
};
