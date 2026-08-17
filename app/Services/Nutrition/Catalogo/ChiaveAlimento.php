<?php

declare(strict_types=1);

namespace App\Services\Nutrition\Catalogo;

/**
 * Da «Pasta  al Pomodoro » alla chiave `pasta al pomodoro` — 17/08/2026.
 *
 * ── 🚨 Perche' la deduplicazione sta tutta qui ─────────────────────────────
 *
 * Il committente ha chiesto: *«il server lo deve scartare se ha lo stesso
 * nome»*. ⚠️ Confrontare i nomi **cosi' come sono scritti** non scarterebbe
 * quasi niente: `Pasta al pomodoro`, `pasta al pomodoro`, `Pasta  al  pomodoro`
 * e `Pasta al pomodoro ` sono quattro righe diverse per il database e lo stesso
 * alimento per una persona.
 *
 * 💡 Questa classe e' quindi **l'unico posto** in cui si decide cosa vuol dire
 * «stesso alimento». Cambiarla cambia il significato di `foods.chiave`, e
 * quindi va cambiata sapendo che le righe gia' scritte con la regola vecchia
 * non si fonderanno da sole.
 */
class ChiaveAlimento
{
    /**
     * ⚠️ Le parole che non distinguono un alimento da un altro.
     *
     * «Pasta al pomodoro» e «pasta con pomodoro» sono la stessa cosa per chi la
     * mangia. 💡 Toglierle **aumenta** le collisioni, ed e' voluto: due modi di
     * scrivere la stessa cosa devono cadere sulla stessa riga.
     *
     * 🚨 L'elenco e' corto di proposito. Ogni parola in piu' e' un modo in piu'
     * di far collidere due alimenti **diversi**, e una collisione sbagliata e'
     * peggio di un doppione: fa comparire i macro di una cosa sotto il nome di
     * un'altra.
     */
    private const RIEMPITIVI = ['al', 'allo', 'alla', 'ai', 'agli', 'alle', 'con', 'di', 'del', 'della', 'e', 'in', 'la', 'il', 'lo', 'i', 'gli', 'le', 'un', 'una'];

    /**
     * La chiave con cui due alimenti si considerano lo stesso.
     *
     * 💡 La marca ne fa parte: la whey alla fragola di un produttore non e'
     * quella di un altro, e fonderle darebbe a entrambe i valori della prima
     * arrivata.
     */
    public function da(string $nome, ?string $marca = null): string
    {
        $pezzi = array_filter([
            $this->normalizza($nome),
            $marca !== null ? $this->normalizza($marca) : null,
        ]);

        return mb_substr(implode('|', $pezzi), 0, 200);
    }

    /**
     * La forma su cui si cerca: come `da()`, ma senza marca e senza troncatura.
     *
     * 🚨 Serve alla ricerca a prefisso: chi digita «pas» deve trovare «pasta»,
     * e il confronto va fatto sulla **stessa** normalizzazione con cui la
     * chiave e' stata scritta. Due normalizzazioni diverse fra scrittura e
     * lettura sono il modo classico di avere un indice che non trova mai
     * niente.
     */
    public function perCercare(string $testo): string
    {
        return $this->normalizza($testo);
    }

    private function normalizza(string $testo): string
    {
        $t = mb_strtolower(trim($testo));

        // 🚨 Gli accenti vanno tolti: chi scrive «purè» e chi scrive «pure»
        // intende la stessa cosa, e sulla tastiera del telefono l'accento e'
        // un tasto in piu' che meta' delle persone non preme.
        $t = $this->senzaAccenti($t);

        // La punteggiatura non distingue: «yogurt, greco» e' «yogurt greco».
        $t = preg_replace('~[^\p{L}\p{N}\s]+~u', ' ', $t) ?? $t;

        // ⚠️ Gli spazi si collassano **dopo** aver tolto la punteggiatura, non
        // prima: togliendo una virgola si crea uno spazio doppio.
        $t = trim(preg_replace('~\s+~u', ' ', $t) ?? $t);

        if ($t === '') {
            return '';
        }

        $parole = array_values(array_filter(
            explode(' ', $t),
            fn (string $p): bool => ! in_array($p, self::RIEMPITIVI, true),
        ));

        // 💡 Se erano **tutte** parole di riempimento si tiene il testo com'era:
        // meglio una chiave strana che una chiave vuota, che collegherebbe fra
        // loro alimenti senza niente in comune.
        return $parole === [] ? $t : implode(' ', $parole);
    }

    /**
     * 💡 A mano e non con `iconv`: `iconv('UTF-8', 'ASCII//TRANSLIT')` dipende
     * dalla localizzazione del sistema, e sullo stesso codice da' risultati
     * diversi fra la macchina di sviluppo e il server — cioe' chiavi diverse
     * per lo stesso alimento, che e' esattamente il difetto che questa classe
     * esiste per evitare.
     */
    private function senzaAccenti(string $testo): string
    {
        return strtr($testo, [
            'à' => 'a', 'á' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
            'è' => 'e', 'é' => 'e', 'ê' => 'e', 'ë' => 'e',
            'ì' => 'i', 'í' => 'i', 'î' => 'i', 'ï' => 'i',
            'ò' => 'o', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o',
            'ù' => 'u', 'ú' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c', 'ñ' => 'n',
        ]);
    }
}
