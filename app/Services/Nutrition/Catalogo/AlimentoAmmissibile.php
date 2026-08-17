<?php

declare(strict_types=1);

namespace App\Services\Nutrition\Catalogo;

/**
 * Il filtro d'ingresso del catalogo — 17/08/2026.
 *
 * ── 🚨 Perche' esiste, oltre a quello che e' stato chiesto ─────────────────
 *
 * Il committente ha chiesto una condizione sola: **tutti i macro compilati**.
 * Questa classe ne applica altre, e sono quelle che tengono in piedi il
 * catalogo nel tempo. La piu' importante: `4·proteine + 4·carboidrati +
 * 9·grassi` deve stare entro ±25 % delle kcal dichiarate.
 *
 * ⚠️ Senza quel controllo, il primo che digita «Riso 3.500 kcal» invece di 350
 * lo pubblica per tutti, e da li' in poi lo suggeriamo noi. Un catalogo
 * condiviso senza filtro non e' un catalogo: e' la somma dei refusi di tutti.
 *
 * ── 🚨 Cosa ha insegnato la prima importazione CREA ────────────────────────
 *
 * La prima versione ha scartato **77 alimenti su 832**, e guardandoli uno per
 * uno erano quasi tutti scarti sbagliati — succo d'arancia, barbabietole,
 * carciofi, lamponi, crusca, birra. Cioe' roba che si scrive nel diario tutti i
 * giorni. Tre difetti, tutti e tre **del filtro** e non della fonte:
 *
 * | Difetto | Perche' | Correzione |
 * |---|---|---|
 * | 13 scarti per «i macro non tornano» | Erano alimenti **ricchi di fibra** (carciofi, crusca, lamponi) e **alcolici**. La fibra porta ~2 kcal/g e l'alcol 7, e la formula dei quattro macro non li vede | La fibra e l'alcol entrano nel conto |
 * | 55 scarti per «manca un macro» | CREA lascia la cella **vuota** dove il valore e' zero o in tracce: i grassi di un succo d'arancia, le proteine della grappa | Da una fonte dichiarata, la cella vuota vale **zero** — ma solo se il conto delle calorie torna lo stesso |
 * | 9 scarti per «sembra un pasto» | Erano nomi CREA veri: «Bovino adulto, geretto anteriore e posteriore», «Pizza con pomodoro e mozzarella» | Il controllo vale solo per il testo scritto **da una persona**, che e' il caso per cui era nato |
 *
 * 💡 **La lezione**: un filtro tarato sull'idea che uno si fa dei dati va poi
 * misurato **sui dati veri**, uno scarto per volta. I 77 rifiuti erano il
 * risultato utile della prima esecuzione, non un fastidio da aggirare
 * allargando le maglie a caso.
 */
class AlimentoAmmissibile
{
    /** Il grasso puro: 9 kcal per grammo, cioe' 900 per 100 g. Non si supera. */
    public const KCAL_MASSIME_100 = 900.0;

    private const TOLLERANZA = 0.25;

    /**
     * ⚠️ Sotto questa soglia il confronto fra kcal dichiarate e kcal calcolate
     * non dice niente: su 5 kcal, uno scarto di 2 e' il 40 % ed e' fisiologico.
     */
    private const KCAL_MINIME_PER_CONTROLLARE = 20.0;

    /** 🚨 Non sono i quattro macro, ma portano calorie e vanno contate. */
    private const KCAL_PER_GRAMMO_FIBRA = 2.0;

    private const KCAL_PER_GRAMMO_ALCOL = 7.0;

    /**
     * Perche' un alimento non e' ammesso, oppure `null` se lo e'.
     *
     * 💡 Restituisce **il motivo** e non un booleano: e' quello che ha permesso
     * di capire, dopo la prima importazione, che i 77 scarti erano tre difetti
     * distinti e non uno.
     *
     * @param  array{kcal_100: ?float, protein_100: ?float, carbs_100: ?float, fat_100: ?float}  $per100
     * @param  array{fibra_100?: ?float, alcol_100?: ?float, da_fonte?: bool}  $contesto
     */
    public function motivoDelRifiuto(string $nome, array $per100, array $contesto = []): ?string
    {
        if (mb_strlen(trim($nome)) < 2) {
            return 'nome troppo corto';
        }

        /*
         * 🚨 Il controllo «e' un pasto» vale solo per il testo scritto da una
         * persona.
         *
         * ⚠️ Applicato a una fonte dichiarata scartava nomi legittimi come
         * «Pizza con pomodoro e mozzarella» o «Bovino adulto, geretto anteriore
         * e posteriore», che nelle Tabelle CREA sono **un alimento solo**. Il
         * difetto che il controllo esiste per fermare — «pasta al pomodoro e
         * due uova» — arriva dalla tastiera di qualcuno, non da un ente di
         * ricerca.
         */
        if (! ($contesto['da_fonte'] ?? false) && $this->sembraUnPasto($nome)) {
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

        // ⚠️ Cento grammi di alimento non possono contenere piu' di cento
        // grammi di roba. Prende errori che le calorie non prendono: chi scrive
        // 80 g di proteine e 60 di carboidrati.
        if ($proteine + $carboidrati + $grassi > 105.0) {
            return 'i macro sommano a piu\' di 100 g su 100 g';
        }

        if ($kcal < self::KCAL_MINIME_PER_CONTROLLARE) {
            // 💡 Alimenti quasi acalorici (verdure, brodo): niente da
            // confrontare, e va bene cosi'.
            return null;
        }

        $calcolate = 4 * $proteine + 4 * $carboidrati + 9 * $grassi
            + self::KCAL_PER_GRAMMO_FIBRA * (float) ($contesto['fibra_100'] ?? 0)
            + self::KCAL_PER_GRAMMO_ALCOL * (float) ($contesto['alcol_100'] ?? 0);

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

    /**
     * @param  array{fibra_100?: ?float, alcol_100?: ?float, da_fonte?: bool}  $contesto
     */
    public function ammesso(string $nome, array $per100, array $contesto = []): bool
    {
        return $this->motivoDelRifiuto($nome, $per100, $contesto) === null;
    }

    /**
     * 🚨 Il riconoscimento del pasto e' volutamente **grossolano**, e vale solo
     * per il testo scritto da una persona.
     *
     * ⚠️ Un riconoscimento fine sbaglierebbe nell'altro verso e scarterebbe
     * alimenti veri. Qui si prendono i casi evidenti: due congiunzioni sono
     * gia' una ricetta, e un nome lunghissimo non e' il nome di una cosa sola.
     */
    private function sembraUnPasto(string $nome): bool
    {
        if (mb_strlen($nome) > 60) {
            return true;
        }

        return preg_match_all('~\b(e|con|piu)\b~ui', $nome) >= 2;
    }
}
