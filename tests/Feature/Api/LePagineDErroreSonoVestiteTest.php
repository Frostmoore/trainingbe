<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Il sito ha una pagina per gli indirizzi che non esistono -- 14/09/2026.
 *
 * -- COSA C'ERA PRIMA --
 *
 * Niente. Qualunque indirizzo sbagliato dava il bianco di Laravel: "404 | Not
 * Found" in mezzo allo schermo, senza intestazione, senza colori e senza NESSUN
 * modo di tornare indietro.
 *
 * Trovato il 14/09 aprendo dal telefono un indirizzo dell'app (/calendario). Le
 * rotte dell'app non sono collegamenti del sito -- solo /invito-palestra lo e'
 * -- e chi ne riceve uno trovava una pagina che non somigliava a niente.
 *
 * -- COSA PROVA QUESTO FILE --
 *
 * Non che la pagina esista: quello lo direbbe il file da solo. Prova che sia
 * VESTITA COME IL SITO e che dia una strada. Una pagina d'errore senza un link
 * e' un vicolo cieco con un titolo piu' bello.
 */
final class LePagineDErroreSonoVestiteTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function un_indirizzo_che_non_esiste_da_una_pagina_vera(): void
    {
        $risposta = $this->get('/questo-non-esiste-di-sicuro');

        $risposta->assertNotFound();

        // 🚨 Vestita come il sito: la barra in alto c'e'.
        $risposta->assertSee('Training Companion', escape: false);

        // 🚨 E una strada per uscirne.
        $risposta->assertSee('Torna alla home', escape: false);
    }

    /**
     * ⛔ E **non** e' piu' la pagina nuda di Laravel.
     *
     * 💡 Il test guarda cosa NON c'e': se un giorno qualcuno togliesse la vista,
     * il 404 tornerebbe quello di serie e gli altri due controlli qui sopra
     * fallirebbero — ma questo dice **perche'**.
     */
    #[Test]
    public function e_non_e_piu_quella_nuda(): void
    {
        $this->get('/questo-non-esiste-di-sicuro')
            ->assertDontSee('404 | Not Found', escape: false);
    }

    /**
     * 🚨 **Il caso vero per cui la pagina esiste**: un indirizzo dell'app aperto
     * nel browser.
     *
     * ⚠️ `/calendario`, `/diario`, `/messaggi` sono rotte dell'**app**, non del
     * sito: non sono nel filtro dei collegamenti (lo e' solo `/invito-palestra`)
     * e qui rispondono 404. 💡 Chi ci arriva deve almeno capire perche'.
     */
    #[Test]
    public function un_indirizzo_dell_app_spiega_che_sta_nell_app(): void
    {
        $this->get('/calendario')
            ->assertNotFound()
            ->assertSee('dentro l\'applicazione', escape: false);
    }
}
