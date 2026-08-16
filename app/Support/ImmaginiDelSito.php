<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Le immagini del sito pubblico — 16/08/2026.
 *
 * ── 🚨 Perche' esiste una classe invece di un `<img src>` ──────────────────
 *
 * Perche' le immagini **non ci sono ancora**: vanno generate e caricate a mano
 * in `public/img/sito/`. Un `<img>` scritto secco mostrerebbe l'icona
 * dell'immagine rotta su tutte le pagine fino a quel momento, cioe' il sito
 * sembrerebbe guasto proprio mentre e' solo incompleto.
 *
 * ⚠️ Qui si guarda se il file c'e'. Se c'e', si mostra; se non c'e', il
 * componente disegna un riempimento **che sembra voluto**. In nessuno dei due
 * casi la pagina si rompe, e in nessuno dei due bisogna ricordarsi di cambiare
 * il template il giorno che i file arrivano.
 *
 * 💡 **E il nome del file nell'URL porta la data di modifica** (`?v=...`): senza,
 * chi ha gia' visto il sito continuerebbe a vedere la vecchia immagine dalla
 * cache del browser anche dopo averla sostituita, e non c'e' nessuna catena di
 * build che aggiunga un'impronta al nome.
 */
class ImmaginiDelSito
{
    /** La cartella, sotto `public/`, dove vanno caricati i file. */
    public const CARTELLA = 'img/sito';

    /**
     * Le immagini che il sito si aspetta, con le misure a cui vanno prodotte.
     *
     * 🚨 **E' l'elenco autorevole**, e `public/img/sito/LEGGIMI.md` lo ripete per
     * chi apre la cartella invece del codice. ⚠️ Le misure non sono un
     * suggerimento: sono quelle scritte negli attributi `width`/`height` del
     * componente, e servono a **riservare lo spazio prima che l'immagine
     * arrivi**. Un rapporto diverso fa saltare la pagina mentre si carica.
     *
     * @var array<string, array{larghezza: int, altezza: int, cosa: string}>
     */
    public const ATTESE = [
        'og' => [
            'larghezza' => 1200,
            'altezza' => 630,
            'cosa' => "L'anteprima quando il link viene condiviso (WhatsApp, Telegram, LinkedIn)",
        ],
        'eroe' => [
            'larghezza' => 1600,
            'altezza' => 1200,
            'cosa' => 'Lo sfondo dietro il telefono, in apertura della home',
        ],
        'platea-palestra' => [
            'larghezza' => 800,
            'altezza' => 600,
            'cosa' => 'La scheda «Per le palestre»',
        ],
        'platea-trainer' => [
            'larghezza' => 800,
            'altezza' => 600,
            'cosa' => 'La scheda «Per i trainer indipendenti»',
        ],
        'platea-solo' => [
            'larghezza' => 800,
            'altezza' => 600,
            'cosa' => 'La scheda «Per chi si allena da solo»',
        ],
        'privacy' => [
            'larghezza' => 1200,
            'altezza' => 900,
            'cosa' => 'La sezione «I dati del tuo corpo non stanno sui nostri server»',
        ],
        'prezzi' => [
            'larghezza' => 1600,
            'altezza' => 700,
            'cosa' => 'La fascia in apertura della pagina dei prezzi',
        ],
    ];

    /**
     * 🚨 **I formati ammessi, in ordine di preferenza.**
     *
     * ⚠️ L'ordine conta: se ci sono sia `eroe.webp` sia `eroe.jpg` si prende il
     * `webp`, che a parita' di resa pesa la meta'. Su un server condiviso con
     * altri due siti, il peso delle pagine non e' un dettaglio estetico.
     *
     * 💡 `svg` viene per primo ed e' li' **per il marchio**, non per le
     * fotografie: un segno vettoriale resta nitido a ogni misura, dalla barra
     * in alto all'icona della scheda.
     */
    private const FORMATI = ['svg', 'webp', 'jpg', 'jpeg', 'png'];

    /** @var array<string, string|null> */
    private array $trovate = [];

    /**
     * Il percorso pubblico dell'immagine, oppure `null` se non e' stata caricata.
     *
     * 💡 Il risultato viene tenuto in memoria per la durata della richiesta: la
     * stessa immagine puo' essere chiesta piu' volte nella stessa pagina, e
     * `is_file()` tocca il disco ogni volta.
     */
    public function url(string $nome): ?string
    {
        return $this->trovate[$nome] ??= $this->cerca($nome);
    }

    public function esiste(string $nome): bool
    {
        return $this->url($nome) !== null;
    }

    /**
     * Quali immagini attese mancano ancora.
     *
     * 💡 Serve a `LEGGIMI.md` e ai test: e' la lista della spesa, e non va
     * ricavata a occhio guardando la cartella.
     *
     * @return list<string>
     */
    public function mancanti(): array
    {
        return array_values(array_filter(
            array_keys(self::ATTESE),
            fn (string $nome): bool => ! $this->esiste($nome),
        ));
    }

    /**
     * Il marchio, se e' stato caricato.
     *
     * ── 💡 Perche' NON sta in `ATTESE` ────────────────────────────────────
     *
     * Perche' `ATTESE` e' la lista di quello che **deve** esserci, e
     * `all_seven_images_are_actually_on_disk()` la controlla: metterci dentro
     * il marchio farebbe fallire quel test fino al giorno in cui viene
     * disegnato, e un test rosso che si impara a ignorare smette di servire.
     *
     * ⚠️ Finche' manca, la barra in alto mostra il quadratino disegnato in CSS
     * — che e' lo stesso segno dell'icona della scheda, quindi non e' un
     * segnaposto: e' il marchio provvisorio.
     */
    public function marchio(): ?string
    {
        return $this->url('marchio');
    }

    private function cerca(string $nome): ?string
    {
        // 🚨 Il nome arriva dai template, ma non si accetta niente che non sia
        // un nome: un `..` qui diventerebbe un percorso fuori da `public/`.
        if (preg_match('~^[a-z0-9-]+$~', $nome) !== 1) {
            return null;
        }

        foreach (self::FORMATI as $estensione) {
            $relativo = self::CARTELLA."/{$nome}.{$estensione}";
            $assoluto = public_path($relativo);

            if (is_file($assoluto)) {
                return '/'.$relativo.'?v='.filemtime($assoluto);
            }
        }

        return null;
    }
}
