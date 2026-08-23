<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * FASE 11.6.3 — le tabelle degli allenamenti cadono. 23/08/2026.
 *
 * ══ 🚨 E' IRREVERSIBILE, ED E' IL PUNTO ═══════════════════════════════════
 *
 * 📌 Autorizzato dal committente: *«sono tutti allenamenti mock fatti da un
 * seeder, l'unico altro utente e' un mio amico che ha l'app di tipo 10 versioni
 * fa, non fa niente rasa tutto»*.
 *
 * ⚠️ Prima di questa migrazione, su staging, e' stata presa una **copia SQL**
 * delle tre tabelle (`memory/scripts/salva-e-lascia-cadere.sh`): ventuno righe
 * costano nulla da conservare, e sono l'unica cosa che le riporterebbe
 * indietro.
 *
 * ── 💡 Perche' una migrazione, dopo aver detto che doveva essere un comando ─
 *
 * `LasciaCadereGliAllenamenti` esisteva perche' **si rifiutava di partire**
 * finche' qualcuno non aveva traslocato, e una migrazione che si rifiuta
 * dentro un deploy con `set -e` lo spezza a meta' — rompendo il server per
 * proteggere qualcuno, che e' il modo peggiore di rompere qualcosa.
 *
 * 🚨 **Quella protezione e' stata spesa**: la decisione e' presa, non c'e' piu'
 * niente da rifiutare. E una migrazione fa una cosa che il comando non poteva:
 * vale **ovunque** — in locale, in CI, su un ambiente nuovo — invece che solo
 * dove qualcuno si ricorda di lanciarla.
 *
 * ⛔ Il comando e' stato cancellato: due strumenti per la stessa cosa, di cui
 * uno da lanciare a mano, sono un modo di avere ambienti diversi fra loro.
 *
 * ── ⚠️ Cosa succede a chi non aveva traslocato ─────────────────────────────
 *
 * Tredici utenti su quattordici non avevano confermato il trasloco, e i loro
 * allenamenti erano l'unica copia. 🚨 **Spariscono.** Era una decisione, non un
 * incidente: erano dati di prova, e le rotte del trasloco sono cadute insieme
 * alle tabelle perche' non avrebbero avuto piu' niente da consegnare.
 */
return new class extends Migration
{
    /** 🚨 L'ordine conta: `session_sets` ha la chiave esterna verso le sessioni. */
    private const TABELLE = ['session_sets', 'workout_sessions', 'daily_burns'];

    public function up(): void
    {
        foreach (self::TABELLE as $tabella) {
            Schema::dropIfExists($tabella);
        }
    }

    /**
     * ⛔ **Non si torna indietro, e non si finge di poterlo fare.**
     *
     * Ricreare tre tabelle vuote darebbe uno schema che sembra a posto e dati
     * che non ci sono piu': peggio di un `down()` che dichiara di non saperlo
     * fare, perche' chi lo esegue crede di aver rimediato.
     *
     * 💡 Se davvero servisse tornare indietro, la strada e' la copia SQL presa
     * prima della caduta — non questo metodo.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'FASE 11.6.3 non si annulla: le tabelle degli allenamenti sono cadute '
            .'e i dati stanno sul telefono. Per recuperare la copia di staging '
            .'vedi memory/scripts/salva-e-lascia-cadere.sh.'
        );
    }
};
