<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\DailyBurn;
use App\Models\FoodEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Dashboard\DashboardService;
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
     * Le 00:30 del 12 agosto 2026 **a Roma**, cioe' le 22:30 dell'11 in UTC.
     *
     * 💡 E' l'istante in cui l'etichetta locale e quella UTC divergono: il
     * momento esatto in cui il difetto si vedeva.
     */
    private const MEZZANOTTE_E_MEZZA_A_ROMA = '2026-08-11 22:30:00';

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->mario = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test', [
            'timezone' => 'Europe/Rome',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Ferma l'orologio su un istante UTC. */
    private function alleOre(string $istanteUtc): Carbon
    {
        $adesso = Carbon::parse($istanteUtc, 'UTC');
        Carbon::setTestNow($adesso);

        return $adesso;
    }

    // ───────────────────────── il difetto, riprodotto ─────────────────────────

    /**
     * 🚨 **IL TEST DELLA FASE.** Alle 00:30 di Roma «oggi» e' il giorno nuovo.
     *
     * Senza A3 qui usciva `2026-08-11`, perche' `Carbon::today()` rispondeva con
     * la mezzanotte **UTC** — e a quell'ora in Italia e' gia' il giorno dopo.
     */
    #[Test]
    public function at_half_past_midnight_in_rome_today_is_the_new_day(): void
    {
        $this->alleOre(self::MEZZANOTTE_E_MEZZA_A_ROMA);

        $this->comeApp($this->mario)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->assertJsonPath('data.date', '2026-08-12');
    }

    /**
     * 🚨 **La cena delle 00:30 conta in OGGI, non in ieri.**
     *
     * E' la meta' del difetto che si vedeva davvero: chi registrava qualcosa
     * dopo mezzanotte lo vedeva sparire: non era nel giorno che aveva davanti,
     * ed era finito in quello prima — dove non lo andava a cercare nessuno.
     */
    #[Test]
    public function a_meal_eaten_after_midnight_belongs_to_the_new_day(): void
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

        $oggi = $this->comeApp($this->mario)
            ->getJson('/api/v1/diary')
            ->assertOk()
            ->json('data');

        $this->assertSame('2026-08-12', $oggi['date']);
        $this->assertSame(600.0, $oggi['totals']['kcal']);

        // E il giorno prima resta vuoto: la voce non e' in tutti e due.
        $ieri = $this->comeApp($this->mario)
            ->getJson('/api/v1/diary?date=2026-08-11')
            ->assertOk()
            ->json('data');

        $this->assertSame(0.0, $ieri['totals']['kcal']);
    }

    /**
     * ⚠️ **La trappola dell'etichetta**, quella che aveva fermato A3 a meta'.
     *
     * `inizio()` e' un **istante**: per Roma d'estate e' le 22:00 del giorno
     * prima. Chiedergli la data darebbe l'etichetta sbagliata. Questo test fissa
     * la differenza perche' non venga «semplificata» da qualcuno che passa di
     * qui e trova due metodi dove gliene sembra bastare uno.
     */
    #[Test]
    public function the_instant_and_the_label_are_deliberately_different(): void
    {
        $giorno = GiornoLocale::conFuso('2026-08-12', 'Europe/Rome');

        $this->assertSame('2026-08-12', $giorno->etichetta);
        $this->assertSame('2026-08-11 22:00:00', $giorno->inizio()->toDateTimeString());
        $this->assertSame('2026-08-12 21:59:59', $giorno->fine()->toDateTimeString());

        // 🚨 La riga che dimostra il difetto: sull'istante, la data e' IERI.
        $this->assertSame('2026-08-11', $giorno->inizio()->toDateString());
    }

    // ───────────────────────── il fuso e' della PERSONA ─────────────────────────

    /**
     * 🚨 Due persone, lo stesso istante, **due giorni diversi** — ed e' corretto.
     *
     * E' la ragione per cui il fuso sta su `users` e non su una costante: nella
     * Parte B arrivano utenti senza palestra, e gia' oggi chi viaggia deve
     * vedere la giornata del posto in cui si trova.
     */
    #[Test]
    public function two_people_in_different_timezones_see_different_days(): void
    {
        $this->alleOre(self::MEZZANOTTE_E_MEZZA_A_ROMA);

        $newyorkese = $this->creaUtente($this->alfa, UserRole::Member, 'john@alfa.test', [
            'timezone' => 'America/New_York',
        ]);

        $this->comeApp($this->mario)
            ->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.date', '2026-08-12');

        // A New York sono ancora le 18:30 dell'11.
        $this->comeApp($newyorkese)
            ->getJson('/api/v1/dashboard')
            ->assertJsonPath('data.date', '2026-08-11');
    }

    /**
     * `users.timezone` a `null` non e' un buco: significa «quello della palestra».
     */
    #[Test]
    public function a_null_user_timezone_falls_back_to_the_gym(): void
    {
        $this->alfa->update(['timezone' => 'America/New_York']);

        $senzaFuso = $this->creaUtente($this->alfa, UserRole::Member, 'anna@alfa.test');

        $this->assertSame('America/New_York', $senzaFuso->fresh()->fusoOrario());
    }

    /**
     * Chi **non ha palestra** ricade sulla chiave di configurazione — mai su UTC.
     *
     * ⚠️ `tenants.timezone` e' `NOT NULL` con default `Europe/Rome`, quindi il
     * secondo anello della catena non puo' mancare per un iscritto. Il terzo
     * anello serve a chi un tenant non ce l'ha: oggi il super admin, e **dalla
     * Parte B i free user** — che sono la ragione per cui il fuso e' stato messo
     * sulla persona invece che sulla palestra.
     *
     * 🚨 Il ripiego non e' UTC ed e' deliberato: UTC e' l'unico valore
     * *sicuramente* sbagliato per un prodotto italiano.
     */
    #[Test]
    public function someone_without_a_gym_falls_back_to_the_configured_timezone(): void
    {
        $senzaPalestra = new User(['timezone' => null]);

        $this->assertSame(config('app.display_timezone'), $senzaPalestra->fusoOrario());
        $this->assertNotSame('UTC', $senzaPalestra->fusoOrario());
    }

    // ───────────────────────── l'ora, non solo la data ─────────────────────────

    /**
     * 🚨 **La meta' meno visibile del difetto: l'ORA nel contesto dell'AI.**
     *
     * Una data sbagliata si nota. Un'ora sbagliata di due no: il consiglio
     * sembra soltanto poco azzeccato. Alle 20:00 di Roma il modello riceveva
     * «18:00», e diceva di stare leggeri a chi aveva gia' cenato.
     */
    #[Test]
    public function the_hour_in_the_summary_is_the_local_one(): void
    {
        // Le 20:00 a Roma = le 18:00 UTC.
        $adesso = $this->alleOre('2026-08-12 18:00:00');

        $riepilogo = app(DashboardService::class)->forToday($this->mario, $adesso);

        $this->assertSame(20, $riepilogo['hour']);

        // La giornata sveglia (6→23) alle 20 e' passata all'82%, non al 71%
        // che darebbero le 18.
        $this->assertSame(82, $riepilogo['day_progress_pct']);
    }

    // ───────────────────────── le colonne `date` ─────────────────────────

    /**
     * ⚠️ L'altra meta': `daily_burns.date` e' un'**etichetta**, e va scritta con
     * il giorno locale. Con l'istante si sarebbe salvato l'11.
     */
    #[Test]
    public function the_manual_burn_is_written_on_the_local_day(): void
    {
        $this->alleOre(self::MEZZANOTTE_E_MEZZA_A_ROMA);

        $this->comeApp($this->mario)
            ->postJson('/api/v1/daily-burn', ['kcal' => 800])
            ->assertCreated()
            ->assertJsonPath('data.date', '2026-08-12');

        $riga = DailyBurn::withoutGlobalScopes()->where('user_id', $this->mario->id)->sole();

        $this->assertSame('2026-08-12', $riga->date->toDateString());
    }

    /**
     * 🚨 **Il giorno che l'utente ha davanti non e' «futuro»** — anche se per
     * UTC lo e'.
     *
     * Prima la regola era `before_or_equal:today`, che confronta con il giorno
     * di Greenwich: alle 00:30 di Roma questa richiesta veniva rifiutata con
     * «la data non puo' essere futura» su una data che era **oggi**.
     */
    #[Test]
    public function the_users_today_is_never_rejected_as_a_future_date(): void
    {
        $this->alleOre(self::MEZZANOTTE_E_MEZZA_A_ROMA);

        $this->comeApp($this->mario)
            ->postJson('/api/v1/daily-burn', ['date' => '2026-08-12', 'kcal' => 500])
            ->assertCreated();

        // Domani invece si rifiuta davvero.
        $this->comeApp($this->mario)
            ->postJson('/api/v1/daily-burn', ['date' => '2026-08-13', 'kcal' => 500])
            ->assertStatus(422);
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
