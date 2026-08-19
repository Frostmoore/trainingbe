<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\Members\MemberResource;
use App\Filament\Gym\Resources\NutritionPlans\NutritionPlanResource;
use App\Filament\Gym\Resources\WorkoutPlans\WorkoutPlanResource;
use App\Models\NutritionPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Tenancy\CreaTenantPersonale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * I ruoli senza palestra — F2 della Parte B, 13/08/2026.
 *
 * ── 🚨 Il difetto che questo file impedisce di reintrodurre ─────────────────
 *
 * Prima di F2, `UserRole::canAccessAnyPanel()` era
 * `return $this !== self::Member;` — una **lista di esclusione**. Aggiungendo
 * `FreeUser` all'enum, quella riga gli avrebbe risposto `true` **in silenzio**:
 * nessun errore, nessun test rosso, solo un ruolo nuovo che risulta ammesso a un
 * pannello per il fatto di non essere `Member`.
 *
 * ⚠️ **Onestà su quanto era grave davvero.** Cercandolo, `canAccessAnyPanel()`
 * **non è chiamato da nessuna parte**: il cancello vero è
 * `User::canAccessPanel()`, che è sempre stato una lista di **inclusione** e non
 * avrebbe aperto niente. Quindi non era una falla aperta — era un metodo che si
 * legge come una regola di sicurezza e che non ne applica nessuna.
 *
 * 💡 È il difetto ricorrente di questo progetto, per la tredicesima volta: *un
 * commento non è un vincolo*. La risposta qui è duplice — il metodo è diventato
 * un `match` **senza `default`** (aggiungere un caso all'enum senza decidere è
 * un errore fatale), e questo file **confronta ciò che dice con ciò che il
 * cancello vero fa**, ruolo per ruolo.
 */
class RuoliSenzaPalestraTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🚨 **La tabella delle attese, scritta a mano di proposito.**
     *
     * È l'unico elenco a mano di tutto il file, ed è voluto: un elenco derivato
     * dal codice proverebbe che il codice è d'accordo con sé stesso. Qui c'è
     * scritto cosa **deve** succedere, e i test verificano che il codice ci
     * corrisponda.
     *
     * @var array<string, array{god: bool, admin: bool}>
     */
    private const ATTESE = [
        'super_admin' => ['god' => true, 'admin' => false],
        'gym_admin' => ['god' => false, 'admin' => true],
        'trainer' => ['god' => false, 'admin' => true],
        'member' => ['god' => false, 'admin' => false],

        // 🆕 F2.3 — entra nel pannello palestra, ma per ora non ci trova niente:
        // vedi `a_free_trainer_enters_the_panel_and_sees_nothing`.
        'free_trainer' => ['god' => false, 'admin' => true],

        // 💡 Sta con `member`, e non è un caso: sono la stessa persona vista da
        // due lati — qualcuno che usa l'app e basta.
        'free_user' => ['god' => false, 'admin' => false],

        /*
         * 🆕 N22.1 — il nutrizionista, **predisposto e non attivo**.
         *
         * 🚨 **Nessun pannello, ed è la risposta giusta per adesso.** Il ruolo
         * esiste perché la struttura a grammi resti provata (N19), ma nessun
         * percorso reale lo assegna e non c'è ancora niente da mostrargli.
         *
         * ⚠️ Il giorno che N22.7 gli darà un pannello, questa riga va cambiata
         * **insieme** — e questo test è il posto in cui ci si ricorda di farlo.
         * 💡 Nel frattempo «fallisce chiuso», che è lo stesso comportamento
         * scelto per `free_trainer` in F2 e per la stessa ragione.
         */
        'nutrizionista' => ['god' => false, 'admin' => false],
        'free_nutrizionista' => ['god' => false, 'admin' => false],
    ];

    // ───────────────── il test che si accorge di un ruolo nuovo ─────────────────

    /**
     * 🚨 **Aggiungere un ruolo all'enum senza decidere dove entra rompe qui.**
     *
     * Non è un test sulla correttezza: è il gancio che costringe chi aggiunge un
     * caso a fermarsi e rispondere alla domanda. Senza, un ruolo nuovo
     * passerebbe da questo file senza essere mai verificato — e i due test qui
     * sotto direbbero «tutto a posto» avendo controllato sei ruoli su sette.
     */
    #[Test]
    public function every_role_has_a_declared_expectation(): void
    {
        $senzaAttesa = array_values(array_filter(
            UserRole::cases(),
            static fn (UserRole $r): bool => ! array_key_exists($r->value, self::ATTESE),
        ));

        $this->assertSame([], array_map(static fn (UserRole $r): string => $r->value, $senzaAttesa), sprintf(
            "Questi ruoli sono in UserRole ma non in RuoliSenzaPalestraTest::ATTESE:\n  - %s\n"
            .'Va deciso — e scritto — in quali pannelli entrano.',
            implode("\n  - ", array_map(static fn (UserRole $r): string => $r->value, $senzaAttesa)),
        ));

        // E il contrario: un'attesa per un ruolo che non esiste più è una riga
        // che nessuno sta più verificando, e va tolta.
        $fantasmi = array_diff(array_keys(self::ATTESE), UserRole::values());
        $this->assertSame([], array_values($fantasmi), 'Attese per ruoli che non esistono più.');
    }

    /**
     * Il riepilogo dell'enum deve dire la verità su ciò che il cancello fa.
     *
     * ⚠️ `UserRole::canAccessAnyPanel()` non lo chiama nessuno: senza questo
     * test potrebbe divergere da `User::canAccessPanel()` per mesi senza che
     * niente lo segnali, e chi lo legge per capire come stanno le cose sarebbe
     * indirizzato male.
     */
    #[Test]
    public function the_enum_summary_agrees_with_the_real_gate(): void
    {
        foreach (self::ATTESE as $valore => $attesa) {
            $ruolo = UserRole::from($valore);

            $this->assertSame(
                $attesa['god'] || $attesa['admin'],
                $ruolo->canAccessAnyPanel(),
                "UserRole::{$valore}->canAccessAnyPanel() non corrisponde a dove quel ruolo entra davvero.",
            );
        }
    }

    // ───────────────────── il cancello vero, ruolo per ruolo ─────────────────────

    #[Test]
    public function each_role_can_open_exactly_the_panels_it_should(): void
    {
        $palestra = $this->creaPalestra();

        foreach (self::ATTESE as $valore => $attesa) {
            $ruolo = UserRole::from($valore);
            $utente = $this->utenteCol($ruolo, $palestra);

            foreach (['god', 'admin'] as $pannello) {
                $this->assertSame(
                    $attesa[$pannello],
                    $utente->canAccessPanel(filament()->getPanel($pannello)),
                    "Il ruolo {$valore} sul pannello /{$pannello}: risposta diversa da quella attesa.",
                );
            }
        }
    }

    /** ⚠️ Un utente disattivato non entra da nessuna parte, qualunque ruolo abbia. */
    #[Test]
    public function a_deactivated_user_opens_nothing(): void
    {
        $palestra = $this->creaPalestra();

        foreach ([UserRole::GymAdmin, UserRole::Trainer, UserRole::FreeTrainer] as $ruolo) {
            $utente = $this->utenteCol($ruolo, $palestra);
            $utente->forceFill(['is_active' => false])->save();

            $this->assertFalse(
                $utente->canAccessPanel(filament()->getPanel('admin')),
                "Il ruolo {$ruolo->value} disattivato riesce ancora a entrare in /admin.",
            );
        }
    }

    // ────────────────── 🚨 Il trainer indipendente fallisce chiuso ──────────────────

    /**
     * **Entra nel pannello e non vede niente. È voluto, non un lavoro a metà.**
     *
     * Ogni scoping del pannello palestra è costruito sulla coppia
     * `isGymAdmin()` / `isTrainer()` e ha, per chiunque non sia nessuno dei due,
     * il ramo `return $query->whereRaw('1 = 0')`.
     *
     * 🚨 **Questo test vale più di quanto sembri**: non prova che il pannello sia
     * vuoto — prova che il pannello **fallisce chiuso** davanti a un ruolo che
     * non conosce. È la proprietà che permetterà di aggiungere `FreeTrainer` in
     * F5.3 e i ruoli futuri senza aprire per sbaglio i dati di una palestra.
     *
     * ⚠️ Il giorno in cui F5.3 darà al trainer indipendente qualcosa da vedere,
     * questo test andrà **cambiato con intenzione**, non cancellato: dovrà
     * diventare «vede i propri modelli e nessun dato di nessuna palestra».
     */
    #[Test]
    public function a_free_trainer_enters_the_panel_and_sees_nothing(): void
    {
        // Una palestra piena: iscritti, una scheda e un piano.
        $palestra = $this->creaPalestra('Palestra Alfa', 'alfa', 'ALFA2345');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@alfa.test');

        $this->ctx()->runAs($palestra, function () use ($iscritto): void {
            WorkoutPlan::create([
                'tenant_id' => $iscritto->tenant_id, 'member_id' => $iscritto->id, 'name' => 'Scheda della palestra',
            ]);
            NutritionPlan::create([
                'tenant_id' => $iscritto->tenant_id, 'member_id' => $iscritto->id, 'name' => 'Piano della palestra',
            ]);
        });

        $libero = app(CreaTenantPersonale::class)(
            'Trainer Indipendente',
            'indipendente@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        // La porta è aperta…
        $this->assertTrue(
            $libero->canAccessPanel(filament()->getPanel('admin')),
            'Il trainer indipendente non riesce a entrare nel pannello.',
        );

        // …e dentro non c'è niente di nessuno.
        $this->actingAs($libero);

        $this->ctx()->runAs($libero->tenant, function (): void {
            $this->assertSame([], MemberResource::getEloquentQuery()->pluck('id')->all(),
                'Un trainer indipendente sta vedendo degli iscritti.');

            $this->assertSame([], WorkoutPlanResource::getEloquentQuery()->pluck('id')->all(),
                'Un trainer indipendente sta vedendo una scheda.');

            $this->assertSame([], NutritionPlanResource::getEloquentQuery()->pluck('id')->all(),
                'Un trainer indipendente sta vedendo un piano alimentare.');
        });
    }

    /**
     * 🚨 E non deve essere scambiato per un trainer di palestra.
     *
     * La tentazione, scrivendo F5 o F6, sarà far rispondere `true` anche a
     * `isTrainer()` «perché in fondo è un trainer». Sarebbe un errore: nel
     * pannello `isTrainer()` significa *«membro dello staff di questa palestra»*,
     * e da lì discende quali iscritti vede.
     */
    #[Test]
    public function a_free_trainer_is_not_a_gym_trainer(): void
    {
        $libero = app(CreaTenantPersonale::class)(
            'Trainer Indipendente',
            'indipendente@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $this->assertTrue($libero->isFreeTrainer());
        $this->assertFalse($libero->isTrainer(), 'Un trainer indipendente viene scambiato per staff di una palestra.');
        $this->assertFalse($libero->isGymAdmin());
        $this->assertFalse($libero->isMember());
    }

    // ───────────────────── F2.2 — i ruoli nascono nel tenant ─────────────────────

    /**
     * ⚠️ I ruoli spatie di un tenant personale si creano **dentro** quel tenant,
     * esattamente come per una palestra: è ciò che rende `assignRole()`
     * possibile, e il motivo per cui la scelta D1 fa sparire il problema dei
     * «ruoli senza palestra» invece di risolverlo.
     */
    #[Test]
    public function a_personal_tenant_gets_the_same_roles_as_a_gym(): void
    {
        $libero = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $nelTenant = Role::query()
            ->where('tenant_id', $libero->tenant_id)
            ->pluck('name')
            ->sort()
            ->values()
            ->all();

        $attesi = collect(UserRole::tenantScoped())
            ->map(static fn (UserRole $r): string => $r->value)
            ->sort()
            ->values()
            ->all();

        $this->assertSame($attesi, $nelTenant);
    }

    /** Il valore di serie di `CreaTenantPersonale` è `FreeUser` da F2.1. */
    #[Test]
    public function someone_who_signs_up_alone_is_a_free_user(): void
    {
        $libero = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $this->assertTrue($libero->isFreeUser());
        $this->assertFalse($libero->isMember(), 'Chi si iscrive da solo risulta ancora «iscritto a una palestra».');
        $this->assertFalse($libero->canAccessPanel(filament()->getPanel('admin')));
        $this->assertFalse($libero->canAccessPanel(filament()->getPanel('god')));
    }

    // ───────────────────────────── utilità ─────────────────────────────

    /**
     * Un utente con quel ruolo, nel posto giusto per quel ruolo.
     *
     * 💡 I ruoli «liberi» vivono in un tenant personale e gli altri in palestra:
     * costruirli tutti nello stesso posto proverebbe qualcosa che nella realtà
     * non succede.
     */
    private function utenteCol(UserRole $ruolo, Tenant $palestra): User
    {
        if ($ruolo === UserRole::SuperAdmin) {
            return $this->creaSuperAdmin();
        }

        if (in_array($ruolo, [UserRole::FreeUser, UserRole::FreeTrainer], true)) {
            return app(CreaTenantPersonale::class)(
                $ruolo->label(),
                $ruolo->value.'@esempio.test',
                ['password' => self::FAKE_PASSWORD],
                $ruolo,
            );
        }

        return $this->creaUtente($palestra, $ruolo, $ruolo->value.'@demo.test');
    }
}
