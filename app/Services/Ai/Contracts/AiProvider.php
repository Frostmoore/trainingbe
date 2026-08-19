<?php

declare(strict_types=1);

namespace App\Services\Ai\Contracts;

use App\Services\Ai\AiCallContext;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Data\ParsedWorkoutPlan;
use App\Services\Ai\Data\PianoTrascritto;
use App\Services\Ai\Data\WorkoutAiContext;

/**
 * Il contratto che ogni fornitore di AI deve rispettare.
 *
 * 🚨 **I metodi restituiscono DTO, non risposte del fornitore.** E' l'unica cosa
 * che rende sostituibile il fornitore davvero: se il resto dell'applicazione
 * leggesse `$message->content[0]->text`, cambiare provider vorrebbe dire
 * riscrivere ogni chiamante. Con i DTO, cambiare provider e' una riga di `.env`
 * — che e' esattamente cio' che ADR-05 promette.
 *
 * **Ogni implementazione ha tre obblighi non negoziabili:**
 *  1. non lasciar uscire eccezioni grezze dell'SDK — vanno tradotte nelle
 *     eccezioni di `App\Services\Ai\Exceptions`, altrimenti l'API pubblica
 *     restituirebbe messaggi di un fornitore ai client;
 *  2. registrare **sempre** il consumo, anche quando la chiamata fallisce: una
 *     richiesta rifiutata dopo aver consumato token di input e' comunque
 *     costata;
 *  3. non mettere mai dati variabili (data, nome utente, nome palestra) nel
 *     prompt di sistema, che e' cachato.
 */
interface AiProvider
{
    /** Il nome con cui compare nei log di consumo. */
    public function name(): string;

    public function foodFromText(string $text, AiCallContext $ctx): FoodEstimate;

    /**
     * La stima da una foto.
     *
     * @param  string  $extra  Testo da aggiungere alla richiesta, dopo l'immagine.
     *                         🚨 Serve al **retry**: quando il validatore trova un
     *                         errore grave, l'elenco delle violazioni entra qui —
     *                         cioe' nel messaggio utente, **mai** nel prompt di
     *                         sistema, che e' il prefisso cachato e condiviso da
     *                         tutti. Infilarcelo lo invaliderebbe per tutti senza
     *                         dare nessun errore: si vedrebbe solo in fattura.
     */
    public function foodFromImage(string $absolutePath, string $mimeType, AiCallContext $ctx, string $extra = ''): FoodEstimate;

    public function workoutCalories(WorkoutAiContext $context, AiCallContext $ctx): int;

    /**
     * Il consiglio del giorno.
     *
     * @param  array<string, mixed>  $context  diario, allenamenti e target del giorno
     */
    public function dailyAdvice(array $context, AiCallContext $ctx): string;

    public function parseWorkoutPdf(string $absolutePath, AiCallContext $ctx, ?string $forceModel = null): ParsedWorkoutPlan;

    /**
     * Trascrive un piano alimentare da PDF — N20.2.
     *
     * 🚨 **Torna una trascrizione, non un piano.** Il piano lo ha fatto un
     * professionista abilitato ed e' nel PDF; questo e' il tentativo di
     * ricopiarlo in una struttura, e finche' qualcuno non lo ha guardato riga
     * per riga non vale niente.
     */
    public function trascriviPianoAlimentare(
        string $absolutePath,
        AiCallContext $ctx,
        ?string $forceModel = null,
    ): PianoTrascritto;
}
