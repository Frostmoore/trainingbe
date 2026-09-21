<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\PlanKind;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\ChiSiVede;
use App\Services\Tenancy\CreaTenantPersonale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Chi vede chi — la regola, 15/09/2026.
 *
 * ══ 📌 DETTA DAL COMMITTENTE ══════════════════════════════════════════════
 *
 * *«Ogni trainer di palestra vede tutti gli allievi di quella palestra, sempre,
 * senza assegnazioni, e tutti gli allievi di quella palestra vedono tutti i
 * trainer di quella palestra. Un trainer indipendente vede i suoi allievi e i
 * suoi allievi vedono lui. Un trainer indipendente che lavora anche in una
 * palestra vede sia i suoi allievi personali — che devono comunque essere
 * limitati al suo livello di abbonamento — che tutti gli allievi della
 * palestra, e tutti gli allievi della palestra più tutti gli allievi del
 * trainer individuale possono vedere lui.»*
 *
 * ══ ⛔ COSA SUCCEDEVA PRIMA ═══════════════════════════════════════════════
 *
 * Un trainer di palestra **non vedeva nessuno**: `/trainer/members` leggeva solo
 * `trainer_member`, che per lui è vuota e resta vuota. 🚨 Provato su staging in
 * una palestra con tre iscritti: elenco vuoto, e nella chat solo l'altro
 * trainer. Un trainer che non vede nessuno e non può scrivere a nessuno.
 */
class ChiVedeChiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private function regola(): ChiSiVede
    {
        return app(ChiSiVede::class);
    }

    /**
     * Dà a una persona il ruolo di trainer **dentro** una palestra, lasciandola
     * dove sta.
     *
     * 🚨 È il caso del trainer con un giro suo: `users.tenant_id` punta al
     * proprio spazio, e il ruolo vive nella palestra.
     */
    private function allenaAnchePresso(User $chi, Tenant $palestra): void
    {
        DB::table('model_has_roles')->updateOrInsert([
            'role_id' => DB::table('roles')->where('name', UserRole::Trainer->value)->value('id'),
            'model_type' => User::class,
            'model_id' => $chi->getKey(),
            'tenant_id' => $palestra->getKey(),
        ], []);
    }

    /** Un allievo personale, col rapporto uno-a-uno che gli compete. */
    private function allievoDi(User $trainer, string $email): User
    {
        $allievo = app(CreaTenantPersonale::class)(
            'Allievo',
            $email,
            ['password' => self::FAKE_PASSWORD],
        );

        DB::table('users')->where('id', $allievo->getKey())->update([
            'tenant_id' => $trainer->tenant_id,
        ]);

        DB::table('trainer_member')->insert([
            'trainer_id' => $trainer->getKey(),
            'member_id' => $allievo->getKey(),
            'tenant_id' => $trainer->tenant_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return User::withoutGlobalScopes()->findOrFail($allievo->getKey());
    }

    /**
     * ⏰ Gli dà la fascia trainer gratuita.
     *
     * 🚨 Serve per chiamare `/trainer/members`: dopo U.3.1 la rotta pretende
     * un abbonamento in piedi, e senza risponde **403** — che in un test somiglia
     * a un difetto di permessi e non lo è.
     */
    private function abbona(User $trainer): void
    {
        $piano = Plan::query()->where('code', Plan::TRAINER_FREE)->first()
            ?? Plan::create([
                'code' => Plan::TRAINER_FREE,
                'name' => 'Trainer — gratuito',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => false,
                'max_members' => 3,
                'price_cents' => 0,
                'is_public' => true,
            ]);

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => $piano->id,
            'starts_at' => now()->subYear(),
        ]);
    }

    private function unTrainerIndipendente(): User
    {
        return app(CreaTenantPersonale::class)(
            'Coach',
            'coach@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );
    }

    // ─────────────── La palestra: tutti con tutti ───────────────

    /** 🚨 Il difetto vero: questo elenco era vuoto. */
    #[Test]
    public function un_trainer_di_palestra_vede_tutti_gli_iscritti(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');

        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');
        $uno = $this->creaUtente($palestra, UserRole::Member, 'uno@olimpo.test');
        $due = $this->creaUtente($palestra, UserRole::Member, 'due@olimpo.test');

        $visti = $this->regola()->allieviDi($trainer)->pluck('id');

        $this->assertEqualsCanonicalizing([$uno->getKey(), $due->getKey()], $visti->all());
    }

    /** ⚖️ E lo specchio: ogni iscritto vede tutti i trainer. */
    #[Test]
    public function un_iscritto_vede_tutti_i_trainer_della_palestra(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');

        $primo = $this->creaUtente($palestra, UserRole::Trainer, 'uno@olimpo.test');
        $secondo = $this->creaUtente($palestra, UserRole::Trainer, 'due@olimpo.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'tizio@olimpo.test');

        $visti = $this->regola()->trainerDi($iscritto)->pluck('id');

        $this->assertEqualsCanonicalizing([$primo->getKey(), $secondo->getKey()], $visti->all());
    }

    /** ⛔ E non vede quelli di un'altra palestra. */
    #[Test]
    public function ma_non_quelli_di_un_altra_palestra(): void
    {
        $olimpo = $this->creaPalestra('Olimpo', 'olimpo');
        $zeus = $this->creaPalestra('Zeus', 'zeus');

        $this->creaUtente($zeus, UserRole::Trainer, 'estraneo@zeus.test');
        $iscritto = $this->creaUtente($olimpo, UserRole::Member, 'tizio@olimpo.test');

        $this->assertSame([], $this->regola()->trainerDi($iscritto)->pluck('id')->all());
    }

    // ─────────────── Il trainer indipendente ───────────────

    #[Test]
    public function un_trainer_indipendente_vede_i_suoi_e_loro_vedono_lui(): void
    {
        $trainer = $this->unTrainerIndipendente();
        $allievo = $this->allievoDi($trainer, 'allievo@esempio.test');

        $this->assertSame(
            [$allievo->getKey()],
            $this->regola()->allieviDi($trainer)->pluck('id')->all(),
        );

        $this->assertSame(
            [$trainer->getKey()],
            $this->regola()->trainerDi($allievo)->pluck('id')->all(),
        );
    }

    // ─────────────── I due cappelli ───────────────

    /**
     * 🚨 **Il caso che il vecchio codice non poteva vedere.** Cercava i trainer
     * con `users.tenant_id` uguale alla palestra; questa persona sta nel proprio
     * spazio, quindi spariva da entrambe le parti.
     */
    #[Test]
    public function chi_ha_un_giro_suo_e_allena_in_palestra_vede_tutti_e_due(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();

        $suo = $this->allievoDi($trainer, 'suo@esempio.test');
        $dellaPalestra = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');

        $this->allenaAnchePresso($trainer, $palestra);

        $visti = $this->regola()->allieviDi($trainer)->pluck('id');

        $this->assertEqualsCanonicalizing(
            [$suo->getKey(), $dellaPalestra->getKey()],
            $visti->all(),
            'Doveva vedere i suoi allievi E gli iscritti della palestra.',
        );
    }

    /** ⚖️ E lo vedono tutti e due i gruppi. */
    #[Test]
    public function e_tutti_e_due_i_gruppi_vedono_lui(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();

        $suo = $this->allievoDi($trainer, 'suo@esempio.test');
        $dellaPalestra = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');

        $this->allenaAnchePresso($trainer, $palestra);

        $this->assertContains(
            $trainer->getKey(),
            $this->regola()->trainerDi($suo)->pluck('id')->all(),
            'Il suo allievo personale non lo vedeva piu.',
        );

        $this->assertContains(
            $trainer->getKey(),
            $this->regola()->trainerDi($dellaPalestra)->pluck('id')->all(),
            'Gli iscritti della palestra non vedevano il trainer col giro suo.',
        );
    }

    // ─────────────── 🚨 Quando lascia la palestra ───────────────

    /**
     * 📌 Il committente: *«un trainer indipendente che lavora in una palestra,
     * se dovesse lasciare la palestra, manterrebbe tutti i suoi allievi
     * personali»*.
     *
     * 🚨 **Sono suoi e li paga lui**: la palestra non c'entra niente, e non
     * puo' portarseli via andandosene.
     */
    #[Test]
    public function chi_lascia_la_palestra_tiene_i_suoi_allievi(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();

        $suo = $this->allievoDi($trainer, 'suo@esempio.test');
        $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');

        $this->allenaAnchePresso($trainer, $palestra);

        // 🚪 Se ne va: gli si toglie il ruolo dentro quella palestra.
        DB::table('model_has_roles')
            ->where('model_id', $trainer->getKey())
            ->where('tenant_id', $palestra->getKey())
            ->delete();

        $visti = $this->regola()->allieviDi($trainer->fresh())->pluck('id');

        $this->assertSame(
            [$suo->getKey()],
            $visti->all(),
            'Lasciando la palestra si e portato via, o ha perso, i suoi allievi.',
        );
    }

    /**
     * 🚨 **E gli allievi suoi non devono vedere gli altri trainer della
     * palestra.**
     *
     * 📌 *«i suoi allievi personali non possono vedere gli altri trainer della
     * palestra»*. ⚠️ Sono clienti **suoi**: la palestra dove lui lavora e' un
     * fatto che li riguarda zero, e vedersi comparire fra i contatti tre
     * sconosciuti sarebbe anche un modo per fargliene scegliere un altro.
     */
    #[Test]
    public function gli_allievi_suoi_non_vedono_i_trainer_della_palestra(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();

        $altro = $this->creaUtente($palestra, UserRole::Trainer, 'altro@olimpo.test');
        $suo = $this->allievoDi($trainer, 'suo@esempio.test');

        $this->allenaAnchePresso($trainer, $palestra);

        $visti = $this->regola()->trainerDi($suo)->pluck('id');

        $this->assertSame(
            [$trainer->getKey()],
            $visti->all(),
            'Un allievo personale vede anche i trainer della palestra.',
        );

        $this->assertNotContains($altro->getKey(), $visti->all());
    }

    /**
     * 🚨 **E la palestra non puo' spegnerglieli.**
     *
     * ⛔ Il modo in cui una palestra toglie di mezzo un trainer, dal pannello, e'
     * lo spegnimento (`is_active`) o la cancellazione: tutti e due fanno partire
     * `ChiEsceDallaPalestraOsservatore`, che chiudeva **ogni** rapporto di quella
     * persona, in ogni spazio.
     *
     * ⚠️ Per un trainer con un giro suo voleva dire che la palestra, con un
     * click, gli chiudeva anche i clienti che paga lui — e che con la palestra
     * non c'entrano niente.
     */
    #[Test]
    public function la_palestra_che_lo_spegne_non_gli_spegne_gli_allievi_suoi(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();

        $suo = $this->allievoDi($trainer, 'suo@esempio.test');
        $this->allenaAnchePresso($trainer, $palestra);

        $trainer->forceFill(['is_active' => false])->save();

        $rapporto = DB::table('trainer_member')
            ->where('trainer_id', $trainer->getKey())
            ->where('member_id', $suo->getKey())
            ->first();

        $this->assertNotNull($rapporto);
        $this->assertNull(
            $rapporto->disattivato_il,
            'La palestra, spegnendolo, gli ha chiuso anche gli allievi suoi.',
        );
    }

    /**
     * ⛔ **E non puo' nemmeno provarci dal pannello.**
     *
     * 🚨 `UserPolicy` confronta i `tenant_id`: quello di un trainer con un
     * giro suo punta a **casa sua**, non alla palestra, quindi il gestore della
     * palestra non ne modifica ne' ne cancella l'account. 💡 Qui e' scritto
     * perche' quella riga di policy, nata per un'altra ragione, adesso regge
     * anche questa garanzia: chi la cambiasse deve sapere cosa rompe.
     *
     * ⚠️ **Il rovescio**: cosi' la palestra non ha nessun gesto per mandarlo
     * via. Sta in `DA-FARE.md`, e' AH.2, e va disegnato — ma un buco nel
     * pannello si aggiunge, un account altrui cancellato no.
     */
    #[Test]
    public function la_palestra_non_tocca_l_account_di_chi_non_e_suo(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $gestore = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@olimpo.test');
        $trainer = $this->unTrainerIndipendente();

        $this->allenaAnchePresso($trainer, $palestra);

        $this->ctx()->runAs($palestra, function () use ($gestore, $trainer): void {
            $capo = User::withoutGlobalScopes()->findOrFail($gestore->getKey());

            $this->assertTrue($capo->isGymAdmin(), 'Il gestore non risultava tale: il test non proverebbe niente.');

            $this->assertFalse(
                Gate::forUser($capo)->allows('update', $trainer),
                "La palestra puo' spegnere l'account di un trainer che non e' suo.",
            );

            $this->assertFalse(
                Gate::forUser($capo)->allows('delete', $trainer),
                "La palestra puo' cancellare l'account di un trainer che non e' suo.",
            );
        });
    }

    // ─────────────── 💬 E la chat deve reggere lo stesso elenco ───────────────

    /**
     * 🚨 **Un contatto che si apre.**
     *
     * ⚠️ `contacts()` adesso elenca secondo la regola nuova, ma `open()` ha una
     * riga tutta sua: *stesso `tenant_id`*. Un trainer con un giro suo ce
     * l'ha diverso da quello dei suoi iscritti in palestra — quindi l'elenco
     * mostrerebbe delle persone e il tocco risponderebbe **404**.
     *
     * 💡 E' il difetto contro cui mette in guardia il commento di `coppia()`:
     * un elenco di gente irraggiungibile e' peggio di un elenco vuoto.
     */
    #[Test]
    public function chi_compare_nei_contatti_si_puo_anche_aprire(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');

        $this->allenaAnchePresso($trainer, $palestra);

        $this->comeApp($iscritto)
            ->postJson('/api/v1/conversations', ['user_id' => $trainer->getKey()])
            ->assertCreated();

        $this->comeApp($trainer, ['trainer'])
            ->postJson('/api/v1/conversations', ['user_id' => $iscritto->getKey()])
            ->assertCreated();
    }

    /**
     * 🚨 **E la chat aperta si deve anche ritrovare, e leggere.**
     *
     * ⚠️ Il filo fra un trainer con un giro suo e un iscritto nasce **nella
     * palestra** (`Conversation::between()` usa il tenant del membro), ma lui
     * legge dal **proprio** spazio: se l'elenco e la lettura filtrano per
     * tenant, quella conversazione la apre e poi non la trova piu'.
     *
     * 💡 Tre passi perche' sono tre porte diverse: aprire, elencare, leggere.
     * Basta che una filtri per tenant e il giro si interrompe li'.
     */
    #[Test]
    public function la_chat_aperta_si_ritrova_e_si_legge(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');

        $this->allenaAnchePresso($trainer, $palestra);

        $id = $this->comeApp($trainer, ['trainer'])
            ->postJson('/api/v1/conversations', ['user_id' => $iscritto->getKey()])
            ->assertCreated()
            ->json('data.id');

        $elenco = $this->comeApp($trainer, ['trainer'])
            ->getJson('/api/v1/conversations')
            ->assertOk()
            ->json('data');

        $this->assertSame(
            [$id],
            collect($elenco)->pluck('id')->all(),
            "L'ha aperta e poi non la trova nel suo elenco.",
        );

        $this->comeApp($trainer, ['trainer'])
            ->getJson("/api/v1/conversations/{$id}/messages")
            ->assertOk();
    }

    /**
     * ⛔ **E chi non c'e' resta fuori.**
     *
     * 🚨 La contropartita del test qui sopra: allargare `open()` non deve
     * aprire la porta a chiunque stia in giro. Un allievo personale del trainer
     * non ha niente a che fare con gli altri trainer della palestra, e la chat
     * deve dirlo con un no — non solo tenerli fuori dall'elenco.
     */
    #[Test]
    public function un_allievo_personale_non_apre_una_chat_col_trainer_della_palestra(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();
        $altro = $this->creaUtente($palestra, UserRole::Trainer, 'altro@olimpo.test');
        $suo = $this->allievoDi($trainer, 'suo@esempio.test');

        $this->allenaAnchePresso($trainer, $palestra);

        $this->comeApp($suo)
            ->postJson('/api/v1/conversations', ['user_id' => $altro->getKey()])
            ->assertStatus(404);
    }

    /**
     * 🚨 **E l'elenco dice quali rapporti sono suoi.**
     *
     * ⛔ L'app mette su ogni riga il pulsante «chiudi la conversazione», e per
     * un iscritto della palestra quel gesto il server lo **rifiuta** — quel
     * rapporto lo decide la palestra. ⚠️ Senza questo campo l'elenco prometteva
     * un'azione che rispondeva *«Persona non trovata»* su una persona che sta
     * li' in elenco.
     */
    #[Test]
    public function l_elenco_dice_quali_rapporti_sono_suoi(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();

        $suo = $this->allievoDi($trainer, 'suo@esempio.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');

        $this->allenaAnchePresso($trainer, $palestra);
        $this->abbona($trainer);

        $risposta = $this->comeApp($trainer, ['trainer'])
            ->getJson('/api/v1/trainer/members')
            ->assertOk();

        $per = collect($risposta->json('data'))->keyBy('id');

        $this->assertTrue($per[$suo->getKey()]['suo'], 'Il suo allievo non risultava suo.');
        $this->assertFalse($per[$iscritto->getKey()]['suo'], 'Un iscritto della palestra risultava suo.');
    }

    /**
     * ⚖️ **Il tetto dei posti vale solo sugli allievi personali.**
     *
     * 🚨 Gli iscritti di una palestra li paga la palestra col suo piano: contarli
     * nel tetto del trainer vorrebbe dire far pagare due volte lo stesso posto.
     */
    #[Test]
    public function gli_iscritti_della_palestra_non_consumano_i_posti_del_trainer(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();

        $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');
        $this->allenaAnchePresso($trainer, $palestra);

        $this->assertSame(
            0,
            DB::table('trainer_member')->where('trainer_id', $trainer->getKey())->count(),
            'Un iscritto della palestra ha creato un rapporto personale.',
        );
    }
}
