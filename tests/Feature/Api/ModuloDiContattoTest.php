<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\StatoDellaRichiesta;
use App\Enums\TipoDiRichiesta;
use App\Models\RichiestaDiContatto;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * ✉️ **Il modulo di contatto** — 21/09/2026.
 *
 * 📌 Il committente, sul piano su misura: *«tanto c'è scrivici. Mettici un
 * modulo di contatto così mi scrivono»*.
 *
 * ── ⛔ Cosa c'era prima, e perché questi test esistono ─────────────────────
 *
 * Un `mailto:`. 🚨 Un link che su un telefono senza posta configurata **non fa
 * niente**: nessun errore, nessuna pagina, nessuna riga da nessuna parte. Un
 * canale di vendita che si rompe in silenzio è il tipo di cosa che non si
 * scopre mai, perché chi se ne va non scrive per dirlo.
 *
 * 💡 Qui si prova che la richiesta **diventa una riga**: è quella la prova che
 * il canale funziona, non il fatto che la pagina risponda 200.
 */
class ModuloDiContattoTest extends TestCase
{
    use RefreshDatabase;

    /** I campi minimi di un invio buono. */
    private const BUONO = [
        'tipo' => 'palestra',
        'nome' => 'Giulia Ferri',
        'email' => 'giulia@palestrademo.it',
        'messaggio' => 'Abbiamo 180 iscritti e 9 istruttori, ci serve un piano che li copra tutti.',
        'accetto' => '1',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    #[Test]
    public function the_contact_form_opens(): void
    {
        $this->get('/scrivici')
            ->assertOk()
            ->assertSee('Manda la richiesta', escape: false);
    }

    /**
     * 💡 **Chi arriva dal pulsante «Scrivici» ha già detto chi è.**
     *
     * Il link della scheda su misura porta `?tipo=palestra`: la tendina deve
     * trovarsi già su quella voce. ⛔ Richiederglielo sarebbe una domanda fatta
     * a chi ha appena risposto, e ogni campo in più è gente che se ne va.
     */
    #[Test]
    public function the_kind_arrives_preselected_from_the_price_page(): void
    {
        $pagina = $this->get('/scrivici?tipo=trainer')->assertOk()->getContent();

        /*
         * ⚠️ Una regex e non una stringa: `@selected` scrive l'attributo
         * **nudo** (`selected`, non `selected="selected"`) e lo scrive sulla
         * riga dopo `value` — una `assertStringContainsString` cercherebbe uno
         * spazio dove c'è un a capo, e fallirebbe su una pagina giusta.
         */
        $this->assertMatchesRegularExpression(
            '~<option value="trainer"\s+selected~',
            $pagina,
            'la tendina non arriva già sul trainer',
        );
    }

    /**
     * 🚨 **La richiesta diventa una riga, ed è tutto il punto.**
     *
     * ⚠️ E nasce `nuova`: è lo stato che accende il numero accanto alla voce di
     * menù del pannello. Senza, una richiesta arrivata di sabato si scopre il
     * mercoledì.
     */
    #[Test]
    public function a_request_becomes_a_row(): void
    {
        $this->post('/scrivici', self::BUONO + ['iscritti' => '180', 'trainer' => '9'])
            ->assertRedirect('/scrivici');

        $richiesta = RichiestaDiContatto::query()->sole();

        $this->assertSame(TipoDiRichiesta::Palestra, $richiesta->tipo);
        $this->assertSame('giulia@palestrademo.it', $richiesta->email);
        $this->assertSame(180, $richiesta->iscritti);
        $this->assertSame(9, $richiesta->trainer);
        $this->assertSame(StatoDellaRichiesta::Nuova, $richiesta->stato);
        $this->assertNull($richiesta->presa_in_carico_il);
    }

    /**
     * 🚨 **Lo stato non si può scegliere da fuori.**
     *
     * ⛔ `stato` e `note_interne` non sono `fillable` proprio per questo: chi
     * scrive dal sito non deve poter far nascere una richiesta già `chiusa` —
     * che vorrebbe dire una richiesta che nessuno vedrà mai, perché il filtro
     * predefinito del pannello nasconde le chiuse.
     */
    #[Test]
    public function the_sender_cannot_decide_the_state(): void
    {
        $this->post('/scrivici', self::BUONO + [
            'stato' => 'chiusa',
            'note_interne' => 'niente da fare',
        ])->assertRedirect('/scrivici');

        $richiesta = RichiestaDiContatto::query()->sole();

        $this->assertSame(StatoDellaRichiesta::Nuova, $richiesta->stato);
        $this->assertNull($richiesta->note_interne);
    }

    /**
     * 🕳️ **Il campo trappola: chi lo compila riceve il grazie e nessuna riga.**
     *
     * 💡 La risposta è **identica** a quella di un invio riuscito. ⛔ Dire «sei
     * un robot» insegnerebbe soltanto a non compilarlo la prossima volta, ed è
     * l'unico motivo per cui la trappola funziona ancora.
     */
    #[Test]
    public function the_honeypot_swallows_the_robots(): void
    {
        $this->post('/scrivici', self::BUONO + ['sito_web' => 'https://spam.example'])
            ->assertRedirect('/scrivici');

        $this->assertSame(0, RichiestaDiContatto::query()->count());
    }

    /**
     * 🚨 **Senza consenso non nasce niente.**
     *
     * ⚠️ `accepted` e non `boolean`: un `false` deve **fallire**, non essere
     * registrato come «ha detto di no e la richiesta la prendiamo lo stesso».
     */
    #[Test]
    public function without_consent_there_is_no_request(): void
    {
        $senza = self::BUONO;
        unset($senza['accetto']);

        $this->post('/scrivici', $senza)->assertSessionHasErrors('accetto');

        $this->assertSame(0, RichiestaDiContatto::query()->count());
    }

    /** ⚠️ Due righe non sono un messaggio: senza un minimo arriva «ciao». */
    #[Test]
    public function the_obvious_rubbish_is_refused(): void
    {
        $this->post('/scrivici', ['tipo' => 'palestra', 'nome' => 'x', 'email' => 'non-una-email', 'messaggio' => 'ciao'])
            ->assertSessionHasErrors(['nome', 'email', 'messaggio', 'accetto']);

        $this->assertSame(0, RichiestaDiContatto::query()->count());
    }

    /**
     * 🚨 **Il `mailto:` non deve tornare, da nessuna pagina.**
     *
     * ⛔ È il difetto che questa fase ha chiuso, e il modo di riaprirlo è
     * copiare un pulsante da una pagina vecchia. 💡 Il test guarda **tutte** le
     * pagine, non solo quella dei prezzi.
     */
    #[Test]
    public function no_page_sends_people_to_an_email_client_anymore(): void
    {
        foreach (['/', '/prezzi', '/prezzi/individuale', '/prezzi/trainer', '/prezzi/palestra', '/professionisti', '/scrivici'] as $pagina) {
            $this->assertStringNotContainsString(
                'mailto:',
                $this->get($pagina)->assertOk()->getContent(),
                "{$pagina} manda ancora a un programma di posta",
            );
        }
    }

    /**
     * 📌 **Il pulsante «Scrivici» della scheda su misura porta al modulo**, e
     * ci porta dicendo chi sta scrivendo.
     */
    #[Test]
    public function the_custom_plan_card_points_at_the_form(): void
    {
        $this->get('/prezzi/palestra')->assertOk()->assertSee('/scrivici?tipo=palestra', escape: false);
        $this->get('/prezzi/trainer')->assertOk()->assertSee('/scrivici?tipo=trainer', escape: false);
    }
}
