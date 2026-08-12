<?php

declare(strict_types=1);

namespace App\Services\Ai\Guardie;

use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Data\FoodItem;

/**
 * I controlli **deterministici** su una stima del modello.
 *
 * ── 🚨 Il principio, e la ragione per cui questa classe esiste ─────────────
 *
 * **Fuzzy al modello, deterministico al backend.** Il modello fa l'unica cosa
 * che sa fare meglio di noi: capire che «due spinacine» sono 220 g di petto di
 * pollo impanato. Tutto cio' che si puo' calcolare lo calcola PHP, che le somme
 * non le sbaglia mai.
 *
 * ⚠️ Chiedere al modello di sommare quattro numeri **dopo** avergli fatto fare
 * tutta l'inferenza e' regalargli un'occasione di errore gratuita. E un
 * controllo calcolato qui e' **indipendente** dal modello: se il modello
 * aggiustasse i valori per far tornare la formula, il backend se ne accorgerebbe
 * lo stesso.
 *
 * ── Le due severita' ───────────────────────────────────────────────────────
 *
 * | | Cosa succede |
 * |---|---|
 * | **Errore grave** | La stima non e' utilizzabile: si richiede al modello di rifarla, **una volta sola** |
 * | **Avviso** | La stima si usa, ma la confidenza scende e chi guarda lo vede |
 *
 * 🚨 **Si corregge automaticamente solo cio' che e' deterministico.** L'alcol
 * ricalcolato dalla gradazione si', perche' e' una formula; le calorie
 * riscritte dai macro **no**, perche' quando i due conti non tornano non si sa
 * quale dei due sia sbagliato — e si salverebbe un numero inventato al posto di
 * uno storto, con l'aggravante che il primo sembra verificato.
 */
final class MealValidator
{
    /** La densita' di ripiego quando quella dichiarata e' assurda. */
    public const DENSITA_PLAUSIBILE = [0.85, 1.15];

    /**
     * 🚨 Nessun alimento supera i 9,4 kcal per grammo: e' il grasso puro. Un
     * valore piu' alto non e' una stima ottimistica, e' un errore di unita'.
     */
    private const KCAL_PER_GRAMMO_MASSIME = 9.5;

    private const KCAL_PASTO_ASSURDE = 6000.0;

    /**
     * Oltre questo scarto fra l'alcol dichiarato e quello che la gradazione
     * impone, si **corregge**: la formula e' deterministica.
     *
     * ⚠️ **La specsheet proponeva il 20%, e il test lo ha bocciato subito**:
     * l'errore vero osservato il 12/08/2026 — 11,84 g invece di 14,2 su 150 ml
     * a 12 gradi — vale il **16,6%**, cioe' sarebbe passato liscio proprio il
     * caso che ha fatto scrivere questa regola.
     *
     * 💡 Dati `ml` e `abv_pct`, i grammi di alcol non sono una stima: sono
     * determinati. L'unica varianza legittima e' l'arrotondamento dei due
     * ingressi, e per quello il 5% e' gia' generoso.
     */
    private const SCARTO_ALCOL_TOLLERATO = 0.05;

    /** @var list<string> */
    private array $gravi = [];

    /** @var list<string> */
    private array $avvisi = [];

    /**
     * Controlla e, dove e' lecito, corregge.
     *
     * @return array{stima: FoodEstimate, gravi: list<string>, avvisi: list<string>}
     */
    public function valida(FoodEstimate $stima): array
    {
        $this->gravi = [];
        $this->avvisi = [];

        $voci = [];

        foreach ($stima->items as $i => $voce) {
            $voci[] = $this->validaVoce($voce, $i + 1);
        }

        $corretta = new FoodEstimate(
            items: $voci,
            totals: FoodEstimate::sommaDi($voci),
            confidence: $this->confidenzaDelPasto($stima->confidence, $voci),
            note: $stima->note,
        );

        if ($corretta->totals['kcal'] !== null && $corretta->totals['kcal'] > self::KCAL_PASTO_ASSURDE) {
            $this->avvisi[] = sprintf(
                'Il pasto totale fa %.0f kcal: e\' un valore implausibile.',
                $corretta->totals['kcal'],
            );
        }

        return ['stima' => $corretta, 'gravi' => $this->gravi, 'avvisi' => $this->avvisi];
    }

    private function validaVoce(FoodItem $voce, int $n): FoodItem
    {
        $etichetta = $voce->name !== '' ? $voce->name : "voce {$n}";

        // ── 1. Le unita' vietate ──────────────────────────────────────────
        //
        // 🚨 Grave e non avviso: se compare «pezzo», il modello ha ignorato lo
        // schema, e cio' che ha ignorato una volta puo' averlo ignorato altrove.
        if ($voce->unit !== null && ! in_array($voce->unit, self::unitaAmmesse(), true)) {
            $this->gravi[] = sprintf('«%s» usa l\'unita\' «%s», che non e\' ammessa.', $etichetta, $voce->unit);
        }

        if ($voce->basis !== null && ! in_array($voce->basis, FoodItem::BASI, true)) {
            $this->gravi[] = sprintf('«%s» ha una base di calcolo sconosciuta: «%s».', $etichetta, $voce->basis);
        }

        if ($voce->state !== null && ! in_array($voce->state, FoodItem::STATI, true)) {
            $this->gravi[] = sprintf('«%s» ha uno stato sconosciuto: «%s».', $etichetta, $voce->state);
        }

        // ── 2. I grammi ───────────────────────────────────────────────────
        //
        // ⚠️ Una voce con calorie e senza peso non entra in nessun totale
        // riscalabile ed e' come non averla scritta.
        if (($voce->kcal ?? 0) > 0 && ($voce->grams === null || $voce->grams <= 0)) {
            $this->gravi[] = sprintf('«%s» ha calorie ma non ha un peso.', $etichetta);
        }

        // ── 3. La densita' ────────────────────────────────────────────────
        $voce = $this->controllaDensita($voce, $etichetta);

        // ── 4. L'alcol, che si corregge ───────────────────────────────────
        $voce = $this->correggiAlcol($voce, $etichetta);

        // ── 5. La plausibilita' grossolana ────────────────────────────────
        if ($voce->grams !== null && $voce->grams > 0 && $voce->kcal !== null) {
            $perGrammo = $voce->kcal / $voce->grams;

            if ($perGrammo > self::KCAL_PER_GRAMMO_MASSIME) {
                $this->avvisi[] = sprintf(
                    '«%s» dichiara %.1f kcal per grammo: piu\' del grasso puro.',
                    $etichetta,
                    $perGrammo,
                );

                $voce = $voce->con(confidence: min($voce->confidence ?? 1.0, 0.4));
            }
        }

        return $voce;
    }

    /**
     * ⚠️ **Si segnala e NON si riscrive.**
     *
     * La spec proponeva di ricalcolare i grammi con densita' 1,0 quando il
     * rapporto esce dall'intervallo. Qui no, e per un motivo concreto: il miele
     * pesa **1,42 g/ml** e lo sciroppo d'acero 1,33. Sostituirli con 1,0
     * introdurrebbe un errore del 30% per «correggere» un valore giusto.
     *
     * 💡 La densita' e' una proprieta' dell'alimento, non una formula: e' fuzzy,
     * e cio' che e' fuzzy non si corregge in automatico.
     */
    private function controllaDensita(FoodItem $voce, string $etichetta): FoodItem
    {
        if (! $voce->eLiquido() || $voce->grams === null || $voce->grams <= 0) {
            return $voce;
        }

        $densita = $voce->grams / $voce->ml;
        [$min, $max] = self::DENSITA_PLAUSIBILE;

        if ($densita >= $min && $densita <= $max) {
            return $voce;
        }

        $this->avvisi[] = sprintf(
            '«%s» ha una densita\' di %.2f g/ml, fuori dall\'intervallo comune.',
            $etichetta,
            $densita,
        );

        return $voce->con(confidence: min($voce->confidence ?? 1.0, 0.6));
    }

    /**
     * 🚨 **Qui la correzione automatica e' legittima**, ed e' l'unico punto in
     * cui lo e': `ml × (gradi / 100) × 0,789` non e' una stima, e' aritmetica.
     *
     * ⚠️ Il modello la sbaglia davvero. Il 12/08/2026, su 150 ml a 12 gradi, ha
     * risposto **11,84 g** invece di 14,2: aveva letto i gradi come se fossero
     * gia' una frazione. Il prompt e' stato corretto, ma un prompt e' una
     * richiesta — questo e' un vincolo.
     *
     * 💡 Corretto l'alcol si rifanno anche le calorie, perche' quasi tutte le
     * calorie di una bevanda alcolica stanno li' dentro: lasciarle ferme
     * significherebbe correggere meta' del dato.
     */
    private function correggiAlcol(FoodItem $voce, string $etichetta): FoodItem
    {
        if ($voce->abvPct === null || $voce->abvPct <= 0 || ! $voce->eLiquido()) {
            return $voce;
        }

        $atteso = round($voce->ml * ($voce->abvPct / 100) * 0.789, 2);
        $dichiarato = $voce->alcohol ?? 0.0;

        if ($atteso <= 0.0) {
            return $voce;
        }

        if (abs($dichiarato - $atteso) / $atteso <= self::SCARTO_ALCOL_TOLLERATO) {
            return $voce;
        }

        $this->avvisi[] = sprintf(
            '«%s»: %.1f g di alcol dichiarati, %.1f g imposti da %.1f%% su %.0f ml. Corretto.',
            $etichetta,
            $dichiarato,
            $atteso,
            $voce->abvPct,
            $voce->ml,
        );

        $kcal = round(
            ($voce->protein ?? 0) * 4
            + ($voce->carbs ?? 0) * 4
            + ($voce->fat ?? 0) * 9
            + $atteso * 7,
            1,
        );

        return $voce->con(kcal: $kcal, alcohol: $atteso);
    }

    /**
     * La confidenza del pasto.
     *
     * 🚨 **Non e' la media**: e' tirata giu' dalle voci incerte che pesano di
     * piu' sulle calorie. Un ingrediente dominante e incerto non puo' dare un
     * pasto «sicuro» — sarebbe la media a mentire, non la voce.
     *
     * @param  list<FoodItem>  $voci
     */
    private function confidenzaDelPasto(float $dichiarata, array $voci): float
    {
        $totale = 0.0;

        foreach ($voci as $v) {
            $totale += $v->kcal ?? 0.0;
        }

        if ($totale <= 0.0) {
            return $dichiarata;
        }

        $tetto = 1.0;

        foreach ($voci as $v) {
            $peso = ($v->kcal ?? 0.0) / $totale;

            if ($peso > 0.30 && $v->confidence !== null) {
                $tetto = min($tetto, $v->confidence);
            }
        }

        return round(min($dichiarata, $tetto), 2);
    }

    /** @return list<string> */
    private static function unitaAmmesse(): array
    {
        return ['g', 'kg', 'mg', 'hg', 'ml', 'cl', 'dl', 'l', 'cucchiaio', 'cucchiaino', 'bicchiere', 'tazza', 'scoop'];
    }
}
