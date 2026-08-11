<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Filament\Gym\Resources\Members\Pages\ListMembers;
use App\Filament\Gym\Resources\Trainers\TrainerResource;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\ListWorkoutPlans;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\ScriveBusteCifrate;
use Tests\TestCase;

/**
 * B3.5 / B3.6 — chi vede cosa dentro il pannello della palestra.
 *
 * 🚨 **E' il test che protegge la ragione per cui una palestra si fida di
 * mettere i propri clienti qui dentro.** Il trainer che se ne va e apre la
 * palestra dall'altra parte della strada non deve poter portare via l'elenco
 * completo.
 *
 * I test attraversano le pagine Livewire vere, non chiamano `getEloquentQuery()`
 * a mano: e' la lezione della sessione precedente — provare i pezzi non basta,
 * va provato il percorso.
 */
class RoleScopingTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use ScriveBusteCifrate;

    private Tenant $alfa;

    private Tenant $beta;

    private User $admin;

    private User $trainerA;

    private User $trainerB;

    private User $mioIscritto;

    private User $suoIscritto;

    private User $iscrittoBeta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');

        $this->admin = $this->creaUtente($this->alfa, UserRole::GymAdmin, 'admin@alfa.test');
        $this->trainerA = $this->creaUtente($this->alfa, UserRole::Trainer, 'anna@alfa.test');
        $this->trainerB = $this->creaUtente($this->alfa, UserRole::Trainer, 'bruno@alfa.test');

        $this->mioIscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
        $this->suoIscritto = $this->creaUtente($this->alfa, UserRole::Member, 'luigi@alfa.test');
        $this->iscrittoBeta = $this->creaUtente($this->beta, UserRole::Member, 'clara@beta.test');

        $this->assegna($this->trainerA, $this->mioIscritto);
        $this->assegna($this->trainerB, $this->suoIscritto);

        filament()->setCurrentPanel('admin');
    }

    /**
     * Entra nel pannello come farebbe una richiesta vera.
     *
     * 🚨 Due cose che una richiesta HTTP fa e `Livewire::test()` no:
     *  - `ResolveTenant` imposta il contesto di palestra. Senza, le risorse che
     *    filtrano sui ruoli (che sono per tenant) trovano zero righe e il test
     *    fallisce sembrando un problema di permessi.
     *  - `flushSession()` fra due `actingAs` diversi: `AuthenticateSession`
     *    invalida la sessione al cambio utente — sta facendo il suo lavoro — e
     *    senza pulirla la seconda identita' finisce su un 302 verso il login.
     */
    private function entraCome(User $utente): void
    {
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs($utente);

        app(\App\Support\Tenancy\TenantContext::class)->set($utente->tenant);
    }

    private function assegna(User $trainer, User $membro): void
    {
        $trainer->assignedMembers()->attach($membro->getKey(), [
            'tenant_id' => $membro->tenant_id,
            'assigned_at' => now(),
        ]);
    }

    // ───────────────────────── iscritti ─────────────────────────

    #[Test]
    public function the_gym_admin_sees_every_member_of_the_gym(): void
    {
        $this->entraCome($this->admin);

        Livewire::test(ListMembers::class)
            ->assertCanSeeTableRecords([$this->mioIscritto, $this->suoIscritto])
            ->assertCanNotSeeTableRecords([$this->iscrittoBeta]);
    }

    /**
     * 🚨 Il caso centrale: il trainer vede **solo i propri**.
     */
    #[Test]
    public function a_trainer_only_sees_their_own_members(): void
    {
        $this->entraCome($this->trainerA);

        Livewire::test(ListMembers::class)
            ->assertCanSeeTableRecords([$this->mioIscritto])
            ->assertCanNotSeeTableRecords([$this->suoIscritto, $this->iscrittoBeta]);
    }

    /**
     * E non basta nascondere l'elenco: l'accesso diretto all'id deve dare 403.
     *
     * E' l'unico controllo che ferma chi digita un URL a mano — e in un pannello
     * gli URL sono prevedibili.
     */
    #[Test]
    public function opening_the_id_of_an_unassigned_member_is_forbidden(): void
    {
        $this->entraCome($this->trainerA);

        $this
            ->get("/admin/members/{$this->suoIscritto->id}/edit")
            ->assertNotFound();
    }

    #[Test]
    public function the_member_of_another_gym_is_not_reachable_at_all(): void
    {
        $this->entraCome($this->admin);

        $this
            ->get("/admin/members/{$this->iscrittoBeta->id}/edit")
            ->assertNotFound();
    }

    /** L'elenco degli iscritti contiene solo iscritti, non trainer. */
    #[Test]
    public function the_member_list_does_not_contain_staff(): void
    {
        $this->entraCome($this->admin);

        Livewire::test(ListMembers::class)
            ->assertCanNotSeeTableRecords([$this->trainerA, $this->admin]);
    }

    // ───────────────────────── trainer ─────────────────────────

    /**
     * Un trainer non gestisce gli altri trainer: potrebbe assegnarsi gli
     * iscritti dei colleghi, che e' il confine che tutto questo difende.
     */
    #[Test]
    public function only_the_gym_admin_manages_trainers(): void
    {
        $this->entraCome($this->admin);
        $this->assertTrue(TrainerResource::canAccess());

        $this->entraCome($this->trainerA);
        $this->assertFalse(TrainerResource::canAccess());

        $this->get('/admin/trainers')->assertForbidden();
    }

    // ───────────────────────── schede ─────────────────────────

    /**
     * Le schede: i **modelli** sono di tutti, le **assegnate** solo dei propri.
     *
     * E' una regola diversa da quella degli iscritti, e la differenza e' voluta:
     * un modello e' patrimonio comune della palestra, una scheda assegnata e' il
     * programma di una persona precisa.
     */
    #[Test]
    public function a_trainer_sees_the_templates_but_only_their_own_assigned_plans(): void
    {
        $modello = $this->piano(null, 'Modello full body');
        $mia = $this->piano($this->mioIscritto, 'Scheda di Mario');
        $sua = $this->piano($this->suoIscritto, 'Scheda di Luigi');

        $this->entraCome($this->trainerA);

        Livewire::test(ListWorkoutPlans::class)
            ->assertCanSeeTableRecords([$modello, $mia])
            ->assertCanNotSeeTableRecords([$sua]);
    }

    #[Test]
    public function the_gym_admin_sees_every_plan(): void
    {
        $mia = $this->piano($this->mioIscritto, 'Scheda di Mario');
        $sua = $this->piano($this->suoIscritto, 'Scheda di Luigi');

        $this->entraCome($this->admin);

        Livewire::test(ListWorkoutPlans::class)
            ->assertCanSeeTableRecords([$mia, $sua]);
    }

    #[Test]
    public function opening_the_plan_of_an_unassigned_member_is_forbidden(): void
    {
        $sua = $this->piano($this->suoIscritto, 'Scheda di Luigi');

        $this->entraCome($this->trainerA);

        $this
            ->get("/admin/workout-plans/{$sua->id}/edit")
            ->assertNotFound();
    }

    // ───────────────────────── assegnazione ─────────────────────────

    /**
     * 🚨 Assegnare un modello **copia**, non condivide.
     *
     * Se venti persone puntassero alla stessa riga, la prima personalizzazione
     * le cambierebbe tutte.
     */
    #[Test]
    public function assigning_a_template_makes_a_copy(): void
    {
        $modello = $this->piano(null, 'Modello');

        $modello->exercises()->create([
            'exercise_id' => $this->esercizio(),
            'position' => 1, 'sets' => 3, 'reps' => '10',
        ]);

        $copia = $this->ctx()->runAs($this->alfa,
            fn () => $modello->assignTo($this->mioIscritto, $this->admin));

        $this->assertNotSame($modello->id, $copia->id);
        $this->assertSame($this->mioIscritto->id, $copia->member_id);
        $this->assertSame(PlanStatus::Draft, $copia->status);
        $this->assertSame(1, $copia->exercises()->count(), 'La copia e\' arrivata senza esercizi.');

        // Il modello non si e' mosso.
        $this->assertNull($modello->refresh()->member_id);

        // E modificare la copia non tocca il modello.
        $copia->exercises()->first()->update(['sets' => 5]);
        $this->assertSame(3, $modello->exercises()->first()->sets);
    }

    // ───────────────────────── chat ─────────────────────────

    /**
     * 🚨 **Nemmeno il conteggio dei non letti arriva al titolare.**
     *
     * ⚠️ **Il test è cambiato in S6, la regola no.** La pagina dei messaggi non
     * mostra più nessuna conversazione a nessuno — i corpi sono buste cifrate e
     * il server non ha le chiavi — quindi non ha più senso verificare che il
     * titolare non le veda: **non le vede più nessuno da qui**.
     *
     * Resta però un pezzo che il server sa ancora fare, perché si calcola sui
     * metadati: **quanti messaggi non letti ci sono**. Ed è proprio lì che la
     * riservatezza poteva rientrare dalla finestra — un badge che dice al
     * titolare «i tuoi trainer hanno 14 messaggi non letti» è già
     * un'informazione sulle conversazioni altrui.
     *
     * Il `gym_admin` è il caso più insidioso perché ha il permesso su tutto il
     * resto della palestra: qui si ferma.
     */
    #[Test]
    public function the_gym_owner_gets_no_signal_about_other_peoples_chats(): void
    {
        $conversazione = $this->ctx()->runAs($this->alfa,
            fn () => \App\Models\Conversation::between($this->trainerA, $this->mioIscritto));

        $this->ctx()->runAs($this->alfa, fn () => $conversazione->messages()->create([
            'sender_id' => $this->mioIscritto->id,
            ...$this->busta('Ho un problema al ginocchio'),
        ]));

        $this->entraCome($this->admin);

        $this->assertSame(
            0,
            Livewire::test(\App\Filament\Gym\Pages\Chat::class)->get('nonLetti'),
            'Il titolare conta i messaggi non letti delle chat dei suoi trainer.',
        );

        $this->assertNull(
            \App\Filament\Gym\Pages\Chat::getNavigationBadge(),
            'Il badge nel menù rivela al titolare l\'attività delle chat altrui.',
        );

        // Il trainer che ci sta dentro, invece, il proprio conteggio ce l'ha:
        // senza, non saprebbe mai che qualcuno gli ha scritto.
        $this->entraCome($this->trainerA);

        $this->assertSame(
            1,
            Livewire::test(\App\Filament\Gym\Pages\Chat::class)->get('nonLetti'),
        );
        $this->assertSame('1', \App\Filament\Gym\Pages\Chat::getNavigationBadge());
    }

    // ───────────────────────── impostazioni ─────────────────────────

    #[Test]
    public function only_the_gym_admin_opens_the_settings(): void
    {
        $this->entraCome($this->admin);
        $this->get('/admin/gym-settings')->assertOk();

        $this->entraCome($this->trainerA);
        $this->get('/admin/gym-settings')->assertForbidden();
    }

    /**
     * 🚨 I termini del contratto non si toccano dal pannello del cliente.
     *
     * Stato, piano e tetto AI restano fuori dal modulo: un cliente che si toglie
     * da solo la sospensione non e' un cliente, e' un problema di fatturazione.
     */
    #[Test]
    public function the_settings_page_cannot_touch_the_commercial_terms(): void
    {
        $this->entraCome($this->admin);

        $componenti = json_encode(
            Livewire::test(\App\Filament\Gym\Pages\GymSettings::class)->get('data'),
        );

        foreach (['status', 'plan', 'ai_monthly_token_cap', 'trial_ends_at'] as $vietato) {
            $this->assertStringNotContainsString(
                $vietato,
                (string) $componenti,
                "Il campo «{$vietato}» e' finito nel modulo del cliente.",
            );
        }
    }

    // ───────────────────────── aiuti ─────────────────────────

    private function piano(?User $membro, string $nome): WorkoutPlan
    {
        return $this->ctx()->runAs($this->alfa, fn () => WorkoutPlan::create([
            'member_id' => $membro?->getKey(),
            'name' => $nome,
            'created_by' => $this->admin->id,
        ]));
    }

    private function esercizio(): int
    {
        return $this->ctx()->runWithoutTenant(fn () => \App\Models\Exercise::create([
            'name' => 'Panca piana',
        ]))->getKey();
    }
}
