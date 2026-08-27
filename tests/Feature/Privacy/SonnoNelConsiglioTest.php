<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * Il sonno nel contesto del consiglio del giorno — 16/08/2026.
 *
 * ── 🚨 Questa classe difende una porta che era stata chiusa apposta ───────
 *
 * `sleep` era nel contesto del consiglio, ed e' stato tolto in **S1.5** per la
 * decisione D9. Il commento in `contestoConsiglio()` diceva: *«chi volesse
 * rimetterli deve prima leggere §C12, dove c'e' la ragione legale per cui non ci
 * sono»*.
 *
 * ✅ §C12 e' stata riletta. Dice tre cose:
 *
 * | | |
 * |---|---|
 * | Il sonno **e'** dato dell'art. 9 | basta la **possibilita' di dedurre** (Corte UE C-184/20) |
 * | Il transito **e'** trattamento | art. 4(2): «non lo conserviamo» abbassa il rischio, non toglie dal GDPR |
 * | Serve **una casella separata** | e nient'altro: nessuna istituzione, nessuna notifica |
 *
 * 🎯 Quindi la riapertura e' legittima **a condizione che** il consenso sia
 * separato, revocabile, e che senza di esso il dato non parta. Questi test sono
 * quella condizione, resa verificabile.
 */
final class SonnoNelConsiglioTest extends TestCase
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
     * Chiede il consiglio e restituisce **il contesto che il modello ha
     * davvero ricevuto**.
     *
     * ── 🚨 Perche' il doppio si registra QUI e non in `setUp()` ────────────
     *
     * `aiFinta()` costruisce un'istanza **nuova** a ogni chiamata e la registra
     * al posto della precedente. ⚠️ Registrarla in `setUp()` e rileggerla dopo
     * la richiesta non funziona: fra le due cose il gestore puo' aver gia'
     * risolto e memorizzato un'altra istanza, e quella che ha registrato la
     * chiamata non e' quella che si sta guardando.
     *
     * 💡 Ci sono cascato scrivendo questa classe, e il sintomo era ingannevole:
     * **zero chiamate registrate** mentre la risposta conteneva un consiglio
     * appena generato (`cached: false`). Sembrava «il sonno non parte», ed era
     * «stai guardando l'oggetto sbagliato».
     *
     * 🎯 Registrare subito prima della richiesta e' il modo in cui lo fanno
     * tutti gli altri test di questa suite. Quando un pattern esiste gia' e
     * funziona, discostarsene va motivato — e qui non c'era motivo.
     *
     * @return array<string, mixed>
     */
    private function contestoDopoAverChiesto(string $query = ''): array
    {
        $finto = $this->aiFinta();

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice'.$query)
            ->assertOk();

        $chiamate = array_values(array_filter(
            $finto->calls,
            static fn (array $c): bool => $c['method'] === 'dailyAdvice',
        ));

        $this->assertNotSame([], $chiamate, 'il modello non e\' stato chiamato: il test non prova niente');

        /*
         * ⚠️ `['args']` e **non** `['args'][0]`: `FakeAiProvider::record()`
         * registra il contesto **cosi' com'e'**, non dentro una lista di
         * argomenti. 🚨 Con `[0]` si legge sempre `null`, quindi un array vuoto,
         * quindi «il sonno non e' arrivato» anche quando e' arrivato — ed e'
         * esattamente l'ora che ci ho perso.
         */
        return (array) ($chiamate[array_key_last($chiamate)]['args'] ?? []);
    }

    // ═══════════════ il consenso separato ═══════════════

    #[Test]
    public function without_the_sleep_consent_the_sleep_does_not_leave(): void
    {
        $contesto = $this->contestoDopoAverChiesto('?hours=6.5&quality=scarsa');

        /*
         * 🚨 **Il consenso all'AI non basta.** Chi accetta che una frase sul
         * pranzo vada a un modello non ha con cio' accettato che ci vada quanto
         * e come dorme: sono due decisioni di intimita' diversa, e l'art. 7
         * vieta il consenso a pacchetto.
         *
         * ⚠️ E l'app manda il dato lo stesso — non deve saperlo lei se puo': la
         * decisione sta sul server, che e' l'unico posto dove non si aggira.
         */
        $this->assertArrayNotHasKey('recovery', $contesto);
    }

    #[Test]
    public function with_the_consent_it_travels(): void
    {
        $this->iscritto->registraConsenso('sleep_ai_consent_at', true);

        $contesto = $this->contestoDopoAverChiesto('?hours=6.5&quality=scarsa');

        $this->assertSame(6.5, $contesto['recovery']['hours'] ?? null);
        $this->assertSame('scarsa', $contesto['recovery']['quality'] ?? null);
    }

    #[Test]
    public function revoking_the_ai_consent_takes_the_sleep_one_with_it(): void
    {
        $this->iscritto->registraConsenso('sleep_ai_consent_at', true);

        $this->comeApp($this->iscritto->fresh())
            ->patchJson('/api/v1/account/consents', ['ai' => false])
            ->assertOk();

        /*
         * 🚨 Senza questa regola resterebbe una data su un consenso appeso a una
         * funzione spenta — e il giorno che l'AI si riaccende tornerebbe attivo
         * **senza che nessuno l'abbia riconfermato**.
         *
         * 💡 E' la conseguenza della subordinazione: un consenso figlio che non
         * puo' valere senza il padre deve cadere con lui.
         */
        $this->assertNull($this->iscritto->fresh()->sleep_ai_consent_at);
    }

    #[Test]
    public function the_consent_is_a_date_not_a_flag(): void
    {
        $this->comeApp($this->iscritto->fresh())
            ->patchJson('/api/v1/account/consents', ['sleep_ai' => true])
            ->assertOk()
            // ⚠️ L'art. 7(1) chiede di poter **dimostrare** il consenso: un
            // `true` non dice quando, quindi non dice sotto quale informativa.
            ->assertJsonPath('data.sleep_ai', fn (?string $v): bool => $v !== null);

        $this->assertNotNull($this->iscritto->fresh()->sleep_ai_consent_at);
    }

    #[Test]
    public function nobody_starts_with_it_given(): void
    {
        // 🚨 Nessun backfill nella migrazione: dare per acconsentito chi non ha
        // mai visto la casella sarebbe un consenso **inventato**.
        $nuovo = $this->creaUtente($this->palestra, UserRole::Member, 'nuovo@alfa.test');

        $this->assertNull($nuovo->sleep_ai_consent_at);
    }

    // ═══════════════ la minimizzazione ═══════════════

    /**
     * 🚨 **Tutto il quadro del recupero, ma SOLO quello dichiarato.**
     *
     * Decisione del committente del 16/08: mandare le sole ore dava un consiglio
     * peggiore a parita' di esposizione legale — sonno, HRV e battito stanno
     * nella stessa categoria dell'art. 9 e chiedono lo stesso consenso.
     *
     * ⚠️ Ma «tutto» resta **una lista chiusa**: quello che l'app allega e non e'
     * in `AiController::RECUPERO` **non parte**. Senza questo test la lista
     * sarebbe un suggerimento, e la prima versione dell'app che aggiunge un
     * campo lo spedirebbe a un modello senza che nessuno l'abbia deciso.
     */
    #[Test]
    public function the_whole_recovery_picture_travels_but_only_the_declared_fields(): void
    {
        $this->iscritto->registraConsenso('sleep_ai_consent_at', true);

        $recupero = $this->contestoDopoAverChiesto(
            '?hours=7.2&quality=buona&wakings=3&deep_min=85&rem_min=95'
            .'&hrv_ms=48&hrv_baseline_ms=65&resting_hr=58&resting_hr_baseline=54'
            // 🚨 Questi due l'app non dovrebbe nemmeno mandarli, e se li manda
            // devono fermarsi qui.
            .'&posizione_gps=45.4,9.1&note_mediche=ansia'
        )['recovery'] ?? [];

        $this->assertSame(7.2, $recupero['hours'] ?? null);
        $this->assertSame(3, $recupero['wakings'] ?? null);
        $this->assertSame(85, $recupero['deep_min'] ?? null);

        // 💡 Le due basi di confronto ci sono: senza, HRV e battito non si
        // leggono — un HRV di 48 non vuol dire niente senza la media di 65.
        $this->assertSame(65, $recupero['hrv_baseline_ms'] ?? null);
        $this->assertSame(54, $recupero['resting_hr_baseline'] ?? null);

        $this->assertArrayNotHasKey('posizione_gps', $recupero);
        $this->assertArrayNotHasKey('note_mediche', $recupero);
    }

    /**
     * ⚠️ **Senza le ore il resto non si legge.**
     *
     * Un HRV da solo non dice se la notte e' stata corta: e' un numero che il
     * modello leggerebbe contro il nulla. Se manca il minimo, non parte niente —
     * ed e' meglio di un contesto a meta' che sembra completo.
     */
    #[Test]
    public function without_the_hours_nothing_travels(): void
    {
        $this->iscritto->registraConsenso('sleep_ai_consent_at', true);

        $contesto = $this->contestoDopoAverChiesto('?hrv_ms=48&resting_hr=58');

        $this->assertArrayNotHasKey('recovery', $contesto);
    }

    #[Test]
    public function a_nonsense_value_is_ignored_not_forwarded(): void
    {
        $this->iscritto->registraConsenso('sleep_ai_consent_at', true);

        // ⚠️ Meglio un consiglio senza il sonno che un prompt con dentro una
        // parola al posto di un numero.
        $this->assertArrayNotHasKey('recovery', $this->contestoDopoAverChiesto('?hours=parecchio'));
    }

    #[Test]
    public function the_server_does_not_keep_it(): void
    {
        $this->iscritto->registraConsenso('sleep_ai_consent_at', true);

        $this->contestoDopoAverChiesto('?hours=6.5');

        /*
         * 🚨 **Il server inoltra, non conserva** — la stessa regola del target
         * che arriva dall'app (S8.2). Non esiste nessuna colonna del sonno su
         * `users`: se un giorno qualcuno la aggiungesse «per comodita'», questo
         * test resterebbe verde e la promessa sarebbe rotta lo stesso.
         *
         * 💡 Percio' si guarda l'unica cosa osservabile: che non ci sia una
         * colonna che lo somigli.
         */
        $colonne = array_keys($this->iscritto->fresh()->getAttributes());

        foreach ($colonne as $c) {
            $this->assertStringNotContainsString(
                'sleep_hours',
                $c,
                "la colonna {$c} sembra conservare il sonno: il server deve inoltrarlo, non tenerlo",
            );
        }
    }

    // ═══════════════ l'interruttore del consiglio ═══════════════

    #[Test]
    public function switching_the_advice_off_stops_it_being_regenerated(): void
    {
        $this->iscritto->forceFill(['consiglio_automatico' => false])->save();

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function it_is_on_for_whoever_has_just_signed_up(): void
    {
        // ⚠️ I default del database non valgono sull'oggetto in memoria: senza
        // `$attributes` questa sarebbe `null`, e il consiglio risulterebbe
        // spento per chi si e' appena iscritto. Trappola gia' pagata su
        // `is_active` e su `Tenant`.
        $this->assertTrue((new User)->consiglio_automatico);
    }
}
