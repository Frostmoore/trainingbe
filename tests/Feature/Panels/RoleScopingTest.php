<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Filament\Gym\Pages\Chat;
use App\Filament\Gym\Pages\GymSettings;
use App\Filament\Gym\Resources\Members\Pages\ListMembers;
use App\Filament\Gym\Resources\Trainers\TrainerResource;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\ListWorkoutPlans;
use App\Models\Conversation;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Support\Tenancy\TenantContext;
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

        app(TenantContext::class)->set($utente->tenant);
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
     * 🆕 ⚠️ **Il trainer vede tutta la SUA palestra** — 16/09/2026.
     *
     * ⛔ **Questo test diceva l'opposto**: *«il trainer vede solo i propri
     * assegnati»*, la regola B3.5. 📌 Il committente l'ha cambiata: *«tutti in
     * lettura, ma le schede e i piani entrano nel suo profilo personale, non
     * può assegnarli dal server»*.
     *
     * 🚨 **La ragione del cambio**: dal 15/09 nell'app quello stesso trainer
     * vede già **tutti** gli iscritti della palestra. Due risposte diverse alla
     * stessa domanda, a seconda di dove si guardava.
     *
     * ⚖️ **E il confine vero resta dov'era**: un'altra palestra non si vede,
     * e su questi non si può fare niente — vedi i due test qui sotto.
     */
    #[Test]
    public function a_trainer_sees_every_member_of_their_gym(): void
    {
        $this->entraCome($this->trainerA);

        Livewire::test(ListMembers::class)
            ->assertCanSeeTableRecords([$this->mioIscritto, $this->suoIscritto])
            ->assertCanNotSeeTableRecords([$this->iscrittoBeta]);
    }

    /**
     * ⚖️ **Vederli sì, modificarli no** — 🆕 e adesso vale per **tutti**.
     *
     * ══ 🚨 COSA È CAMBIATO — 23/09/2026, F5.7 ═══════════════════════════
     *
     * ⛔ **La pagina di modifica di un iscritto non esiste più**, per nessuno:
     * né per il trainer né per il gestore. 📌 *«⛔ niente identità modificabile,
     * ⛔ niente password»*.
     *
     * ⚠️ Cambiare l'email di un account è **prenderne il controllo** (il reset
     * della password arriva lì), e scegliere la password di qualcun altro
     * significa poter entrare al posto suo. ⛔ L'account non dipende dalla
     * palestra (D4).
     *
     * 💡 Prima la difesa era `UserPolicy::update()` — una regola che si poteva
     * dimenticare di chiamare. Adesso **la porta non c'è**: è la stessa
     * protezione, ma non richiede che qualcuno si ricordi di applicarla.
     */
    #[Test]
    public function la_pagina_di_modifica_di_un_iscritto_non_esiste_per_nessuno(): void
    {
        $this->entraCome($this->trainerA);
        $this->get("/admin/members/{$this->mioIscritto->id}/edit")->assertNotFound();

        $this->entraCome($this->admin);
        $this->get("/admin/members/{$this->mioIscritto->id}/edit")->assertNotFound();
    }

    /** 👁️ La scheda invece si apre: è una pagina che si guarda. */
    #[Test]
    public function la_scheda_di_un_iscritto_si_apre(): void
    {
        $this->entraCome($this->trainerA);

        $this->get("/admin/members/{$this->mioIscritto->id}")->assertOk();
    }

    #[Test]
    public function the_member_of_another_gym_is_not_reachable_at_all(): void
    {
        $this->entraCome($this->admin);

        $this
            ->get("/admin/members/{$this->iscrittoBeta->id}")
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
     * ══ 📄 L'ELENCO CONTIENE SOLO MODELLI — 23/09/2026, F5.14 ═════════════
     *
     * 📌 **R2**: *«Le schede vivono sui telefoni. Non ci sono destinatari.
     * Quando un trainer (o una palestra) creano una scheda, questa va a finire
     * tra le schede di tutti i trainer della palestra, e dal telefono questi le
     * possono inviare a chi vogliono.»*
     *
     * 🚨 **Questo test diceva il contrario**, ed era giusto quando lo diceva:
     * i modelli a tutti, le assegnate solo ai propri. ⛔ Adesso le schede con un
     * destinatario **non compaiono affatto** — sono quelle che l'iscritto
     * scrive per sé dall'app, e sono sue.
     *
     * 💡 **E questo chiude anche F5.10**: se tutto quello che c'è è un modello
     * della palestra, la palestra vede le schede dei suoi trainer per
     * costruzione.
     */
    #[Test]
    public function l_elenco_delle_schede_contiene_solo_i_modelli(): void
    {
        $modello = $this->piano(null, 'Modello full body');
        $mia = $this->piano($this->mioIscritto, 'Scheda di Mario');
        $sua = $this->piano($this->suoIscritto, 'Scheda di Luigi');

        $this->entraCome($this->trainerA);

        Livewire::test(ListWorkoutPlans::class)
            ->assertCanSeeTableRecords([$modello])
            ->assertCanNotSeeTableRecords([$mia, $sua]);
    }

    /** 🏢 E il gestore vede gli stessi modelli, non di più e non di meno. */
    #[Test]
    public function the_gym_admin_sees_every_plan(): void
    {
        $modello = $this->piano(null, 'Modello full body');
        $mia = $this->piano($this->mioIscritto, 'Scheda di Mario');

        $this->entraCome($this->admin);

        Livewire::test(ListWorkoutPlans::class)
            ->assertCanSeeTableRecords([$modello])
            ->assertCanNotSeeTableRecords([$mia]);
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
            fn () => Conversation::between($this->trainerA, $this->mioIscritto));

        $this->ctx()->runAs($this->alfa, fn () => $conversazione->messages()->create([
            'sender_id' => $this->mioIscritto->id,
            ...$this->busta('Ho un problema al ginocchio'),
        ]));

        $this->entraCome($this->admin);

        $this->assertSame(
            0,
            Livewire::test(Chat::class)->get('nonLetti'),
            'Il titolare conta i messaggi non letti delle chat dei suoi trainer.',
        );

        $this->assertNull(
            Chat::getNavigationBadge(),
            'Il badge nel menù rivela al titolare l\'attività delle chat altrui.',
        );

        // Il trainer che ci sta dentro, invece, il proprio conteggio ce l'ha:
        // senza, non saprebbe mai che qualcuno gli ha scritto.
        $this->entraCome($this->trainerA);

        $this->assertSame(
            1,
            Livewire::test(Chat::class)->get('nonLetti'),
        );
        $this->assertSame('1', Chat::getNavigationBadge());
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
            Livewire::test(GymSettings::class)->get('data'),
        );

        foreach (['status', 'plan', 'ai_monthly_call_cap', 'trial_ends_at'] as $vietato) {
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
        return $this->ctx()->runWithoutTenant(fn () => Exercise::create([
            'name' => 'Panca piana',
        ]))->getKey();
    }
}
