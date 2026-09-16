<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\AiFeature;
use App\Enums\UserRole;
use App\Models\AiCreditMovement;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Services\Ai\CancelloDeiGettoni;
use App\Services\Billing\Exceptions\GettoniEsauritiException;
use App\Services\Billing\PianoAttivo;
use App\Services\Billing\PortafoglioGettoni;
use App\Services\Tenancy\CreaTenantPersonale;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🪙 I gettoni sono della persona che li compra — 3b-AH.8, 16/09/2026.
 *
 * ══ 📌 LA DECISIONE ═══════════════════════════════════════════════════════
 *
 * 📌 Il committente: *«Ovvio che i gettoni sono soltanto di chi li compra»*, e
 * poi, scegliendo fra tre strade: **un solo portafoglio, quello personale**.
 *
 * ══ ⛔ COSA SUCCEDEVA PRIMA ═══════════════════════════════════════════════
 *
 * Il saldo stava su `tenants.ai_credits`. 🚨 Per l'**abbonamento** c'era una
 * guardia dal 10/09 — *«un iscritto non compra per la sua palestra»* — e per i
 * **gettoni** no: chiunque comprava, e il saldo finiva nel portafoglio della
 * palestra, dove lo spendeva chiunque altro.
 *
 * ══ ⚖️ E COSA NON DOVEVA CAMBIARE ════════════════════════════════════════
 *
 * Tre cose, e sono quelle che il committente ha chiesto di guardare: chi ha
 * l'**AI illimitata**, i **rapporti** fra palestre, trainer e allievi, e la
 * **quota inclusa**, che non passa dal portafoglio.
 */
class IGettoniSonoDellaPersonaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private function portafoglio(): PortafoglioGettoni
    {
        return app(PortafoglioGettoni::class);
    }

    /** ⚠️ Il saldo si scrive dal servizio, non a mano: è fuori da `$fillable`. */
    private function dai(User $chi, int $gettoni): void
    {
        $this->portafoglio()->accredita($chi, $gettoni);
    }

    private function saldoDi(User $chi): int
    {
        return (int) DB::table('users')->where('id', $chi->getKey())->value('ai_credits');
    }

    // ─────────────── 🧱 La colonna sta dove deve ───────────────

    /**
     * 🚨 **La colonna vecchia è stata tolta, non lasciata a zero.**
     *
     * ⚠️ Una colonna che nessuno legge più è la trappola già pagata con
     * `plans.max_trainers`: sembrava una regola e non lo era. Su un **saldo**
     * sarebbe peggio — qualcuno la leggerebbe credendo di sapere quanti gettoni
     * ha un cliente.
     */
    #[Test]
    public function il_saldo_sta_sulla_persona_e_non_piu_sul_tenant(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'ai_credits'));
        $this->assertFalse(
            Schema::hasColumn('tenants', 'ai_credits'),
            'Il saldo vecchio e ancora li: qualcuno lo leggera credendo che valga.',
        );
    }

    // ─────────────── 🪙 Di chi sono ───────────────

    /**
     * 🚨 **Il difetto vero**: due persone nella stessa palestra, e i gettoni di
     * una finivano nel portafoglio che spendeva anche l'altra.
     */
    #[Test]
    public function chi_compra_li_tiene_e_nessun_altro_li_tocca(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');

        $chiPaga = $this->creaUtente($palestra, UserRole::Member, 'paga@olimpo.test');
        $laltro = $this->creaUtente($palestra, UserRole::Member, 'altro@olimpo.test');

        $this->dai($chiPaga, 100);

        $this->assertSame(100, $this->portafoglio()->saldo($chiPaga->fresh()));
        $this->assertSame(
            0,
            $this->portafoglio()->saldo($laltro->fresh()),
            'Un collega puo spendere i gettoni che ha comprato qualcun altro.',
        );
    }

    /** ⚖️ E spendendoli cala il suo saldo, non quello di nessun altro. */
    #[Test]
    public function spendendoli_cala_solo_il_suo(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');

        $chiPaga = $this->creaUtente($palestra, UserRole::Member, 'paga@olimpo.test');
        $laltro = $this->creaUtente($palestra, UserRole::Member, 'altro@olimpo.test');

        $this->dai($chiPaga, 100);
        $this->dai($laltro, 10);

        $costo = AiFeature::DailyAdvice->costoInGettoni();

        $this->portafoglio()->consuma($chiPaga->fresh(), AiFeature::DailyAdvice);

        $this->assertSame(100 - $costo, $this->saldoDi($chiPaga));
        $this->assertSame(10, $this->saldoDi($laltro), 'La spesa di uno ha intaccato il saldo di un altro.');
    }

    /**
     * 🚨 **Il registro dice di chi sono.**
     *
     * ⚠️ Un movimento senza proprietario non e' un registro: il giorno che un
     * cliente contesta un saldo, serve poter dire **di chi** era quel gettone.
     */
    #[Test]
    public function il_registro_intesta_ogni_movimento_a_una_persona(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $chiPaga = $this->creaUtente($palestra, UserRole::Member, 'paga@olimpo.test');

        $acquisto = $this->portafoglio()->accredita($chiPaga, 100);
        $consumo = $this->portafoglio()->consuma($chiPaga->fresh(), AiFeature::DailyAdvice);

        $this->assertSame($chiPaga->getKey(), $acquisto->user_id);
        $this->assertSame($chiPaga->getKey(), $consumo->user_id);

        // 💡 E il tenant resta, perche' dice DOVE e' successo.
        $this->assertSame($palestra->getKey(), $consumo->tenant_id);
    }

    // ─────────────── ✨ Chi ha l'AI illimitata ───────────────

    /**
     * ✨ **Chi ha «AI illimitata» non tocca il portafoglio, e non deve.**
     *
     * 📌 *«gli utenti che hanno ai illimitata devono avere anche GETTONI
     * illimitati, non 0 gettoni»* — 02/09/2026.
     *
     * 🚨 È la garanzia che il committente ha chiesto di guardare per prima. La
     * concessione è **personale** (`users.ai_monthly_call_cap = 0`) e
     * `CancelloDeiGettoni::apri()` la guarda **prima di tutto il resto**: con
     * zero gettoni in tasca la chiamata deve passare lo stesso.
     */
    #[Test]
    public function chi_ha_ai_illimitata_passa_con_zero_gettoni(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $fortunato = $this->creaUtente($palestra, UserRole::Member, 'illimitato@olimpo.test');

        $fortunato->forceFill([
            'ai_monthly_call_cap' => 0,
            'ai_monthly_photo_call_cap' => 0,
        ])->save();

        $this->assertSame(0, $this->portafoglio()->saldo($fortunato->fresh()));

        // ⚠️ Una funzione che si paga SEMPRE a gettoni: e' il caso peggiore.
        $conGettoni = app(CancelloDeiGettoni::class)
            ->apri($fortunato->fresh(), AiFeature::PdfImport, aRichiesta: true);

        $this->assertFalse($conGettoni, 'A chi ha l AI illimitata si stanno scalando dei gettoni.');
    }

    // ─────────────── 🤝 I rapporti che non si rompono ───────────────

    /**
     * 🚨 **Nessuno perde l'accesso all'AI**, ed è la cosa più importante di
     * tutta questa migrazione.
     *
     * 💡 Il portafoglio è solo l'**extra** oltre la quota inclusa: chi ha l'AI
     * la ha da `PianoAttivo::haLaAi()`, che guarda il piano, l'override
     * personale e il **rapporto col trainer** — mai il saldo.
     *
     * ⚠️ L'allievo di un trainer indipendente è il caso che lo prova: sta in un
     * tenant personale col piano `free`, e l'AI gliela paga il trainer (U.2).
     */
    #[Test]
    public function l_allievo_di_un_trainer_ha_ancora_la_sua_ai(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Coach',
            'coach@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $allievo = app(CreaTenantPersonale::class)(
            'Allievo',
            'allievo@esempio.test',
            ['password' => self::FAKE_PASSWORD],
        );

        DB::table('users')->where('id', $allievo->getKey())->update(['tenant_id' => $trainer->tenant_id]);

        DB::table('trainer_member')->insert([
            'trainer_id' => $trainer->getKey(),
            'member_id' => $allievo->getKey(),
            'tenant_id' => $trainer->tenant_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /*
         * ⚠️ **Un trainer copre i suoi solo se si e' abbonato davvero.**
         * 💡 La fascia gratuita e' per provare l'app, non per regalare l'AI a
         * dieci persone: senza questa riga il test misurerebbe quel rifiuto
         * invece della migrazione.
         */
        $this->seed(PlanSeeder::class);

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::TRAINER_PRO)->firstOrFail()->id,
            'starts_at' => now()->subMonth(),
        ]);

        $fresco = User::withoutGlobalScopes()->findOrFail($allievo->getKey());

        $this->assertTrue(
            app(PianoAttivo::class)->haLaAi($fresco),
            'L allievo di un trainer ha perso l AI perche i gettoni sono diventati personali.',
        );

        // ⚖️ E il suo portafoglio e' vuoto: l AI non gliela da il saldo.
        $this->assertSame(0, $this->portafoglio()->saldo($fresco));
    }

    /**
     * ⛔ **E il trainer non compra più per i suoi.**
     *
     * ⚠️ È la conseguenza dichiarata della scelta, non una sorpresa: i gettoni
     * del trainer restano del trainer. 💡 Sta scritto qui perché chi legge il
     * codice fra sei mesi lo trovi come una decisione, non come un difetto.
     */
    #[Test]
    public function i_gettoni_del_trainer_non_li_spende_il_suo_allievo(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Coach',
            'coach@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $allievo = app(CreaTenantPersonale::class)(
            'Allievo',
            'allievo@esempio.test',
            ['password' => self::FAKE_PASSWORD],
        );

        DB::table('users')->where('id', $allievo->getKey())->update(['tenant_id' => $trainer->tenant_id]);

        $this->dai($trainer, 500);

        $this->assertSame(500, $this->portafoglio()->saldo($trainer->fresh()));
        $this->assertSame(0, $this->portafoglio()->saldo(
            User::withoutGlobalScopes()->findOrFail($allievo->getKey())
        ));

        $this->expectException(GettoniEsauritiException::class);

        $this->portafoglio()->consuma(
            User::withoutGlobalScopes()->findOrFail($allievo->getKey()),
            AiFeature::DailyAdvice,
        );
    }

    // ─────────────── 💬 Il messaggio giusto ───────────────

    /**
     * ⚠️ **Chi non ha mai comprato gettoni riceve il messaggio della quota**,
     * non quello dei gettoni.
     *
     * 🚨 Con il filtro sul tenant, bastava che un **collega** ne avesse comprati
     * per vedersi dire *«ricarica»* — a chi non sa nemmeno che esistano.
     */
    #[Test]
    public function il_messaggio_guarda_se_ha_comprato_lui(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');

        $chiCompra = $this->creaUtente($palestra, UserRole::Member, 'compra@olimpo.test');
        $chiNo = $this->creaUtente($palestra, UserRole::Member, 'mai@olimpo.test');

        $this->dai($chiCompra, 100);

        $this->assertSame(
            1,
            AiCreditMovement::withoutGlobalScopes()->where('user_id', $chiCompra->getKey())->count(),
        );

        $this->assertSame(
            0,
            AiCreditMovement::withoutGlobalScopes()->where('user_id', $chiNo->getKey())->count(),
            'Un movimento di un collega risulta suo.',
        );
    }
}
