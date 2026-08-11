<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Buste cifrate finte, per i test del backend — S6.
 *
 * ── 🚨 Perche' finte, e perche' va benissimo cosi' ─────────────────────────
 *
 * Produrre una busta **vera** qui dentro vorrebbe dire implementare X25519 e
 * XSalsa20-Poly1305 in PHP soltanto per i test — cioe' scrivere crittografia a
 * mano, che e' la cosa che questo piano vieta esplicitamente.
 *
 * ⚠️ E non servirebbe a niente, perche' **non e' quello che il backend deve
 * dimostrare**. Il backend deve dimostrare che *trasporta e non interpreta*: che
 * accetta una busta, la restituisce identica, e rifiuta il testo in chiaro. Che
 * la busta sia poi davvero apribile solo dai due interessati e' un fatto che
 * vive in Dart, ed e' provato la' (`test/crypto/`), dove sta la libreria.
 *
 * 💡 Il corpo finto e' **opaco davvero** — il digest di un'etichetta, non
 * l'etichetta in base64. Se fosse decodificabile, il test «la palestra non
 * legge» proverebbe il contrario di quello che dice.
 *
 * ⚠️ **Niente stringhe cifrate scritte a mano nel sorgente**: si generano qui.
 * Un blob base64 committato somiglia a una credenziale, e gli scanner dei
 * repository lo segnalano — una segnalazione falsa alla settimana e' il modo
 * piu' rapido per smettere di leggerle.
 */
trait ScriveBusteCifrate
{
    /**
     * Il payload che l'app manderebbe per un messaggio.
     *
     * @return array{envelope_version: int, nonce: string, body: string}
     */
    protected function busta(string $etichetta): array
    {
        return [
            'envelope_version' => 1,
            // 24 byte come vuole `crypto_box`, 32 caratteri in base64.
            'nonce' => base64_encode(substr(hash('sha256', "nonce:{$etichetta}", true), 0, 24)),
            'body' => $this->corpoDi($etichetta),
        ];
    }

    /**
     * Il corpo opaco corrispondente a un'etichetta — deterministico.
     *
     * Serve per confrontare: «il messaggio tornato indietro e' quello che ho
     * mandato» si verifica senza che il testo sia mai leggibile.
     */
    protected function corpoDi(string $etichetta): string
    {
        return base64_encode(hash('sha256', "busta:{$etichetta}", true));
    }
}
