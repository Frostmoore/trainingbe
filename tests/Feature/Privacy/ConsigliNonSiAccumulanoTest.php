<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Enums\UserRole;
use App\Models\AiAdvice;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tempo\FasciaDelConsiglio;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * I consigli non si accumulano — 16/08/2026.
 *
 * ── 🚨 Una cache a cui nessuno aveva dato una scadenza ────────────────────
 *
 * `ai_advices` **e' una cache**: serve a non pagare due volte lo stesso
 * consiglio nello stesso giorno, e `AiAdvice::cached()` filtra
 * `whereDate('date', $oggi)` — non guarda **mai** indietro.
 *
 * ⚠️ Ma nessuno la potava. Ogni consiglio mai generato restava in tabella per
 * sempre: righe che nessun codice avrebbe piu' letto, e che l'unico altro
 * lettore (`AccountEraser`) tocca solo per cancellarle.
 *
 * 🚨 **E non e' peso morto qualunque.** Un consiglio dice *«hai mangiato 1.400
 * kcal, ti mancano proteine, ieri non ti sei allenato»*: e' il testo piu' intimo
 * che il server contenga, e stava li' a tempo indeterminato **senza servire a
 * niente**.
 *
 * 💡 La domanda giusta l'ha fatta il committente: *«perche' dovremmo salvare il
 * consiglio del giorno? L'utente lo vede quel giorno e via»*. Aveva ragione — e
 * la versione precedente di questo lavoro proponeva di tenerne **90 giorni**,
 * cioe' ottimizzava *quanti* invece di chiedersi *perche'*.
 */
final class ConsigliNonSiAccumulanoTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $palestra;

    private User $iscritto;

    /**
     * 🚨 **L'orologio si congela, e non e' pignoleria** — 03/09/2026.
     *
     * ⛔ Questo file e' stato rosso fra mezzanotte e le 09:00 **senza che niente
     * fosse rotto**, ed e' la terza volta che inciampa sullo stesso scoglio:
     *
     * | Quando | Cosa succedeva |
     * |---|---|
     * | 19/08 | Asseriva `now()->toDateString()`, cioe' la data **UTC**: falliva due ore al giorno |
     * | 03/09 | Asseriva la data di **oggi**, ma da 3b-AB la chiave e' la **fascia**, e quella delle 22 scavalca la mezzanotte: fra le 00:00 e le 09:00 il consiglio di adesso porta legittimamente la data di **ieri** |
     *
     * ⚠️ E c'era un secondo guaio: [vecchio(1)] fabbrica una riga con fascia
     * `ieriT22`, che in quella finestra **e' la fascia corrente** — il fixture
     * collideva con cio' che il test stava per generare.
     *
     * 💡 Le 15:00 sono un'ora qualunque **dentro** una fascia (quella delle 14),
     * lontana da tutti e tre i confini: cosi' «ieri» e' davvero ieri e la fascia
     * di adesso non somiglia a nessun fixture.
     *
     * 🚨 Un test che dipende da che ora e' quando lo lanci e' un test che, il
     * giorno che diventa rosso davvero, nessuno crede.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-03 13:00:00', 'UTC'));

        $this->aiFinta();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'iscritto@alfa.test');
        $this->iscritto->accendiLAi();
    }

    /**
     * Un consiglio di un giorno passato, come se fosse rimasto li'.
     *
     * ⚠️ **Con la sua fascia** — 3b-AB. Dal 30/08/2026 la chiave della cache e'
     * `(utente, fascia, tipo)`: una riga senza fascia non e' «una riga vecchia
     * qualunque», e' una riga che non potrebbe esistere.
     */
    private function vecchio(int $giorniFa): AiAdvice
    {
        $giorno = now()->subDays($giorniFa)->toDateString();

        return AiAdvice::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->getKey(),
            'user_id' => $this->iscritto->getKey(),
            'date' => $giorno,
            'fascia' => $giorno.'T22',
            'kind' => 'daily',
            'context_hash' => 'vecchio'.$giorniFa,
            'body' => 'Ti mancano 30 g di proteine.',
            'model' => 'finto',
        ]);
    }

    #[Test]
    public function generating_todays_advice_throws_away_the_old_ones(): void
    {
        $this->vecchio(1);
        $this->vecchio(30);
        $this->vecchio(400);

        $this->assertSame(3, AiAdvice::withoutGlobalScopes()->count());

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice')
            ->assertOk();

        /*
         * 🎯 Resta **solo quello di oggi**. Non 90 giorni, non un mese: uno.
         * Quello che l'utente sta guardando adesso.
         */
        $rimaste = AiAdvice::withoutGlobalScopes()->get();

        $this->assertCount(1, $rimaste);

        /*
         * 🚨 **La data della FASCIA, non quella di oggi** — 3b-AB.
         *
         * ⚠️ Qui c'era `giornoDiOggi()->etichetta`, che era la correzione del
         * 19/08 al difetto A3 (prima c'era `now()->toDateString()`, cioe' UTC).
         * 💡 Giusta allora, e non piu' adesso: da 3b-AB la chiave della cache e'
         * `(utente, fascia, tipo)`, e **la fascia delle 22 scavalca la
         * mezzanotte**. Fra le 00:00 e le 09:00 il consiglio di adesso porta la
         * data di ieri, ed e' esattamente cio' che deve fare.
         *
         * 🚨 Si asserisce quindi la stessa cosa che il codice usa per scriverla:
         * la fascia. ⛔ Riscrivere la regola qui in un'altra forma vorrebbe dire
         * un test che dice «giusto» a due cose diverse.
         */
        $fascia = FasciaDelConsiglio::adesso($this->iscritto->fresh());

        $this->assertSame($fascia->etichetta(), $rimaste->first()->fascia);
        $this->assertSame($fascia->giorno->etichetta, $rimaste->first()->date->toDateString());
    }

    #[Test]
    public function it_only_touches_the_advice_of_that_person(): void
    {
        $altro = $this->creaUtente($this->palestra, UserRole::Member, 'altro@alfa.test');

        AiAdvice::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->getKey(),
            'user_id' => $altro->getKey(),
            'date' => now()->subDay()->toDateString(),
            'fascia' => now()->subDay()->toDateString().'T22',
            'kind' => 'daily',
            'context_hash' => 'di-un-altro',
            'body' => 'Il consiglio di ieri di un\'altra persona.',
            'model' => 'finto',
        ]);

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice')
            ->assertOk();

        /*
         * 🚨 **La potatura e' per persona, non globale.** Una `delete()` senza
         * il filtro sull'utente cancellerebbe il consiglio di **tutti** ogni
         * volta che qualcuno apre l'app — e funzionerebbe benissimo, nel senso
         * che nessuno se ne accorgerebbe: il consiglio si rigenera, e ognuno
         * pagherebbe una chiamata in piu' senza sapere perche'.
         */
        $this->assertSame(
            1,
            AiAdvice::withoutGlobalScopes()->where('user_id', $altro->getKey())->count(),
        );
    }

    #[Test]
    public function todays_cache_still_works(): void
    {
        // ⚠️ La potatura non deve buttare via il motivo per cui la tabella
        // esiste: due aperture nello stesso giorno restano **una** chiamata.
        $this->comeApp($this->iscritto->fresh())->getJson('/api/v1/ai/advice')->assertOk();

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice')
            ->assertOk()
            ->assertJsonPath('data.cached', true);

        $this->assertSame(1, AiAdvice::withoutGlobalScopes()->count());
    }
}
