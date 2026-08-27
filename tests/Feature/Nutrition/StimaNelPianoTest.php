<?php

declare(strict_types=1);

namespace Tests\Feature\Nutrition;

use App\Enums\AiFeature;
use App\Enums\UserRole;
use App\Models\AiUsageLog;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * La stima di un alimento mentre il trainer compone — G5.11 (D13).
 *
 * 🚨 **La cosa che questa classe difende e' chi paga.** Il costo di comporre un
 * piano e' del trainer: quando l'allievo lo ricevera', la spesa e' gia' stata
 * sostenuta da chi l'ha scritto. Scalarla all'allievo sarebbe invisibile — il
 * servizio funziona, la quota di qualcun altro cala — e si scoprirebbe solo da
 * un iscritto che dice «ho finito le stime senza averle usate».
 */
final class StimaNelPianoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $palestra;

    private User $trainer;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->aiFinta();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'trainer@alfa.test');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'iscritto@alfa.test');

        foreach ([$this->trainer, $this->iscritto] as $u) {
            $u->forceFill(['ai_consent_at' => now(), 'ai_disclaimer_at' => now(), 'health_consent_at' => now()])->save();
        }
    }

    #[Test]
    public function the_estimate_is_charged_to_the_trainer(): void
    {
        $this->actingAs($this->trainer->fresh(), 'sanctum')
            ->postJson('/api/v1/nutrition-plans/stima-alimento', ['text' => '120 g di petto di pollo'])
            ->assertOk();

        $riga = AiUsageLog::withoutGlobalScopes()->firstOrFail();

        // 🚨 Il consumo e' del trainer, e ha l'etichetta sua.
        $this->assertSame($this->trainer->getKey(), $riga->user_id);
        // ⚠️ `feature` e' castata a enum sul modello: si confronta l'enum.
        $this->assertSame(AiFeature::PlanFood, $riga->feature);

        // ⚠️ E l'iscritto non ha speso niente: il suo contatore e' fermo.
        $this->assertSame(
            0,
            AiUsageLog::callsForUser((int) $this->iscritto->getKey()),
            'la stima del trainer e\' stata addebitata all\'allievo',
        );
    }

    #[Test]
    public function the_feature_has_its_own_label_in_the_accounting(): void
    {
        $this->actingAs($this->trainer->fresh(), 'sanctum')
            ->postJson('/api/v1/nutrition-plans/stima-alimento', ['text' => 'due uova'])
            ->assertOk();

        $this->actingAs($this->trainer->fresh(), 'sanctum')
            ->postJson('/api/v1/ai/food/text', ['text' => 'due uova', 'save' => false])
            ->assertStatus(202);

        /*
         * 💡 **Due chiamate, due etichette.** E' il motivo per cui `PlanFood`
         * esiste come caso a se' pur usando lo stesso contatore: senza,
         * «quanto ci costa far comporre i piani» e «quanto ci costa farli
         * usare» diventano lo stesso numero, e non si puo' piu' dare un prezzo
         * a nessuno dei due.
         */
        $this->assertSame(1, AiUsageLog::withoutGlobalScopes()
            ->where('feature', AiFeature::PlanFood->value)->count());
        $this->assertSame(1, AiUsageLog::withoutGlobalScopes()
            ->where('feature', AiFeature::FoodText->value)->count());

        // ⚠️ Ma la quota e' **una sola**: due chiamate sono due chiamate.
        $this->assertSame(2, AiUsageLog::callsForUser((int) $this->trainer->getKey()));
    }

    #[Test]
    public function a_member_cannot_use_it(): void
    {
        $this->actingAs($this->iscritto->fresh(), 'sanctum')
            ->postJson('/api/v1/nutrition-plans/stima-alimento', ['text' => 'due uova'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'not_a_trainer');

        // 🚨 E soprattutto: **non ha speso niente**. Un 403 dopo la chiamata
        // sarebbe un rifiuto gia' pagato.
        $this->assertSame(0, AiUsageLog::withoutGlobalScopes()->count());
    }

    #[Test]
    public function the_answer_is_shaped_like_the_plan_expects_it(): void
    {
        $r = $this->actingAs($this->trainer->fresh(), 'sanctum')
            ->postJson('/api/v1/nutrition-plans/stima-alimento', ['text' => '120 g di petto di pollo'])
            ->assertOk();

        $primo = $r->json('data.items.0');

        /*
         * 💡 I campi sono quelli che `NutritionPlanRequest` accetta, cosi' l'app
         * incolla senza tradurre. Una forma diversa qui vorrebbe dire una
         * conversione nell'app — cioe' un punto in piu' in cui i campi possono
         * divergere.
         */
        $this->assertArrayHasKey('description', $primo);
        $this->assertArrayHasKey('kcal', $primo);
        $this->assertSame('ai', $primo['origine_valori']);
    }

    #[Test]
    public function without_a_paying_plan_it_is_refused_before_the_call(): void
    {
        // La palestra smette di pagare.
        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $this->palestra->id)->delete();

        $this->actingAs($this->trainer->fresh(), 'sanctum')
            ->postJson('/api/v1/nutrition-plans/stima-alimento', ['text' => 'due uova'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'plan_without_ai');

        // ⚠️ `RequirePlanWithAi` gira **prima** del controller: la chiamata non
        // parte, quindi non si paga niente.
        $this->assertSame(0, AiUsageLog::withoutGlobalScopes()->count());
    }
}
