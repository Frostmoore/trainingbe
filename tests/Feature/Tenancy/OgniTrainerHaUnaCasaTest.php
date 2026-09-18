<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\MealType;
use App\Enums\UserRole;
use App\Filament\Gym\Resources\Trainers\TrainerResource;
use App\Http\Middleware\SpazioDelPannello;
use App\Models\FoodEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\ChiSiVede;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\DaiUnaCasaAlTrainer;
use App\Services\Tenancy\TogliUnTrainerDallaPalestra;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Response;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🏠 **Ogni trainer ha sempre uno spazio suo** — decisione 2 del committente,
 * 18/09/2026.
 *
 * > *«Ogni trainer ha sempre uno spazio suo.»*
 *
 * ══ 🚨 COSA PROVA, OLTRE AL TRASLOCO ══════════════════════════════════════
 *
 * Il trasloco in sé è la parte facile. Questa classe prova **le quattro cose
 * che il trasloco avrebbe rotto**, trovate contando su staging chi ne sarebbe
 * stato toccato — tre trainer veri:
 *
 * 1. il **diario** del trainer: spostando solo `users.tenant_id`, il
 *    `TenantScope` glielo avrebbe reso invisibile;
 * 2. il **pannello**: a casa è `free_user`, e `isTrainer()` risponde nel
 *    contesto — sarebbe rimasto chiuso fuori;
 * 3. la **prima pagina**: sarebbe atterrato nel proprio spazio vuoto, credendo
 *    che la migrazione gli avesse cancellato il lavoro;
 * 4. l'**elenco dei trainer** della palestra: con la casa altrove, il
 *    `TenantScope` su `User` lo faceva sparire.
 */
final class OgniTrainerHaUnaCasaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /** Un trainer creato come si faceva prima: **dentro** la palestra. */
    private function trainerNatoDentro(Tenant $palestra, string $email): User
    {
        return $this->creaUtente($palestra, UserRole::Trainer, $email);
    }

    #[Test]
    public function il_trainer_riceve_una_casa_e_tiene_il_lavoro(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->trainerNatoDentro($palestra, 'dentro@esempio.test');

        $casa = app(DaiUnaCasaAlTrainer::class)($trainer);

        $this->assertNotNull($casa);
        $this->assertTrue($casa->ePersonale());
        $this->assertSame($casa->getKey(), $trainer->fresh()->tenant_id, 'la casa non e sua');
        $this->assertSame($trainer->getKey(), $casa->proprietario_id);

        /*
         * 🚨 **Il lavoro resta.** Traslocare anche il ruolo vorrebbe dire
         * licenziarlo senza che nessuno l'abbia chiesto.
         */
        $this->assertTrue(
            app(ChiSiVede::class)->palestreDoveAllena($trainer->fresh())->contains($palestra->getKey()),
            'dandogli una casa lo si e licenziato',
        );
    }

    /**
     * 🚨 **1 — Il diario lo segue.** Spostando solo `users.tenant_id`, il
     * `TenantScope` glielo renderebbe invisibile: non cancellato, peggio.
     */
    #[Test]
    public function il_suo_diario_lo_segue(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->trainerNatoDentro($palestra, 'diario@esempio.test');

        $this->ctx()->runAs($palestra, fn () => FoodEntry::create([
            'tenant_id' => $palestra->getKey(),
            'user_id' => $trainer->getKey(),
            'eaten_at' => now(),
            'meal' => MealType::Breakfast,
            'description' => 'Due uova',
            'kcal' => 140,
        ]));

        $casa = app(DaiUnaCasaAlTrainer::class)($trainer);

        $this->assertSame(
            1,
            $this->ctx()->runAs($casa, fn (): int => FoodEntry::where('user_id', $trainer->getKey())->count()),
            'il diario del trainer e diventato invisibile',
        );
    }

    /** ⚠️ Gli iscritti della palestra restano della palestra: si sposta solo lui. */
    #[Test]
    public function gli_iscritti_della_palestra_non_si_muovono(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->trainerNatoDentro($palestra, 'solo-lui@esempio.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'resta@esempio.test');

        app(DaiUnaCasaAlTrainer::class)($trainer);

        $this->assertSame($palestra->getKey(), $iscritto->fresh()->tenant_id, 'si e portato dietro un iscritto');
    }

    /** 💡 Idempotente: chi ha già una casa non si tocca. */
    #[Test]
    public function chi_ha_gia_una_casa_non_si_tocca(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->trainerNatoDentro($palestra, 'due-volte@esempio.test');

        $prima = app(DaiUnaCasaAlTrainer::class)($trainer);
        $seconda = app(DaiUnaCasaAlTrainer::class)($trainer->fresh());

        $this->assertNotNull($prima);
        $this->assertNull($seconda, 'gli e stata data una seconda casa');
        $this->assertSame($prima->getKey(), $trainer->fresh()->tenant_id);
    }

    /**
     * 🚨 **2 — Il pannello lo lascia entrare.**
     *
     * ⛔ A casa è `free_user`, e `isTrainer()` risponde nel contesto: senza
     * `allenaInUnaPalestra()` il pannello gli avrebbe detto di no.
     */
    #[Test]
    public function il_pannello_non_lo_chiude_fuori(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->trainerNatoDentro($palestra, 'pannello@esempio.test');

        app(DaiUnaCasaAlTrainer::class)($trainer);

        $fresco = $trainer->fresh();

        $this->ctx()->runAs($fresco->tenant, function () use ($fresco): void {
            $this->assertTrue(
                $fresco->canAccessPanel(Filament::getPanel('admin')),
                'il trainer con la casa nuova e chiuso fuori dal pannello',
            );
        });
    }

    /**
     * 🚨 **3 — Senza una scelta, atterra nella palestra.**
     *
     * ⛔ Altrimenti si ritroverebbe nel proprio spazio vuoto, e penserebbe che
     * la migrazione gli avesse cancellato il lavoro.
     */
    #[Test]
    public function senza_una_scelta_atterra_dove_lavora(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->trainerNatoDentro($palestra, 'atterra@esempio.test');

        app(DaiUnaCasaAlTrainer::class)($trainer);

        $contesto = $this->attraversaIlMiddleware($trainer->fresh());

        $this->assertSame($palestra->getKey(), $contesto, 'e atterrato nello spazio vuoto di casa');
    }

    /**
     * ⚠️ **Ma un free trainer col suo giro parte da casa**, come prima: il suo
     * lavoro è lì.
     */
    #[Test]
    public function un_free_trainer_col_suo_giro_parte_da_casa(): void
    {
        $palestra = $this->creaPalestra();

        $libero = app(CreaTenantPersonale::class)(
            'Libero', 'libero@esempio.test',
            ['password' => TestCase::FAKE_PASSWORD], UserRole::FreeTrainer,
        );

        $this->ctx()->runAs($palestra, function () use ($libero): void {
            $fresco = User::withoutGlobalScopes()->findOrFail($libero->getKey());
            $fresco->unsetRelation('roles');
            $fresco->assignRole(UserRole::Trainer->value);
        });

        $contesto = $this->attraversaIlMiddleware($libero->fresh());

        $this->assertNull($contesto, 'il free trainer e stato portato via dal suo giro');
    }

    /**
     * 🚨 **4 — Compare nell'elenco dei trainer della palestra.**
     *
     * ⛔ `User` usa `BelongsToTenant`: con la casa altrove, il `TenantScope` lo
     * faceva sparire — e con lui la possibilità di aprirlo e di congedarlo.
     */
    #[Test]
    public function compare_fra_i_trainer_della_palestra(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->trainerNatoDentro($palestra, 'elenco@esempio.test');

        app(DaiUnaCasaAlTrainer::class)($trainer);

        $elencati = $this->ctx()->runAs(
            $palestra,
            fn () => TrainerResource::getEloquentQuery()->pluck('users.id')->all(),
        );

        $this->assertContains($trainer->getKey(), $elencati, 'il trainer e sparito dallelenco della palestra');
    }

    /** ⚠️ E l'elenco non mostra i trainer di **un'altra** palestra. */
    #[Test]
    public function lelenco_non_mostra_i_trainer_degli_altri(): void
    {
        $qui = $this->creaPalestra('Qui', 'qui', 'QUII2345');
        $altrove = $this->creaPalestra('Altrove', 'altrove', 'ALTR2345');

        $estraneo = $this->trainerNatoDentro($altrove, 'estraneo@esempio.test');
        app(DaiUnaCasaAlTrainer::class)($estraneo);

        $elencati = $this->ctx()->runAs(
            $qui,
            fn () => TrainerResource::getEloquentQuery()->pluck('users.id')->all(),
        );

        $this->assertNotContains($estraneo->getKey(), $elencati, 'si vedono i trainer di unaltra palestra');
    }

    /**
     * 💡 **E adesso la palestra lo può congedare.** Prima, con la casa dentro la
     * palestra, `TogliUnTrainerDallaPalestra` rifiutava: *«non ha uno spazio
     * suo»* — l'unico modo di mandarlo via era cancellargli l'account.
     */
    #[Test]
    public function adesso_la_palestra_lo_puo_congedare(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->trainerNatoDentro($palestra, 'congedo@esempio.test');

        app(DaiUnaCasaAlTrainer::class)($trainer);

        app(TogliUnTrainerDallaPalestra::class)($trainer->fresh(), $palestra);

        $this->assertFalse(app(ChiSiVede::class)->palestreDoveAllena($trainer->fresh())->contains($palestra->getKey()));
        $this->assertNotNull($trainer->fresh()->tenant, 'congedato, e rimasto senza casa');
    }

    /** 🚨 **La migrazione sistema chi c'era**, e solo chi ne ha bisogno. */
    #[Test]
    public function la_migrazione_da_una_casa_ai_trainer_che_vivevano_dentro(): void
    {
        $palestra = $this->creaPalestra();
        $dentro = $this->trainerNatoDentro($palestra, 'mig-dentro@esempio.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'mig-iscritto@esempio.test');

        (require database_path('migrations/2026_09_18_120000_ogni_trainer_ha_una_casa.php'))->up();

        $this->assertTrue($dentro->fresh()->tenant->ePersonale(), 'la migrazione non gli ha dato una casa');
        $this->assertSame($palestra->getKey(), $iscritto->fresh()->tenant_id, 'la migrazione ha spostato un iscritto');
    }

    /** Fa passare questa persona da `SpazioDelPannello` e dice dove l'ha messa. */
    private function attraversaIlMiddleware(User $utente): ?int
    {
        $contesto = app(TenantContext::class);
        $contesto->set($utente->tenant);

        $richiesta = Request::create('/admin', 'GET');
        $richiesta->setLaravelSession(app('session.store'));
        $richiesta->setUserResolver(fn () => $utente);

        $prima = $contesto->id();

        app(SpazioDelPannello::class)->handle($richiesta, fn () => new Response);

        $dopo = $contesto->id();

        return $dopo === $prima ? null : $dopo;
    }
}
