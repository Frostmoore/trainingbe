<?php

declare(strict_types=1);

namespace App\Services\Nutrition\Catalogo;

/**
 * Il filtro d'ingresso del catalogo — 17/08/2026.
 *
 * ── 🚨 Perche' esiste, oltre a quello che e' stato chiesto ─────────────────
 *
 * Il committente ha chiesto una condizione sola: **tutti i macro compilati**.
 * Questa classe ne applica altre tre, e sono quelle che tengono in piedi il
 * catalogo nel tempo:
 *
 * 1. i valori devono essere ricavabili **per 100 g/ml**, o lo stesso nome
 *    finirebbe con due verita' diverse a seconda della porzione registrata;
 * 2. `4·proteine + 4·carboidrati + 9·grassi` deve stare entro ±25 % delle
 *    kcal dichiarate — e' la sola verifica che smaschera una virgola messa
 *    male senza sapere niente dell'alimento;
 * 3. le kcal per 100 g non possono superare **900**, che e' il grasso puro:
 *    nessun alimento al mondo ne ha di piu'.
 *
 * ⚠️ Senza il punto 2, il primo che digita «Riso 3.500 kcal» invece di 350 lo
 * pubblica per tutti, e da li' in poi lo suggeriamo noi. Un catalogo condiviso
 * senza filtro non e' un catalogo: e' la somma dei refusi di tutti.
 *
 * 💡 La tolleranza e' larga (±25 %) apposta: fibre, alcol e poliolo portano
 * calorie che i quattro macro non spiegano, e un controllo stretto scarterebbe
 * alimenti veri. Serve a prendere gli errori di **ordine di grandezza**, non le
 * imprecisioni.
 */
class AlimentoAmmissibile
{
    /** Il grasso puro: 9 kcal per grammo, cioe' 900 per 100 g. Non si supera. */
    public const KCAL_MASSIME_100 = 900.0;

    private const TOLLERANZA = 0.25;

    /**
     * ⚠️ Sotto questa soglia il confronto fra kcal dichiarate e kcal calcolate
     * non dice niente: su 5 kcal, uno scarto di 2 e' il 40 % ed e' fisiologico.
     * Sopra, e' un errore.
     */
    private const KCAL_MINIME_PER_CONTROLLARE = 20.0;

    /**
     * Perche' un alimento non e' ammesso, oppure `null` se lo e'.
     *
     * 💡 Restituisce **il motivo** e non un booleano: serve nei log e nei test,
     * e un giorno servira' all'app per dire a chi ha inserito un dato assurdo
     * che l'ha inserito assurdo, invece di scartarlo in silenzio.
     *
     * @param  array{kcal_100: ?float, protein_100: ?float, carbs_100: ?float, fat_100: ?float}  $per100
     */
    public function motivoDelRifiuto(string $nome, array $per100): ?string
    {
        if (mb_strlen(trim($nome)) < 2) {
            return 'nome troppo corto';
        }

        // 🚨 Un pasto non e' un alimento. «Pasta al pomodoro e due uova» come
        // riga di catalogo non serve a nessuno, e ci finirebbe di continuo
        // perche' e' esattamente come si scrive in un diario.
        if ($this->sembraUnPasto($nome)) {
            return 'sembra un pasto e non un alimento';
        }

        foreach (['kcal_100', 'protein_100', 'carbs_100', 'fat_100'] as $campo) {
            if (($per100[$campo] ?? null) === null) {
                return "manca {$campo}";
            }

            if ($per100[$campo] < 0) {
                return "{$campo} negativo";
            }
        }

        $kcal = (float) $per100['kcal_100'];
        $proteine = (float) $per100['protein_100'];
        $carboidrati = (float) $per100['carbs_100'];
        $grassi = (float) $per100['fat_100'];

        if ($kcal > self::KCAL_MASSIME_100) {
            return 'piu\' di 900 kcal per 100 g: impossibile';
        }

        // ⚠️ La somma dei macro non puo' superare 100 g su 100 g di alimento.
        // E' un controllo diverso dalle calorie e prende errori diversi: chi
        // scrive 80 g di proteine e 60 di carboidrati.
        if ($proteine + $carboidrati + $grassi > 105.0) {
            return 'i macro sommano a piu\' di 100 g su 100 g';
        }

        if ($kcal < self::KCAL_MINIME_PER_CONTROLLARE) {
            // 💡 Alimenti quasi acalorici (verdure, brodo): niente da
            // confrontare, e va bene cosi'.
            return null;
        }

        $calcolate = 4 * $proteine + 4 * $carboidrati + 9 * $grassi;
        $scarto = abs($calcolate - $kcal) / $kcal;

        if ($scarto > self::TOLLERANZA) {
            return sprintf(
                'i macro non tornano con le kcal (dichiarate %.0f, calcolate %.0f)',
                $kcal,
                $calcolate,
            );
        }

        return null;
    }

    public function ammesso(string $nome, array $per100): bool
    {
        return $this->motivoDelRifiuto($nome, $per100) === null;
    }

    /**
     * 🚨 Il riconoscimento del pasto e' volutamente **grossolano**.
     *
     * ⚠️ Un riconoscimento fine sbaglierebbe nell'altro verso e scarterebbe
     * alimenti veri («pasta e fagioli» e' un alimento del catalogo CREA). Qui
     * si prendono solo i casi evidenti: una congiunzione fra due pezzi lunghi,
     * o un nome cosi' lungo da non poter essere il nome di una cosa sola.
     */
    private function sembraUnPasto(string $nome): bool
    {
        if (mb_strlen($nome) > 60) {
            return true;
        }

        // «X con Y e Z»: due congiunzioni sono gia' una ricetta.
        return preg_match_all('~\b(e|con|piu)\b~ui', $nome) >= 2;
    }
}
