<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AiFeature;
use App\Models\StimaCibo;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use App\Services\Ai\CancelloDeiGettoni;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\StimaConRitentativo;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Stima un pasto fuori dalla richiesta web — FASE 9.3.
 *
 * ══ 🚨 COSA CAMBIA, E COSA NON CAMBIA ═════════════════════════════════════
 *
 * **Non cambia il codice della stima**: prompt, schema, validatore e
 * ritentativo sono gli stessi di prima, e stanno in `StimaConRitentativo` —
 * riscriverli mentre li si sposta vorrebbe dire non sapere piu', quando
 * qualcosa si rompera', se e' colpa della coda o della riscrittura.
 *
 * **Cambia chi li esegue.** Prima era il processo PHP che aveva ricevuto la
 * richiesta, e restava fermo ad aspettare il modello per 2–8 secondi: con sei
 * processi in tutto, sette persone che scrivono il pranzo insieme fermavano
 * **il sito**, non l'AI. Adesso e' un worker, che non toglie posto a nessuno.
 *
 * ── 🚨 Gira in coda, quindi FUORI da qualunque contesto di palestra ────────
 *
 * Un job non ha una richiesta HTTP alle spalle: `ResolveTenant` non e' passato e
 * `TenantContext` e' vuoto. Ogni accesso ai dati va dentro `runAs()`, altrimenti
 * il global scope non filtra — e in un job che scrive, «non filtra» significa
 * scrivere nella palestra sbagliata.
 *
 * ── ⚠️ Tre tentativi, ma NON per il motivo che sembra ─────────────────────
 *
 * `TrascriviPianoAlimentare` ne fa **uno solo**, e ha ragione: costa 50 gettoni,
 * e un ritentativo automatico e' una seconda fattura. 💡 Qui invece una stima
 * costa **una** chiamata da pochi millesimi, e la causa piu' probabile di un
 * fallimento e' passeggera — un `429` del fornitore, una connessione caduta.
 *
 * 🚨 E il `backoff` cresce apposta: ritentare subito contro un fornitore che ha
 * appena detto «troppe richieste» e' il modo di farsi dire di no una seconda
 * volta.
 *
 * ── 🚨 `ShouldBeUnique`: la stessa foto due volte non si paga due volte ────
 *
 * L'app riprova quando la risposta si perde. ⚠️ Senza questo, un rinvio
 * diventerebbe **due chiamate al modello** — che e' lo stesso difetto della
 * FASE 2-septies visto da un'altra parte, e stavolta si previene invece di
 * rimediarlo.
 */
class StimaIlCibo implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /**
     * ⚠️ Piu' lungo della chiamata piu' lenta mai vista (8,8 s) con un margine
     * largo: un `timeout` stretto ucciderebbe stime che stavano per riuscire, e
     * il fornitore le avrebbe comunque fatte pagare.
     */
    public int $timeout = 120;

    /** @return list<int> */
    public function backoff(): array
    {
        return [5, 20];
    }

    /**
     * 💡 L'unicita' e' sulla **riga**, non sul contenuto: due piatti identici
     * scritti davvero due volte sono due stime, ed e' giusto che lo siano. Qui
     * si difende dal rinvio della stessa richiesta.
     */
    public function uniqueId(): string
    {
        return 'stima-cibo-'.$this->stimaId;
    }

    public function __construct(
        public readonly int $stimaId,
    ) {}

    public function handle(
        AiManager $ai,
        TenantContext $palestre,
        CancelloDeiGettoni $cancello,
        StimaConRitentativo $stimatore,
    ): void {
        $stima = StimaCibo::withoutGlobalScopes()->find($this->stimaId);

        if ($stima === null) {
            return;
        }

        $utente = $stima->user;

        if ($utente === null) {
            $stima->fallisce('senza_proprietario');

            return;
        }

        $palestra = $utente->tenant;

        if ($palestra === null) {
            $stima->fallisce('senza_palestra');

            return;
        }

        $palestre->runAs($palestra, function () use ($stima, $utente, $ai, $cancello, $stimatore): void {
            $funzione = $stima->origine === StimaCibo::FOTO
                ? AiFeature::FoodPhoto
                : AiFeature::FoodText;

            $chiamata = $this->chiamata($stima, $utente, $ai, $funzione);

            if ($chiamata === null) {
                // ⚠️ La foto non c'e' piu': puo' succedere se il disco e' stato
                // ripulito mentre la stima era in coda. Si dichiara, non si
                // finge un errore del modello.
                $stima->fallisce('foto_non_leggibile');

                return;
            }

            $stima->inLavorazione();

            try {
                [$risultato, $avvisi] = $stimatore->esegui($chiamata);
            } catch (Throwable $e) {
                /*
                 * 🚨 **Fallita = non pagata.** I gettoni si scalano solo sotto,
                 * dopo che la stima esiste davvero.
                 *
                 * ⚠️ E si **rilancia**: senza, la coda crederebbe che sia andata
                 * bene e i tre tentativi non servirebbero a niente. Lo stato
                 * `fallita` scritto qui vale finche' un tentativo successivo non
                 * lo sovrascrive con `pronta`.
                 */
                $stima->fallisce('modello_non_risponde');

                throw $e;
            }

            /*
             * ⚠️ **La decisione arriva dalla colonna, non si ricalcola.** Il
             * cancello si e' aperto all'accodamento, minuti fa; fra allora e
             * adesso la quota inclusa potrebbe essersi esaurita per altre
             * chiamate. Ricontrollarla qui farebbe pagare con i gettoni una
             * stima che era coperta — stessa ragione gia' scritta in
             * `TrascriviPianoAlimentare`.
             */
            $cancello->consuma($utente, $funzione, $stima->paga_con_gettoni);

            /*
             * 🚨 La forma e' **identica** a quella che l'endpoint sincrono
             * restituiva con `save: false`: l'app la sa gia' leggere, e non c'e'
             * nessuna ragione perche' impari una seconda forma della stessa cosa.
             */
            $stima->completa([
                'estimate' => $risultato->toArray(),
                'warnings' => $avvisi,
                'entries' => [],
                'saved' => false,
            ]);
        });
    }

    /**
     * La chiusura che parla col modello, o `null` se non si puo'.
     *
     * @return null|callable(string): FoodEstimate
     */
    private function chiamata(
        StimaCibo $stima,
        object $utente,
        AiManager $ai,
        AiFeature $funzione,
    ): ?callable {
        $contesto = AiCallContext::for($utente, $funzione);

        if ($stima->origine === StimaCibo::FOTO) {
            $percorso = $stima->percorsoFoto();

            if ($percorso === null || ! is_readable($percorso)) {
                return null;
            }

            $mime = (string) ($stima->richiesta['mime'] ?? 'image/jpeg');

            return fn (string $appendice): FoodEstimate => $ai->for($funzione)->foodFromImage(
                $percorso,
                $mime,
                $contesto,
                $appendice,
            );
        }

        $testo = (string) ($stima->richiesta['text'] ?? '');

        return fn (string $appendice): FoodEstimate => $ai->for($funzione)->foodFromText(
            $testo.$appendice,
            $contesto,
        );
    }

    /**
     * Se il job muore prima di poter scrivere lo stato.
     *
     * 💡 Senza questo, una stima morta resterebbe `in_lavorazione` per sempre e
     * l'app mostrerebbe una rotellina che non gira piu'. 🚨 E' lo stesso motivo
     * per cui esiste in `TrascriviPianoAlimentare`: la lezione era gia' pagata.
     */
    public function failed(?Throwable $e): void
    {
        $stima = StimaCibo::withoutGlobalScopes()->find($this->stimaId);

        $stima?->fallisce('non_riuscita');
    }
}
