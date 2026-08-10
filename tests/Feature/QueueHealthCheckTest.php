<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\QueueSelfTest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * `queue:health` — il controllo che dice se un worker sta davvero lavorando.
 *
 * 🚨 Il caso che conta e' il **fallimento**: un controllo di salute che dice
 * «tutto bene» quando la coda e' ferma e' peggio di nessun controllo, perche'
 * toglie il sospetto proprio a chi stava per andare a guardare.
 */
class QueueHealthCheckTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ⚠️ In test la coda e' `sync`: il job viene eseguito subito, quindi il
     * comando trova il marcatore al primo giro. E' il percorso «tutto bene».
     */
    #[Test]
    public function con_la_coda_che_funziona_esce_a_zero(): void
    {
        $this->artisan('queue:health', ['--wait' => 2])
            ->expectsOutputToContain('Coda viva')
            ->assertSuccessful();
    }

    /**
     * 🚨 Il caso vero: il job resta accodato e nessuno lo esegue.
     *
     * `Queue::fake()` lo intercetta senza eseguirlo, che e' esattamente cio'
     * che succede quando il worker e' morto.
     */
    #[Test]
    public function senza_nessun_worker_fallisce_e_dice_dove_guardare(): void
    {
        Queue::fake();

        $this->artisan('queue:health', ['--wait' => 1])
            ->expectsOutputToContain('Nessun worker')
            ->expectsOutputToContain('supervisorctl')
            ->assertFailed();

        Queue::assertPushed(QueueSelfTest::class);
    }

    /**
     * 🚨 Il marcatore cambia a ogni esecuzione.
     *
     * Con una chiave fissa, il valore lasciato dall'esecuzione precedente
     * farebbe passare il controllo anche a coda ferma — cioe' un controllo che
     * mente proprio nel caso che deve intercettare.
     */
    #[Test]
    public function un_esito_precedente_non_fa_passare_un_controllo_nuovo(): void
    {
        Cache::put(QueueSelfTest::chiaveCache('marcatore-vecchio'), now()->toIso8601String(), 300);

        Queue::fake();

        $this->artisan('queue:health', ['--wait' => 1])->assertFailed();
    }

    /** Il job non lascia residui: il marcatore si cancella appena letto. */
    #[Test]
    public function non_lascia_niente_in_cache(): void
    {
        $this->artisan('queue:health', ['--wait' => 2])->assertSuccessful();

        $rimasti = collect(Cache::get('queue-self-test.*'))->filter();

        $this->assertTrue($rimasti->isEmpty());
    }
}
