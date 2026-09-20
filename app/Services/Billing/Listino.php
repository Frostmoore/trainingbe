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

    /**
     * Le **richieste incluse** nell'abbonamento, ogni mese.
     *
     * ⛔ **Non sono gettoni.** Si chiamava `gettoniMensili()`, e quel nome ha
     * prodotto un accredito di gettoni all'accensione dell'abbonamento —
     * scritto e tolto lo stesso giorno. La nota lunga sta in
     * `config/listino.php`.
     */
    public function chiamateMensili(): int
    {
        return (int) config('listino.chiamate_mensili');
    }

    /**
     * @return list<array{gettoni: int, prezzo: int, nota: string}>
     */
    public function pacchettiGettoni(): array
    {
        return array_map(
            static fn (array $p): array => [
                // 🆕 20/09/2026: i tagli hanno un **nome** («Prova», «Serie»,
                // «Blocco», «Stagione»), che è come li chiama chi li compra.
                'nome' => $p['nome'],
                'gettoni' => $p['gettoni'],
                'prezzo' => $p['prezzo_cent'],
                'nota' => $p['nota'],

                // 💡 Il prezzo unitario lo calcola il listino, non la pagina:
                // serve uguale nell'app e sul sito.
                'centPerGettone' => $p['prezzo_cent'] / $p['gettoni'],
            ],
            config('listino.pacchetti'),
        );
    }

    /**
     * I piani di una platea, con il prezzo per slot gia' calcolato.
     *
     * 🆕 **Rifatto il 13/09/2026**: prima erano «pacchetti di posti» con un
     * numero solo. 🚨 Da oggi un piano da palestra ha **due tetti** — slot
     * iscritto e slot trainer — e tutti e due valgono.
     *
     * 💡 **Il prezzo per slot e' il numero che vende**, ed e' anche quello che
     * nessuno ha voglia di calcolarsi: «199,90 per 50 iscritti» non dice niente,
     * «4,00 a iscritto» dice tutto.
     *
     * ⚠️ Un piano da trainer non ha slot trainer, e `trainer` torna `null`: nel
     * suo piano il trainer e' **lui**, e non ce ne sono altri da contare.
     *
     * @param  'palestre'|'trainer'  $platea
     * @return list<array{codice: string, nome: string, slot: int, trainer: int|null, prezzo: int, perSlot: int, nota: string}>
     */
    public function piani(string $platea): array
    {
        return array_map(
            static fn (array $p): array => [
                'codice' => $p['codice'],
                'nome' => $p['nome'],
                'slot' => $p['iscritti'] ?? $p['allievi'],
                'trainer' => $p['trainer'] ?? null,
                'prezzo' => $p['prezzo_cent'],

                // ⚠️ `intdiv` e non una divisione in virgola mobile: qui si
                // stampa un prezzo, e `4.9899999` stampato diventa «4,99» solo
                // per fortuna.
                'perSlot' => intdiv($p['prezzo_cent'], $p['iscritti'] ?? $p['allievi']),
                'nota' => $p['nota'],
            ],
            config("listino.{$platea}.piani"),
        );
    }

    /**
     * Il piano a consumo di una platea, se ne ha uno.
     *
     * 🆕 **Solo le palestre ce l'hanno** — 13/09/2026. ⛔ Per un trainer non
     * avrebbe senso: i suoi allievi sono pochi e li conosce per nome.
     *
     * ⚠️ Torna `null` per chi non ce l'ha, e chi lo stampa deve gestirlo: uno
     * zero al suo posto scriverebbe «0,00 a iscritto» su una pagina di prezzi.
     *
     * @return ?array{codice: string, nome: string, prezzo_iscritto_cent: int, minimo_iscritti: int}
     */
    public function aConsumo(string $platea = 'palestre'): ?array
    {
        return config("listino.{$platea}.a_consumo");
    }

    /**
     * Il piano piu' conveniente di una platea, per slot.
     *
     * 💡 Serve alla frase «da X a iscritto»: e' il numero che si annuncia, e va
     * preso dai dati invece che scritto a mano — o il giorno che si aggiunge un
     * piano piu' grande, l'annuncio resta indietro.
     */
    public function migliorPrezzoPerSlot(string $platea): int
    {
        return min(array_column($this->piani($platea), 'perSlot'));
    }

    /**
     * L'esempio che vende: cosa paga una palestra e cosa le resta.
     *
     * 💡 **E' il numero che conta davvero.** Una palestra non compra «4,00 a
     * iscritto»: compra «incasso 250 e ne pago 200». Senza la differenza, il
     * listino e' solo un costo.
     *
     * @return array{slot: int, costo: int, ricavo: int, margine: int}
     */
    public function esempio(string $platea = 'palestre', ?int $slot = null): array
    {
        $slot ??= (int) config('listino.esempio_posti');

        $piani = $this->piani($platea);

        // Il piano che copre quegli slot; se nessuno basta, il piu' grande.
        $scelto = null;
        foreach ($piani as $p) {
            if ($p['slot'] >= $slot) {
                $scelto = $p;
                break;
            }
        }
        $scelto ??= $piani[count($piani) - 1];

        $ricavo = $slot * (int) config('listino.rivendita_suggerita_cent');

        return [
            'slot' => $slot,

            // 🆕 20/09/2026: anche **quale** piano, non solo quanto costa. La
            // pagina dei prezzi lo nomina, e senza sarebbe l'unico numero senza
            // un'etichetta accanto.
            'piano' => $scelto,
            'costo' => $scelto['prezzo'],
            'rivendita' => (int) config('listino.rivendita_suggerita_cent'),
            'ricavo' => $ricavo,
            'margine' => $ricavo - $scelto['prezzo'],
        ];
    }

    /**
     * 📣 Quanto costa una visualizzazione pubblicitaria, in centesimi — M5.
     *
     * 🚨 E' il **listino**, non il prezzo di una campagna in corso: quello viene
     * fotografato all'attivazione in `campagne.costo_visualizzazione_cent`.
     * ⚠️ Cambiare questo valore vale solo per le campagne accese **dopo**.
     */
    public function costoVisualizzazione(): int
    {
        return (int) config('listino.pubblicita.costo_visualizzazione_cent', 2);
    }

    /**
     * Il tetto di spesa mensile minimo, in centesimi.
     *
     * 💡 Non e' un modo per far spendere di piu': sotto questa cifra la campagna
     * si spegnerebbe in un giorno, e chi l'ha attivata concluderebbe che la
     * pubblicita' non funziona avendola vista funzionare per poche ore.
     */
    public function budgetMinimoPubblicita(): int
    {
        return (int) config('listino.pubblicita.budget_minimo_cent', 1000);
    }

    /**
     * 🆕 **Il piano su misura** — 20/09/2026.
     *
     * 📌 *«Personalizzato (min. 30 allievi): 2,80€/mese per allievo +
     * 10€/mese»* per i trainer, *«min. 60 … + 15€/mese con trainer
     * illimitati»* per le palestre.
     *
     * 💡 Torna anche `trainerIllimitati`, che è la cosa che quel piano **vende**
     * a una palestra: non è un dettaglio del prezzo, è il motivo per sceglierlo.
     *
     * @return array{codice: string, nome: string, perSlot: int, canone: int, minimo: int, trainerIllimitati: bool}
     */
    public function suMisura(string $platea = 'palestre'): array
    {
        $p = (array) config("listino.{$platea}.a_consumo");

        return [
            'codice' => (string) $p['codice'],
            'nome' => (string) $p['nome'],
            'perSlot' => (int) $p['prezzo_iscritto_cent'],
            'canone' => (int) ($p['canone_cent'] ?? 0),
            'minimo' => (int) $p['minimo_iscritti'],

            // ⚠️ `array_key_exists` e non `?? null`: la chiave c'è **e vale
            // `null`** proprio quando i trainer sono illimitati.
            'trainerIllimitati' => array_key_exists('trainer', $p) && $p['trainer'] === null,
        ];
    }

    /**
     * Quanto costa il su misura con tanti allievi, in centesimi.
     *
     * 🚨 **Sotto il minimo si paga il minimo**, e va detto dove si mostra il
     * numero: chi fa il conto con dieci allievi e legge il prezzo di trenta
     * pensa a un errore, se nessuno glielo spiega.
     */
    public function costoSuMisura(string $platea, int $slot): int
    {
        $misura = $this->suMisura($platea);

        return $misura['canone'] + max($slot, $misura['minimo']) * $misura['perSlot'];
    }

    /**
     * Da quanti allievi conviene il su misura, o `null` se non conviene mai.
     *
     * 💡 È la domanda vera di chi sta scegliendo — «da quando mi conviene?» — e
     * rispondere con una tabella di prezzi lascia il conto da fare a lui.
     *
     * 🚨 Col listino del 20/09 la risposta è **il minimo stesso**: un trainer
     * con 30 allievi paga 94 € su misura e 100 € col Pro. ⚠️ Non è un caso ed è
     * il motivo per cui il minimo è quello: sotto, il su misura sarebbe solo un
     * modo più caro di comprare il piano piccolo.
     */
    public function quandoConvieneIlSuMisura(string $platea): ?int
    {
        $piani = $this->piani($platea);
        $piuGrande = $piani[count($piani) - 1];
        $misura = $this->suMisura($platea);

        return $this->costoSuMisura($platea, $misura['minimo']) < $piuGrande['prezzo']
            ? $misura['minimo']
            : null;
    }
}
