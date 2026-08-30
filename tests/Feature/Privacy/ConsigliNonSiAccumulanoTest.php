<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Enums\UserRole;
use App\Models\AiAdvice;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    protected function setUp(): void
    {
        parent::setUp();

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
         * #! **Il giorno dell'UTENTE, non quello di UTC** - 19/08/2026.
         *
         * Qui c'era `now()->toDateString()`, cioe' la data in UTC. /!\ Il
         * consiglio invece nasce con `giornoDiOggi()`, che usa il fuso della
         * persona: fra le 22:00 UTC e la mezzanotte, a Roma e' gia' domani, e
         * questo test falliva con "atteso 2026-08-18, ricevuto 2026-08-19".
         *
         * * Non era un difetto del codice - il codice aveva ragione, ed e'
         * esattamente il difetto A3 che `giornoDiOggi()` esiste per chiudere.
         * Era il test a guardare l'orologio sbagliato, e falliva solo due ore
         * al giorno.
         */
        $this->assertSame(
            $this->iscritto->fresh()->giornoDiOggi()->etichetta,
            $rimaste->first()->date->toDateString(),
        );
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
