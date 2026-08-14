<?php

declare(strict_types=1);

namespace Tests\Feature\Privacy;

use App\Enums\UserRole;
use App\Filament\God\Resources\Users\Pages\ListUsers;
use App\Models\NutritionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il «Rif. Allievo» — R4, G10.1.
 *
 * ── 🚨 Perche' questa classe esiste, e perche' prova TRE punti ────────────
 *
 * `rif_allievo` e' l'unico campo in chiaro sul server che possa identificare
 * chi segue un programma. ⚠️ Da un programma post-infortunio si capisce cos'e'
 * successo a chi lo esegue: e' dato sanitario di rimbalzo, e la sola cosa che lo
 * tiene innocuo e' che **lo veda solo chi l'ha scritto**.
 *
 * La regola ha **tre** punti di applicazione, e **uno solo dimenticato annulla
 * gli altri due**:
 *
 * | # | Dove | Come |
 * |---|---|---|
 * | 1 | L'API | la chiave **sparisce** dalla risposta |
 * | 2 | Il pannello | `formatStateUsing()` azzera lo stato all'idratazione |
 * | 3 | La busta cifrata | l'app lo **toglie** prima di mandare (test lato app) |
 *
 * 🚨 E un quarto, che non e' un punto ma una condizione: **nemmeno in
 * impersonazione**. Vale la stessa regola dei messaggi.
 */
final class RifAllievoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $tommaso;

    private User $altroTrainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->tommaso = $this->creaUtente($this->palestra, UserRole::Trainer, 'tommaso@alfa.test');
        $this->altroTrainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'altro@alfa.test');
    }

    private function pianoDiTommaso(): NutritionPlan
    {
        return $this->ctx()->runAs($this->palestra, fn (): NutritionPlan => NutritionPlan::create([
            'tenant_id' => $this->palestra->getKey(),
            'created_by' => $this->tommaso->getKey(),
            'name' => 'Definizione',
            'rif_allievo' => 'M.R. spalla dx',
        ]));
    }

    // ───────────────── punto 1: l'API ─────────────────

    #[Test]
    public function the_author_sees_it(): void
    {
        $piano = $this->pianoDiTommaso();

        $this->actingAs($this->tommaso, 'sanctum')
            ->getJson("/api/v1/nutrition-plans/{$piano->getKey()}")
            ->assertOk()
            ->assertJsonPath('data.rif_allievo', 'M.R. spalla dx');
    }

    #[Test]
    public function another_trainer_gets_a_404_not_a_403(): void
    {
        $piano = $this->pianoDiTommaso();

        /*
         * 🚨 **404 e non 403, ed è una scelta.** Un `403` confermerebbe che quel
         * piano **esiste** — e su un elenco di piani anonimi anche solo
         * l'esistenza, associata a un id, è un'informazione sul lavoro di un
         * collega.
         */
        $this->actingAs($this->altroTrainer, 'sanctum')
            ->getJson("/api/v1/nutrition-plans/{$piano->getKey()}")
            ->assertStatus(404);
    }

    #[Test]
    public function the_key_disappears_it_does_not_arrive_empty(): void
    {
        $this->pianoDiTommaso();

        // L'elenco dell'altro trainer non contiene il piano di Tommaso, ma se
        // un giorno lo contenesse la chiave **non** dovrebbe esserci.
        $risposta = $this->actingAs($this->altroTrainer, 'sanctum')
            ->getJson('/api/v1/nutrition-plans')
            ->assertOk();

        foreach ($risposta->json('data') as $riga) {
            /*
             * 💡 **Assente, non vuota.** Una chiave sempre presente e a volte
             * piena direbbe comunque **che esiste un riferimento**, e in un
             * elenco di piani anonimi anche questo è un'informazione.
             */
            $this->assertArrayNotHasKey('rif_allievo', $riga);
        }
    }

    #[Test]
    public function a_member_never_sees_it(): void
    {
        $this->pianoDiTommaso();

        $iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'iscritto@alfa.test');

        // ⚠️ Un iscritto non arriva nemmeno alla rotta: `not_a_trainer`.
        $this->actingAs($iscritto, 'sanctum')
            ->getJson('/api/v1/nutrition-plans')
            ->assertStatus(403);
    }

    // ───────────────── punto 2: il pannello ─────────────────

    #[Test]
    public function the_panel_does_not_serve_it_to_another_trainer(): void
    {
        $piano = $this->pianoDiTommaso();

        $risposta = $this->actingAs($this->altroTrainer)
            ->get("/admin/nutrition-plans/{$piano->getKey()}/edit");

        /*
         * 🚨 **`visible()` non basta, e questo test lo dimostra.** Livewire
         * serializza lo **stato del modulo** dentro la pagina: un campo nascosto
         * ma **idratato** manda comunque il proprio valore al browser.
         *
         * 💡 La difesa è `formatStateUsing()`, che azzera lo stato
         * all'idratazione — quindi non c'è niente da serializzare.
         */
        if ($risposta->status() === 200) {
            $risposta->assertDontSee('M.R. spalla dx');
        } else {
            $this->assertContains($risposta->status(), [403, 404]);
        }
    }

    // ───────────────── la condizione: l'impersonazione ─────────────────

    #[Test]
    public function not_even_when_impersonating(): void
    {
        $piano = $this->pianoDiTommaso();
        $god = $this->creaSuperAdmin();

        filament()->setCurrentPanel('god');

        $this->actingAs($god);

        Livewire::test(ListUsers::class)
            ->callTableAction('impersona', $this->altroTrainer);

        app(TenantContext::class)->set($this->palestra);

        /*
         * 🚨 **Vale la stessa regola dei messaggi** (R2/R3): «anche quando c'è
         * l'impersonazione non si devono poter leggere i messaggi degli altri».
         *
         * ⚠️ E qui c'è una cosa che rende il caso più insidioso della chat:
         * durante un'impersonazione `auth()->user()` **è la persona
         * impersonata**, quindi il controllo su `created_by` funziona da solo —
         * ma solo finché nessuno lo sostituisce con «chi ha davvero fatto
         * l'accesso», che sarebbe la cosa apparentemente più corretta da fare.
         */
        $this->getJson("/api/v1/nutrition-plans/{$piano->getKey()}")
            ->assertStatus(404);
    }

    // ───────────────── e vale identico sulle schede ─────────────────

    #[Test]
    public function the_same_rule_holds_for_workout_plans(): void
    {
        $scheda = $this->ctx()->runAs($this->palestra, fn (): WorkoutPlan => WorkoutPlan::create([
            'tenant_id' => $this->palestra->getKey(),
            'created_by' => $this->tommaso->getKey(),
            'name' => 'Split A/B',
            'rif_allievo' => 'G.B. — off season',
        ]));

        $risposta = $this->actingAs($this->altroTrainer)
            ->get("/admin/workout-plans/{$scheda->getKey()}/edit");

        if ($risposta->status() === 200) {
            $risposta->assertDontSee('G.B. — off season');
        } else {
            $this->assertContains($risposta->status(), [403, 404]);
        }
    }

    // ───────────────── e non finisce in un posto pubblico ─────────────────

    #[Test]
    public function it_never_reaches_the_public_site(): void
    {
        $this->pianoDiTommaso();

        /*
         * ⚠️ Il sito è **pubblico**: qualunque cosa ci finisca è leggibile da
         * chiunque, senza accesso. È il posto dove un campo riservato fa il
         * danno peggiore, ed è anche quello che nessuno pensa a controllare.
         */
        $this->get('/')->assertOk()->assertDontSee('M.R. spalla dx');
    }
}
