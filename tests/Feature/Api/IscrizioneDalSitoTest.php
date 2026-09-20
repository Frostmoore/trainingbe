<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\TenantKind;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\StripeFinto;
use Tests\Concerns\UsaStripeFinto;
use Tests\TestCase;

/**
 * 💳 **L'iscrizione dal sito** — 20/09/2026.
 *
 * 📌 Il committente: *«ci deve essere un modo per permettere alle palestre e ai
 * trainers di abbonarsi direttamente dal sito»*.
 *
 * ── ⛔ Cosa c'era prima ────────────────────────────────────────────────────
 *
 * **Niente.** Il listino mostrava i prezzi e finiva lì: chi voleva comprare
 * scriveva una mail, e qualcuno gli creava l'account a mano dal pannello god.
 * 🚨 La pagina dei prezzi aveva perfino un pulsante «Iscriviti ora» che puntava
 * a `/registrati`, una rotta **mai esistita** — 404 sul pulsante principale
 * della pagina che deve vendere.
 *
 * ── 🚨 Cosa provano questi test ────────────────────────────────────────────
 *
 * Che dopo aver riempito quattro campi esista **uno spazio vero**: tenant del
 * tipo giusto, utente col ruolo giusto, proprietario segnato, dichiarazioni
 * conservate con la loro data, e il checkout di Stripe aperto. ⚠️ Ognuno di
 * questi passi, saltato, non dà errore: dà un account che sembra a posto e non
 * funziona.
 */
final class IscrizioneDalSitoTest extends TestCase
{
    use RefreshDatabase;
    use UsaStripeFinto;

    private StripeFinto $stripe;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->stripe = $this->stripeFinto();
    }

    /** @return array<string, string> i campi del modulo, già validi */
    private function campi(array $cambia = []): array
    {
        return array_merge([
            'nome' => 'Marco Rossi',
            'attivita' => 'Palestra Olimpo',
            'email' => 'marco@esempio.test',
            'password' => 'unapassword1',
            'password_confirmation' => 'unapassword1',
            'accetto' => '1',
        ], $cambia);
    }

    // ═══════════════════════ il modulo ═══════════════════════

    #[Test]
    public function il_modulo_mostra_il_piano_che_si_sta_comprando(): void
    {
        $this->get('/iscriviti/gym_10')
            ->assertOk()
            ->assertSee('Palestra Basic', escape: false)
            ->assertSee('60,00 €', escape: false)
            // 🚨 Il modulo di una palestra chiede il nome della palestra.
            ->assertSee('Nome della palestra', escape: false);

        $this->get('/iscriviti/trainer_5')
            ->assertOk()
            ->assertSee('Trainer Basic', escape: false)
            ->assertDontSee('Nome della palestra', escape: false);
    }

    /**
     * 🚨 **Non tutti i piani si comprano da soli.**
     *
     * ⛔ Fuori restano il piano individuale (si attiva dentro l'app, qui non ci
     * sarebbe niente da creare), i piani **su misura** (hanno un minimo e una
     * fattura a consumo: si concordano) e quelli **ritirati**, che esistono solo
     * per chi ci sta già sopra.
     *
     * ⚠️ 404 e non un messaggio: un modulo che accetta dati e poi rifiuta è
     * peggio di una pagina che non c'è.
     */
    #[Test]
    public function i_piani_che_non_si_comprano_non_hanno_nemmeno_il_modulo(): void
    {
        foreach (['plus', 'free', 'gym_consumo', 'trainer_consumo', 'trainer_10', 'inventato'] as $codice) {
            $this->get("/iscriviti/{$codice}")->assertNotFound("«{$codice}» non doveva avere un modulo");
            $this->post("/iscriviti/{$codice}", $this->campi())->assertNotFound();
        }

        $this->assertSame(0, User::withoutGlobalScopes()->count(), 'e non è nato nessuno');
    }

    // ═══════════════════════ la palestra ═══════════════════════

    #[Test]
    public function una_palestra_nasce_col_suo_amministratore(): void
    {
        $risposta = $this->post('/iscriviti/gym_10', $this->campi());

        // 💡 Si esce verso Stripe: il finto risponde con un indirizzo plausibile.
        $risposta->assertRedirect();
        $this->assertStringContainsString('checkout.stripe.test', (string) $risposta->headers->get('Location'));

        $palestra = Tenant::query()->where('name', 'Palestra Olimpo')->firstOrFail();

        $this->assertSame(TenantKind::Gym, $palestra->kind);
        $this->assertSame('marco@esempio.test', $palestra->contact_email);

        $utente = User::withoutGlobalScopes()->where('email', 'marco@esempio.test')->firstOrFail();

        $this->assertSame($palestra->getKey(), $utente->tenant_id);
        $this->assertTrue($utente->isGymAdmin(), 'chi apre la palestra non ne è amministratore');

        // 🚨 Il proprietario: è la riga che dice chi paga.
        $this->assertSame($utente->getKey(), $palestra->fresh()->proprietario_id);

        // 💡 Ed è già dentro: chi torna da Stripe trova il pannello aperto.
        $this->assertAuthenticatedAs($utente);
    }

    /**
     * 💡 **Due palestre possono chiamarsi uguale** — «Fitness Club» ce n'è uno
     * per provincia — e lo `slug` è unico.
     *
     * ⚠️ Senza il contatore in coda, la seconda iscrizione morirebbe su un
     * errore di database che chi la sta facendo non può né capire né risolvere:
     * avrebbe scritto tutto giusto.
     */
    #[Test]
    public function due_palestre_con_lo_stesso_nome_non_si_scontrano(): void
    {
        $this->post('/iscriviti/gym_10', $this->campi());

        $this->post('/iscriviti/gym_30', $this->campi([
            'email' => 'seconda@esempio.test',
            'nome' => 'Anna Bianchi',
        ]));

        $slug = Tenant::query()->where('name', 'Palestra Olimpo')->pluck('slug')->all();

        $this->assertCount(2, $slug, 'la seconda palestra non è nata');
        $this->assertCount(2, array_unique($slug), 'due palestre hanno lo stesso slug');
    }

    // ═══════════════════════ il trainer ═══════════════════════

    #[Test]
    public function un_trainer_nasce_nel_suo_spazio_personale(): void
    {
        // ⚠️ Senza nome dell'attività: a un trainer non si chiede.
        $risposta = $this->post('/iscriviti/trainer_15', $this->campi([
            'attivita' => null,
            'email' => 'coach@esempio.test',
        ]));

        $risposta->assertRedirect();

        $utente = User::withoutGlobalScopes()->where('email', 'coach@esempio.test')->firstOrFail();

        $this->assertSame(TenantKind::Personal, $utente->tenant->kind);
        // ⚠️ `isFreeTrainer()` e non `hasRole()`: i ruoli di spatie sono per
        // palestra, e fuori dal contesto del tenant una lettura diretta torna
        // sempre `false` — cioè un test verde su un utente senza ruolo.
        $this->assertTrue($utente->isFreeTrainer(), 'il trainer non ha il suo ruolo');
        $this->assertSame($utente->getKey(), $utente->tenant->proprietario_id);
    }

    /** 🚨 A una palestra invece il nome serve: è quello che vedranno gli iscritti. */
    #[Test]
    public function il_nome_della_palestra_e_obbligatorio_solo_per_la_palestra(): void
    {
        $this->post('/iscriviti/gym_10', $this->campi(['attivita' => null]))
            ->assertSessionHasErrors('attivita');

        $this->assertSame(0, User::withoutGlobalScopes()->count());
    }

    // ═══════════════════════ i rifiuti ═══════════════════════

    /**
     * 🚨 **Senza la spunta non si passa**, e `accepted` non è `boolean`: un
     * `false` deve **fallire**, non essere registrato come «ha detto di no e
     * comunque entra».
     */
    #[Test]
    public function senza_accettare_condizioni_e_maggiore_eta_non_si_entra(): void
    {
        $this->post('/iscriviti/gym_10', $this->campi(['accetto' => null]))
            ->assertSessionHasErrors('accetto');

        $this->assertSame(0, User::withoutGlobalScopes()->count());
        $this->assertGuest();
    }

    #[Test]
    public function la_password_deve_reggere_il_minimo(): void
    {
        $this->post('/iscriviti/gym_10', $this->campi([
            'password' => 'corta1',
            'password_confirmation' => 'corta1',
        ]))->assertSessionHasErrors('password');

        // ⚠️ E le due devono coincidere: chi sbaglia a ribattere resta fuori dal
        // proprio pannello il giorno dopo, e non lo scopre subito.
        $this->post('/iscriviti/gym_10', $this->campi([
            'password_confirmation' => 'unaltrapassword1',
        ]))->assertSessionHasErrors('password');

        $this->assertSame(0, User::withoutGlobalScopes()->count());
    }

    /**
     * 🚨 **L'indirizzo dev'essere libero su tutta la piattaforma.**
     *
     * ⚠️ L'unicità dell'email a database è **per palestra**, ma chi entra da un
     * pannello si autentica con email *o* nome utente su tutta la piattaforma:
     * due righe uguali renderebbero l'accesso un'ambiguità risolta da un
     * `first()`.
     *
     * 💡 E qui il messaggio **dice com'è andata**, al contrario della
     * registrazione dall'app: là sarebbe un oracolo su chi è iscritto a quale
     * palestra, qui è una persona che sta creando il proprio spazio.
     */
    #[Test]
    public function un_indirizzo_gia_usato_lo_dice_invece_di_creare_un_doppione(): void
    {
        $this->post('/iscriviti/gym_10', $this->campi());

        $this->assertSame(1, User::withoutGlobalScopes()->count());

        $this->post('/iscriviti/trainer_5', $this->campi(['attivita' => null]))
            ->assertSessionHasErrors('email');

        $this->assertSame(1, User::withoutGlobalScopes()->count(), 'è nato un doppione');
    }

    // ═══════════════════════ le dichiarazioni ═══════════════════════

    /**
     * 🚨 **Le due dichiarazioni si conservano con la loro data.**
     *
     * L'art. 7(1) del GDPR chiede di poter **dimostrare** che il consenso è
     * stato dato: senza la data non si dimostra niente, e una spunta che non
     * lascia traccia vale quanto non averla chiesta.
     */
    #[Test]
    public function le_dichiarazioni_restano_con_la_loro_data(): void
    {
        $this->post('/iscriviti/gym_10', $this->campi());

        $utente = User::withoutGlobalScopes()->where('email', 'marco@esempio.test')->firstOrFail();

        $this->assertNotNull($utente->age_confirmed_at, 'la maggiore età non è stata registrata');
        $this->assertNotNull($utente->terms_accepted_at, "l'accettazione delle condizioni non è stata registrata");
    }

    /**
     * 💡 **Il nome utente si ricava dall'indirizzo**, e non si chiede: un campo
     * in più in un modulo di iscrizione è gente che se ne va.
     *
     * 🚨 Deve rispettare la stessa regola della registrazione dall'app —
     * minuscole, niente chiocciola — o verrebbe scambiato per un'email al
     * momento dell'accesso. E dev'essere **unico**: due persone con lo stesso
     * indirizzo su domini diversi non si devono scontrare.
     */
    #[Test]
    public function il_nome_utente_nasce_dall_indirizzo_e_resta_unico(): void
    {
        $this->post('/iscriviti/gym_10', $this->campi());
        $this->post('/iscriviti/trainer_5', $this->campi([
            'email' => 'marco@altrodominio.test',
            'attivita' => null,
        ]));

        $nomi = User::withoutGlobalScopes()->orderBy('id')->pluck('username')->all();

        $this->assertCount(2, $nomi);
        $this->assertSame('marco', $nomi[0]);
        $this->assertNotSame($nomi[0], $nomi[1], 'due account con lo stesso nome utente');

        foreach ($nomi as $nome) {
            $this->assertMatchesRegularExpression('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/', (string) $nome);
        }
    }

    /**
     * 🚨 **Il checkout parte col piano giusto e col suo prezzo.**
     *
     * ⚠️ È la cosa che il finto può provare e la sandbox no: qui si guarda
     * **cosa** si sta per far pagare, non che Stripe risponda.
     */
    #[Test]
    public function il_checkout_porta_il_piano_e_il_prezzo_giusti(): void
    {
        $this->post('/iscriviti/gym_30', $this->campi());

        $sessione = $this->stripe->ultima('checkout.sessions.create')[0];

        $this->assertSame('subscription', $sessione['mode']);
        $this->assertSame(13000, $sessione['line_items'][0]['price_data']['unit_amount']);
        $this->assertSame('gym_30', $sessione['metadata']['piano']);
    }
}
