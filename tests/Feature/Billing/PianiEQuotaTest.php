<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\PlanKind;
use App\Enums\UserRole;
use App\Models\AiUsageLog;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Services\Ai\Quota\MemberAiQuota;
use App\Services\Billing\PianoAttivo;
use App\Services\Tenancy\CreaTenantPersonale;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Piani, «zero AI» e la catena della quota — F4 della Parte B, 13/08/2026.
 *
 * ── 🚨 Il difetto che tutta questa fase esiste per non commettere ───────────
 *
 * `MemberAiQuota::capFor()` usa due convenzioni: `null` = «scendi al livello
 * successivo», `0` = **illimitato**. Quindi **non esiste un numero che voglia
 * dire "niente AI"**.
 *
 * ⚠️ La strada ovvia — mettere `0` a un utente gratuito — gli darebbe l'AI
 * **senza limiti**: l'esatto contrario del requisito B2, e nel modo che non si
 * scopre finché non arriva la fattura.
 *
 * ✅ Da qui la decisione D2: un flag sul piano, controllato **prima** della
 * quota e non dentro di essa.
 */
class PianiEQuotaTest extends TestCase
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

    private function abbona(Tenant $tenant, string $code, ?string $fineIso = null): PlanSubscription
    {
        return PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $this->piano($code)->id,
            'starts_at' => now()->subDay(),
            'ends_at' => $fineIso,
        ]);
    }

    private function attivo(): PianoAttivo
    {
        return app(PianoAttivo::class);
    }

    // ─────────────────────── F4.1 — il listino ───────────────────────

    /** 🚨 Il gratuito **non** ha l'AI: è il requisito B2, ed è definitivo. */
    #[Test]
    public function the_free_plan_has_no_ai(): void
    {
        $this->assertFalse($this->piano(Plan::FREE)->ai_enabled);
        $this->assertTrue($this->piano(Plan::PLUS)->ai_enabled);
        $this->assertSame(0, $this->piano(Plan::FREE)->price_cents);
    }

    /** ⚠️ Rilanciare il seeder non deve creare doppioni: gli abbonamenti puntano agli id. */
    #[Test]
    public function seeding_twice_does_not_duplicate_the_catalogue(): void
    {
        $prima = Plan::query()->count();
        $idFree = $this->piano(Plan::FREE)->id;

        $this->seed(PlanSeeder::class);

        $this->assertSame($prima, Plan::query()->count());
        $this->assertSame($idFree, $this->piano(Plan::FREE)->id, 'Il piano gratuito ha cambiato id.');
    }

    // ─────────────────── F4.2 — «hai diritto» viene prima ───────────────────

    /**
     * 🚨 **Chi non ha nessun abbonamento ricade sul gratuito, non su `null`.**
     *
     * Restituire `null` avrebbe voluto dire che ogni chiamante deve ricordarsi
     * di trattare quel caso — e chi se lo dimentica scrive `?->ai_enabled`, che
     * vale `null` e passa per «falso» solo per fortuna.
     */
    #[Test]
    public function someone_without_a_subscription_falls_back_to_free(): void
    {
        $libero = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $this->assertSame(Plan::FREE, $this->attivo()->per($libero)->code);
        $this->assertFalse($this->attivo()->haLaAi($libero));
    }

    /**
     * ⚠️ E se nemmeno il piano gratuito esistesse — installazione nuova, seeder
     * mai girato — la risposta deve essere **negare**, non regalare.
     */
    #[Test]
    public function a_misconfigured_install_denies_the_ai(): void
    {
        Plan::query()->delete();

        $libero = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $this->assertFalse($this->attivo()->haLaAi($libero), 'Un\'installazione mal configurata sta regalando l\'AI.');
    }

    #[Test]
    public function a_paid_plan_switches_the_ai_on(): void
    {
        $libero = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $this->abbona($libero->tenant, Plan::PLUS);

        $this->assertTrue($this->attivo()->haLaAi($libero->fresh()));
    }

    /** 🚨 `ends_at = null` significa **non scade**, non «scaduto». */
    #[Test]
    public function a_subscription_without_an_end_date_never_expires(): void
    {
        $libero = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $this->abbona($libero->tenant, Plan::PLUS, null);

        $this->assertTrue($this->attivo()->haLaAi($libero->fresh()));
    }

    /** F4.4 — quando scade, l'AI si spegne e si torna al gratuito. */
    #[Test]
    public function an_expired_subscription_turns_the_ai_off(): void
    {
        $libero = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $this->abbona($libero->tenant, Plan::PLUS, now()->subDay()->toDateTimeString());

        $this->assertSame(Plan::FREE, $this->attivo()->per($libero->fresh())->code);
        $this->assertFalse($this->attivo()->haLaAi($libero->fresh()));
    }

    /** ⚠️ E uno che comincia domani non vale oggi. */
    #[Test]
    public function a_future_subscription_does_not_count_yet(): void
    {
        $libero = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $libero->tenant_id,
            'plan_id' => $this->piano(Plan::PLUS)->id,
            'starts_at' => now()->addWeek(),
        ]);

        $this->assertFalse($this->attivo()->haLaAi($libero->fresh()));
    }

    // ────────────── 🚨 F4.5 — un gratuito non consuma un token ──────────────

    /**
     * **Il test che dà senso a tutta la fase.**
     *
     * Non prova un numero: prova che la chiamata **non parte**. Un utente
     * gratuito che riceve `403` non ha speso niente; uno che riceve `200` e poi
     * un errore ha già pagato i token.
     */
    #[Test]
    public function a_free_user_cannot_spend_a_single_token(): void
    {
        $libero = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        // ⚠️ Il consenso **c'è**: senza, il 403 arriverebbe da lì e questo test
        // proverebbe il cancello sbagliato.
        $libero->forceFill(['ai_consent_at' => now(), 'health_consent_at' => now()])->save();

        $this->actingAs($libero->fresh(), 'sanctum')
            ->postJson('/api/v1/ai/food/text', ['text' => 'due uova'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'plan_without_ai');

        $this->assertSame(0, AiUsageLog::withoutGlobalScopes()->count());
    }

    /**
     * 💡 I tre errori devono restare **distinguibili**: portano a tre schermate
     * diverse. Un unico codice manderebbe chi ha finito i token a riconcedere un
     * consenso che ha già dato.
     */
    #[Test]
    public function the_plan_gate_and_the_consent_gate_speak_with_different_codes(): void
    {
        $libero = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        // Piano con AI, ma nessun consenso.
        $this->abbona($libero->tenant, Plan::PLUS);

        $this->actingAs($libero->fresh(), 'sanctum')
            ->postJson('/api/v1/ai/food/text', ['text' => 'due uova'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'ai_consent_required');
    }

    /** Un iscritto a una palestra con abbonamento attivo passa entrambi i cancelli. */
    #[Test]
    public function a_gym_member_with_a_paying_gym_gets_through(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->abbona($palestra, Plan::GYM);

        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@alfa.test');
        $iscritto->forceFill(['ai_consent_at' => now(), 'health_consent_at' => now()])->save();

        $this->assertTrue($this->attivo()->haLaAi($iscritto->fresh()));
    }

    // ─────────────────── 🚨 F4.3 — la catena a cinque livelli ───────────────────

    /** 1. L'eccezione per una persona batte tutto. */
    #[Test]
    public function the_personal_cap_wins_over_everything(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $palestra->update(['ai_monthly_tokens_per_member' => 111]);

        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@alfa.test');
        $iscritto->forceFill(['ai_monthly_token_cap' => 999])->save();

        $this->assertSame(999, app(MemberAiQuota::class)->capFor($iscritto->fresh()));
    }

    /** 2. Poi la palestra. */
    #[Test]
    public function then_the_gym_decides(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $palestra->update(['ai_monthly_tokens_per_member' => 111]);

        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@alfa.test');

        $this->assertSame(111, app(MemberAiQuota::class)->capFor($iscritto->fresh()));
    }

    /**
     * 🚨 **3. Il trainer indipendente, ma SOLO se non c'è una palestra** — D3.
     *
     * ⚠️ L'ordine inverso sembrerebbe altrettanto sensato, e non lo è: se un
     * iscritto di una palestra si fa seguire **anche** da un trainer
     * indipendente, non deve poter drenare il monte token di quel trainer — che
     * se lo paga di tasca sua per i **suoi** utenti.
     */
    #[Test]
    public function the_gym_comes_before_the_independent_trainer(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $palestra->update(['ai_monthly_tokens_per_member' => 111]);

        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@alfa.test');

        $trainer = app(CreaTenantPersonale::class)(
            'Trainer', 'trainer@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );
        $trainer->forceFill(['ai_monthly_token_cap' => 5])->save();

        $iscritto->assignedTrainers()->attach($trainer->id, [
            'tenant_id' => $trainer->tenant_id, 'assigned_at' => now(),
        ]);

        $this->assertSame(
            111,
            app(MemberAiQuota::class)->capFor($iscritto->fresh()),
            'Un iscritto di una palestra sta drenando il monte token di un trainer indipendente.',
        );
    }

    /** …ma per chi la palestra non ce l'ha, il tetto del trainer vale. */
    #[Test]
    public function without_a_gym_the_trainer_cap_applies(): void
    {
        $utente = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $trainer = app(CreaTenantPersonale::class)(
            'Trainer', 'trainer@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );
        $trainer->forceFill(['ai_monthly_token_cap' => 4242])->save();

        $utente->assignedTrainers()->attach($trainer->id, [
            'tenant_id' => $trainer->tenant_id, 'assigned_at' => now(),
        ]);

        $this->assertSame(4242, app(MemberAiQuota::class)->capFor($utente->fresh()));
    }

    /** 4. Poi il piano. */
    #[Test]
    public function then_the_plan_decides(): void
    {
        $utente = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $this->piano(Plan::PLUS)->update(['ai_monthly_tokens_per_member' => 7777]);
        $this->abbona($utente->tenant, Plan::PLUS);

        $this->assertSame(7777, app(MemberAiQuota::class)->capFor($utente->fresh()));
    }

    /** 5. E in fondo il default di sistema. */
    #[Test]
    public function and_finally_the_system_default(): void
    {
        config(['ai.quota.default_monthly_tokens_per_user' => 1234]);

        $utente = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $this->assertSame(1234, app(MemberAiQuota::class)->capFor($utente->fresh()));
    }

    /**
     * 🚨 `0` continua a significare **illimitato**, a ogni livello.
     *
     * ⚠️ È la convenzione che rende impossibile esprimere «niente AI» con un
     * numero, e cambiarla qui vorrebbe dire cambiare il significato di dati
     * **già scritti**.
     */
    #[Test]
    public function zero_still_means_unlimited(): void
    {
        $utente = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);
        $utente->forceFill(['ai_monthly_token_cap' => 0])->save();

        $this->assertNull(app(MemberAiQuota::class)->capFor($utente->fresh()));
    }

    // ───────────────────────── l'isolamento ─────────────────────────

    /**
     * ⚠️ Gli abbonamenti sono scopati: una palestra non vede quelli delle altre.
     *
     * 💡 **Non si abbona niente qui dentro**, e la ragione va detta: da F4
     * `CreaAmbiente::creaPalestra()` crea **già** l'abbonamento al piano
     * palestra, perché una palestra di prova senza abbonamento non è «una
     * palestra normale» — è una palestra **morosa**. Aggiungerne un secondo a
     * mano faceva contare due righe per tenant, e il test falliva dicendo `2`
     * dove ne aspettava `1`: il numero era giusto, era l'attesa a essere vecchia.
     */
    #[Test]
    public function subscriptions_are_scoped_per_tenant(): void
    {
        $alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');

        $this->assertSame(1, $this->ctx()->runAs($alfa, fn (): int => PlanSubscription::count()));
        $this->assertSame(1, $this->ctx()->runAs($beta, fn (): int => PlanSubscription::count()));

        // 🚨 E nessuna delle due vede quello dell'altra: due righe in tabella,
        // una per ciascuna.
        $this->assertSame(2, PlanSubscription::withoutGlobalScopes()->count());
    }

    /** 💡 Il listino invece è della piattaforma, non di un cliente. */
    #[Test]
    public function the_catalogue_belongs_to_the_platform(): void
    {
        $alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');

        $this->assertSame(
            5,
            $this->ctx()->runAs($alfa, fn (): int => Plan::query()->count()),
            'Il listino sparisce dentro il contesto di una palestra.',
        );

        $this->assertSame(2, Plan::query()->diTipo(PlanKind::Trainer)->count());
    }
}
