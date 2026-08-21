<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Guardie\MealValidator;

/**
 * Chiama il modello, controlla, e **una volta sola** gli chiede di rifare.
 *
 * ── 🚨 Perche' e' nata: due chiamanti, una regola sola — FASE 9 ────────────
 *
 * Questa logica viveva come metodo privato di `AiController`. ⚠️ Con la FASE 9
 * la stima del cibo se ne va in coda (`StimaIlCibo`) mentre quella del piano
 * (`AiController::planFood()`) resta sincrona: due posti diversi, **la stessa
 * regola**. Lasciandola privata sarebbe stata copiata, e una copia diverge —
 * qui diverge in silenzio, perche' un ritentativo in meno non da' nessun errore:
 * da' solo qualche stima peggiore ogni tanto.
 *
 * 💡 E' lo stesso movimento gia' fatto per `CancelloDeiGettoni`: la regola
 * lascia il controller e i vecchi metodi restano come deleghe.
 *
 * ── 🚨 Perche' un solo tentativo ──────────────────────────────────────────
 *
 * Un errore grave e' quasi sempre di formato — un'unita' vietata, un enum
 * sconosciuto — e il modello lo corregge alla prima richiesta. Se non lo
 * corregge alla seconda non lo correggera' alla terza: quello che cambierebbe,
 * ripetendo, e' solo il conto di fine mese.
 *
 * ── ⚠️ Il ritentativo riscrive il MESSAGGIO UTENTE, mai il prompt di sistema ─
 *
 * Il prompt di sistema e' il prefisso identico di ogni chiamata di ogni utente,
 * ed e' cio' che lo rende cachabile a un decimo del costo. 🚨 Infilarci l'elenco
 * degli errori di *questa* richiesta lo invaliderebbe per tutti **senza dare
 * nessun errore**: si vedrebbe solo in fattura.
 */
class StimaConRitentativo
{
    public function __construct(
        private readonly MealValidator $validatore,
    ) {}

    /**
     * ⚠️ **Se anche il secondo tentativo fallisce si restituisce comunque la
     * stima**, con gli errori negli avvisi. E' l'app che decide: il foglio di
     * conferma li mostra, e la persona corregge o annulla. Rifiutare tutto
     * vorrebbe dire buttare una chiamata gia' pagata e lasciare chi scrive
     * senza niente.
     *
     * @param  callable(string): FoodEstimate  $chiamata  riceve l'appendice da
     *                                                    accodare al messaggio
     * @return array{0: FoodEstimate, 1: list<string>}
     */
    public function esegui(callable $chiamata): array
    {
        $esito = $this->validatore->valida($chiamata(''));

        if ($esito['gravi'] === []) {
            return [$esito['stima'], $esito['avvisi']];
        }

        $appendice = "\n\n[SISTEMA] Il tuo output precedente violava lo schema: "
            .implode(' ', $esito['gravi'])
            .' Correggi e restituisci solo il JSON.';

        $secondo = $this->validatore->valida($chiamata($appendice));

        return [$secondo['stima'], array_merge($secondo['avvisi'], $secondo['gravi'])];
    }
}
