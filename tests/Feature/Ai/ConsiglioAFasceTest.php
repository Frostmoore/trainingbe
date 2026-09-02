<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\UserRole;
use App\Models\AiAdvice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Providers\FakeAiProvider;
use App\Services\Billing\PortafoglioGettoni;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * Tre consigli al giorno, e solo se e' successo qualcosa — 3b-AB, 30/08/2026.
 *
 * ══ 📌 LA REGOLA ══════════════════════════════════════════════════════════
 *
 * 📌 Il committente: *«bisogna fare in modo che il consiglio del giorno si
 * rigeneri in automatico solo 3 volte al giorno (9:00, 14:00 e 22:00).
 * Naturalmente questo puo' succedere solo dopo che apri l'app e solo dopo che
 * si e' registrato un pasto, un allenamento o il sonno: quindi se ad esempio
 * sono le 9:35 e io registro gli alimenti alle 9:41, si usa lo slot delle 9
 * fino alle 14:01»*.
 *
 * ══ 🚨 COSA C'ERA PRIMA, E PERCHE' NESSUN TEST LO VEDEVA ══════════════════
 *
 * La cache era su `(giorno, hash del contesto)`, e nell'hash ci sono `totals`,
 * `burned` e `targets`. ⛔ **Ogni pasto registrato era una chiamata al
 * modello**: colazione, spuntino, pranzo, merenda, cena e allenamento facevano
 * sei chiamate automatiche in un giorno.
 *
 * ⚠️ E c'era un test — `a_new_meal_makes_the_advice_stale` — che **certificava**
 * quel comportamento come se fosse un pregio. Il commento in `AiController`
 * diceva nel frattempo *«al massimo una volta per fascia, e cosi' il tetto di
 * tre al giorno resta»*: 🚨 **il tetto era scritto in un commento e in nessuna
 * riga di codice.**
 *
 * 💡 Questo file e' il posto dove quel tetto smette di essere una frase.
 *
 * ══ ⚠️ I DUE CANCELLI, E SERVONO TUTTI E DUE ══════════════════════════════
 *
 * | | Cosa impedisce |
 * |---|---|
 * | la **fascia** | piu' di tre consigli in ventiquattr'ore |
 * | la **notizia** | tre consigli identici a chi non ha registrato niente |
 *
 * ⛔ Con la sola fascia, chi apre l'app tre volte senza fare niente paga tre
 * chiamate. Con la sola notizia, chi segna sei pasti ne paga sei.
 */
final class ConsiglioAFasceTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $palestra;

    private User $iscritto;

    private FakeAiProvider $finta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finta = $this->aiFinta()->willReturnAdvice('Il primo consiglio.');

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'anna@alfa.test');

        /*
         * 🚨 **Il fuso si fissa**, e non e' pedanteria: le fasce sono ore
         * locali. ⛔ Con il fuso di sistema, questi test direbbero cose diverse
         * a seconda della macchina su cui girano — cioe' passerebbero qui e
         * fallirebbero altrove senza che nessuno capisca perche'.
         */
        $this->iscritto->forceFill(['timezone' => 'Europe/Rome'])->save();
        $this->iscritto->accendiLAi();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** Ferma l'orologio a un'ora **locale** di chi guarda. */
    private function alle(string $quando): void
    {
        Carbon::setTestNow(Carbon::parse($quando, 'Europe/Rome')->utc());
    }

    /**
     * Chiede il consiglio come fa l'app, dicendo quand'e' l'ultima notizia.
     *
     * ⚠️ `$notizia` a `null` manda il campo **vuoto**, non assente: e' l'app
     * nuova che dice «non ho mai registrato niente». Un'app vecchia non manda
     * il campo per niente, ed e' un caso diverso — vedi
     * [un_app_vecchia_riceve_lo_stesso_il_consiglio].
     */
    private function chiediIlConsiglio(?string $notizia, bool $manuale = false): TestResponse
    {
        $query = ['last_event_at' => $notizia === null ? '' : Carbon::parse($notizia, 'Europe/Rome')->toIso8601String()];

        if ($manuale) {
            $query['manuale'] = '1';
        }

        return $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice?'.http_build_query($query));
    }

    private function quanteChiamate(): int
    {
        return count(array_filter(
            $this->finta->calls,
            static fn (array $c): bool => $c['method'] === 'dailyAdvice',
        ));
    }

    // ───────────────────────── il primo cancello: la fascia ─────────────

    /**
     * 🎯 **L'esempio del committente, riga per riga.**
     *
     * 📌 *«se ad esempio sono le 9:35 e io registro gli alimenti alle 9:41, si
     * usa lo slot delle 9 fino alle 14:01»*.
     */
    #[Test]
    public function dentro_la_stessa_fascia_un_pasto_non_rigenera_niente(): void
    {
        $this->alle('2026-08-30 09:41');

        $this->chiediIlConsiglio('2026-08-30 09:41')
            ->assertOk()
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.body', 'Il primo consiglio.');

        $this->assertSame(1, $this->quanteChiamate());

        /*
         * 🍝 Il pranzo. ⛔ **Prima questo bastava a far ripartire il modello**:
         * `totals` cambia, quindi l'hash cambia, quindi cache mancata.
         */
        $this->alle('2026-08-30 13:00');

        $this->comeApp($this->iscritto->fresh())->postJson('/api/v1/food-entries', [
            'description' => 'Pollo', 'meal' => 'lunch', 'kcal' => 400, 'grams' => 250,
        ])->assertCreated();

        $this->finta->willReturnAdvice('Il secondo consiglio.');

        $this->chiediIlConsiglio('2026-08-30 13:00')
            ->assertOk()
            ->assertJsonPath('data.cached', true)
            /*
             * 🚨 **Ancora il primo**: dentro la fascia il consiglio e' uno, e la
             * fascia lo dice.
             *
             * ⛔ Qui c'era `assertJsonPath('data.body', 'Il primo consiglio.')`.
             * Da I5.3 il server il testo non lo conserva: su una cache manda
             * `body: null` e la **fascia**, che e' la chiave con cui l'app
             * ritrova la propria copia. 💡 E' un controllo piu' forte, non piu'
             * debole: una fascia sbagliata e' un consiglio che non si trova.
             */
            ->assertJsonPath('data.body', null)
            ->assertJsonPath('data.fascia', '2026-08-30T09');

        $this->assertSame(1, $this->quanteChiamate(), 'Il pasto ha fatto ripartire il modello dentro la stessa fascia.');
        $this->assertSame(1, AiAdvice::withoutGlobalScopes()->count());
    }

    /** ⏰ Alle 14:01 la fascia e' un'altra, e con una notizia si rigenera. */
    #[Test]
    public function alla_fascia_dopo_con_una_notizia_si_rigenera(): void
    {
        $this->alle('2026-08-30 09:41');
        $this->chiediIlConsiglio('2026-08-30 09:41')->assertOk();

        $this->alle('2026-08-30 14:01');
        $this->finta->willReturnAdvice('Il secondo consiglio.');

        $this->chiediIlConsiglio('2026-08-30 13:55')
            ->assertOk()
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.body', 'Il secondo consiglio.');

        $this->assertSame(2, $this->quanteChiamate());
        $this->assertSame(2, AiAdvice::withoutGlobalScopes()->count());
    }

    /**
     * 🌙 **La fascia delle 22 scavalca la mezzanotte**, ed e' quello che tiene
     * il tetto a **tre** invece che a quattro.
     *
     * ⛔ Se la notte facesse fascia a se', chi apre l'app alle 07:00 pagherebbe
     * una quarta chiamata ogni giorno — e la promessa «tre al giorno» sarebbe
     * falsa di un terzo.
     */
    #[Test]
    public function prima_delle_nove_si_e_ancora_nella_fascia_della_sera(): void
    {
        $this->alle('2026-08-30 22:30');
        $this->chiediIlConsiglio('2026-08-30 22:00')->assertOk();

        // ☀️ Il mattino dopo, sveglio presto e con il sonno appena importato.
        $this->alle('2026-08-31 07:00');
        $this->finta->willReturnAdvice('Il consiglio del mattino.');

        $this->chiediIlConsiglio('2026-08-31 06:55')
            ->assertOk()
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.body', null)
            /*
             * 🌙 **La fascia e' quella di IERI sera**, ed e' tutto il senso del
             * test: alle 07:00 del 31 il consiglio in corso e' quello delle 22
             * del 30. 🚨 Da I5.3 e' anche la chiave con cui l'app lo ritrova —
             * se qui comparisse `2026-08-31T22`, il telefono cercherebbe un
             * consiglio che non ha e mostrerebbe il ripiego.
             */
            ->assertJsonPath('data.fascia', '2026-08-30T22');

        $this->assertSame(1, $this->quanteChiamate());

        // 🕘 Alle 09:00 invece si', perche' la fascia e' cambiata davvero.
        $this->alle('2026-08-31 09:00');

        $this->chiediIlConsiglio('2026-08-31 06:55')
            ->assertOk()
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.body', 'Il consiglio del mattino.');

        $this->assertSame(2, $this->quanteChiamate());
    }

    /**
     * 🚨 **Il tetto vero: tre in ventiquattr'ore, non uno di piu'.**
     *
     * 💡 Sei letture, una per ogni momento in cui una persona apre l'app, tutte
     * con una notizia nuova. ⛔ Prima sarebbero state sei chiamate.
     */
    #[Test]
    public function in_un_giorno_intero_non_si_va_oltre_tre(): void
    {
        foreach ([
            '2026-08-30 07:00', '2026-08-30 09:10', '2026-08-30 12:30',
            '2026-08-30 14:05', '2026-08-30 19:00', '2026-08-30 22:10',
        ] as $momento) {
            $this->alle($momento);
            $this->chiediIlConsiglio($momento)->assertOk();
        }

        /*
         * 🎯 Tre: la coda della sera prima (07:00), quella delle 9 e quella
         * delle 14 — piu' quella delle 22. ⚠️ Sono quattro **etichette**, ma
         * la prima e' la fascia del giorno precedente: dentro la giornata le
         * fasce che cominciano sono tre.
         */
        $this->assertSame(4, $this->quanteChiamate());

        // ⛔ E delle sei aperture, due non hanno chiamato niente.
        $this->assertLessThan(6, $this->quanteChiamate());
    }

    // ───────────────────────── il secondo cancello: la notizia ──────────

    /**
     * ⛔ **Fascia nuova ma niente di nuovo: non si paga.**
     *
     * 📌 *«solo dopo che si e' registrato un pasto, un allenamento o il
     * sonno»*.
     *
     * 💡 E si restituisce **quello che c'e'**, non `null`: chi legge vede il
     * consiglio di prima con la sua data, invece di una card che gira.
     */
    #[Test]
    public function alla_fascia_dopo_senza_notizie_non_si_rigenera(): void
    {
        $this->alle('2026-08-30 09:41');
        $this->chiediIlConsiglio('2026-08-30 09:30')->assertOk();

        $this->alle('2026-08-30 14:30');
        $this->finta->willReturnAdvice('Un consiglio che non deve nascere.');

        // ⚠️ La stessa identica notizia di prima: non e' successo niente.
        $this->chiediIlConsiglio('2026-08-30 09:30')
            ->assertOk()
            ->assertJsonPath('data.cached', true)
            ->assertJsonPath('data.body', null)
            /*
             * 💡 **La fascia e' quella di PRIMA**, non quella di adesso: sono le
             * 14:30, ma senza notizie non si genera e si restituisce cio' che
             * c'e' — 📌 *«l'app lo mostra con la sua data, e chi legge vede che
             * e' di prima»*.
             */
            ->assertJsonPath('data.fascia', '2026-08-30T09');

        $this->assertSame(1, $this->quanteChiamate());
        $this->assertSame(1, AiAdvice::withoutGlobalScopes()->count());
    }

    /** ⛔ Chi non ha mai registrato niente non fa partire nessuna chiamata. */
    #[Test]
    public function chi_non_ha_mai_registrato_niente_non_paga_un_consiglio(): void
    {
        $this->alle('2026-08-30 09:41');

        $this->chiediIlConsiglio(null)
            ->assertOk()
            ->assertJsonPath('data', null);

        $this->assertSame(0, $this->quanteChiamate());
        $this->assertSame(0, AiAdvice::withoutGlobalScopes()->count());
    }

    /**
     * 🍽️ **Un pasto e' una notizia, e la porta il telefono** — I5.1.
     *
     * ══ ⛔ QUI C'ERA IL CONTRARIO, ED ERA GIUSTO FINO A I2.5 ═══════════════
     *
     * Il test si chiamava `un_pasto_registrato_conta_come_notizia_anche_se_l_app
     * _non_lo_dice`: scriveva una voce con `POST /food-entries` e provava che il
     * consiglio si rigenerasse **con l'app che taceva**, perche' `food_entries`
     * era del server.
     *
     * 🚨 Da I2.5 quella tabella non riceve piu' niente. ⚠️ Se il test fosse
     * rimasto com'era sarebbe diventato **verde per il motivo sbagliato** — la
     * riga la scriveva lui stesso con una rotta che l'app non chiama piu'.
     *
     * 💡 Adesso e' l'app a dire quand'e' successo l'ultimo gesto, pasti
     * compresi: `ultimaNotiziaProvider` guarda `scrittaIl` del diario locale
     * insieme a sedute e risvegli.
     */
    #[Test]
    public function un_pasto_e_una_notizia_e_la_porta_il_telefono(): void
    {
        $this->alle('2026-08-30 09:41');
        $this->chiediIlConsiglio('2026-08-30 09:30')->assertOk();

        $this->alle('2026-08-30 14:30');

        $this->finta->willReturnAdvice('Il consiglio del pomeriggio.');

        // 🍽️ Il pranzo scritto alle 14:10, come lo racconta l'app.
        $this->chiediIlConsiglio('2026-08-30 14:10')
            ->assertOk()
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.body', 'Il consiglio del pomeriggio.');

        $this->assertSame(2, $this->quanteChiamate());
    }

    /**
     * ⛔ **E senza notizie non si paga, nemmeno con il diario pieno.**
     *
     * 🚨 E' l'altra meta' dello stesso cambio: il server **non guarda piu'
     * nessuna tabella** per decidere se qualcosa e' successo. ⚠️ Una riga in
     * `food_entries` — che oggi puo' esistere solo come residuo di prima del
     * trasloco — non deve far scattare niente.
     */
    #[Test]
    public function una_voce_vecchia_sul_server_non_e_una_notizia(): void
    {
        $this->alle('2026-08-30 09:41');
        $this->chiediIlConsiglio('2026-08-30 09:30')->assertOk();

        $this->alle('2026-08-30 14:30');

        $this->comeApp($this->iscritto->fresh())->postJson('/api/v1/food-entries', [
            'description' => 'Pollo', 'meal' => 'lunch', 'kcal' => 400, 'grams' => 250,
        ])->assertCreated();

        // ⚠️ L'app dice «io non ho notizie»: e adesso non ne ha nessun altro.
        $this->chiediIlConsiglio(null)->assertOk()->assertJsonPath('data.cached', true);

        $this->assertSame(1, $this->quanteChiamate());
    }

    /**
     * ⚠️ **Un'app vecchia non manda il campo, e non deve restare a secco.**
     *
     * 🚨 «Assente» e «vuoto» sono due cose diverse, e trattarle allo stesso
     * modo vorrebbe dire scegliere fra due difetti: o un'app vecchia che non
     * riceve mai un consiglio nuovo, o un'app nuova senza dati che ne paga uno
     * per fascia.
     *
     * 💡 La fascia mette il tetto comunque: al peggio un'app vecchia fa tre
     * chiamate al giorno invece di meno.
     */
    #[Test]
    public function un_app_vecchia_riceve_lo_stesso_il_consiglio(): void
    {
        $this->alle('2026-08-30 09:41');

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice')
            ->assertOk()
            ->assertJsonPath('data.cached', false);

        $this->assertSame(1, $this->quanteChiamate());

        // 🚨 Ma la fascia vale anche per lei.
        $this->alle('2026-08-30 13:00');

        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice')
            ->assertOk()
            ->assertJsonPath('data.cached', true);

        $this->assertSame(1, $this->quanteChiamate());
    }

    // ───────────────────────── e «Rigenera» ─────────────────────────────

    /**
     * 🎯 **Il pulsante salta tutti e due i cancelli, ed e' il suo senso.**
     *
     * 📌 *«se pago 1 gettone lo devo poter rifare eh»*. ⛔ Un limite a chi sta
     * pagando vuol dire prendergli i soldi e decidere al posto suo.
     *
     * ⚠️ E **sostituisce** la riga della fascia invece di affiancarla: due
     * consigli della stessa fascia sarebbero due righe fra cui la cache ne
     * troverebbe una a caso.
     */
    #[Test]
    public function rigenera_a_mano_ignora_la_fascia_e_la_notizia(): void
    {
        /*
         * 🎟️ **E costa un gettone** — 3b-AE, 31/08/2026.
         *
         * 📌 *«tutte le richieste all'ai non automatiche devono costare
         * GETTONI»*, e «Rigenera» e' la definizione stessa di richiesta fatta a
         * mano.
         *
         * 💡 L'app lo dice da 16/08: il pulsante porta scritto «1 gettone».
         * ⛔ Fino a ieri era una promessa che il server non manteneva — la
         * chiamata passava dalla quota inclusa.
         */
        $this->dagliGettoni($this->palestra, 10);

        $this->alle('2026-08-30 09:41');
        $this->chiediIlConsiglio('2026-08-30 09:30')->assertOk();

        $this->alle('2026-08-30 10:00');
        $this->finta->willReturnAdvice('Quello chiesto a mano.');

        // ⚠️ Stessa fascia, nessuna notizia nuova: e parte lo stesso.
        $this->chiediIlConsiglio('2026-08-30 09:30', manuale: true)
            ->assertOk()
            ->assertJsonPath('data.cached', false)
            ->assertJsonPath('data.body', 'Quello chiesto a mano.');

        $this->assertSame(2, $this->quanteChiamate());
        $this->assertSame(1, AiAdvice::withoutGlobalScopes()->count(), 'La rigenerazione ha affiancato invece di sostituire.');

        /*
         * 🚨 **Uno solo.** Il primo consiglio era automatico e l'ha pagato la
         * quota; il secondo l'ha chiesto una persona e l'ha pagato il
         * portafoglio. ⛔ Due gettoni vorrebbero dire che anche l'automatico
         * paga, cioe' l'abbonamento che non copre piu' niente.
         */
        $this->assertSame(
            9,
            app(PortafoglioGettoni::class)->saldo($this->iscritto->fresh()),
        );
    }
}
