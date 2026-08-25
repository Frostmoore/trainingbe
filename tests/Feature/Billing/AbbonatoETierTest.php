<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Services\Billing\PianoAttivo;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * `abbonato` e `tier`, e perche' NON sono `ai_enabled` — 3b-C.8, 25/08/2026.
 *
 * ── 📌 La richiesta ────────────────────────────────────────────────────────
 *
 * *«aggiusta anche il server in modo che gli utenti (free users o iscritti in
 * una palestra o con un trainer) abbiano il flag abbonato e il flag tier»*.
 *
 * ── 🚨 Il difetto che questo file esiste per non commettere ────────────────
 *
 * ⛔ L'app aveva trattato «abbonato» e «AI illimitata» come **la stessa cosa**,
 * appoggiandosi al fatto che oggi l'abbonamento concede la quota illimitata.
 * Il committente l'ha corretto: *«ovviamente AI illimitata e abbonato sono due
 * cose diverse, non va bene che siano trattati come una cosa singola»*.
 *
 * ⚠️ La differenza si vede in un caso vero, non teorico: **chi compra dei
 * gettoni su un piano gratuito**. Ha l'AI, e abbonato non lo e'.
 */
class AbbonatoETierTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function piano(string $code): Plan
    {
        return Plan::query()->where('code', $code)->firstOrFail();
    }

    private function abbona(Tenant $tenant, string $code, ?string $fine = null): void
    {
        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $this->piano($code)->id,
            'starts_at' => now()->subDay(),
            'ends_at' => $fine,
        ]);
    }

    private function attivo(): PianoAttivo
    {
        return app(PianoAttivo::class);
    }

    /**
     * Una palestra **morosa**: nessun abbonamento addosso.
     *
     * 🚨 `creaPalestra()` ne crea una che **paga** — sta scritto nel suo
     * dartdoc — e i suoi iscritti sono percio' abbonati, che e' il modello di
     * vendita: la palestra compra i posti. ⚠️ Per provare il caso «utente
     * gratuito» l'abbonamento va tolto, o si prova un caso che non esiste.
     *
     * 💡 Le mie prime aspettative erano sbagliate proprio qui: davo per scontato
     * che un iscritto non fosse abbonato, e invece lo e'.
     */
    private function spiantata(Tenant $tenant): void
    {
        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->delete();
    }

    #[Test]
    public function un_utente_senza_abbonamento_e_free_e_non_e_abbonato(): void
    {
        $palestra = $this->creaPalestra();
        $utente = $this->creaUtente($palestra, UserRole::Member, 'libero@test.it');

        $this->spiantata($palestra);

        self::assertFalse($this->attivo()->eAbbonato($utente));
        self::assertSame(Plan::FREE, $this->attivo()->livello($utente));
    }

    /**
     * 🚨 **Il livello non e' mai `null`.** Chi non ha niente e' `free`, che e'
     * un livello e non un'assenza: un `null` costringerebbe ogni client a un
     * ramo in piu' per dire la stessa cosa.
     */
    #[Test]
    public function e_il_livello_non_e_mai_nullo(): void
    {
        $palestra = $this->creaPalestra();
        $utente = $this->creaUtente($palestra, UserRole::Member, 'vuoto@test.it');

        self::assertNotEmpty($this->attivo()->livello($utente));
    }

    #[Test]
    public function con_un_abbonamento_attivo_e_abbonato_e_il_tier_e_quello(): void
    {
        $palestra = $this->creaPalestra();
        $utente = $this->creaUtente($palestra, UserRole::Member, 'pagante@test.it');

        $this->abbona($palestra, Plan::PLUS);

        self::assertTrue($this->attivo()->eAbbonato($utente));
        self::assertSame(Plan::PLUS, $this->attivo()->livello($utente));
    }

    /**
     * ⚠️ Un abbonamento **scaduto** non abbona: `attivi()` filtra su `ends_at`,
     * e ricadere sul gratuito e' esattamente la risposta giusta.
     */
    #[Test]
    public function un_abbonamento_scaduto_non_abbona(): void
    {
        $palestra = $this->creaPalestra();
        $utente = $this->creaUtente($palestra, UserRole::Member, 'scaduto@test.it');

        $this->spiantata($palestra);
        $this->abbona($palestra, Plan::PLUS, now()->subHour()->toDateTimeString());

        self::assertFalse($this->attivo()->eAbbonato($utente));
        self::assertSame(Plan::FREE, $this->attivo()->livello($utente));
    }

    /**
     * 🚨 **Il caso che dimostra che sono due cose diverse.**
     *
     * ⛔ Chi compra dei gettoni su un piano gratuito puo' usare l'AI — e
     * `ai_enabled` dice `true` — **senza essere abbonato**. Confondere i due
     * flag gli darebbe le funzioni a pagamento senza che abbia pagato
     * l'abbonamento; oppure, nel verso opposto, le toglierebbe a un abbonato
     * che ha finito i gettoni.
     */
    #[Test]
    public function chi_ha_lai_non_e_per_questo_abbonato(): void
    {
        $palestra = $this->creaPalestra();

        $utente = $this->creaUtente(
            $palestra,
            UserRole::Member,
            'gettoni@test.it',
        );

        /*
         * ⚠️ **`forceFill` e non `creaUtente($extra)`.** `ai_enabled_override`
         * non e' fra i `fillable` di `User`, quindi passato all'assegnazione di
         * massa viene **scartato in silenzio**: il test passava dei dati che non
         * arrivavano, e falliva su una regola che funzionava benissimo.
         */
        $utente->forceFill(['ai_enabled_override' => true])->save();

        $this->spiantata($palestra);

        self::assertTrue(
            $this->attivo()->aiUtilizzabile($utente),
            'l\'AI accesa a mano si usa',
        );

        self::assertFalse(
            $this->attivo()->eAbbonato($utente),
            'ma non lo rende abbonato',
        );
    }

    /**
     * ⛔ E nemmeno il contrario: un abbonato a cui l'AI e' stata **spenta a
     * mano** dal pannello resta abbonato.
     */
    #[Test]
    public function e_chi_non_ha_lai_puo_essere_abbonato(): void
    {
        $palestra = $this->creaPalestra();

        $utente = $this->creaUtente(
            $palestra,
            UserRole::Member,
            'spento@test.it',
        );

        /*
         * ⚠️ **`forceFill` e non `creaUtente($extra)`.** `ai_enabled_override`
         * non e' fra i `fillable` di `User`, quindi passato all'assegnazione di
         * massa viene **scartato in silenzio**: il test passava dei dati che non
         * arrivavano, e falliva su una regola che funzionava benissimo.
         */
        $utente->forceFill(['ai_enabled_override' => false])->save();

        $this->spiantata($palestra);
        $this->abbona($palestra, Plan::PLUS);

        self::assertFalse($this->attivo()->aiUtilizzabile($utente));
        self::assertTrue($this->attivo()->eAbbonato($utente));
    }

    /**
     * 🚨 **L'iscritto di una palestra che paga E' abbonato**, ed e' il modello
     * di vendita: la palestra compra i posti per i suoi.
     *
     * ⚠️ E' anche il caso che mi ero sbagliato a prevedere, quindi sta scritto
     * come test: chi legge non deve dedurlo.
     */
    #[Test]
    public function liscritto_di_una_palestra_che_paga_e_abbonato(): void
    {
        $palestra = $this->creaPalestra();
        $utente = $this->creaUtente($palestra, UserRole::Member, 'insieme@test.it');

        self::assertTrue($this->attivo()->eAbbonato($utente));
    }

    /**
     * 💡 E l'app li vede: senza questo, tutto il resto resta sul server.
     */
    #[Test]
    public function lapp_li_riceve_da_me(): void
    {
        $palestra = $this->creaPalestra();
        $utente = $this->creaUtente($palestra, UserRole::Member, 'app@test.it');

        $this->spiantata($palestra);
        $this->abbona($palestra, Plan::PLUS);

        $this->actingAs($utente, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.abbonato', true)
            ->assertJsonPath('data.tier', Plan::PLUS);
    }

    #[Test]
    public function e_un_free_user_li_riceve_lo_stesso(): void
    {
        $palestra = $this->creaPalestra();
        $utente = $this->creaUtente($palestra, UserRole::Member, 'free@test.it');

        $this->spiantata($palestra);

        $this->actingAs($utente, 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('data.abbonato', false)
            ->assertJsonPath('data.tier', Plan::FREE);
    }
}
