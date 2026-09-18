<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\LaPausaDellAbbonamento;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\EsciDaUnaPalestra;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Services\Tenancy\LoSlotDelTrainer;
use App\Services\Tenancy\UnisciAUnaPalestra;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🎟️ **Lo slot sospeso e la pausa dell'abbonamento** — AH.1-ter.6 e la regola
 * 8.1.6, 18/09/2026.
 *
 * ══ 📌 LE DUE REGOLE DEL COMMITTENTE ══════════════════════════════════════
 *
 * > *«Si deve chiedere: se il trainer decide di mantenere lo slot occupato,
 * > l'allievo passa alla palestra, poi se si dissocia dalla palestra torna al
 * > trainer. Se il trainer decide di liberare lo slot, l'allievo passa alla
 * > palestra e se si dissocia da essa non torna al trainer.»* (17/09)
 *
 * > *«Assicurati che l'abbonamento dell'allievo venga sospeso se si iscrive a
 * > una palestra, se era abbonato, e che riprenda quando l'allievo torna
 * > libero.»* (18/09)
 *
 * ══ 🚨 COSA PROVA DAVVERO ═════════════════════════════════════════════════
 *
 * Le cose che, sbagliate, **non danno nessun errore**:
 *
 * 1. che un abbonamento in pausa **non risulti attivo** — se risultasse, la
 *    persona avrebbe due coperture e nessuno se ne accorgerebbe;
 * 2. che i giorni già pagati **tornino**, e non si perdano né si raddoppino;
 * 3. che uno slot **tenuto** continui a contare, e uno **liberato** no;
 * 4. che si riattivi **solo** uscendo dalla palestra che aveva sospeso;
 * 5. che un trainer che **non lavora** in quella palestra non venga toccato —
 *    è la condizione che separa la regola da un danno.
 */
final class LoSlotEQuandoSiEntraInPalestraTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /** Un trainer con un giro suo, fuori da qualunque palestra. */
    private function trainerIndipendente(string $email = 'coach@esempio.test'): User
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

    /** Una persona con uno spazio suo e, se chiesto, un abbonamento. */
    private function persona(string $email, ?Carbon $scade = null): User
    {
        $u = app(CreaTenantPersonale::class)(
            ucfirst(explode('@', $email)[0]),
            $email,
            ['password' => TestCase::FAKE_PASSWORD],
            UserRole::FreeUser,
        );

        if ($scade !== null) {
            PlanSubscription::withoutGlobalScopes()->create([
                'tenant_id' => $u->tenant_id,
                'plan_id' => Plan::query()->where('code', Plan::PLUS)->firstOrFail()->id,
                'starts_at' => now()->subDays(10),
                'ends_at' => $scade,
            ]);
        }

        return $u->fresh();
    }

    /** Fa allenare questo trainer dentro questa palestra. */
    private function assumi(Tenant $palestra, User $trainer): void
    {
        app(TenantContext::class)->runAs($palestra, function () use ($trainer): void {
            $fresco = User::withoutGlobalScopes()->findOrFail($trainer->getKey());
            $fresco->unsetRelation('roles');
            $fresco->assignRole(UserRole::Trainer->value);
        });
    }

    private function riga(User $trainer, User $allievo): ?object
    {
        return DB::table('trainer_member')
            ->where('trainer_id', $trainer->getKey())
            ->where('member_id', $allievo->getKey())
            ->first();
    }

    /**
     * 🚨 **Il caso centrale**: l'allievo di un trainer entra nella palestra dove
     * quel trainer lavora.
     */
    #[Test]
    public function entrando_in_palestra_lo_slot_si_sospende_e_il_trainer_va_interpellato(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->trainerIndipendente();
        $this->assumi($palestra, $trainer);

        $allievo = $this->persona('allievo@esempio.test');
        app(InvitiDelTrainer::class)->riscattaPerChiEsiste(
            app(TenantContext::class)->runAs(
                $trainer->tenant,
                fn () => app(InvitiDelTrainer::class)->invita($trainer),
            )->token,
            $allievo,
        );

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant(
            $allievo->fresh(),
            $palestra,
            UserRole::Member,
        );

        $riga = $this->riga($trainer, $allievo);

        $this->assertNotNull($riga);
        $this->assertNotNull($riga->sospeso_il, 'il rapporto non e stato sospeso');
        $this->assertSame($palestra->getKey(), (int) $riga->sospeso_per_tenant_id);
        $this->assertNull($riga->slot_deciso_il, 'risulta gia deciso senza aver chiesto niente');

        /*
         * ⛔ **Non si chiude niente.** Liberare lo slot di default vorrebbe dire
         * decidere al posto del trainer, nella direzione che gli toglie
         * l'allievo.
         */
        $this->assertNull($riga->disattivato_il, 'il rapporto e stato chiuso invece che sospeso');

        $daDecidere = app(LoSlotDelTrainer::class)->daDecidere($trainer);

        $this->assertCount(1, $daDecidere);
        $this->assertSame($allievo->getKey(), (int) $daDecidere->first()->member_id);
    }

    /**
     * ⛔ **Un trainer che non lavora lì non viene toccato.**
     *
     * 🚨 È la condizione che separa la regola da un danno: due cose separate,
     * e sospendere il rapporto personale vorrebbe dire romperlo senza che
     * nessuno l'abbia chiesto.
     */
    #[Test]
    public function un_trainer_che_non_lavora_in_quella_palestra_non_si_tocca(): void
    {
        $palestra = $this->creaPalestra();
        $estraneo = $this->trainerIndipendente('estraneo@esempio.test');

        $allievo = $this->persona('allievo2@esempio.test');
        app(InvitiDelTrainer::class)->riscattaPerChiEsiste(
            app(TenantContext::class)->runAs(
                $estraneo->tenant,
                fn () => app(InvitiDelTrainer::class)->invita($estraneo),
            )->token,
            $allievo,
        );

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant(
            $allievo->fresh(),
            $palestra,
            UserRole::Member,
        );

        $riga = $this->riga($estraneo, $allievo);

        $this->assertNotNull($riga);
        $this->assertNull($riga->sospeso_il, 'sospeso un rapporto che con quella palestra non centra');
        $this->assertEmpty(app(LoSlotDelTrainer::class)->daDecidere($estraneo));
    }

    /**
     * 📌 *«Se il trainer decide di mantenere lo slot occupato […] poi se si
     * dissocia dalla palestra torna al trainer.»*
     */
    #[Test]
    public function chi_tiene_lo_slot_si_riprende_lallievo_quando_esce(): void
    {
        [$palestra, $trainer, $allievo] = $this->giroCompleto('tiene@esempio.test');

        $this->assertTrue(app(LoSlotDelTrainer::class)->decidi($trainer, $allievo, true));

        $dopoLaScelta = $this->riga($trainer, $allievo);
        $this->assertNotNull($dopoLaScelta->slot_deciso_il);
        $this->assertNull($dopoLaScelta->disattivato_il, 'tenere lo slot ha chiuso il rapporto');

        app(EsciDaUnaPalestra::class)($allievo->fresh());

        $dopoLuscita = $this->riga($trainer, $allievo);

        $this->assertNull($dopoLuscita->sospeso_il, 'il rapporto e rimasto sospeso');
        $this->assertNull($dopoLuscita->sospeso_per_tenant_id);
        $this->assertNull($dopoLuscita->disattivato_il, 'lallievo non e tornato al trainer');
    }

    /**
     * 📌 *«Se il trainer decide di liberare lo slot […] se si dissocia da essa
     * non torna al trainer.»*
     *
     * 💡 È la metà della regola che dà senso all'altra: si pagava per
     * prenotarsi il ritorno, e chi non ha pagato non lo ha prenotato.
     */
    #[Test]
    public function chi_libera_lo_slot_non_se_lo_riprende(): void
    {
        [$palestra, $trainer, $allievo] = $this->giroCompleto('libera@esempio.test');

        $this->assertTrue(app(LoSlotDelTrainer::class)->decidi($trainer, $allievo, false));

        $dopoLaScelta = $this->riga($trainer, $allievo);
        $this->assertNotNull($dopoLaScelta->disattivato_il, 'liberare lo slot non ha chiuso niente');

        app(EsciDaUnaPalestra::class)($allievo->fresh());

        $dopoLuscita = $this->riga($trainer, $allievo);

        $this->assertNotNull(
            $dopoLuscita->disattivato_il,
            'lallievo e tornato a un trainer che aveva liberato lo slot',
        );
    }

    /**
     * ⚠️ **Si riattiva solo uscendo da QUELLA palestra.**
     *
     * ⛔ Riattivare tutto vorrebbe dire riaprire legami sospesi altrove, che
     * nessuno ha chiesto di riaprire.
     */
    #[Test]
    public function si_riattiva_solo_uscendo_dalla_palestra_che_aveva_sospeso(): void
    {
        [$palestra, $trainer, $allievo] = $this->giroCompleto('solo-quella@esempio.test');

        app(LoSlotDelTrainer::class)->decidi($trainer, $allievo, true);

        $altra = $this->creaPalestra('Altra', 'altra', 'ALTR2345');

        $this->assertSame(
            0,
            app(LoSlotDelTrainer::class)->riattivaPerUscitaDallaPalestra($allievo->fresh(), $altra),
            'un rapporto si e riattivato uscendo da una palestra che non centra',
        );

        $this->assertNotNull($this->riga($trainer, $allievo)->sospeso_il);
    }

    /**
     * 🚨 **L'abbonamento si ferma, e i giorni restano scritti.**
     */
    #[Test]
    public function entrando_in_palestra_labbonamento_si_mette_in_pausa(): void
    {
        $palestra = $this->creaPalestra();
        $abbonato = $this->persona('abbonato@esempio.test', now()->addDays(20));
        $suo = $abbonato->tenant;

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant($abbonato, $palestra, UserRole::Member);

        $riga = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $suo->getKey())
            ->firstOrFail();

        $this->assertNotNull($riga->in_pausa_dal, 'labbonamento non e stato messo in pausa');
        $this->assertSame($palestra->getKey(), (int) $riga->in_pausa_per_tenant_id);
        $this->assertSame(20, $riga->restavano_giorni, 'i giorni che restavano non sono stati salvati');

        /*
         * 🚨 **La prova che conta**: in pausa non vuol dire attivo. Se
         * risultasse attivo, quella persona avrebbe due coperture — e nessuno
         * se ne accorgerebbe.
         */
        $this->assertFalse($riga->eAttivo(), 'un abbonamento in pausa risulta attivo');
        $this->assertSame(
            0,
            PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $suo->getKey())
                ->attivi()
                ->count(),
            'lo scope attivi conta anche quelli in pausa',
        );
    }

    /**
     * 📌 *«Si riconoscono alla ripresa»* — i venti giorni tornano.
     */
    #[Test]
    public function uscendo_labbonamento_riprende_con_i_giorni_che_restavano(): void
    {
        $palestra = $this->creaPalestra();
        $abbonato = $this->persona('torna@esempio.test', now()->addDays(20));

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant($abbonato, $palestra, UserRole::Member);

        Carbon::setTestNow(now()->addDays(45));

        $nuovo = app(EsciDaUnaPalestra::class)($abbonato->fresh());

        $riga = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $nuovo->getKey())
            ->first();

        $this->assertNotNull($riga, 'labbonamento non e tornato nello spazio nuovo');
        $this->assertNull($riga->in_pausa_dal, 'e tornato ma e rimasto in pausa');
        $this->assertTrue($riga->eAttivo());
        /*
         * ⚠️ **`ceil` anche qui, e per la stessa ragione del servizio**: fra il
         * momento in cui la scadenza è stata scritta e questa riga passano
         * microsecondi, quindi venti giorni pieni misurano `19,999…`. 🚨 La
         * prima stesura di questo test asseriva su un troncamento e falliva
         * dicendo `19` — con il codice giusto.
         */
        $this->assertSame(
            20,
            (int) ceil(now()->diffInDays($riga->ends_at, false)),
            'i giorni riconosciuti non sono quelli che restavano',
        );

        Carbon::setTestNow();
    }

    /**
     * ⚠️ **E chi non era abbonato esce non abbonato.**
     *
     * ⛔ La direzione dell'errore conta: creare un abbonamento che non c'era
     * vorrebbe dire regalare il servizio a chi non l'ha mai pagato, e scoprirlo
     * mai.
     */
    #[Test]
    public function chi_non_era_abbonato_esce_non_abbonato(): void
    {
        $palestra = $this->creaPalestra();
        $nudo = $this->persona('nudo@esempio.test');

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant($nudo, $palestra, UserRole::Member);

        $nuovo = app(EsciDaUnaPalestra::class)($nudo->fresh());

        $this->assertSame(
            0,
            PlanSubscription::withoutGlobalScopes()->where('tenant_id', $nuovo->getKey())->count(),
            'e nato un abbonamento che non era mai esistito',
        );
    }

    /**
     * ⚠️ **Un abbonamento senza scadenza resta senza scadenza.**
     *
     * 🚨 `ends_at IS NULL` è la convenzione «non scade». ⛔ Salvarci `0` giorni
     * lo farebbe riprendere **già scaduto** — e sarebbe un abbonamento perpetuo
     * spento da una funzione che doveva solo metterlo in pausa.
     */
    #[Test]
    public function un_abbonamento_perpetuo_riprende_perpetuo(): void
    {
        $palestra = $this->creaPalestra();
        $perpetuo = $this->persona('perpetuo@esempio.test');

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $perpetuo->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::PLUS)->firstOrFail()->id,
            'starts_at' => now()->subYear(),
            'ends_at' => null,
        ]);

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant($perpetuo->fresh(), $palestra, UserRole::Member);

        $inPausa = PlanSubscription::withoutGlobalScopes()
            ->whereNotNull('in_pausa_dal')
            ->firstOrFail();

        $this->assertNull($inPausa->restavano_giorni, 'un perpetuo ha preso una scadenza');

        $nuovo = app(EsciDaUnaPalestra::class)($perpetuo->fresh());

        $ripreso = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $nuovo->getKey())
            ->firstOrFail();

        $this->assertNull($ripreso->ends_at, 'il perpetuo e tornato con una scadenza');
        $this->assertTrue($ripreso->eAttivo());
    }

    /**
     * ⛔ **L'abbonamento della palestra non si tocca.**
     *
     * 🚨 È la riga più importante di `LaPausaDellAbbonamento::metti()`: fermare
     * quello della palestra mentre entra un iscritto vorrebbe dire spegnere il
     * servizio a tutti gli altri.
     */
    #[Test]
    public function labbonamento_della_palestra_non_si_ferma(): void
    {
        $palestra = $this->creaPalestra();
        $tizio = $this->persona('tizio@esempio.test', now()->addDays(5));

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant($tizio, $palestra, UserRole::Member);

        $dellaPalestra = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->firstOrFail();

        $this->assertNull($dellaPalestra->in_pausa_dal, 'e stato fermato labbonamento della palestra');
        $this->assertTrue($dellaPalestra->eAttivo());
    }

    /**
     * 📌 *«al trainer appaia di nuovo la modale»* quando l'allievo **torna** in
     * palestra.
     */
    #[Test]
    public function se_lallievo_torna_la_domanda_si_rifa(): void
    {
        [$palestra, $trainer, $allievo] = $this->giroCompleto('ritorno@esempio.test');

        app(LoSlotDelTrainer::class)->decidi($trainer, $allievo, true);
        app(EsciDaUnaPalestra::class)($allievo->fresh());

        $this->assertEmpty(app(LoSlotDelTrainer::class)->daDecidere($trainer));

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant(
            $allievo->fresh(),
            $palestra,
            UserRole::Member,
        );

        $this->assertCount(
            1,
            app(LoSlotDelTrainer::class)->daDecidere($trainer),
            'lallievo e tornato e al trainer non e stato richiesto niente',
        );
    }

    /**
     * Il giro completo: palestra, trainer che ci lavora, allievo suo che entra.
     *
     * @return array{0: Tenant, 1: User, 2: User}
     */
    private function giroCompleto(string $email): array
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->trainerIndipendente('t-'.$email);
        $this->assumi($palestra, $trainer);

        $allievo = $this->persona($email);

        app(InvitiDelTrainer::class)->riscattaPerChiEsiste(
            app(TenantContext::class)->runAs(
                $trainer->tenant,
                fn () => app(InvitiDelTrainer::class)->invita($trainer),
            )->token,
            $allievo,
        );

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant(
            $allievo->fresh(),
            $palestra,
            UserRole::Member,
        );

        return [$palestra, $trainer->fresh(), $allievo->fresh()];
    }
}
