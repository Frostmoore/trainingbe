<?php

namespace Tests;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * La password usata da tutti i test.
     *
     * NON e' un segreto e non lo e' mai stata: e' scritta cosi' perche' gli
     * analizzatori di credenziali (GitGuardian & co.) non la scambino per una
     * password vera a ogni push. Una stringa che *sembra* una password in un
     * repository genera avvisi a ripetizione, e gli avvisi a ripetizione fanno
     * smettere di leggerli — che e' il modo migliore per non accorgersi di
     * quello vero.
     *
     * ⚠️ **La cifra in fondo non e' decorativa.** Da `v4.9.0` la registrazione
     * pretende lettere **e** numeri (`RegisterRequest`), e senza quel `7` ogni
     * test che registra qualcuno fallirebbe con un 422 — dando la colpa al
     * codice invece che alla password del test.
     *
     * 🚨 Cambiando questo valore va aggiornato anche `.gitguardian.yaml`, che
     * lo elenca fra le corrispondenze da ignorare: sono le due meta' della
     * stessa decisione, e disallinearle rimette gli avvisi che questa costante
     * serviva a togliere.
     */
    public const FAKE_PASSWORD = 'not-a-secret-test-value-7';

    /** Il DB di test di questo processo è stato registrato come aggiornato. */
    private static bool $impronta = false;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * ⚡ **Il broadcast nei test si arrende subito** — 19/09/2026.
         *
         * Il driver resta `reverb` (vedi `phpunit.xml`: serve alla verifica
         * vera dei canali), ma a `127.0.0.1:8081` non ascolta nessuno. Su
         * Windows una connessione rifiutata impiega ~2 s a fallire, e
         * `MessageSent::announce()` la inghiottiva: **2 s per ogni messaggio**
         * scritto in un test, senza nessun errore visibile.
         */
        config(['broadcasting.connections.reverb.client_options' => [
            'connect_timeout' => 0.05,
            'timeout' => 0.1,
        ]]);
        // ⚠️ Il client è già stato creato all'avvio (le rotte dei canali):
        // senza `purge` terrebbe le opzioni di prima. E il `purge` butta via
        // anche i canali registrati su quello vecchio: si rileggono, o
        // /broadcasting/auth risponde 403 a tutti (BroadcastAuthTest).
        $this->app->make(BroadcastManager::class)->purge('reverb');
        require base_path('routes/channels.php');

        /*
         * ⚡ Il DB di test è stato ricreato in questo processo, ed è andata
         * bene: si registra l'impronta, e il prossimo lancio salta i ~16 s
         * della ricreazione (vedi `tests/bootstrap.php`).
         */
        if (! self::$impronta && defined('IMPRONTA_DEL_DB_DI_TEST')) {
            self::$impronta = true;
            @file_put_contents(FILE_IMPRONTA_DEL_DB_DI_TEST, IMPRONTA_DEL_DB_DI_TEST);
        }
    }
}
