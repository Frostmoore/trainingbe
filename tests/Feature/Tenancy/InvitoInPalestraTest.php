<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Comune;
use App\Models\InvitoInPalestra;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\ProfiloPubblico;
use App\Models\Tenant;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Scoperta\ChiaveComune;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiInPalestra;
use App\Support\Tenancy\CosaOttieniInPalestra;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🎯 Entrare in palestra con un **link monouso** — 3b-V.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«non mi piace il codice per iscriversi in palestra, preferisco un link di
 * invito»* — 28/08. E il 29/08: *«Il link d'invito deve essere monouso, e a chi
 * ci clicca si deve aprire l'app in una pagina con la descrizione della
 * palestra, il logo, i colori, un messaggio di congratulazioni, le cose a cui
 * avrà accesso e due tasti, uno per accettare e uno per rifiutare»*.
 *
 * ══ ⛔ COSA DIFENDONO QUESTI TEST ═════════════════════════════════════════
 *
 * Le tre proprietà che il `join_code` **non ha nessuna**, e che sono il motivo
 * per cui questa fase esiste:
 *
 * | | `join_code` | l'invito |
 * |---|---|---|
 * | Quante volte | infinite | **una sola** |
 * | Per quanto | per sempre | **scade** |
 * | Revocabile | rigenerandolo per tutti | **uno per volta** |
 *
 * 🚨 Il caso concreto: **un link che finisce in una chat di gruppo non deve far
 * entrare venti persone**.
 */
final class InvitoInPalestraTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $this->gestore = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'gestore@olimpo.it');
    }

    private function inviti(): InvitiInPalestra
    {
        return app(InvitiInPalestra::class);
    }

    private function unInvito(): InvitoInPalestra
    {
        return $this->inviti()->invita($this->palestra, $this->gestore, 'mario@esempio.test');
    }

    private function unaPersonaFuori(string $email = 'mario@esempio.test'): User
    {
        return app(CreaTenantPersonale::class)('Mario', $email, [
            'password' => self::FAKE_PASSWORD,
        ]);
    }

    // ───────────────────── V.1 — il monouso ─────────────────────

    /**
     * 🚨 **Il cuore di 3b-V**: due persone, un link, una sola entra.
     *
     * 📌 *«un link che finisce in una chat di gruppo non deve far entrare venti
     * persone»*.
     */
    #[Test]
    public function un_invito_fa_entrare_una_persona_sola(): void
    {
        $invito = $this->unInvito();

        $prima = $this->unaPersonaFuori('mario@esempio.test');
        $dopo = $this->unaPersonaFuori('luigi@esempio.test');

        $entrata = $this->inviti()->accetta($invito->token, $prima);

        $this->assertSame($this->palestra->id, $entrata->id);
        $this->assertSame($this->palestra->id, $prima->fresh()->tenant_id);

        // ⛔ Il secondo trova la porta chiusa.
        $this->expectException(ValidationException::class);
        $this->inviti()->accetta($invito->token, $dopo);
    }

    /**
     * ⛔ **Rifiutare brucia l'invito** — V.1.3.
     *
     * 💡 Quell'invito era per quella persona, e quella persona ha detto no.
     * Lasciarlo valido vorrebbe dire un invito che nessuno userà mai e che la
     * palestra crede ancora in piedi.
     */
    #[Test]
    public function un_invito_rifiutato_non_si_accetta_piu(): void
    {
        $invito = $this->unInvito();

        $this->inviti()->rifiuta($invito->token);

        $this->assertNotNull($invito->fresh()->rifiutato_il);
        $this->assertFalse($invito->fresh()->eValido());

        $this->expectException(ValidationException::class);
        $this->inviti()->accetta($invito->token, $this->unaPersonaFuori());
    }

    /** ⏰ Scaduto vuol dire scaduto. */
    #[Test]
    public function un_invito_scaduto_non_vale(): void
    {
        $invito = $this->unInvito();
        $invito->forceFill(['expires_at' => now()->subDay()])->save();

        $this->assertNull($this->inviti()->anteprima($invito->token));
    }

    /** ⛔ E revocato pure. */
    #[Test]
    public function un_invito_revocato_non_vale(): void
    {
        $invito = $this->unInvito();
        $this->inviti()->revoca($invito);

        $this->assertNull($this->inviti()->anteprima($invito->token));
    }

    /**
     * 🚨 **`rifiutato_il` vale anche per gli inviti dei trainer** — V.1.3.
     *
     * ⛔ Non è scope che scappa: `EUnInvito::eValido()` è **una regola sola** per
     * tutti e due i modelli. Se `TrainerInvite` non avesse la colonna,
     * esploderebbe alla prima chiamata — e questo test è quello che se ne
     * accorge invece di lasciarlo scoprire in produzione.
     *
     * 💡 È anche la prova che il concern serviva: la quarta condizione è
     * arrivata a tutti e due nello stesso istante.
     */
    #[Test]
    public function anche_gli_inviti_dei_trainer_conoscono_il_rifiuto(): void
    {
        $trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'anna@olimpo.it');

        $invito = TrainerInvite::create([
            'tenant_id' => $this->palestra->id,
            'trainer_id' => $trainer->id,
            'token' => TrainerInvite::generaToken(),
            'expires_at' => now()->addDays(TrainerInvite::GIORNI_DI_VITA),
        ]);

        $this->assertTrue($invito->eValido());

        $invito->forceFill(['rifiutato_il' => now()])->save();

        $this->assertFalse(
            $invito->fresh()->eValido(),
            'Il rifiuto non vale per gli inviti dei trainer: due inviti con due regole diverse.',
        );

        $this->assertSame(
            0,
            TrainerInvite::withoutGlobalScopes()->validi()->count(),
            'Lo scope non guarda `rifiutato_il`: una query lo vedrebbe ancora valido.',
        );
    }

    // ───────────────────── V.2 — la pagina ─────────────────────

    /**
     * 🎁 **Quello che la persona legge**, e che il committente ha elencato.
     */
    #[Test]
    public function l_anteprima_porta_marchio_descrizione_e_cosa_ottieni(): void
    {
        /*
         * ⚠️ `comune_id` e' obbligatorio: una scheda del catalogo (M2) vive
         * sempre in un comune, perche' il catalogo si ordina per vicinanza.
         */
        $comune = Comune::create([
            'codice' => '056059', 'nome' => 'Viterbo',
            'chiave' => app(ChiaveComune::class)->da('Viterbo'),
            'provincia' => 'VT', 'provincia_nome' => 'Viterbo', 'regione' => 'Lazio',
            'popolazione' => 66_598, 'lat' => 42.417, 'lng' => 12.104, 'attivo' => true,
        ]);

        ProfiloPubblico::create([
            'tenant_id' => $this->palestra->id,
            'comune_id' => $comune->id,
            'titolo' => 'Palestra Olimpo',
            'descrizione' => 'Sala pesi, corsi e due personal trainer.',
            'visibile' => true,
        ]);

        $dati = $this->inviti()->anteprima($this->unInvito()->token);

        $this->assertNotNull($dati);
        $this->assertSame('Olimpo', $dati['palestra']['name']);
        $this->assertArrayHasKey('colors', $dati['palestra']);
        $this->assertSame('Sala pesi, corsi e due personal trainer.', $dati['descrizione']);
        $this->assertNotEmpty($dati['cosa_ottieni']);
    }

    /**
     * 🚨 **L'anteprima NON consuma l'invito** — V.2.1.
     *
     * ⛔ La gente apre i link, li chiude e li riapre da un altro telefono. Un
     * invito che si brucia guardandolo si perde la prima volta che qualcuno lo
     * legge senza decidere.
     */
    #[Test]
    public function guardare_l_invito_non_lo_consuma(): void
    {
        $invito = $this->unInvito();

        $this->inviti()->anteprima($invito->token);
        $this->inviti()->anteprima($invito->token);
        $this->inviti()->anteprima($invito->token);

        $this->assertTrue($invito->fresh()->eValido());

        // 💡 E dopo tre letture si entra lo stesso.
        $this->inviti()->accetta($invito->token, $this->unaPersonaFuori());
        $this->assertNotNull($invito->fresh()->used_at);
    }

    /**
     * 🚨 **`cosa_ottieni` lo dice il SERVER, e cambia col piano** — V.2.2.
     *
     * ⛔ Un elenco scritto nell'app sarebbe una promessa che l'app fa a nome
     * della palestra, e diventerebbe falsa il giorno che il piano cambia. Il
     * caso concreto: una palestra senza AI a cui l'app promette l'AI.
     */
    #[Test]
    public function cosa_ottieni_non_promette_l_ai_a_chi_non_ce_l_ha(): void
    {
        $cosa = app(CosaOttieniInPalestra::class);

        $conAi = $cosa->per($this->palestra);

        $this->assertTrue(
            collect($conAi)->contains(fn (array $r): bool => str_contains($r['titolo'], 'AI')),
            'La palestra ha un piano con AI e l\'invito non la nomina.',
        );

        // La palestra passa a un piano senza AI.
        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $this->palestra->id)
            ->delete();

        $senzaAi = Plan::query()->where('code', Plan::FREE)->firstOrFail();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->id,
            'plan_id' => $senzaAi->id,
            'starts_at' => now()->subYear(),
        ]);

        $this->assertFalse(
            collect($cosa->per($this->palestra->fresh()))
                ->contains(fn (array $r): bool => str_contains($r['titolo'], 'AI')),
            'L\'invito promette l\'AI a una palestra che non ce l\'ha: '
            .'la persona entrerebbe e non la troverebbe.',
        );
    }

    /** ⚠️ Una palestra sospesa non fa entrare nessuno. */
    #[Test]
    public function una_palestra_sospesa_non_fa_entrare(): void
    {
        $invito = $this->unInvito();

        $this->palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->assertNull($this->inviti()->anteprima($invito->token));
    }

    // ───────────────────── V.2.3 — i rifiuti indistinguibili ─────────────────────

    /**
     * ⛔ **Scaduto, revocato, usato, rifiutato e mai esistito: lo stesso 404.**
     *
     * 🚨 Distinguerli darebbe a chiunque un modo di sapere quali token sono
     * validi e quali inviti sono stati usati — cioè un oracolo su chi si è
     * iscritto dove. È lo stesso principio già scritto su `BrandingController`.
     */
    #[Test]
    public function tutti_i_rifiuti_si_somigliano(): void
    {
        $scaduto = $this->unInvito();
        $scaduto->forceFill(['expires_at' => now()->subDay()])->save();

        $revocato = $this->unInvito();
        $this->inviti()->revoca($revocato);

        $usato = $this->unInvito();
        $usato->forceFill(['used_at' => now()])->save();

        $rifiutato = $this->unInvito();
        $this->inviti()->rifiuta($rifiutato->token);

        $risposte = [];

        foreach ([$scaduto->token, $revocato->token, $usato->token, $rifiutato->token, 'maiEsistito'] as $token) {
            $r = $this->getJson("/api/v1/inviti-palestra/{$token}");

            $risposte[] = [$r->status(), $r->json('code'), $r->json('message')];
        }

        $this->assertCount(
            1,
            array_unique(array_map('serialize', $risposte)),
            'Le risposte negative non sono identiche: qualcuna dice perché.',
        );
        $this->assertSame(404, $risposte[0][0]);
    }

    /**
     * 🚨 **Anche il rifiuto risponde sempre uguale** — e qui la posta è più
     * alta: rispondere `404` ai token falsi e `204` a quelli veri
     * trasformerebbe questo endpoint in un modo per provarli a tappeto, **con
     * l'aggravante che ogni tentativo azzeccato brucerebbe l'invito di
     * qualcuno**.
     */
    #[Test]
    public function rifiutare_un_token_inventato_non_lo_dice(): void
    {
        $this->postJson('/api/v1/inviti-palestra/maiEsistito/rifiuta')->assertStatus(204);
        $this->postJson('/api/v1/inviti-palestra/'.$this->unInvito()->token.'/rifiuta')->assertStatus(204);
    }

    // ───────────────────── le rotte ─────────────────────

    /**
     * ⚠️ **L'anteprima è pubblica, e non è una dimenticanza.**
     *
     * Chi tocca il link **non ha ancora l'app**: se chiedesse l'accesso, la
     * prima cosa che quella persona vedrebbe sarebbe un modulo di registrazione
     * invece dell'invito — cioè il muro che questo link esiste per togliere.
     */
    #[Test]
    public function l_anteprima_si_legge_senza_essere_nessuno(): void
    {
        $this->getJson('/api/v1/inviti-palestra/'.$this->unInvito()->token)
            ->assertStatus(200)
            ->assertJsonPath('data.palestra.name', 'Olimpo')
            ->assertJsonStructure(['data' => ['palestra', 'descrizione', 'cosa_ottieni', 'scade_il']]);
    }

    /** ✅ E accettando si entra, con il marchio della palestra in risposta. */
    #[Test]
    public function accettare_dall_app_fa_entrare(): void
    {
        $invito = $this->unInvito();
        $persona = $this->unaPersonaFuori();

        $this->actingAs($persona, 'sanctum')
            ->postJson('/api/v1/inviti-palestra/'.$invito->token.'/accetta')
            ->assertStatus(200)
            ->assertJsonPath('data.palestra.name', 'Olimpo');

        $this->assertSame($this->palestra->id, $persona->fresh()->tenant_id);
        $this->assertNotNull($invito->fresh()->used_at);
    }

    /**
     * 🚨 **Un tentativo fallito non brucia l'invito** — l'ordine è
     * entrare-poi-bruciare.
     *
     * ⛔ Se `UnisciAUnaPalestra` rifiuta per un motivo risolvibile — l'email è
     * già presa in quella palestra — bruciare prima costerebbe alla persona
     * l'unico invito che aveva, per un problema che la palestra può sistemare
     * in un minuto.
     */
    #[Test]
    public function un_tentativo_fallito_lascia_l_invito_valido(): void
    {
        $invito = $this->unInvito();

        // ⚠️ La stessa email esiste già dentro la palestra.
        $this->creaUtente($this->palestra, UserRole::Member, 'mario@esempio.test');

        try {
            $this->inviti()->accetta($invito->token, $this->unaPersonaFuori('mario@esempio.test'));
            $this->fail('Doveva rifiutare: l\'email è già presa in quella palestra.');
        } catch (ValidationException) {
            // atteso
        }

        $this->assertTrue(
            $invito->fresh()->eValido(),
            'Un tentativo fallito ha bruciato l\'invito.',
        );
    }

    /** ⛔ Un tenant personale non è una palestra e non invita nessuno. */
    #[Test]
    public function un_tenant_personale_non_puo_invitare(): void
    {
        $tizio = $this->unaPersonaFuori('tizio@esempio.test');

        $this->expectException(ValidationException::class);

        $this->inviti()->invita($tizio->tenant, $tizio);
    }

    /** ⏰ Sette giorni, come per gli inviti dei trainer. */
    #[Test]
    public function un_invito_vive_una_settimana(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 8, 29, 12));

        $this->assertSame(
            '2026-09-05',
            $this->unInvito()->expires_at->toDateString(),
        );

        Carbon::setTestNow();
    }

    // ───────────────────── V.1.5 — i posti ─────────────────────

    /**
     * 🚨 **I posti si contano PRIMA di creare l'invito** — V.1.5.
     *
     * ⛔ Un invito creato oltre il limite fallisce **davanti alla persona
     * invitata**: ha già deciso di entrare, ha già installato l'app, e si becca
     * un errore che non la riguarda. 💡 Chi invita, invece, il problema può
     * risolverlo.
     */
    #[Test]
    public function finiti_i_posti_non_si_creano_altri_inviti(): void
    {
        $piano = Plan::query()->where('code', Plan::GYM)->firstOrFail();
        $piano->forceFill(['max_members' => 2])->save();

        // Il gestore occupa già un posto.
        $this->inviti()->invita($this->palestra, $this->gestore, 'uno@esempio.test');

        $this->assertSame(0, $this->inviti()->postiRimasti($this->palestra));

        $this->expectException(ValidationException::class);

        $this->inviti()->invita($this->palestra, $this->gestore, 'due@esempio.test');
    }

    /**
     * 🚨 **Gli inviti in sospeso occupano un posto**, e questo è il test che lo
     * inchioda.
     *
     * ⛔ Contando solo gli iscritti si potrebbero creare venti inviti su un
     * piano da dieci: i primi dieci che rispondono riempiono la palestra, e gli
     * altri dieci — che un invito valido ce l'hanno in mano — trovano la porta
     * chiusa. ⚠️ Il difetto non si vedrebbe il giorno in cui si crea: si
     * vedrebbe settimane dopo, addosso a qualcun altro.
     */
    #[Test]
    public function un_invito_in_sospeso_occupa_un_posto(): void
    {
        $piano = Plan::query()->where('code', Plan::GYM)->firstOrFail();
        $piano->forceFill(['max_members' => 5])->save();

        $prima = $this->inviti()->postiRimasti($this->palestra);

        $invito = $this->unInvito();

        $this->assertSame(
            $prima - 1,
            $this->inviti()->postiRimasti($this->palestra),
            'Un invito in sospeso non occupa niente: si possono creare più '
            .'inviti dei posti che esistono.',
        );

        // 💡 E revocandolo il posto torna libero.
        $this->inviti()->revoca($invito);

        $this->assertSame($prima, $this->inviti()->postiRimasti($this->palestra));
    }

    /** ⚠️ Un piano senza limite non ne ha: `null`, non zero. */
    #[Test]
    public function un_piano_senza_limite_non_conta_i_posti(): void
    {
        $piano = Plan::query()->where('code', Plan::GYM)->firstOrFail();
        $piano->forceFill(['max_members' => null])->save();

        $this->assertNull($this->inviti()->postiRimasti($this->palestra));
    }

    // ───────────────────── V.3.3 — dopo l'installazione ─────────────────────

    /**
     * 🚨 **Il link allo store porta il token** — V.3.3.
     *
     * È la metà che fa funzionare il «referrer»: senza questo parametro il Play
     * Store non ha niente da conservare, e il codice nell'app legge sempre
     * vuoto. ⛔ Funzionerebbe benissimo e non servirebbe a niente — nessun
     * errore, nessun segno.
     */
    #[Test]
    public function la_pagina_web_manda_allo_store_col_token(): void
    {
        $invito = $this->unInvito();

        $risposta = $this->get('/invito-palestra/'.$invito->token);

        $risposta->assertStatus(200);

        /*
         * ⚠️ **Codificato**: è un parametro dentro un parametro, e il Play
         * Store lo decodifica una volta lui prima di passarlo all'app.
         */
        $risposta->assertSee('referrer='.rawurlencode('invito='.$invito->token), false);

        // 💡 E parte da quello configurato, non da un URL costruito a mano.
        $risposta->assertSee(config('app_versione.store.android'), false);
    }

    /**
     * ⚠️ **E il tasto per chi l'app ce l'ha già.**
     *
     * Di solito su un telefono con l'app installata gli App Links la aprono da
     * soli e qui non ci arriva nessuno. ⛔ Ma «di solito» non è «sempre»: la
     * verifica può non essere passata, o il link può essere stato aperto da
     * dentro un'altra app. Senza questo tasto quella persona resta su una
     * pagina che le dice di installare una cosa che ha già.
     */
    #[Test]
    public function la_pagina_web_da_una_via_a_chi_l_app_ce_l_ha(): void
    {
        $this->get('/invito-palestra/'.$this->unInvito()->token)
            ->assertStatus(200)
            ->assertSee('Apri nell\'app', false);
    }

    /** ⛔ E un invito morto non manda da nessuna parte. */
    #[Test]
    public function la_pagina_web_di_un_invito_morto_non_offre_niente(): void
    {
        $invito = $this->unInvito();
        $invito->forceFill(['expires_at' => now()->subDay()])->save();

        $this->get('/invito-palestra/'.$invito->token)
            ->assertStatus(200)
            ->assertSee('non è più valido', false)
            ->assertDontSee('referrer=', false);
    }
}
