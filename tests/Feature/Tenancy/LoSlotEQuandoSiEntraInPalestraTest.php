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
 * 4. che la sovrapposizione si chiuda **solo** uscendo dalla palestra che l'aveva aperta;
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
     * 🚨 **LA REGOLA, provata invece che affermata** — 18/09/2026.
     *
     * 📌 Il committente: *«un allievo deve poter essere iscritto a infiniti
     * free_trainer e una sola palestra, anche contemporaneamente. L'unico caso
     * limite è se un allievo di un free trainer si iscrive in una palestra in
     * cui lavora quel free trainer.»*
     *
     * ⛔ Qui tre trainer, una palestra, e **uno solo** dei tre ci lavora. Alla
     * fine:
     *
     * - tutti e tre vedono ancora il loro allievo — **nessun rapporto si rompe**;
     * - l'allievo vede ancora tutti e tre;
     * - **solo** quello che lavora lì riceve la domanda sullo slot.
     */
    #[Test]
    public function infiniti_trainer_e_una_palestra_convivono_e_solo_chi_si_sovrappone_viene_interpellato(): void
    {
        $palestra = $this->creaPalestra();

        $dentro = $this->trainerIndipendente('dentro@esempio.test');
        $fuoriUno = $this->trainerIndipendente('fuori1@esempio.test');
        $fuoriDue = $this->trainerIndipendente('fuori2@esempio.test');

        $this->assumi($palestra, $dentro);

        $allievo = $this->persona('tre-trainer@esempio.test');

        foreach ([$dentro, $fuoriUno, $fuoriDue] as $t) {
            app(InvitiDelTrainer::class)->riscattaPerChiEsiste(
                app(TenantContext::class)->runAs(
                    $t->tenant,
                    fn () => app(InvitiDelTrainer::class)->invita($t),
                )->token,
                $allievo->fresh(),
                affianco: true,
            );
        }

        $this->assertCount(
            3,
            $allievo->fresh()->assignedTrainers()->get(),
            'non si possono seguire tre trainer insieme',
        );

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant(
            $allievo->fresh(),
            $palestra,
            UserRole::Member,
        );

        /*
         * 🚨 **La prova che il committente ha chiesto**: entrare in palestra non
         * rompe niente. Tre trainer prima, tre trainer dopo.
         */
        $this->assertCount(
            3,
            $allievo->fresh()->assignedTrainers()->wherePivotNull('disattivato_il')->get(),
            'entrando in palestra si e perso un trainer',
        );

        foreach ([$dentro, $fuoriUno, $fuoriDue] as $t) {
            $this->assertTrue(
                app(TenantContext::class)->runAs(
                    $t->tenant,
                    fn (): bool => User::withoutGlobalScopes()
                        ->findOrFail($t->getKey())
                        ->assignedMembers()
                        ->where('users.id', $allievo->getKey())
                        ->exists(),
                ),
                "il trainer {$t->email} non vede piu il suo allievo",
            );
        }

        /*
         * ⚠️ E la domanda sullo slot la riceve **uno solo**: quello che si
         * ritrova l'allievo dentro la palestra dove lavora.
         */
        $this->assertCount(1, app(LoSlotDelTrainer::class)->daDecidere($dentro));
        $this->assertEmpty(app(LoSlotDelTrainer::class)->daDecidere($fuoriUno));
        $this->assertEmpty(app(LoSlotDelTrainer::class)->daDecidere($fuoriDue));

        $this->assertNull(
            $this->riga($fuoriUno, $allievo)->sovrapposto_dal,
            'marcato un trainer che con quella palestra non centra niente',
        );
    }

    /**
     * 🚨 **Il caso centrale**: l'allievo di un trainer entra nella palestra dove
     * quel trainer lavora.
     */
    #[Test]
    public function entrando_dove_lavora_il_suo_trainer_il_trainer_viene_interpellato(): void
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
        $this->assertNotNull($riga->sovrapposto_dal, 'la sovrapposizione non e stata segnata');
        $this->assertSame($palestra->getKey(), (int) $riga->sovrapposto_in_tenant_id);
        $this->assertNull($riga->slot_deciso_il, 'risulta gia deciso senza aver chiesto niente');

        /*
         * ⛔ **Non si chiude niente.** Liberare lo slot di default vorrebbe dire
         * decidere al posto del trainer, nella direzione che gli toglie
         * l'allievo.
         */
        $this->assertNull($riga->disattivato_il, 'il rapporto e stato chiuso: non doveva essere toccato');

        $daDecidere = app(LoSlotDelTrainer::class)->daDecidere($trainer);

        $this->assertCount(1, $daDecidere);
        $this->assertSame($allievo->getKey(), (int) $daDecidere->first()->member_id);
    }

    /**
     * ⛔ **Un trainer che non lavora lì non viene toccato.**
     *
     * 🚨 È la condizione che separa la regola da un danno: sono due cose
     * separate, e fargli una domanda sullo slot vorrebbe dire chiedergli di
     * decidere su una palestra con cui non c'entra niente.
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
        $this->assertNull($riga->sovrapposto_dal, 'marcato un rapporto che con quella palestra non centra');
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

        $this->assertNull($dopoLuscita->sovrapposto_dal, 'la sovrapposizione e rimasta aperta');
        $this->assertNull($dopoLuscita->sovrapposto_in_tenant_id);
        $this->assertNull($dopoLuscita->disattivato_il, 'lallievo non e tornato al trainer');

        // 🆕 22/09/2026 (Q-A5-1): e torna **nello spazio** del trainer, da iscritto suo.
        $tornato = User::withoutGlobalScopes()->findOrFail($allievo->getKey());
        $this->assertSame($trainer->tenant_id, $tornato->tenant_id, 'Doveva tornare nello spazio del trainer.');
        $this->assertTrue(
            app(TenantContext::class)->runAs(
                $trainer->tenant,
                fn (): bool => $tornato->unsetRelation('roles')->hasRole(UserRole::Member->value),
            ),
            'Nello spazio del trainer doveva essere un iscritto.',
        );
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
    public function si_chiude_solo_uscendo_dalla_palestra_che_laveva_aperta(): void
    {
        [$palestra, $trainer, $allievo] = $this->giroCompleto('solo-quella@esempio.test');

        app(LoSlotDelTrainer::class)->decidi($trainer, $allievo, true);

        $altra = $this->creaPalestra('Altra', 'altra');

        $this->assertSame(
            0,
            app(LoSlotDelTrainer::class)->chiudiLaSovrapposizione($allievo->fresh(), $altra),
            'una sovrapposizione si e chiusa uscendo da una palestra che non centra',
        );

        $this->assertNotNull($this->riga($trainer, $allievo)->sovrapposto_dal);
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
     * 🆕 🔁 **Riaccettare un trainer che aveva liberato il posto riapre il
     * rapporto** — D5, giro del 18/09/2026.
     *
     * ⛔ Prima la riga restava chiusa: `$gia` era vero e il riscatto non faceva
     * niente, mentre l'app diceva «fatto». 📌 *«il loro rapporto deve
     * riprendere da dove si era fermato»*.
     */
    #[Test]
    public function riaccettare_un_trainer_che_aveva_liberato_il_posto_riapre_il_rapporto(): void
    {
        [$palestra, $trainer, $allievo] = $this->giroCompleto('riapre@esempio.test');

        app(LoSlotDelTrainer::class)->decidi($trainer, $allievo, false);
        $this->assertNotNull($this->riga($trainer, $allievo)->disattivato_il, 'premessa: chiuso');

        $idPrima = $this->riga($trainer, $allievo)->id;

        app(InvitiDelTrainer::class)->riscattaPerChiEsiste(
            app(TenantContext::class)->runAs(
                $trainer->tenant,
                fn () => app(InvitiDelTrainer::class)->invita($trainer),
            )->token,
            $allievo->fresh(),
        );

        $dopo = $this->riga($trainer, $allievo);

        $this->assertNull($dopo->disattivato_il, 'Il rapporto doveva riaprirsi.');
        $this->assertSame($idPrima, $dopo->id, 'Stessa riga, non una nuova.');

        // 💡 L'allievo e' ancora in una palestra dove il trainer lavora: la
        // domanda sul posto torna al trainer.
        $this->assertSame($palestra->getKey(), $dopo->sovrapposto_in_tenant_id);
        $this->assertNull($dopo->slot_deciso_il);
    }

    /**
     * 🆕 🚨 **I giorni pagati tornano da qualunque tenant** — D1, giro del 18/09.
     *
     * ⛔ Il percorso di `mepippo`: abbonato, accetta un trainer indipendente
     * (finisce nel suo spazio: pausa), entra in palestra, esce. La ripresa
     * cercava solo la pausa **di quella palestra**, e lui tornava «scaduto».
     *
     * 📌 *«quando torna individuale non può avere l'abbonamento scaduto,
     * l'ha pagato»*.
     */
    #[Test]
    public function chi_segue_un_trainer_poi_entra_ed_esce_da_una_palestra_ritrova_i_giorni_pagati(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->trainerIndipendente('coach-d1@esempio.test');
        $allievo = $this->persona('pagante@esempio.test', now()->addDays(20));

        app(InvitiDelTrainer::class)->riscattaPerChiEsiste(
            app(TenantContext::class)->runAs(
                $trainer->tenant,
                fn () => app(InvitiDelTrainer::class)->invita($trainer),
            )->token,
            $allievo,
        );

        app(UnisciAUnaPalestra::class)->dentroQuestoTenant($allievo->fresh(), $palestra, UserRole::Member);

        /*
         * 🔄 22/09/2026 (Q-A5-1): uscendo dalla palestra **torna dal trainer**,
         * che il posto non l'ha mai liberato. Lì paga il trainer: il Plus resta
         * in pausa, con i giorni che restavano.
         */
        $dalTrainer = User::withoutGlobalScopes()->findOrFail($allievo->getKey());
        app(EsciDaUnaPalestra::class)($dalTrainer);
        $dalTrainer = $dalTrainer->fresh();

        $this->assertSame($trainer->tenant_id, $dalTrainer->tenant_id, 'Doveva tornare nello spazio del trainer.');

        $inPausa = PlanSubscription::withoutGlobalScopes()
            ->whereHas('plan', fn ($q) => $q->where('code', Plan::PLUS))
            ->whereIn('tenant_id', Tenant::withoutGlobalScopes()->withTrashed()->where('proprietario_id', $allievo->getKey())->select('id'))
            ->first();

        $this->assertNotNull($inPausa?->in_pausa_dal, 'Dal trainer il Plus doveva restare in pausa.');

        // 📌 *«quando torna individuale non può avere l'abbonamento scaduto, l'ha pagato»*.
        app(EsciDaUnaPalestra::class)($dalTrainer);
        $tornato = $dalTrainer->fresh();

        $riga = PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tornato->tenant_id)
            ->whereHas('plan', fn ($q) => $q->where('code', Plan::PLUS))
            ->first();

        $this->assertNotNull($riga, 'L\'abbonamento doveva seguirlo nello spazio nuovo.');
        $this->assertNull($riga->in_pausa_dal, 'Doveva riprendere.');
        $this->assertEqualsWithDelta(20, now()->diffInDays($riga->ends_at), 1, 'I giorni pagati sono quelli.');
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
