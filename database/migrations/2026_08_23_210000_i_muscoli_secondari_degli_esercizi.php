<?php

declare(strict_types=1);

use App\Support\Training\MuscoliDegliEsercizi;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 3b-A.3 — un esercizio allena piu' di un muscolo. 23/08/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«Tutti gli esercizi devono indicare il muscolo o il gruppo muscolare che
 * allenano (**anche piu' di uno**)»* · *«Mi devi sistemare tutti gli esercizi in
 * modo che abbiano effettivamente questo campo pieno»*.
 *
 * ── 🚨 Il campo non era vuoto: era povero ────────────────────────────────
 *
 * `muscle_group` c'era gia', valorizzato su **tutti** e 121 gli esercizi di
 * allora. ⚠️ Ma
 * e' **singolo**: una panca piana dice `chest` e tace su tricipiti e deltoidi
 * anteriori. 💡 Finche' serviva a filtrare un elenco andava bene; su un uomo
 * colorato per zone quella lacuna **si vede**.
 *
 * ── 💡 Perche' primario + secondari, e non un elenco piatto ──────────────
 *
 * ⛔ Un elenco senza gerarchia direbbe che in una panca il petto e i tricipiti
 * valgono uguale, e la mappa del corpo li colorerebbe allo stesso modo. 🚨 Per
 * colorare serve un **peso**, e il peso piu' onesto che abbiamo e' la
 * distinzione fra chi fa il lavoro e chi aiuta.
 *
 * 💡 **`muscle_group` resta il primario**, non una copia derivata: e' gia'
 * indicizzato, gia' usato dai filtri e dal pannello, e gia' pieno. Due colonne
 * per lo stesso fatto sarebbero due fonti che divergono — qui invece la nuova
 * colonna contiene **solo** quello che la vecchia non sapeva dire.
 *
 * ── ⚠️ `cardio` e `full_body` non sono muscoli ───────────────────────────
 *
 * 🚨 Restano primari validi — descrivono l'esercizio — ma **una zona del corpo
 * non si puo' colorare con «cardio»**. 💡 Per questo anche loro hanno dei
 * secondari veri: una corsa dice `cardio` come natura e `quads, hamstrings,
 * calves, glutes` come muscoli. Senza, chi corre e basta avrebbe una figura
 * completamente grigia — che sarebbe falsa.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exercises', function (Blueprint $table): void {
            /*
             * ⚠️ JSON e non una tabella di appoggio: sono al massimo quattro
             * valori di un'enum chiusa, letti sempre **insieme all'esercizio** e
             * mai interrogati da soli. Una pivot qui costerebbe una join a ogni
             * lettura per una cosa che non si cerca mai al contrario.
             */
            $table->json('secondary_muscles')->nullable()->after('muscle_group');
        });

        /*
         * 🚨 Si aggiorna **per nome**, non per id: lo stesso esercizio del
         * catalogo esiste in copia dentro le palestre che l'hanno
         * personalizzato, e devono ricevere lo stesso dato.
         *
         * ⛔ `withoutGlobalScopes` non serve — qui si scrive con il query
         * builder, che i global scope non li ha. E' il motivo per cui una
         * migrazione non deve mai usare i modelli.
         */
        /*
         * 🚨 **La mappa sta in `MuscoliDegliEsercizi`, non qui.**
         *
         * ⚠️ Una migrazione di norma congela i suoi dati, per poter girare
         * identica fra un anno. Questa no, ed e' una scelta consapevole:
         * duplicare centoventuno righe vorrebbe dire due verita' che divergono
         * al primo esercizio corretto — e il seeder ha bisogno della stessa
         * mappa per gli ambienti nuovi.
         *
         * ⛔ Se un giorno quella classe sparisse, questo blocco va **svuotato**,
         * non lasciato a puntare nel vuoto: sulle righe gia' sistemate non
         * serve piu' a niente.
         */
        foreach (MuscoliDegliEsercizi::tutti() as $nome => $muscoli) {
            DB::table('exercises')
                ->where('name', $nome)
                ->update(['secondary_muscles' => json_encode($muscoli[1])]);
        }
    }

    public function down(): void
    {
        Schema::table('exercises', function (Blueprint $table): void {
            $table->dropColumn('secondary_muscles');
        });
    }
};
