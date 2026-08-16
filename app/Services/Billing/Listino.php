<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Il listino a posti — 16/08/2026.
 *
 * 🚨 **Il calcolo dello scaglione vive QUI, non nella vista.** Il sito lo mostra
 * e il pannello lo fatturera' (`H2`): due implementazioni della stessa formula
 * divergono sempre, e quella che sbaglia e' quella che il cliente non guarda —
 * cioe' la fattura.
 *
 * ⚠️ **Tutto in centesimi.** `4.99` in virgola mobile e' `4.9899999...`: su
 * trecento posti l'errore si vede, e su una fattura si contesta.
 */
class Listino
{
    /**
     * Quanto costa un mese con questo numero di posti accesi, in centesimi.
     *
     * ── 🚨 E' progressivo, come le aliquote ───────────────────────────────
     *
     * I primi 25 posti costano 4,99 **anche a chi ne ha 300**: non si applica
     * un prezzo unico a tutto il blocco. ⚠️ L'alternativa — «oltre 100 paghi
     * tutto a 2,99» — creerebbe un salto in cui **aggiungere un posto fa
     * scendere la fattura**, e chi se ne accorge lo racconta.
     */
    public function costoMensile(int $posti): int
    {
        if ($posti <= 0) {
            return 0;
        }

        $totale = 0;
        $precedente = 0;

        foreach (config('listino.scaglioni') as $scaglione) {
            $fino = $scaglione['fino'] ?? PHP_INT_MAX;
            $quanti = max(0, min($posti, $fino) - $precedente);

            $totale += $quanti * $scaglione['prezzo_cent'];
            $precedente = $fino;

            if ($posti <= $fino) {
                break;
            }
        }

        return $totale;
    }

    /** Il prezzo del primo scaglione: quello che il sito annuncia. */
    public function primoScaglione(): int
    {
        return (int) config('listino.scaglioni.0.prezzo_cent');
    }

    /**
     * Gli scaglioni in forma leggibile, per la tabella del sito.
     *
     * @return list<array{etichetta: string, prezzo: int}>
     */
    public function scaglioniLeggibili(): array
    {
        $out = [];
        $da = 1;

        foreach (config('listino.scaglioni') as $s) {
            $fino = $s['fino'];

            $out[] = [
                'etichetta' => $fino === null ? "oltre {$da} posti" : "da {$da} a {$fino} posti",
                'prezzo' => $s['prezzo_cent'],
            ];

            $da = ($fino ?? 0) + 1;
        }

        return $out;
    }

    /**
     * L'esempio che vende: cosa paga una palestra e cosa le resta.
     *
     * 💡 **E' il numero che conta davvero.** Una palestra non compra «4,99 a
     * posto»: compra «incasso 600 e ne pago 264». Senza la differenza, il
     * listino e' solo un costo.
     *
     * @return array{posti: int, costo: int, ricavo: int, margine: int}
     */
    public function esempio(?int $posti = null): array
    {
        $posti ??= (int) config('listino.esempio_posti');

        $costo = $this->costoMensile($posti);
        $ricavo = $posti * (int) config('listino.rivendita_suggerita_cent');

        return [
            'posti' => $posti,
            'costo' => $costo,
            'ricavo' => $ricavo,
            'margine' => $ricavo - $costo,
        ];
    }

    /**
     * @return list<array{gettoni: int, prezzo: int, nota: string}>
     */
    public function pacchetti(): array
    {
        return array_map(
            static fn (array $p): array => [
                'gettoni' => $p['gettoni'],
                'prezzo' => $p['prezzo_cent'],
                'nota' => $p['nota'],
            ],
            config('listino.pacchetti'),
        );
    }
}
