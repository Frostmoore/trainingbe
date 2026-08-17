<?php

declare(strict_types=1);

namespace App\Services\Nutrition\Catalogo;

use App\Models\Food;

/**
 * Scrivere nel catalogo mille righe per volta — 17/08/2026.
 *
 * ── 🚨 Perche' esiste, con i numeri misurati ───────────────────────────────
 *
 * La prima importazione scriveva **una riga per volta**: `updateOrCreate` sono
 * due interrogazioni, piu' una terza per il controllo di gerarchia. Ha letto
 * 4.532.767 righe e ne ha scritte 203.577 in **circa sette ore**, cioe' ~180
 * righe al secondo.
 *
 * A blocchi da mille: **~14.000 righe al secondo**, misurate.
 *
 * ── ⚠️ E il degrado che si e' visto dopo, che nessun blocco risolve ────────
 *
 * Portando il catalogo da 160.000 a 1,5 milioni di righe, la velocita' e'
 * scesa progressivamente: +458.000 nella prima ora, +112.000 nella terza,
 * +54.000 nella quarta. 🚨 Non e' un difetto di questa classe: e' l'indice
 * unico su `chiave` che supera la memoria che MySQL gli riserva, e da quel
 * momento **ogni** scrittura va a leggerlo su disco.
 *
 * 💡 Da cui due regole, che valgono piu' del codice qui sotto:
 *
 * 1. **Un'importazione di massa si fa una volta**, e sul server ci si arriva
 *    con un dump — non rieseguendola.
 * 2. **L'aggiornamento periodico non deve mai diventare un'importazione di
 *    massa.** Il job settimanale ha un tetto di 20.000 prodotti per
 *    esecuzione: settantacinque volte meno di quanto e' servito a innescare il
 *    degrado, e quasi tutti aggiornamenti di righe esistenti — che non
 *    spezzano pagine d'indice.
 */
class ScritturaABlocchi
{
    /**
     * Quante righe per volta.
     *
     * 💡 Mille e' il compromesso: una query da mille righe sta comodamente
     * dentro `max_allowed_packet`, e riduce le interrogazioni di tre ordini di
     * grandezza rispetto a una per prodotto.
     */
    public const QUANTE = 1000;

    /**
     * 🚨 Le colonne che un'importazione puo' riscrivere.
     *
     * ⚠️ **`usi` non c'e', ed e' la cosa piu' importante di questo elenco.** E'
     * il conteggio di quante volte le persone hanno scelto quell'alimento, e
     * un'importazione non ha nessun diritto di azzerarlo. Sarebbe il difetto
     * che non fallisce e non si vede: i suggerimenti tornerebbero in ordine
     * alfabetico e nessuno saprebbe perche'.
     *
     * 💡 `conferme` e `pubblico` invece si aggiornano di proposito: un alimento
     * che prima era scritto da una persona sola, e che ora arriva da una fonte
     * dichiarata, e' diventato pubblico davvero.
     */
    private const AGGIORNABILI = [
        'nome', 'marca',
        'kcal_100', 'protein_100', 'carbs_100', 'fat_100',
        'basis', 'origine', 'fonte', 'note',
        'codice_a_barre', 'immagine_url', 'immagine_piccola_url',
        'pubblico', 'conferme', 'updated_at',
    ];

    /** @var array<string, array<string, mixed>> */
    private array $blocco = [];

    private int $scritte = 0;

    /**
     * Mette una riga in coda, e scrive quando il blocco e' pieno.
     *
     * ── ⚠️ Perche' l'indice e' la chiave e non un numero ───────────────────
     *
     * Perche' **lo stesso file contiene la stessa chiave piu' volte**: varianti
     * di formato dello stesso prodotto, stesso nome e stessa marca, codici a
     * barre diversi. 🚨 Due righe con la stessa chiave nel **medesimo** `upsert`
     * fanno fallire l'intera operazione — MySQL non sa quale tenere. Cosi'
     * l'ultima sostituisce la precedente **prima** che il blocco parta, che e'
     * lo stesso esito che dava la scrittura una-per-volta.
     *
     * @param  array<string, mixed>  $riga
     */
    public function aggiungi(array $riga): void
    {
        $this->blocco[$riga['chiave']] = $riga;

        if (count($this->blocco) >= self::QUANTE) {
            $this->scarica();
        }
    }

    /**
     * Scrive quello che resta.
     *
     * 🚨 Va chiamata alla fine, sempre: l'ultimo blocco e' quasi sempre
     * incompleto, e senza si perderebbero fino a mille prodotti **in silenzio**.
     */
    public function scarica(): void
    {
        if ($this->blocco === []) {
            return;
        }

        Food::query()->upsert(array_values($this->blocco), ['chiave'], self::AGGIORNABILI);

        $this->scritte += count($this->blocco);
        $this->blocco = [];
    }

    public function scritte(): int
    {
        return $this->scritte;
    }
}
