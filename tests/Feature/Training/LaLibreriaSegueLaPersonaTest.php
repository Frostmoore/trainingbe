<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\MuscleGroup;
use App\Enums\UserRole;
use App\Models\Exercise;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\EsciDaUnaPalestra;
use App\Services\Tenancy\InvitiDelTrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Chi vede quali esercizi — 3b-M, 28/08/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«un utente deve poter aggiungere quanti esercizi vuole… visibili solo a lui
 * se e' un free_user, a lui e a tutti i suoi iscritti se e' un free_trainer, un
 * trainer o una palestra — e gli utenti che sono stati iscritti con questi non
 * devono piu' perdere quegli esercizi»*.
 *
 * ══ 🚨 UN CANCELLO SI PROVA IN TUTTI E DUE I VERSI ════════════════════════
 *
 * ⛔ Un test che dimostra solo «l'iscritto vede la libreria del trainer»
 * passerebbe anche se lo scope fosse **saltato del tutto** e tutti vedessero
 * tutto. ⚠️ E' il verso in cui un difetto di isolamento non fa rumore: si vede
 * roba **in piu'**, non in meno, e nessuno si lamenta.
 *
 * 💡 Per questo qui sotto ci sono tanti «non vede» quanti «vede».
 */
final class LaLibreriaSegueLaPersonaTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private function trainerIndipendente(string $email): User
    {
        return app(CreaTenantPersonale::class)(
            'Trainer '.$email,
            $email,
            ['password' => self::FAKE_PASSWORD, 'username' => str_replace(['@', '.'], '', $email)],
            UserRole::FreeTrainer,
        );
    }

    private function utenteLibero(string $email): User
    {
        return app(CreaTenantPersonale::class)(
            'Utente '.$email,
            $email,
            ['password' => self::FAKE_PASSWORD, 'username' => str_replace(['@', '.'], '', $email)],
            UserRole::FreeUser,
        );
    }

    /** Un iscritto vero di quel trainer: nasce dall'invito, come in produzione. */
    private function iscrittoDi(User $trainer, string $email): User
    {
        $invito = app(InvitiDelTrainer::class)->invita($trainer);

        $this->postJson('/api/v1/auth/register-with-invite', [
            'token' => $invito->token,
            'name' => 'Iscritto',
            'email' => $email,
            'username' => str_replace(['@', '.'], '', $email),
            'password' => self::FAKE_PASSWORD,
            'password_confirmation' => self::FAKE_PASSWORD,
            'age_confirmed' => true,
            'terms_accepted' => true,
        ])->assertCreated();

        return User::withoutGlobalScopes()->where('email', $email)->firstOrFail();
    }

    private function esercizioDi(User $proprietario, string $nome): Exercise
    {
        return Exercise::withoutGlobalScopes()->create([
            'tenant_id' => $proprietario->tenant_id,
            'name' => $nome,
            'slug_normalized' => Exercise::normalize($nome),
            'is_custom' => true,
            'created_by' => $proprietario->getKey(),
            'muscle_group' => MuscleGroup::Chest,
            'secondary_muscles' => [],
        ]);
    }

    /** @return list<string> i nomi che quella persona vede dall'app */
    private function catalogoDi(User $utente): array
    {
        $risposta = $this->comeApp($utente)
            ->getJson('/api/v1/exercises?limit=1000')
            ->assertOk();

        return array_column($risposta->json('data'), 'name');
    }

    // ═══════════════════════════════════════════════════════════════════
    //  free_user: solo suoi
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function un_utente_libero_vede_i_propri_esercizi(): void
    {
        $mario = $this->utenteLibero('mario@esempio.test');
        $this->esercizioDi($mario, 'Panca di Mario');

        self::assertContains('Panca di Mario', $this->catalogoDi($mario));
    }

    #[Test]
    public function un_utente_libero_non_vede_quelli_di_un_altro(): void
    {
        $mario = $this->utenteLibero('mario@esempio.test');
        $lucia = $this->utenteLibero('lucia@esempio.test');

        $this->esercizioDi($lucia, 'Panca di Lucia');

        self::assertNotContains('Panca di Lucia', $this->catalogoDi($mario));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  free_trainer: lui e i suoi iscritti — il caso che era rotto
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function l_iscritto_vede_la_libreria_del_suo_trainer(): void
    {
        $trainer = $this->trainerIndipendente('coach@esempio.test');
        $iscritto = $this->iscrittoDi($trainer, 'seguito@esempio.test');

        $this->esercizioDi($trainer, 'Panca del coach');

        self::assertContains('Panca del coach', $this->catalogoDi($iscritto));
    }

    /**
     * ⛔ **La libreria scende, non sale.** Chi si e' scritto un esercizio nel
     * proprio diario non se lo ritrova nell'elenco del trainer.
     */
    #[Test]
    public function il_trainer_non_vede_gli_esercizi_inventati_dall_iscritto(): void
    {
        $trainer = $this->trainerIndipendente('coach@esempio.test');
        $iscritto = $this->iscrittoDi($trainer, 'seguito@esempio.test');

        $this->esercizioDi($iscritto, 'Panca della vergogna');

        self::assertNotContains('Panca della vergogna', $this->catalogoDi($trainer));
    }

    /**
     * 🚨 Il verso che conta di piu': un trainer **che non mi segue** non mi
     * passa niente.
     */
    #[Test]
    public function un_trainer_estraneo_non_entra_nella_mia_libreria(): void
    {
        $mio = $this->trainerIndipendente('mio@esempio.test');
        $altro = $this->trainerIndipendente('altro@esempio.test');

        $iscritto = $this->iscrittoDi($mio, 'seguito@esempio.test');

        $this->esercizioDi($altro, 'Panca di un estraneo');

        self::assertNotContains('Panca di un estraneo', $this->catalogoDi($iscritto));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  «non devono piu' perdere quegli esercizi»
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⚖️ 🚨 **La riga per cui esiste tutta la fase.**
     *
     * ⛔ Se la libreria sparisse quando il rapporto finisce, quella persona si
     * ritroverebbe lo **storico degli allenamenti** pieno di esercizi che non sa
     * piu' leggere: non cancellati — muti. ⚠️ E se ne accorgerebbe mesi dopo,
     * andando a cercare quanto sollevava a marzo.
     */
    #[Test]
    public function disattivare_il_legame_non_toglie_gli_esercizi(): void
    {
        $trainer = $this->trainerIndipendente('coach@esempio.test');
        $iscritto = $this->iscrittoDi($trainer, 'seguito@esempio.test');

        $this->esercizioDi($trainer, 'Panca del coach');

        self::assertContains('Panca del coach', $this->catalogoDi($iscritto));

        app(InvitiDelTrainer::class)->disattiva($trainer, $iscritto);

        self::assertContains(
            'Panca del coach',
            $this->catalogoDi($iscritto->fresh()),
            'Il rapporto e\' finito, ma lo storico di quella persona parla ancora '
            .'di quegli esercizi.'
        );
    }

    /**
     * ⚖️ 🚨 **Il caso che `trainer_member` da sola NON copriva.**
     *
     * ⛔ Uscendo da una palestra quella riga viene **cancellata davvero**, e
     * `exercises` sta in `RESTANO_ALLA_PALESTRA`. ⚠️ Senza `librerie_viste`,
     * questa persona uscirebbe e la libreria diventerebbe muta — e nessuno se
     * ne accorgerebbe, perche' non e' un errore: e' un elenco piu' corto.
     */
    #[Test]
    public function chi_esce_da_una_palestra_continua_a_leggerne_la_libreria(): void
    {
        $palestra = $this->creaPalestra();
        $mario = $this->creaUtente($palestra, UserRole::Member, 'mario@demo.test');

        Exercise::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->getKey(),
            'name' => 'Panca della palestra',
            'slug_normalized' => Exercise::normalize('Panca della palestra'),
            'is_custom' => true,
            'muscle_group' => MuscleGroup::Chest,
            'secondary_muscles' => [],
        ]);

        self::assertContains('Panca della palestra', $this->catalogoDi($mario));

        app(EsciDaUnaPalestra::class)($mario);

        $mario = $mario->fresh();

        self::assertNotSame(
            $palestra->getKey(),
            $mario->tenant_id,
            'Il test non prova niente se la persona e\' rimasta nella palestra.'
        );

        self::assertContains(
            'Panca della palestra',
            $this->catalogoDi($mario),
            'E\' uscito dalla palestra e il suo storico parla di esercizi che non '
            .'sa piu\' leggere.'
        );
    }

    /**
     * ⛔ E il verso opposto: leggere non e' entrare. Chi e' uscito **non**
     * vede tornare le altre cose della palestra, e non ne diventa iscritto.
     */
    #[Test]
    public function chi_esce_non_vede_la_libreria_di_una_palestra_dove_non_e_mai_stato(): void
    {
        $sua = $this->creaPalestra();
        $altra = $this->creaPalestra('Altra Palestra', 'altra', 'ALTRA234');

        $mario = $this->creaUtente($sua, UserRole::Member, 'mario@demo.test');

        Exercise::withoutGlobalScopes()->create([
            'tenant_id' => $altra->getKey(),
            'name' => 'Panca di un altro posto',
            'slug_normalized' => Exercise::normalize('Panca di un altro posto'),
            'is_custom' => true,
            'muscle_group' => MuscleGroup::Chest,
            'secondary_muscles' => [],
        ]);

        app(EsciDaUnaPalestra::class)($mario);

        self::assertNotContains('Panca di un altro posto', $this->catalogoDi($mario->fresh()));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Il matcher deve stare d'accordo con quello che si vede
    // ═══════════════════════════════════════════════════════════════════

    /**
     * 🚨 **La meta' che, se manca, fa piu' danno di tutta la funzione.**
     *
     * ⛔ L'iscritto vedrebbe «Panca del coach» nell'elenco e, scrivendone il
     * nome in una scheda, ne farebbe nascere **un doppione** nel proprio
     * tenant: senza illustrazione, senza muscoli veri, e con lo storico spezzato
     * in due nomi identici.
     */
    #[Test]
    public function scrivere_il_nome_riusa_l_esercizio_del_trainer(): void
    {
        $trainer = $this->trainerIndipendente('coach@esempio.test');
        $iscritto = $this->iscrittoDi($trainer, 'seguito@esempio.test');

        $delCoach = $this->esercizioDi($trainer, 'Panca del coach');

        $this->comeApp($iscritto)
            ->postJson('/api/v1/exercises', [
                'name' => 'Panca del coach',
                'muscle_group' => MuscleGroup::Chest->value,
                'secondary_muscles' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $delCoach->id)
            ->assertJsonPath('data.created', false);

        self::assertSame(
            1,
            Exercise::withoutGlobalScopes()->where('slug_normalized', Exercise::normalize('Panca del coach'))->count(),
            'E\' nato un doppione invece di riusare quello del trainer.'
        );
    }

    /**
     * ⚠️ **Si cerca nella libreria del trainer, ma si crea sempre nella
     * propria.** ⛔ Creando la' dentro, un iscritto potrebbe allargare la
     * libreria del trainer a sua insaputa.
     */
    #[Test]
    public function un_esercizio_nuovo_nasce_nel_tenant_di_chi_lo_scrive(): void
    {
        $trainer = $this->trainerIndipendente('coach@esempio.test');
        $iscritto = $this->iscrittoDi($trainer, 'seguito@esempio.test');

        $this->comeApp($iscritto)
            ->postJson('/api/v1/exercises', [
                'name' => 'Panca mia soltanto',
                'muscle_group' => MuscleGroup::Chest->value,
                'secondary_muscles' => [],
            ])
            ->assertCreated();

        $nato = Exercise::withoutGlobalScopes()
            ->where('slug_normalized', Exercise::normalize('Panca mia soltanto'))
            ->firstOrFail();

        self::assertSame($iscritto->tenant_id, $nato->tenant_id);
        self::assertNotContains('Panca mia soltanto', $this->catalogoDi($trainer));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  `origine`: la pagina «Esercizi» deve poter distinguere i tre casi
    // ═══════════════════════════════════════════════════════════════════

    /**
     * ⛔ `is_global` non bastava: diceva «della piattaforma o no», e da 3b-M
     * quel «no» comprende due cose molto diverse — quelli che ho scritto io e
     * quelli che vedo perche' me li passa qualcuno.
     */
    #[Test]
    public function la_risposta_dice_da_dove_viene_ogni_esercizio(): void
    {
        $trainer = $this->trainerIndipendente('coach@esempio.test');
        $iscritto = $this->iscrittoDi($trainer, 'seguito@esempio.test');

        $this->esercizioDi($trainer, 'Panca del coach');
        $this->esercizioDi($iscritto, 'Panca mia');

        Exercise::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'name' => 'Panca di tutti',
            'slug_normalized' => Exercise::normalize('Panca di tutti'),
            'is_custom' => false,
            'muscle_group' => MuscleGroup::Chest,
            'secondary_muscles' => [],
        ]);

        $righe = collect(
            $this->comeApp($iscritto)
                ->getJson('/api/v1/exercises?limit=1000')
                ->assertOk()
                ->json('data')
        )->keyBy('name');

        self::assertSame('mia', $righe['Panca mia']['origine']);
        self::assertSame('condivisa', $righe['Panca del coach']['origine']);
        self::assertSame('piattaforma', $righe['Panca di tutti']['origine']);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Il catalogo della piattaforma resta di tutti
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function la_libreria_della_piattaforma_si_vede_sempre(): void
    {
        $mario = $this->utenteLibero('mario@esempio.test');

        Exercise::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'name' => 'Panca di tutti',
            'slug_normalized' => Exercise::normalize('Panca di tutti'),
            'is_custom' => false,
            'muscle_group' => MuscleGroup::Chest,
            'secondary_muscles' => [],
        ]);

        self::assertContains('Panca di tutti', $this->catalogoDi($mario));
    }

    /**
     * ⚠️ **Quanti ne vuole**: non c'e' nessun tetto, e questo test lo fissa —
     * se qualcuno domani ne mette uno, deve essere una decisione, non un
     * effetto collaterale.
     */
    #[Test]
    public function non_c_e_un_tetto_al_numero_di_esercizi_propri(): void
    {
        $mario = $this->utenteLibero('mario@esempio.test');

        for ($i = 1; $i <= 30; $i++) {
            $this->comeApp($mario)
                ->postJson('/api/v1/exercises', [
                    'name' => "Esercizio mio $i",
                    'muscle_group' => MuscleGroup::Chest->value,
                    'secondary_muscles' => [],
                ])
                ->assertCreated();
        }

        self::assertSame(
            30,
            Exercise::withoutGlobalScopes()->where('tenant_id', $mario->tenant_id)->count()
        );
    }
}
