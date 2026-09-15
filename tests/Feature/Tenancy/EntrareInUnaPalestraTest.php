<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\FoodEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiInPalestra;
use App\Services\Tenancy\UnisciAUnaPalestra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Un utente senza palestra entra in una palestra — requisito **B4**, 13/08/2026.
 *
 * ── 🚨 Perché era stato rimandato, e cosa lo rendeva rischioso ──────────────
 *
 * `plan_parte_b.md` §5.1 lo scriveva fin dal principio: **non è un `UPDATE` su
 * una colonna, è una migrazione di dati.** Una persona con un tenant personale
 * ha diario, allenamenti, piani, foto e conversazioni **tutti marcati con il suo
 * tenant**.
 *
 * ⚠️ Spostando solo `users.tenant_id`, il `TenantScope` smetterebbe di far
 * vedere a quella persona **tutta la sua storia**. Non cancellata: *invisibile*
 * — che è peggio, perché non se ne accorge nessuno finché non la cerca.
 */
class EntrareInUnaPalestraTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private function libero(string $email = 'mario@esempio.test'): User
    {
        return app(CreaTenantPersonale::class)($email, $email, [
            'password' => self::FAKE_PASSWORD,
        ]);
    }

    /**
     * Fa entrare qualcuno in palestra **come si entra davvero** — 15/09/2026.
     *
     * ⛔ **Qui si passava da `POST /account/join-gym`, col codice digitato a
     * mano**, e quell'endpoint non esiste piu': dal 15/09 in palestra si entra
     * solo da un link d'invito. 📌 *«Il codice d'ingresso va tolto»*.
     *
     * 🚨 **Il trasloco che questi test sorvegliano non cambia**: e' sempre
     * `UnisciAUnaPalestra`, chiamata da `accetta()` invece che dal controller.
     * Cambia la porta, non cosa succede quando si entra.
     *
     * ⚠️ L'invito lo emette un `gym_admin`, che e' quello che fa il pannello.
     */
    private function entraDaInvito(User $chi, Tenant $palestra): TestResponse
    {
        $gestore = $this->creaUtente(
            $palestra,
            UserRole::GymAdmin,
            'gestore-'.$palestra->getKey().'@esempio.test',
        );

        $invito = app(InvitiInPalestra::class)->invita($palestra, $gestore, $chi->email);

        return $this->comeApp($chi)
            ->postJson('/api/v1/inviti-palestra/'.$invito->token.'/accetta');
    }

    private function conDeiDati(User $utente): void
    {
        $this->ctx()->runAs($utente->tenant, function () use ($utente): void {
            FoodEntry::create([
                'tenant_id' => $utente->tenant_id,
                'user_id' => $utente->getKey(),
                'eaten_at' => now(),
                'meal' => MealType::Breakfast,
                'description' => 'Due uova',
                'kcal' => 140,
            ]);

            WorkoutPlan::create([
                'tenant_id' => $utente->tenant_id,
                'member_id' => $utente->getKey(),
                'name' => 'La mia scheda',
            ]);
        });
    }

    // ─────────────── 🚨 La storia segue la persona ───────────────

    /**
     * **Il test che dà senso a tutta la funzione.**
     *
     * ⚠️ Non prova che il passaggio «funzioni»: prova che **dopo** il passaggio
     * la persona veda ancora le proprie cose. È l'unico modo di accorgersi della
     * migrazione mancata, perché senza di essa non succede niente di visibile —
     * le righe restano in tabella e semplicemente non si vedono più.
     */
    #[Test]
    public function the_whole_history_follows_the_person(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $utente = $this->libero();
        $this->conDeiDati($utente);

        app(UnisciAUnaPalestra::class)($utente, 'ALFA2345');

        $fresco = User::withoutGlobalScopes()->findOrFail($utente->getKey());

        $this->assertSame($palestra->id, $fresco->tenant_id);

        // 🚨 Dal contesto della palestra, le sue cose ci sono ancora.
        $this->ctx()->runAs($palestra, function () use ($fresco): void {
            $this->assertSame(1, FoodEntry::where('user_id', $fresco->getKey())->count(),
                'Il diario è diventato invisibile.');
            $this->assertSame(1, WorkoutPlan::where('member_id', $fresco->getKey())->count(),
                'Le schede sono diventate invisibili.');
        });
    }

    /** E l'HTTP lo conferma dal lato di chi usa l'app. */
    #[Test]
    public function the_api_lets_you_join_and_returns_the_new_branding(): void
    {
        $this->creaPalestra('Palestra Alfa', 'alfa', 'ALFA2345');
        $utente = $this->libero();

        $this->entraDaInvito($utente, Tenant::where('slug', 'alfa')->firstOrFail())
            ->assertOk()
            ->assertJsonPath('data.palestra.name', 'Palestra Alfa');
    }

    /**
     * ⚠️ **Si entra come `Member`, sempre.**
     *
     * Chi era `FreeTrainer` non diventa trainer della palestra perché ha
     * digitato il suo codice: quello lo decide la palestra dal suo pannello.
     */
    #[Test]
    public function you_always_join_as_a_member(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');

        $trainer = app(CreaTenantPersonale::class)(
            'Trainer', 'trainer@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        app(UnisciAUnaPalestra::class)($trainer, 'ALFA2345');

        $ruoli = $this->ctx()->runAs($palestra, function () use ($trainer): array {
            $f = User::withoutGlobalScopes()->findOrFail($trainer->getKey());
            $f->unsetRelation('roles');

            return $f->getRoleNames()->all();
        });

        $this->assertSame([UserRole::Member->value], $ruoli);
    }

    /**
     * 🚨 Senza rifare i ruoli, la persona resterebbe **senza nessun ruolo**
     * nella palestra: un account che esiste e non può fare niente.
     */
    #[Test]
    public function the_old_roles_do_not_survive(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $utente = $this->libero();
        $vecchio = $utente->tenant_id;

        app(UnisciAUnaPalestra::class)($utente, 'ALFA2345');

        $this->assertSame(
            0,
            DB::table('model_has_roles')
                ->where('model_id', $utente->getKey())
                ->where('tenant_id', $vecchio)
                ->count(),
            'Il ruolo nel tenant vecchio è rimasto lì.',
        );
    }

    // ─────────────── ⚠️ Cosa NON si sposta ───────────────

    /**
     * 🚨 **La contabilità AI resta dov'era.**
     *
     * `ai_usage_logs` dice quanto è costata una persona a **quel** tenant, in
     * **quel** mese. Spostarla farebbe comparire nella fattura della palestra
     * consumi di quando quella persona non era ancora sua.
     */
    #[Test]
    public function the_ai_bill_stays_where_it_was(): void
    {
        $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $utente = $this->libero();
        $vecchio = $utente->tenant_id;

        DB::table('ai_usage_logs')->insert([
            'tenant_id' => $vecchio,
            'user_id' => $utente->getKey(),
            'feature' => 'food_text',
            'provider' => 'fake',
            'model' => 'finto',
            'input_tokens' => 10,
            'output_tokens' => 5,
            'duration_ms' => 1,
            'success' => true,
            'created_at' => now(),
        ]);

        app(UnisciAUnaPalestra::class)($utente, 'ALFA2345');

        $this->assertSame(
            1,
            DB::table('ai_usage_logs')->where('tenant_id', $vecchio)->count(),
            'Il consumo AI si è spostato: finirebbe nella fattura della palestra.',
        );
    }

    // ─────────────── I rifiuti ───────────────

    /**
     * 🚨 **Solo da un tenant personale.**
     *
     * Spostare un iscritto da una palestra a un'altra è un'operazione
     * commerciale, non una scelta dell'utente: vorrebbe dire che chiunque
     * conosca il codice di un'altra palestra può portarsi via i propri dati
     * dalla propria.
     */
    #[Test]
    public function a_gym_member_cannot_move_themselves(): void
    {
        $alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->creaPalestra('Beta', 'beta', 'BETA2345');

        $iscritto = $this->creaUtente($alfa, UserRole::Member, 'iscritto@alfa.test');

        // 🆕 Anche con un invito in mano: il rifiuto non dipende dalla porta.
        $this->entraDaInvito($iscritto, Tenant::where('slug', 'beta')->firstOrFail())
            ->assertStatus(422);

        $this->assertSame($alfa->id, $iscritto->fresh()->tenant_id);
    }

    /**
     * ⚠️ **L'email è unica per palestra, e in quella nuova può essere già presa.**
     *
     * 🚨 Senza questo controllo l'`UPDATE` finale violerebbe
     * `UNIQUE(tenant_id, email)` e il server risponderebbe **500** — a metà
     * migrazione.
     */
    #[Test]
    public function it_refuses_when_the_gym_already_has_that_email(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->creaUtente($palestra, UserRole::Member, 'mario@esempio.test');

        $utente = $this->libero();

        // 🆕 A dire di no e' `UnisciAUnaPalestra`, che adesso la chiama
        // l'accettazione dell'invito.
        $this->entraDaInvito($utente, $palestra)->assertStatus(422);

        $this->assertNotSame($palestra->id, $utente->fresh()->tenant_id);
    }

    /*
     * ⛔ **«Un codice sbagliato non dice perche'» non e' piu' qui** — 15/09/2026.
     *
     * 🚨 Quel test provava che un codice inventato, una palestra sospesa e un
     * tenant personale dessero **lo stesso** errore: distinguerli avrebbe
     * permesso di provare codici a tappeto e sapere quali esistono.
     *
     * 💡 La regola non e' sparita, e' **cambiata di porta**: adesso vale sui
     * token d'invito, e la sorvegliano `InvitoInPalestraTest` con
     * `tutti_i_rifiuti_si_somigliano` e
     * `rifiutare_un_token_inventato_non_lo_dice`.
     *
     * ⚠️ Tenerlo qui vorrebbe dire provare un endpoint che non esiste.
     */

    // ─────────────── Il tenant che resta indietro ───────────────

    /**
     * ⚠️ Il tenant personale si cancella **logicamente**: restare `active` lo
     * farebbe contare fra i tenant vivi, cancellarlo davvero perderebbe la
     * traccia di dove quella persona stava prima.
     */
    #[Test]
    public function the_personal_tenant_is_retired_not_erased(): void
    {
        $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $utente = $this->libero();
        $vecchio = $utente->tenant_id;

        app(UnisciAUnaPalestra::class)($utente, 'ALFA2345');

        $this->assertNull(Tenant::query()->find($vecchio), 'Il tenant personale è ancora vivo.');
        $this->assertNotNull(
            Tenant::withTrashed()->find($vecchio),
            'Il tenant personale è sparito del tutto: si perde da dove veniva.',
        );
    }

    /** 💡 E i conteggi commerciali non lo vedono più. */
    #[Test]
    public function it_disappears_from_the_personal_tenant_count(): void
    {
        $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $utente = $this->libero();

        $this->assertSame(1, Tenant::query()->personali()->count());

        app(UnisciAUnaPalestra::class)($utente, 'ALFA2345');

        $this->assertSame(0, Tenant::query()->personali()->count());
    }

    /**
     * 🚨 **Il test che si accorge di una tabella nuova dimenticata.**
     *
     * L'elenco delle tabelle da spostare si ricava dallo **schema**, non da una
     * lista scritta a mano — e le eccezioni sono poche e motivate. Questo test
     * verifica che le eccezioni siano ancora quelle, così aggiungerne una per
     * distrazione fa fallire qualcosa.
     */
    #[Test]
    public function only_the_documented_tables_stay_behind(): void
    {
        $this->assertSame(
            [
                'ai_usage_logs',
                'audit_logs',
                'model_has_permissions',
                'model_has_roles',
                'plan_subscriptions',
                'roles',
                'trainer_member',
                'users',
            ],
            UnisciAUnaPalestra::RESTANO_INDIETRO,
            'È cambiato l\'elenco delle tabelle che NON seguono la persona: '
            .'ognuna ha una motivazione scritta, e va aggiornata insieme.',
        );
    }

    // ─────────────── 🚨 E il giorno dopo si rientra ───────────────

    /**
     * **Il test che mancava, ed è costato l'accesso a chiunque entri in
     * palestra** — 14/09/2026.
     *
     * ⛔ `accediSenzaPalestra()` cercava fra i **soli tenant personali**. Chi
     * entrava in una palestra dall'app finiva nel tenant della palestra, e al
     * rientro successivo `POST /auth/login` rispondeva «Credenziali non valide»
     * **con la password giusta**.
     *
     * 🚨 Nessun errore, nessun log, nessun test rosso: la funzione di ingresso
     * funzionava benissimo, e a rompersi era quella di ritorno — una schermata
     * più in là e un giorno dopo.
     *
     * ⚠️ E non c'era una seconda strada: la schermata di accesso il codice
     * palestra **non lo chiede più** da 3b-J.1.
     *
     * 💡 Questo test finisce dove finisce la persona: non all'invito accettato, ma al
     * login successivo.
     */
    #[Test]
    public function after_joining_you_can_still_log_in_without_the_code(): void
    {
        $this->creaPalestra('Palestra Alfa', 'alfa', 'ALFA2345');
        $utente = $this->libero('rientro@esempio.test');

        $this->entraDaInvito($utente, Tenant::where('slug', 'alfa')->firstOrFail())
            ->assertOk();

        // 🚨 Senza `join_code`: è tutto quello che la schermata di accesso ha.
        $this->postJson('/api/v1/auth/login', [
            'login' => 'rientro@esempio.test',
            'password' => self::FAKE_PASSWORD,
        ])->assertOk()->assertJsonPath('data.email', 'rientro@esempio.test');
    }

    /**
     * ⚖️ **E il nome utente funziona come l'email**, che è la strada che resta a
     * chi ha un indirizzo ripetuto su due tenant: è unico su tutta la
     * piattaforma, quindi non è mai ambiguo.
     */
    #[Test]
    public function after_joining_the_username_works_too(): void
    {
        $this->creaPalestra('Palestra Alfa', 'alfa', 'ALFA2345');
        $utente = $this->libero('conutente@esempio.test');

        $utente->forceFill(['username' => 'quellodelrientro'])->save();

        $this->entraDaInvito($utente, Tenant::where('slug', 'alfa')->firstOrFail())
            ->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'login' => 'quellodelrientro',
            'password' => self::FAKE_PASSWORD,
        ])->assertOk()->assertJsonPath('data.username', 'quellodelrientro');
    }

    /**
     * 🚨 **E l'ambiguità si rifiuta ancora, invece di indovinare.**
     *
     * ⚠️ Allargare la ricerca a tutti i tenant allarga anche i casi in cui lo
     * stesso indirizzo esiste due volte. ⛔ Scegliere «il più probabile»
     * porterebbe qualcuno dentro un diario che non è il suo, e penserebbe di
     * aver perso i propri dati: molto peggio di un rifiuto.
     */
    #[Test]
    public function a_repeated_email_is_refused_not_guessed(): void
    {
        $palestra = $this->creaPalestra('Palestra Alfa', 'alfa', 'ALFA2345');

        $this->libero('doppio@esempio.test');

        $this->ctx()->runAs($palestra, fn (): User => User::create([
            'tenant_id' => $palestra->getKey(),
            'name' => 'Omonimo',
            'email' => 'doppio@esempio.test',
            'username' => 'omonimo',
            'password' => self::FAKE_PASSWORD,
        ]));

        $this->postJson('/api/v1/auth/login', [
            'login' => 'doppio@esempio.test',
            'password' => self::FAKE_PASSWORD,
        ])->assertStatus(422);
    }
}
