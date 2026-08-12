<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Gli obiettivi del profilo passano da tre a cinque — 12/08/2026.
 *
 * ── 🚨 Perche' i dati vanno migrati e non solo tradotti a runtime ─────────
 *
 * `Profile::goalForFormula()` sa gia' leggere i valori vecchi, quindi il calcolo
 * non si romperebbe. Ma il valore salvato comparirebbe **in una tendina che non
 * lo contiene**: il form di Filament e quello dell'app mostrano `Profile::GOALS`,
 * e un `lose_weight` che non c'e' piu' in quell'elenco si presenta come «nessuna
 * scelta». La persona aprirebbe il profilo, vedrebbe il campo vuoto, e ne
 * concluderebbe che l'app ha perso i suoi dati.
 *
 * ── ⚠️ Perche' «dimagrire» diventa GRADUALE e non rapido ──────────────────
 *
 * Il vecchio `lose_weight` valeva **−15%**, esattamente a meta' fra i due nuovi
 * gradini: nessuno dei due conserva il numero. Fra i due si sceglie quello con
 * il **deficit piu' piccolo**, per la stessa ragione per cui in dubbio il sesso
 * vale «femminile»: un deficit troppo piccolo fa dimagrire piu' lentamente, uno
 * troppo grande e' una scelta sulla salute di qualcuno presa **al posto suo**.
 *
 * 💡 Chi vuole il gradino piu' aggressivo lo trova nella tendina, e adesso e'
 * una cosa che ha scelto invece di una che gli e' capitata.
 *
 * 🚨 **`down()` riporta indietro i valori**, perche' un rollback che lascia in
 * tabella parole che il codice precedente non conosce e' peggio del problema che
 * il rollback doveva risolvere.
 */
return new class extends Migration
{
    /**
     * Il vecchio vocabolario del profilo, e dove finisce.
     *
     * ⚠️ `lose`, `cut` e `bulk` non erano valori del profilo — erano quelli
     * *interni* al calcolatore — ma si migrano lo stesso: se una riga li
     * contenesse per un errore passato, lasciarli la' vorrebbe dire lasciare una
     * mina.
     */
    private const MAPPA = [
        'lose_weight' => 'lose_slow',
        'gain_muscle' => 'gain_lean',
        'lose' => 'lose_slow',
        'cut' => 'lose_fast',
        'bulk' => 'gain_lean',
    ];

    public function up(): void
    {
        foreach (self::MAPPA as $vecchio => $nuovo) {
            DB::table('profiles')->where('goal', $vecchio)->update(['goal' => $nuovo]);
        }
    }

    public function down(): void
    {
        // I cinque gradini tornano nei tre di prima. ⚠️ L'informazione «rapido o
        // graduale» si perde: e' irreversibile per costruzione, non per svista.
        $indietro = [
            'lose_fast' => 'lose_weight',
            'lose_slow' => 'lose_weight',
            'gain_lean' => 'gain_muscle',
            'gain_fast' => 'gain_muscle',
        ];

        foreach ($indietro as $nuovo => $vecchio) {
            DB::table('profiles')->where('goal', $nuovo)->update(['goal' => $vecchio]);
        }
    }
};
