<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\ImportazioneDaDocumento;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use App\Services\Ai\CancelloDeiGettoni;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Ricopia un documento — piano o scheda — in una bozza — N20.2, poi K2.
 *
 * ⚠️ **Si chiamava `TrascriviPianoAlimentare`.** Da K2 trascrive anche le
 * schede: il meccanismo e' lo stesso — carica, chiama, salva la bozza, scala i
 * gettoni — e cambia solo **cosa si legge**. 🚨 Un gemello sarebbe stato due
 * implementazioni della stessa cosa, e quella che diverge per prima e' sempre
 * la copia meno provata.
 *
 * ── 🚨 Gira in coda, quindi FUORI da qualunque contesto di palestra ────────
 *
 * Un job non ha una richiesta HTTP alle spalle: `ResolveTenant` non e' passato e
 * `TenantContext` e' vuoto. Ogni accesso ai dati va quindi dentro `runAs()`,
 * altrimenti il global scope non filtra — e in un job che scrive, «non filtra»
 * significa scrivere nella palestra sbagliata.
 *
 * ── 🚨 Nessuna escalation, al contrario dell'import delle schede ───────────
 *
 * `ParseWorkoutPdf` ritenta sul modello superiore quando la confidenza e'
 * bassa, e ha ragione: una scheda mediocre da correggere e' comunque un punto
 * di partenza, e costa 1 gettone.
 *
 * ⚠️ Qui **no**, per due motivi che vanno insieme:
 *
 *   1. **Costa 50 gettoni.** Raddoppiarli in silenzio perche' il modello ha
 *      detto «0.6» e' una decisione commerciale che nessuno ha preso.
 *   2. **La confidenza bassa non e' un guasto da nascondere, e' l'informazione
 *      piu' utile che abbiamo.** Il rischio di questo import non e' fallire —
 *      un fallimento si vede e si rifa'. E' riuscire **a meta'**: «200 g» letti
 *      «20 g» non danno nessun errore, danno un piano plausibile e sbagliato.
 *      Una confidenza bassa dice a chi rivede *dove* guardare; ritentare finche'
 *      il numero non sale la cancella.
 *
 * 💡 Il posto dove si recupera un import mediocre non e' qui: e' la revisione
 * riga per riga con l'originale accanto (N20.3), che e' obbligatoria comunque.
 */
class TrascriviIlDocumento implements ShouldQueue
{
    use Queueable;

    /**
     * Un solo tentativo.
     *
     * 🚨 **A 50 gettoni, un retry automatico e' una seconda fattura.** La coda
     * ripeterebbe la chiamata identica: stesso PDF, stesso prompt, stesso
     * modello. Se il fornitore l'ha rifiutata la rifiutera' di nuovo, e se e'
     * riuscita a meta' la rifara' uguale — pagandola due volte.
     */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public readonly int $importazioneId,
    ) {}

    public function handle(AiManager $ai, TenantContext $palestre, CancelloDeiGettoni $cancello): void
    {
        $importazione = ImportazioneDaDocumento::withoutGlobalScopes()->find($this->importazioneId);

        if ($importazione === null) {
            return;
        }

        $utente = $importazione->user;

        if ($utente === null) {
            $importazione->fallisce('Importazione senza proprietario.');

            return;
        }

        $palestra = $utente->tenant;

        if ($palestra === null) {
            $importazione->fallisce('Importazione senza palestra.');

            return;
        }

        $palestre->runAs($palestra, function () use ($importazione, $utente, $ai, $cancello): void {
            $percorsi = $importazione->percorsiAssoluti();

            /*
             * 🚨 **Basta che ne manchi UNO** — K1, 03/09/2026.
             *
             * ⛔ Non `$percorsi === []`: trascrivere due pagine su tre non da'
             * nessun errore, da' una scheda che comincia dal secondo giorno — e
             * sembra completa. 💡 O ci sono tutti, o non si parte.
             */
            if ($percorsi === [] || $importazione->documentiMancanti() > 0) {
                $importazione->fallisce('I documenti non sono piu\' leggibili.');

                return;
            }

            $importazione->inLavorazione();

            /*
             * 🚨 **La funzione la dice la riga, e in un posto solo.**
             *
             * ⛔ I punti che devono saperlo sono tre — il cancello dei gettoni
             * quando si carica, il contesto della chiamata, e lo scalo dopo la
             * trascrizione. 💡 In tre posti diversi, uno prima o poi guarda
             * l'altra: `ImportazioneDaDocumento::funzione()` e' l'unico.
             */
            $funzione = $importazione->funzione();
            $modello = $ai->modelFor($funzione);
            $ctx = AiCallContext::for($utente, $funzione);

            try {
                $bozza = $importazione->eUnaScheda()
                    ? $ai->for($funzione)->parseWorkoutPdf($percorsi, $ctx)
                    : $ai->for($funzione)->trascriviPianoAlimentare($percorsi, $ctx);
            } catch (Throwable $e) {
                /*
                 * 🚨 **Fallita = non pagata.** I gettoni si scalano solo sotto,
                 * dopo che la trascrizione esiste davvero: far pagare 50 gettoni
                 * per un errore del fornitore vuol dire far pagare al cliente un
                 * guasto nostro.
                 */
                $importazione->fallisce($e->getMessage());

                return;
            }

            $importazione->salvaBozza($bozza, $modello);

            /*
             * ⚠️ **La decisione arriva dalla colonna, non si ricalcola.** Il
             * cancello si e' aperto quando il PDF e' stato caricato, minuti fa;
             * fra allora e adesso la quota inclusa potrebbe essersi esaurita per
             * altre chiamate. Ricontrollarla qui farebbe pagare 50 gettoni una
             * chiamata che era coperta — vedi `CancelloDeiGettoni::apri()`.
             */
            $cancello->consuma($utente, $funzione, $importazione->paga_con_gettoni);
        });
    }

    /**
     * Se il job muore prima di poter scrivere lo stato.
     *
     * 💡 Senza questo, un'importazione morta resterebbe `in_lavorazione` per
     * sempre, e l'app mostrerebbe una rotellina che non gira piu'.
     */
    public function failed(?Throwable $e): void
    {
        $importazione = ImportazioneDaDocumento::withoutGlobalScopes()->find($this->importazioneId);

        $importazione?->fallisce($e?->getMessage() ?? 'Importazione interrotta.');
    }
}
