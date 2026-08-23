<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\FoodEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tempo\GiornoLocale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * A3 — **il confine del giorno e' quello di chi guarda**.
 *
 * ── 🚨 Perche' questo file esiste ──────────────────────────────────────────
 *
 * Il difetto era: dopo mezzanotte «Oggi» mostrava ieri. Ma **di giorno
 * funzionava comunque**, e questa e' la ragione per cui era sopravvissuto a una
 * suite verde di quasi quattrocento test: nessuno di quelli guardava mai
 * l'applicazione in un'ora in cui il fuso fa differenza.
 *
 * Ogni test qui dentro **ferma l'orologio** con `Carbon::setTestNow()` in un
 * istante scelto apposta. Senza quello non verificano niente: passerebbero
 * anche con il difetto in piedi, per undici ore su ventiquattro.
 *
 * ── ⚠️ Le due meta' del difetto ───────────────────────────────────────────
 *
 * Un giorno e' **etichetta** e **finestra** insieme, e si sbaglia in due versi
 * opposti — entrambi verificati qui:
 *
 * 1. usare la finestra UTC dove serviva quella locale (la cena che scompare);
 * 2. chiedere `->toDateString()` a un istante che e' gia' l'inizio del giorno
 *    locale — che per Roma sono **le 22:00 del giorno prima**, quindi
 *    l'etichetta di **ieri**. E' lo stesso difetto spostato di un metodo.
 */
class FusoOrarioTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $mario;

    /**
     * 🚨 Le 00:30 del 12 agosto **a Roma** — cioe' le 22:30 dell'11 in UTC.
     *
     * ⚠️ E' l'istante che fa la differenza: in UTC il giorno e' ancora l'11, e
     * un raggruppamento fatto su quella colonna mette la cena nella casella del
     * giorno prima. Di giorno lo stesso codice funziona, ed e' il motivo per cui
     * il difetto era sopravvissuto a una suite verde.
     */
    private const MEZZANOTTE_E_MEZZA_A_ROMA = '2026-08-12 00:30:00';

    /**
     * ⛔ **RICOSTRUITO IL 23/08/2026.**
     *
     * 🚨 Questo file dichiarava `$alfa` e `$mario` **senza inizializzarli mai**,
     * e chiamava un `alleOre()` che non esisteva: il test del calendario **non
     * e' mai stato eseguibile**, e la suite lo riportava come *errore* invece
     * che come fallimento — che e' il modo in cui e' passato inosservato.
     *
     * ⚠️ Un test che non gira e' peggio di un test che manca: il file esiste, il
     * commento in cima spiega un difetto vero e circostanziato, e chi lo legge
     * crede che quel difetto sia sorvegliato.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra();

        /*
         * 💡 Il fuso si mette **sull'utente**, non sul tenant: e' il primo
         * anello della catena che `User::fusoOrario()` percorre
         * (`users.timezone` → `tenants.timezone` → `Europe/Rome`), ed e' quello
         * che decide davvero.
         */
        $this->mario = $this->creaUtente(
            $this->alfa,
            UserRole::Member,
            'mario@demo.test',
            ['timezone' => 'Europe/Rome'],
        );
    }

    protected function tearDown(): void
    {
        /*
         * ⚠️ **L'orologio fermo non si sgela da solo.** Senza questa riga il
         * test successivo eredita il 12 agosto 2026 e sbaglia per una ragione
         * che non ha niente a che vedere con lui — il genere di rosso che si
         * insegue nel file sbagliato.
         */
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Ferma l'orologio a un'ora di Roma, e restituisce quell'istante. */
    private function alleOre(string $oraDiRoma): Carbon
    {
        $adesso = Carbon::parse($oraDiRoma, 'Europe/Rome');

        Carbon::setTestNow($adesso);

        return $adesso;
    }

    // ───────────────────────── il calendario ─────────────────────────

    /**
     * 🚨 Il raggruppamento delle celle era **in UTC**: la cena delle 00:30
     * finiva nella casella del giorno prima.
     *
     * Sul calendario e' il difetto piu' visibile di tutti, perche' si vede la
     * casella sbagliata **accanto** a quella giusta.
     */
    #[Test]
    public function the_calendar_puts_the_after_midnight_meal_in_the_right_cell(): void
    {
        $adesso = $this->alleOre(self::MEZZANOTTE_E_MEZZA_A_ROMA);

        $this->ctx()->runAs($this->alfa, fn () => FoodEntry::create([
            'user_id' => $this->mario->getKey(),
            'eaten_at' => $adesso,
            'meal' => 'dinner',
            'description' => 'Avanzi di mezzanotte',
            'grams' => 200,
            'kcal' => 600,
        ]));

        $celle = collect(
            $this->comeApp($this->mario)
                ->getJson('/api/v1/calendar?month=2026-08')
                ->assertOk()
                ->json('data.days')
        );

        $this->assertSame(600, $celle->firstWhere('date', '2026-08-12')['kcal']);
        $this->assertNull($celle->firstWhere('date', '2026-08-11')['kcal']);

        // E il pallino di «oggi» sta sulla casella giusta.
        $this->assertTrue($celle->firstWhere('date', '2026-08-12')['today']);
        $this->assertFalse($celle->firstWhere('date', '2026-08-11')['today']);
    }

    // ───────────────────────── l'ora legale ─────────────────────────

    /**
     * 🚨 **Il 25 ottobre 2026 a Roma dura 25 ore.**
     *
     * E' la ragione per cui `GiornoLocale` conserva l'**etichetta** e ricalcola
     * la finestra, invece di conservare un istante e sommarci 86.400 secondi:
     * quel calcolo cadrebbe alle 23:00 del giorno prima, e il giorno dopo
     * l'ora legale sparirebbe dal calendario.
     */
    #[Test]
    public function adding_a_day_survives_the_daylight_saving_change(): void
    {
        $sabato = GiornoLocale::conFuso('2026-10-24', 'Europe/Rome');

        $this->assertSame('2026-10-25', $sabato->piuGiorni(1)->etichetta);
        $this->assertSame('2026-10-26', $sabato->piuGiorni(2)->etichetta);

        // L'offset cambia dentro la finestra: il giorno del cambio comincia con
        // +02:00 e finisce con +01:00, e dura percio' un'ora in piu'.
        $giornoDelCambio = GiornoLocale::conFuso('2026-10-25', 'Europe/Rome');

        $this->assertSame('2026-10-24 22:00:00', $giornoDelCambio->inizio()->toDateTimeString());
        $this->assertSame('2026-10-25 22:59:59', $giornoDelCambio->fine()->toDateTimeString());
    }

    /** L'intervallo fra due giorni non salta ne' duplica quello del cambio d'ora. */
    #[Test]
    public function the_day_range_covers_the_daylight_saving_day_exactly_once(): void
    {
        $giorni = GiornoLocale::conFuso('2026-10-24', 'Europe/Rome')
            ->finoA(GiornoLocale::conFuso('2026-10-27', 'Europe/Rome'));

        $this->assertSame(
            ['2026-10-24', '2026-10-25', '2026-10-26', '2026-10-27'],
            array_map(fn (GiornoLocale $g): string => $g->etichetta, $giorni),
        );
    }
}
