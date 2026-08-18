<?php

declare(strict_types=1);

namespace App\Services\Billing;

/**
 * Il listino — rifatto il 18/08/2026.
 *
 * ── 🚨 I conti stanno QUI, non nella vista ────────────────────────────────
 *
 * Il sito li mostra e il pannello li fatturera' (`H2`): due implementazioni
 * della stessa formula divergono sempre, e quella che sbaglia e' quella che il
 * cliente non guarda — cioe' la fattura.
 *
 * ── 📌 Cosa e' cambiato rispetto agli scaglioni ───────────────────────────
 *
 * Prima c'era una **scala progressiva** sul numero di posti, come le aliquote.
 * Era corretta e difficile da leggere: per sapere quanto si spende bisognava
 * fare una somma a pezzi. ⚠️ Un listino che richiede un calcolo per essere
 * capito e' un listino che nessuno confronta.
 *
 * Adesso ci sono **pacchetti a prezzo fisso** — dieci posti, venticinque,
 * cinquanta — piu' **un solo piano a consumo** per chi non vuole impegnarsi.
 * Il prezzo si legge, non si calcola.
 */
class Listino
{
    /** L'abbonamento del singolo, in centesimi. E' il perno di tutto il listino. */
    public function singolo(): int
    {
        return (int) config('listino.singolo_cent');
    }

    /** I gettoni che l'abbonamento accredita ogni mese. */
    public function gettoniMensili(): int
    {
        return (int) config('listino.gettoni_mensili');
    }

    /**
     * @return list<array{gettoni: int, prezzo: int, nota: string}>
     */
    public function pacchettiGettoni(): array
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

    /**
     * I pacchetti di posti di una platea, col prezzo per posto gia' calcolato.
     *
     * 💡 **Il prezzo per posto e' il numero che vende**, ed e' anche quello che
     * nessuno ha voglia di calcolarsi: «249,90 per 50 posti» non dice niente,
     * «4,00 a posto» dice tutto.
     *
     * @param  'palestre'|'trainer'  $platea
     * @return list<array{posti: int, prezzo: int, perPosto: int, nota: string}>
     */
    public function pacchettiPosti(string $platea): array
    {
        return array_map(
            static fn (array $p): array => [
                'posti' => $p['posti'],
                'prezzo' => $p['prezzo_cent'],
                // ⚠️ `intdiv` e non una divisione in virgola mobile: qui si
                // stampa un prezzo, e `4.9899999` stampato diventa «4,99» solo
                // per fortuna.
                'perPosto' => intdiv($p['prezzo_cent'], $p['posti']),
                'nota' => $p['nota'],
            ],
            config("listino.{$platea}.pacchetti"),
        );
    }

    /** Il prezzo per posto del piano a consumo, in centesimi. */
    public function aConsumo(string $platea): int
    {
        return (int) config("listino.{$platea}.a_consumo_cent");
    }

    /** Quanti posti bisogna accendere come minimo sul piano a consumo. */
    public function minimo(string $platea): int
    {
        return (int) config("listino.{$platea}.minimo");
    }

    /**
     * Il pacchetto piu' conveniente di una platea, per posto.
     *
     * 💡 Serve alla frase «da X a posto»: e' il numero che si annuncia, e va
     * preso dai dati invece che scritto a mano — o il giorno che si aggiunge un
     * pacchetto piu' grande, l'annuncio resta indietro.
     */
    public function migliorPrezzoPerPosto(string $platea): int
    {
        return min(array_column($this->pacchettiPosti($platea), 'perPosto'));
    }

    /**
     * L'esempio che vende: cosa paga una palestra e cosa le resta.
     *
     * 💡 **E' il numero che conta davvero.** Una palestra non compra «4,40 a
     * posto»: compra «incasso 250 e ne pago 110». Senza la differenza, il
     * listino e' solo un costo.
     *
     * @return array{posti: int, costo: int, ricavo: int, margine: int}
     */
    public function esempio(string $platea = 'palestre', ?int $posti = null): array
    {
        $posti ??= (int) config('listino.esempio_posti');

        $pacchetti = $this->pacchettiPosti($platea);

        // Il pacchetto che copre quei posti; se nessuno basta, il piu' grande.
        $scelto = null;
        foreach ($pacchetti as $p) {
            if ($p['posti'] >= $posti) {
                $scelto = $p;
                break;
            }
        }
        $scelto ??= $pacchetti[count($pacchetti) - 1];

        $ricavo = $posti * (int) config('listino.rivendita_suggerita_cent');

        return [
            'posti' => $posti,
            'costo' => $scelto['prezzo'],
            'ricavo' => $ricavo,
            'margine' => $ricavo - $scelto['prezzo'],
        ];
    }
}
