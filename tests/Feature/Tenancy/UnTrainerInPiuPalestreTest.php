<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\ChiSiVede;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Services\Tenancy\InvitiInPalestra;
use App\Services\Tenancy\LoSlotDelTrainer;
use App\Services\Tenancy\TogliUnTrainerDallaPalestra;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🏋️ **Un trainer in infinite palestre** — regola 8.1.1, 18/09/2026.
 *
 * > *«Naturalmente, assicurati che un trainer possa lavorare per infinite
 * > palestre.»*
 *
 * ══ 🚨 COSA SUCCEDEVA PRIMA ═══════════════════════════════════════════════
 *
 * ⛔ L'invito da trainer passava dal **trasloco**, che rifiuta chiunque abbia
 * qualcun altro nel proprio spazio. Quindi:
 *
 * - un trainer con **un solo** allievo suo non entrava in **nessuna** palestra;
 * - uno già dentro una palestra non entrava nella seconda.
 *
 * 💡 Adesso resta a casa sua e guadagna un **ruolo** per ogni palestra.
 */
final class UnTrainerInPiuPalestreTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function trainerIndipendente(string $email): User
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Coach',
            $email,
            ['password' => TestCase::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::TRAINER_30)->firstOrFail()->id,
            'starts_at' => now()->subYear(),
        ]);

        return $trainer->fresh();
    }

    /** Una palestra con un piano che ha posti trainer, e il suo capo. */
    private function palestraConPosti(string $nome, string $slug, string $codice): array
    {
        $palestra = $this->creaPalestra($nome, $slug, $codice);

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->update(['plan_id' => Plan::query()->where('code', 'gym_50')->firstOrFail()->id]);

        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, "capo-{$slug}@esempio.test");

        return [$palestra->fresh(), $capo];
    }

    /** La palestra invita questo trainer, e lui accetta. */
    private function assumiPerInvito(Tenant $palestra, User $capo, User $trainer): void
    {
        $invito = $this->ctx()->runAs(
            $palestra,
            fn () => app(InvitiInPalestra::class)->invita(
                $palestra,
                $capo,
                $trainer->email,
                UserRole::Trainer,
            ),
        );

        app(InvitiInPalestra::class)->accetta($invito->token, $trainer->fresh());
    }

    private function allievoDi(User $trainer, User $allievo): void
    {
        app(InvitiDelTrainer::class)->riscattaPerChiEsiste(
            app(TenantContext::class)->runAs(
                $trainer->tenant,
                fn () => app(InvitiDelTrainer::class)->invita($trainer),
            )->token,
            $allievo->fresh(),
            affianco: true,
        );
    }

    /**
     * 🚨 **Il caso che prima era impossibile**: un trainer con allievi suoi
     * entra in **due** palestre, e resta a casa sua.
     */
    #[Test]
    public function un_trainer_con_allievi_suoi_lavora_in_due_palestre(): void
    {
        $trainer = $this->trainerIndipendente('due-palestre@esempio.test');
        $casa = $trainer->tenant_id;

        $allievo = app(CreaTenantPersonale::class)(
            'Suo', 'suo@esempio.test', ['password' => TestCase::FAKE_PASSWORD], UserRole::FreeUser,
        );
        $this->allievoDi($trainer, $allievo);

        [$olimpo, $capoOlimpo] = $this->palestraConPosti('Olimpo', 'olimpo', 'OLIM2345');
        [$ares, $capoAres] = $this->palestraConPosti('Ares', 'ares', 'ARES2345');

        $this->assumiPerInvito($olimpo, $capoOlimpo, $trainer);
        $this->assumiPerInvito($ares, $capoAres, $trainer);

        $this->assertSame($casa, $trainer->fresh()->tenant_id, 'il trainer e stato traslocato');

        $dove = app(ChiSiVede::class)->palestreDoveAllena($trainer->fresh());

        $this->assertTrue($dove->contains($olimpo->getKey()), 'non allena a Olimpo');
        $this->assertTrue($dove->contains($ares->getKey()), 'non allena ad Ares');

        /*
         * ⛔ **E il suo allievo resta suo.** Il trasloco di prima lo avrebbe
         * rifiutato proprio per colpa di questo allievo.
         */
        $this->assertNull(
            DB::table('trainer_member')
                ->where('trainer_id', $trainer->getKey())
                ->where('member_id', $allievo->getKey())
                ->value('disattivato_il'),
            'entrando in palestra il trainer ha perso il suo allievo',
        );
    }

    /**
     * 🚨 **L'app sa che è un trainer**, anche se il ruolo vive solo in palestra.
     *
     * ⛔ `getRoleNames()` risponde nel tenant della persona: un trainer che
     * allena **solo** in palestra, con lo spazio suo, risulterebbe un utente
     * qualunque — niente «I miei utenti», niente domanda sullo slot.
     */
    #[Test]
    public function lapp_riconosce_il_trainer_anche_se_allena_solo_in_palestra(): void
    {
        $persona = app(CreaTenantPersonale::class)(
            'Solo Palestra', 'solo-palestra@esempio.test',
            ['password' => TestCase::FAKE_PASSWORD], UserRole::FreeUser,
        );

        [$palestra, $capo] = $this->palestraConPosti('Crono', 'crono', 'CRON2345');
        $this->assumiPerInvito($palestra, $capo, $persona);

        $ruoli = $this->ctx()->runAs(
            $persona->fresh()->tenant,
            fn () => $this->actingAs($persona->fresh(), 'sanctum')
                ->getJson('/api/v1/auth/me')
                ->assertOk()
                ->json('data.roles') ?? [],
        );

        $this->assertContains('trainer', $ruoli, 'lapp non sa che questa persona allena');
    }

    /**
     * 🚨 **Fase 8.3.3** — il trainer entra dove **più** dei suoi allievi sono già
     * iscritti.
     *
     * 📌 *«assicurati che al trainer compaia la modale che mostra gli allievi che
     * ha che sono anche iscritti in quella palestra (potrebbero essere più di
     * uno)»*.
     */
    #[Test]
    public function entrando_dove_stanno_i_suoi_allievi_gli_si_chiede_di_tutti(): void
    {
        $trainer = $this->trainerIndipendente('ritrova@esempio.test');
        [$palestra, $capo] = $this->palestraConPosti('Zeus', 'zeus', 'ZEUS2345');

        $rita = $this->creaUtente($palestra, UserRole::Member, 'rita-z@esempio.test');
        $ugo = $this->creaUtente($palestra, UserRole::Member, 'ugo-z@esempio.test');
        $fuori = app(CreaTenantPersonale::class)(
            'Fuori', 'fuori-z@esempio.test', ['password' => TestCase::FAKE_PASSWORD], UserRole::FreeUser,
        );

        $this->allievoDi($trainer, $rita);
        $this->allievoDi($trainer, $ugo);
        $this->allievoDi($trainer, $fuori);

        $this->assumiPerInvito($palestra, $capo, $trainer);

        $daDecidere = app(LoSlotDelTrainer::class)->daDecidere($trainer->fresh());

        $this->assertCount(2, $daDecidere, 'non gli si chiede di tutti gli allievi iscritti li');
        $this->assertEqualsCanonicalizing(
            [$rita->getKey(), $ugo->getKey()],
            $daDecidere->pluck('member_id')->map(fn ($id): int => (int) $id)->all(),
        );

        /*
         * ⛔ **Chi non è iscritto lì non entra nella domanda**: è solo suo, e la
         * palestra non c'entra.
         */
        $this->assertNull(
            DB::table('trainer_member')
                ->where('trainer_id', $trainer->getKey())
                ->where('member_id', $fuori->getKey())
                ->value('sovrapposto_dal'),
            'marcato un allievo che con quella palestra non centra',
        );
    }

    /**
     * 🚨 **Fase 8.3.6** — il trainer lascia la palestra.
     *
     * 📌 *«se il trainer ha deciso di mantenere lo slot occupato l'allievo torni
     * al trainer, altrimenti che l'allievo resti solo iscritto in palestra»*.
     */
    #[Test]
    public function lasciando_la_palestra_chi_ha_tenuto_lo_slot_si_tiene_lallievo(): void
    {
        $trainer = $this->trainerIndipendente('lascia@esempio.test');
        [$palestra, $capo] = $this->palestraConPosti('Era', 'era', 'ERAA2345');

        $tenuto = $this->creaUtente($palestra, UserRole::Member, 'tenuto@esempio.test');
        $liberato = $this->creaUtente($palestra, UserRole::Member, 'liberato@esempio.test');

        $this->allievoDi($trainer, $tenuto);
        $this->allievoDi($trainer, $liberato);

        $this->assumiPerInvito($palestra, $capo, $trainer);

        app(LoSlotDelTrainer::class)->decidi($trainer->fresh(), $tenuto, true);
        app(LoSlotDelTrainer::class)->decidi($trainer->fresh(), $liberato, false);

        app(TogliUnTrainerDallaPalestra::class)($trainer->fresh(), $palestra);

        $rigaTenuto = DB::table('trainer_member')
            ->where('trainer_id', $trainer->getKey())
            ->where('member_id', $tenuto->getKey())
            ->first();

        $this->assertNull($rigaTenuto->disattivato_il, 'chi aveva tenuto lo slot ha perso lallievo');
        $this->assertNull($rigaTenuto->sovrapposto_dal, 'la sovrapposizione e rimasta aperta');

        $this->assertNotNull(
            DB::table('trainer_member')
                ->where('trainer_id', $trainer->getKey())
                ->where('member_id', $liberato->getKey())
                ->value('disattivato_il'),
            'lallievo liberato e tornato al trainer',
        );

        /*
         * ⚠️ **E non vede più gli iscritti della palestra**: non ci allena più.
         */
        $this->assertFalse(
            app(ChiSiVede::class)->palestreDoveAllena($trainer->fresh())->contains($palestra->getKey()),
            'il trainer allena ancora in una palestra che ha lasciato',
        );
    }

    /**
     * ⚠️ **Lasciarne una non tocca l'altra** — 8.1.1.e.
     */
    #[Test]
    public function lasciando_una_palestra_resta_nellaltra(): void
    {
        $trainer = $this->trainerIndipendente('una-sola@esempio.test');

        [$uno, $capoUno] = $this->palestraConPosti('Uno', 'uno', 'UNOO2345');
        [$due, $capoDue] = $this->palestraConPosti('Due', 'due', 'DUEE2345');

        $this->assumiPerInvito($uno, $capoUno, $trainer);
        $this->assumiPerInvito($due, $capoDue, $trainer);

        app(TogliUnTrainerDallaPalestra::class)($trainer->fresh(), $uno);

        $dove = app(ChiSiVede::class)->palestreDoveAllena($trainer->fresh());

        $this->assertFalse($dove->contains($uno->getKey()));
        $this->assertTrue($dove->contains($due->getKey()), 'lasciando una palestra ha perso anche laltra');
    }

    /**
     * 💡 **Accettare due volte non duplica niente.** Spatie, in modalità teams,
     * sul ruolo duplicato risponderebbe 500.
     */
    #[Test]
    public function assumere_due_volte_non_rompe_niente(): void
    {
        $trainer = $this->trainerIndipendente('due-volte@esempio.test');
        [$palestra, $capo] = $this->palestraConPosti('Bis', 'bis', 'BISS2345');

        $this->assumiPerInvito($palestra, $capo, $trainer);
        $this->assumiPerInvito($palestra, $capo, $trainer);

        $this->assertSame(
            1,
            DB::table('model_has_roles as mhr')
                ->join('roles as r', 'r.id', '=', 'mhr.role_id')
                ->where('mhr.model_id', $trainer->getKey())
                ->where('mhr.tenant_id', $palestra->getKey())
                ->where('r.name', UserRole::Trainer->value)
                ->count(),
        );
    }
}
