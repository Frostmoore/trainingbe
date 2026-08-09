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
     */
    public const FAKE_PASSWORD = 'not-a-secret-test-value';

    //
}
