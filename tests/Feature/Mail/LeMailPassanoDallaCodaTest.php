<?php

declare(strict_types=1);

namespace Tests\Feature\Mail;

use App\Enums\UserRole;
use App\Jobs\MandaLaMail;
use App\Mail\BenvenutoMail;
use App\Models\InvitoInPalestra;
use App\Models\RichiestaDiContatto;
use App\Models\RispostaAllaRichiesta;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Tenancy\InvitiInPalestra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 📬 **Le mail passano dalla coda** — voce 62.7, 28/09/2026.
 *
 * ══ 📌 LA DOMANDA DA CUI NASCE ════════════════════════════════════════════
 *
 * 📌 Il committente, 27/09: *«notifiche e email passano per una queue tipo redis?
 * Perché potrebbe essere una buona idea, e se non lo fanno poi succede sempre un
 * casino con le notifiche»*. E il 28/09, quando gli ho detto che le email no:
 * *«E certo che le accodiamo, tutte»*.
 *
 * ══ 🚨 COSA ANDAVA STORTO ═════════════════════════════════════════════════
 *
 * ⛔ Tredici `Mail::to(...)->send()` in `app/`, **zero** accodati. 🚨 E uno stava
 * **dentro il webhook di Stripe**: una connessione SMTP lenta lì significa un
 * webhook in timeout a ~20 s, dichiarato fallito e **rimandato** da Stripe — con
 * il lavoro rifatto su un incasso già registrato.
 *
 * ══ ⚠️ PERCHE' QUESTO TEST DEVE USARE `Queue::fake()` ═════════════════════
 *
 * 🔑 In prova `QUEUE_CONNECTION=sync` (vedi `phpunit.xml`): i job partono **in
 * linea**, quindi la mail viene spedita davvero durante il test. 💡 È voluto — è
 * ciò che tiene verdi le **trentasette** asserzioni `Mail::assertSent` scritte
 * prima di questo giro, senza doverle riscrivere.
 *
 * ⛔ Ma proprio per questo, senza `Queue::fake()` non si vedrebbe la differenza
 * fra «accodata» e «mandata subito»: il risultato sarebbe identico. 🚨 Qui
 * interessa **dove** parte, non **se** parte, quindi la coda va finta.
 */
class LeMailPassanoDallaCodaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🚨 **La mail del pagamento fallito non parte dentro il webhook.**
     *
     * ⚖️ È il caso per cui la coda serve davvero, ed è l'unico in cui la lentezza
     * costa qualcosa di più di un'attesa: un webhook rimandato da Stripe.
     */
    #[Test]
    public function la_mail_del_pagamento_fallito_viene_accodata(): void
    {
        Queue::fake();
        Mail::fake();

        $palestra = $this->creaPalestra('Coda', 'coda');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@coda.test');

        $palestra->forceFill(['proprietario_id' => $capo->getKey()])->save();

        /*
         * ⚠️ Si chiama il metodo privato che manda l'avviso, perché il giro
         * completo vorrebbe un webhook di Stripe firmato: 💡 quello che si prova
         * qui è **dove** parte la mail, non come ci si arriva.
         */
        $cassa = app(Cassa::class);

        $metodo = new \ReflectionMethod($cassa, 'avvisaDelPagamentoFallito');
        $metodo->invoke($cassa, $palestra, 'la fattura del mese');

        Queue::assertPushed(
            MandaLaMail::class,
            fn (MandaLaMail $job): bool => $job->a === $capo->email,
        );

        Mail::assertNothingSent();
    }

    /**
     * 🔑 **E la data «è partita» la scrive il JOB, non chi accoda.**
     *
     * ⛔ Prima `email_inviata_il` si scriveva subito dopo `send()`: era il modo
     * di distinguere «mandata» da «ci abbiamo provato». 🚨 Accodando, in quel
     * punto non si sa più: si sa nel worker.
     *
     * 💡 Quindi il job porta con sé **quale riga segnare**, e la segna **dopo**
     * l'invio riuscito. ⚠️ Senza questo, la colonna direbbe «partita» a una mail
     * ancora in coda — la stessa bugia che quella colonna è nata per togliere.
     */
    #[Test]
    public function il_job_sa_quale_riga_segnare_dopo_l_invio(): void
    {
        Queue::fake();

        $palestra = $this->creaPalestra('Inviti in coda', 'inviti-coda');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@inviti-coda.test');

        $invito = $this->ctx()->runAs($palestra, fn () => InvitoInPalestra::create([
            'tenant_id' => $palestra->getKey(),
            'invitato_da' => $capo->getKey(),
            'token' => str_repeat('a', 32),
            'email' => 'invitato@esempio.test',
            'expires_at' => now()->addDays(7),
        ]));

        app(InvitiInPalestra::class)->mandaIlLink($invito);

        Queue::assertPushed(
            MandaLaMail::class,
            fn (MandaLaMail $job): bool => $job->a === 'invitato@esempio.test'
                && array_key_exists('email_inviata_il', $job->segna ?? [])
                && $job->id === (int) $invito->getKey(),
        );

        self::assertNull(
            $invito->fresh()->email_inviata_il,
            '🚨 La data è stata scritta prima che la mail partisse: direbbe «è partita» '
            .'di una mail che è solo in coda.',
        );
    }

    /**
     * ✅ **E quando il job gira davvero, la colonna si riempie.**
     *
     * ⚠️ Senza questo, il test qui sopra passerebbe anche se la colonna **non si
     * riempisse mai**: direbbe «non è ancora scritta» e avrebbe ragione per il
     * motivo peggiore. 🔑 Qui si esegue il job e si guarda il risultato.
     */
    #[Test]
    public function e_quando_il_job_gira_la_colonna_si_riempie(): void
    {
        Mail::fake();

        $palestra = $this->creaPalestra('Segna', 'segna');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@segna.test');

        $richiesta = RichiestaDiContatto::create([
            'nome' => 'Tizio',
            'email' => 'tizio@esempio.test',
            'messaggio' => 'Ciao',
        ]);

        $risposta = RispostaAllaRichiesta::query()->create([
            'richiesta_di_contatto_id' => $richiesta->getKey(),
            'scritta_da' => $capo->getKey(),
            'oggetto' => 'Ecco',
            'testo' => 'La risposta',
            'mandata' => false,
        ]);

        (new MandaLaMail(
            'tizio@esempio.test',
            new BenvenutoMail($capo, 'persona'),
            modello: RispostaAllaRichiesta::class,
            id: (int) $risposta->getKey(),
            segna: ['mandata' => true],
        ))->handle();

        Mail::assertSent(BenvenutoMail::class);

        /*
         * 🚨 **`assertTrue`, non `assertNotNull`** — e ci sono cascato il 28/09.
         *
         * ⛔ `mandata` è un **booleano** e parte da `false`: `assertNotNull(false)`
         * passa sempre. 💡 Il test era verde e non dimostrava niente — l'ho
         * scoperto **falsificandolo**, togliendo la chiamata che segna la riga:
         * nessun test cadeva.
         *
         * 🔑 È la stessa lezione di `AiApiTest` e del ciclo di Google: un test
         * verde per il motivo sbagliato è peggio di un test che manca, perché
         * nessuno lo riguarda.
         */
        self::assertTrue(
            (bool) $risposta->fresh()->mandata,
            '🚨 Il job ha mandato la mail e non ha segnato la riga: la colonna resterebbe '
            .'a «false» per sempre, e chi guarda crederebbe che non sia partita.',
        );
    }

    /**
     * 🚨 **Nessuno manda mail fuori dal job.**
     *
     * ⛔ Senza questa guardia, `MandaLaMail` sarebbe **una strada consigliata**:
     * chi ha fretta scrive `Mail::to(...)->send()` e la mail torna dentro la
     * richiesta — che è esattamente com'era prima del 28/09.
     *
     * 💡 Guarda le **righe di codice**, non i commenti: nei due servizi degli
     * inviti la vecchia chiamata è **nominata** per spiegare cosa è cambiato, e
     * vietare anche la spiegazione vorrebbe dire cancellare la cosa che serve di
     * più.
     */
    #[Test]
    public function nessuno_manda_mail_fuori_dal_job(): void
    {
        $colpevoli = [];
        $guardati = 0;

        $file = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path())
        );

        foreach ($file as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }

            $relativo = str_replace('\\', '/', substr($f->getPathname(), strlen(base_path()) + 1));

            if ($relativo === 'app/Jobs/MandaLaMail.php') {
                continue;
            }

            $guardati++;

            foreach (file($f->getPathname()) as $n => $riga) {
                $pulita = ltrim($riga);

                if (str_starts_with($pulita, '*') || str_starts_with($pulita, '//')
                    || str_starts_with($pulita, '/*')) {
                    continue;
                }

                if (str_contains($pulita, 'Mail::to(')) {
                    $colpevoli[] = $relativo.':'.($n + 1);
                }
            }
        }

        self::assertGreaterThan(50, $guardati, '⛔ Troppo pochi file guardati: il test non protegge niente.');

        self::assertSame(
            [],
            $colpevoli,
            "🚨 Qualcuno manda mail senza passare dalla coda:\n  ".implode("\n  ", $colpevoli)."\n"
            .'⛔ Dentro una richiesta vuol dire una connessione SMTP mentre qualcuno aspetta — '
            .'e nel webhook di Stripe vuol dire un webhook rimandato.',
        );
    }
}
