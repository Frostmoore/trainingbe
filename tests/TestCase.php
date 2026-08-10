<?php

namespace Tests;

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

    //
}
