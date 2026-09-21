<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\CreateWorkoutPlan;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SpazioDelPannello;
use App\Models\WorkoutPlan;
use App\Support\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🧩 **Il contesto della palestra sopravvive alle richieste di Livewire** —
 * 21/09/2026, fase F0 di `plan_UX_pannello.md`.
 *
 * ══ 📌 IL DIFETTO RIFERITO ════════════════════════════════════════════════
 *
 * Il committente, provando a creare una scheda vuota dal pannello:
 *
 * > `SQLSTATE[23000]: Integrity constraint violation: 1048 Column 'tenant_id'
 * > cannot be null`
 *
 * ══ ⛔ LA CAUSA, CHE NON ERA NEL FORM ═════════════════════════════════════
 *
 * Filament applica `middleware()` e `authMiddleware()` alle **pagine**. Il
 * salvataggio però non è una pagina: è una richiesta di Livewire a
 * `/livewire/update`, e lì Livewire ri-applica **solo** i middleware
 * dichiarati *persistenti*.
 *
 * 🚨 `ResolveTenant` non lo era. Quindi in **ogni** richiesta che salvava
 * qualcosa il contesto era vuoto, `BelongsToTenant` non aveva niente da
 * scrivere, e il database rifiutava la riga.
 *
 * ⚠️ **E non era solo la scheda**: `TenantScope` a contesto vuoto **non
 * filtra** (di proposito: comandi e seeder girano senza tenant). Con il
 * contesto vuoto su ogni richiesta Livewire, le azioni del pannello giravano
 * senza il filtro della palestra.
 *
 * ══ 💡 COSA DIFENDE QUESTO FILE ═══════════════════════════════════════════
 *
 * ⛔ **Non che una scheda si salvi**: quello lo provano i test della risorsa.
 * Difende le due cose che si possono togliere senza che nulla diventi rosso:
 *
 * 1. che i due middleware del contesto siano **dichiarati persistenti**;
 * 2. che un modello con `BelongsToTenant` **si rifiuti** di nascere senza
 *    palestra, dicendo cosa manca invece di far esplodere il database.
 */
class IlContestoSopravviveALivewireTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🚨 **Il pannello dichiara persistenti i middleware del contesto.**
     *
     * ⚠️ È un test sulla **configurazione**, e va bene così: la conseguenza di
     * togliere quella riga non si vede da nessun'altra parte finché qualcuno
     * non prova a salvare qualcosa — cioè in produzione.
     */
    #[Test]
    public function i_middleware_del_contesto_sono_persistenti(): void
    {
        /*
         * 💡 Filament li passa a `Livewire::addPersistentMiddleware()` mentre
         * costruisce il pannello: qui si rilegge quel registro, che è l'unico
         * posto in cui la dichiarazione esiste davvero.
         */
        Filament::getPanel('admin')->register([]);

        $persistenti = Livewire::getPersistentMiddleware();

        $this->assertContains(
            ResolveTenant::class,
            $persistenti,
            'Senza questo, ogni salvataggio dal pannello gira senza palestra: '
            .'le creazioni falliscono e le letture non sono filtrate.',
        );

        $this->assertContains(
            SpazioDelPannello::class,
            $persistenti,
            'Chi ha due cappelli salverebbe nello spazio sbagliato.',
        );
    }

    /**
     * 🚨 **Senza palestra non si scrive**, e si capisce perché.
     *
     * ⛔ Prima qui arrivava `SQLSTATE[23000] … Column 'tenant_id' cannot be
     * null`: mezzo SQL in faccia, e nessun accenno alla cosa che conta.
     */
    #[Test]
    public function creare_senza_contesto_dice_cosa_manca(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('il contesto del tenant è vuoto');

        app(TenantContext::class)->runWithoutTenant(
            fn () => WorkoutPlan::create(['name' => 'Scheda senza casa']),
        );
    }

    /**
     * ⚠️ **E il super admin resta senza palestra**, che è l'unica eccezione.
     *
     * 🚨 `users.tenant_id` è nullable **apposta**: il super admin della
     * piattaforma non sta in nessuna palestra, e `canAccessPanel()` ci conta.
     * ⛔ Senza questo test, la guardia qui sopra sembrerebbe una regola che vale
     * per tutti — e la prima persona che la irrigidisse chiuderebbe fuori
     * l'amministratore dalla piattaforma.
     */
    #[Test]
    public function il_super_admin_invece_nasce_senza_palestra(): void
    {
        $god = $this->creaSuperAdmin('altro.god@piattaforma.test');

        $this->assertNull($god->tenant_id);
        $this->assertFalse($god->tenantObbligatorio());
    }

    /**
     * 💡 E con la palestra al suo posto, la stessa scrittura passa.
     *
     * ⚠️ Serve a dire che la difesa qui sopra **non è di troppo**: rifiuta il
     * caso rotto, non il caso normale.
     */
    #[Test]
    public function con_il_contesto_la_scheda_nasce(): void
    {
        $palestra = $this->creaPalestra();
        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'titolare@esempio.test');

        $this->actingAs($titolare);
        filament()->setCurrentPanel('admin');

        $scheda = app(TenantContext::class)->runAs(
            $palestra,
            fn (): WorkoutPlan => WorkoutPlan::create(['name' => 'Full body A']),
        );

        $this->assertSame($palestra->getKey(), $scheda->tenant_id);

        // ⚠️ E resta un **modello della palestra**: nessun destinatario.
        $this->assertNull($scheda->member_id);
    }

    /**
     * 🧩 La pagina di creazione si apre e salva, dentro il contesto.
     *
     * ⛔ Questo test **non riproduce** il difetto (in un test Livewire nudo i
     * middleware non girano affatto, quindi il contesto lo si mette a mano):
     * serve a dire che il resto della catena — form, `mutateFormDataBeforeCreate`,
     * `created_by` — fa il suo lavoro.
     */
    #[Test]
    public function dal_pannello_si_crea_una_scheda(): void
    {
        $palestra = $this->creaPalestra();
        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'titolare@esempio.test');

        $this->actingAs($titolare);
        filament()->setCurrentPanel('admin');
        app(TenantContext::class)->set($palestra);

        Livewire::test(CreateWorkoutPlan::class)
            ->fillForm(['name' => 'Scheda dal pannello'])
            ->call('create')
            ->assertHasNoFormErrors();

        $scheda = WorkoutPlan::withoutGlobalScopes()->where('name', 'Scheda dal pannello')->sole();

        $this->assertSame($palestra->getKey(), $scheda->tenant_id);
        $this->assertSame($titolare->getKey(), $scheda->created_by);
    }
}
