<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\InvitiDeiTrainer\InvitoDelTrainerResource;
use App\Filament\Gym\Resources\Members\MemberResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🏋️ **I tre pannelli dei trainer** — F10, 23/09/2026.
 *
 * 📌 Il committente: *«i pannelli di free_trainer, trainer palestra e un trainer
 * che sia entrambi falli tu, vedi come sono fatti quelli delle palestre e falli
 * uguali. Ovviamente il trainer di palestra non deve poter modificare nulla che
 * riguardi la palestra e i suoi iscritti, mentre un free trainer potrà agire
 * sostanzialmente come una palestra»*.
 *
 * ══ 🚨 I TRE CASI SONO UNO SOLO, VISTO DA TRE SPAZI ═════════════════════════
 *
 * ⛔ **Non ci sono tre pannelli**, e questo file lo tiene fermo: c'è un pannello
 * che prende la forma dello **spazio in cui sei**. Chi è tutti e due cambia
 * spazio con «Cambia spazio» e cambia forma con lui.
 *
 * 💡 È possibile perché i ruoli si risolvono **nel contesto** (`hasAppRole`): la
 * stessa persona è `FreeTrainer` a casa propria e `Trainer` nella palestra dove
 * allena, e nessuno dei due ruolo la segue nell'altro spazio.
 */
final class ITrePannelliDeiTrainerTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private const PAROLA = 'Prova-2309-pannelli';

    /** Un trainer indipendente, con il suo spazio personale. */
    private function unIndipendente(string $email = 'libero@esempio.test'): User
    {
        return app(CreaTenantPersonale::class)(
            'Trainer Indipendente',
            $email,
            ['password' => self::PAROLA],
            UserRole::FreeTrainer,
        );
    }

    /**
     * Un allievo legato al trainer, **che vive altrove**.
     *
     * 🚨 È il caso che conta: chi aveva già un account resta nel proprio spazio,
     * e solo `trainer_member` lo lega al trainer (F6.1). ⛔ Cercarlo con «chi ha
     * il ruolo member in questo tenant» non lo troverebbe mai.
     */
    private function unAllievoAltrove(User $trainer, Tenant $casaSua): User
    {
        $allievo = $this->creaUtente($casaSua, UserRole::Member, 'allievo@altrove.test');

        $allievo->assignedTrainers()->attach($trainer->getKey(), [
            'tenant_id' => $trainer->tenant_id,
            'assigned_at' => now(),
            'assigned_by' => $trainer->getKey(),
        ]);

        return $allievo;
    }

    // ───────────────── 1. il trainer indipendente ─────────────────

    /**
     * 🚨 **Vede i suoi allievi anche se abitano altrove.**
     *
     * ⛔ Prima non vedeva **niente**: nel proprio spazio non è né `GymAdmin` né
     * `Trainer`, e ogni scoping del pannello lo trattava come uno sconosciuto —
     * il pannello *falliva chiuso*, che era giusto finché non c'era niente da
     * mostrargli.
     */
    #[Test]
    public function un_indipendente_vede_i_suoi_allievi_ovunque_abitino(): void
    {
        $altrove = $this->creaPalestra('Altrove', 'altrove');
        $libero = $this->unIndipendente();
        $allievo = $this->unAllievoAltrove($libero, $altrove);

        $this->actingAs($libero);

        $visti = $this->ctx()->runAs(
            $libero->tenant,
            fn (): array => MemberResource::getEloquentQuery()->pluck('id')->all(),
        );

        $this->assertContains(
            $allievo->getKey(),
            $visti,
            'Il trainer indipendente non vede un suo allievo che abita in un altro spazio: '
            .'e non avrebbe modo di accorgersene, perche\' l\'elenco funziona lo stesso.',
        );
    }

    /** ⛔ E non vede gli iscritti di una palestra dove non allena. */
    #[Test]
    public function ma_non_vede_gli_iscritti_di_una_palestra_che_non_e_sua(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $estraneo = $this->creaUtente($palestra, UserRole::Member, 'estraneo@olimpo.test');

        $libero = $this->unIndipendente();

        $this->actingAs($libero);

        $visti = $this->ctx()->runAs(
            $libero->tenant,
            fn (): array => MemberResource::getEloquentQuery()->pluck('id')->all(),
        );

        $this->assertNotContains(
            $estraneo->getKey(),
            $visti,
            'Un trainer indipendente sta vedendo un iscritto di una palestra estranea.',
        );
    }

    /** ✅ E a casa propria comanda: è il senso di «come una palestra». */
    #[Test]
    public function a_casa_propria_comanda(): void
    {
        $libero = $this->unIndipendente();

        $this->assertTrue(
            $this->ctx()->runAs($libero->tenant, fn (): bool => $libero->comandaQui()),
            'Il trainer indipendente non comanda nemmeno a casa sua.',
        );
    }

    // ───────────────── 2. il trainer di palestra ─────────────────

    /**
     * ⛔ **Non comanda, e non deve**: la palestra non è sua.
     *
     * 📌 *«il trainer di palestra non deve poter modificare nulla che riguardi
     * la palestra e i suoi iscritti»*.
     */
    #[Test]
    public function un_trainer_di_palestra_non_comanda_nella_palestra(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');

        $this->actingAs($trainer);

        $this->assertFalse(
            $this->ctx()->runAs($palestra, fn (): bool => $trainer->comandaQui()),
            'Un trainer di palestra risulta padrone della palestra.',
        );
    }

    /**
     * 🚨 **Ma gli iscritti li vede**, in lettura — decisione del committente del
     * 16/09: *«tutti in lettura, ma le schede e i piani entrano nel suo profilo
     * personale, non può assegnarli dal server»*.
     *
     * 💡 Si prova insieme al caso sopra perché sono **la stessa regola**: vedere
     * non è toccare, e separarli in due file farebbe leggere il primo senza il
     * secondo.
     */
    #[Test]
    public function pero_gli_iscritti_della_palestra_li_vede(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');

        $this->actingAs($trainer);

        $visti = $this->ctx()->runAs(
            $palestra,
            fn (): array => MemberResource::getEloquentQuery()->pluck('id')->all(),
        );

        $this->assertContains($iscritto->getKey(), $visti);

        // ⛔ E non può modificarlo: `UserPolicy::update` è solo dell'amministratore.
        $this->assertFalse(
            $trainer->can('update', $iscritto),
            'Un trainer di palestra puo\' modificare un iscritto.',
        );

        $this->assertFalse(
            $trainer->can('create', User::class),
            'Un trainer di palestra puo\' creare persone nella palestra.',
        );
    }

    // ───────────────── 3. chi è tutti e due ─────────────────

    /**
     * 🚨 **Il caso in cui sbagliare costa di più.**
     *
     * ⛔ La tentazione è far bastare `isFreeTrainer()` per dire «comanda». Ma
     * quel ruolo **ce l'ha a casa sua**, e una persona che allena anche in una
     * palestra ci entra portandoselo dietro: senza il controllo sul
     * proprietario, si ritroverebbe i comandi del padrone di casa **a casa
     * d'altri**.
     *
     * 💡 Qui si prova la stessa persona nei due spazi, ed è l'unico modo di
     * vedere la differenza: guardata in uno spazio solo, la regola sbagliata
     * sembra giusta.
     */
    #[Test]
    public function chi_e_tutti_e_due_comanda_solo_a_casa_propria(): void
    {
        $libero = $this->unIndipendente('doppio@esempio.test');
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');

        // La stessa persona allena anche nella palestra.
        $this->ctx()->runAs($palestra, function () use ($libero, $palestra): void {
            $libero->forceFill(['tenant_id' => $libero->tenant_id])->save();
            $libero->assignRole(UserRole::Trainer->value);
        });

        $fresco = User::withoutGlobalScopes()->findOrFail($libero->getKey());

        $this->actingAs($fresco);

        /*
         * ⚠️ **Prima si verifica che sia davvero tutti e due**, o il test è
         * vacuo: se `assignRole()` non attecchisse, la seconda asserzione
         * passerebbe per il motivo sbagliato — «non comanda nella palestra»
         * sarebbe vero perché nella palestra non c'è.
         */
        $this->assertTrue(
            $this->ctx()->runAs($palestra, fn (): bool => User::withoutGlobalScopes()
                ->findOrFail($libero->getKey())->isTrainer()),
            'Il doppio ruolo non e\' stato creato: il test non proverebbe niente.',
        );

        $this->assertTrue(
            $this->ctx()->runAs($fresco->tenant, fn (): bool => $fresco->fresh()->comandaQui()),
            'A casa sua non comanda piu\'.',
        );

        $this->assertFalse(
            $this->ctx()->runAs($palestra, fn (): bool => User::withoutGlobalScopes()
                ->findOrFail($libero->getKey())->comandaQui()),
            '🚨 Entrando nella palestra si porta dietro i comandi di casa propria: '
            .'e\' il difetto che questo test esiste per impedire.',
        );
    }

    // ───────────────── 4. gli inviti agli allievi ─────────────────

    /**
     * 🔗 **La pagina degli inviti la vede chi può avere allievi suoi** — F10.1.
     *
     * 📌 Il committente, il 23/09: *«Questo mi servirà anche nel pannello dei
     * free_trainers»*, riferito alla pagina degli inviti delle palestre.
     *
     * ⚠️ Il cancello è `puoAvereAllieviSuoi()` e non il ruolo: uno spazio suo
     * **e** un piano da trainer. ⛔ Senza piano non si invita nessuno, ed è la
     * stessa regola che applica l'app — due risposte diverse alla stessa
     * domanda sono il difetto che il 16/09 ha fatto riscrivere gli scoping.
     */
    #[Test]
    public function la_pagina_degli_inviti_la_vede_chi_ha_un_piano_da_trainer(): void
    {
        $libero = $this->unIndipendente();

        $this->actingAs($libero);

        // ⛔ Senza piano da trainer: niente pagina.
        $this->assertFalse(
            $this->ctx()->runAs($libero->tenant, fn (): bool => InvitoDelTrainerResource::canAccess()),
            'Un trainer senza piano da trainer vede la pagina degli inviti.',
        );

        $this->conPianoDaTrainer($libero->fresh());

        $this->assertTrue(
            $this->ctx()->runAs(
                $libero->tenant,
                fn (): bool => InvitoDelTrainerResource::canAccess(),
            ),
            'Un trainer indipendente col suo piano non vede la pagina degli inviti.',
        );
    }

    /**
     * 🚨 **Ognuno vede i propri, e solo i propri.**
     *
     * ⛔ Il filtro è su `trainer_id`, non sullo scope del tenant: nello spazio
     * di un trainer ci vivono anche i suoi allievi, e un invito è **di chi
     * l'ha fatto**.
     */
    #[Test]
    public function ognuno_vede_soltanto_i_propri_inviti(): void
    {
        $uno = $this->conPianoDaTrainer($this->unIndipendente('uno@esempio.test'));
        $due = $this->conPianoDaTrainer($this->unIndipendente('due@esempio.test'));

        $servizio = app(InvitiDelTrainer::class);

        $suo = $servizio->invita($uno->fresh(), 'allievo-di-uno@esempio.test');
        $altrui = $servizio->invita($due->fresh(), 'allievo-di-due@esempio.test');

        $this->actingAs($uno);

        $visti = $this->ctx()->runAs(
            $uno->tenant,
            fn (): array => InvitoDelTrainerResource::getEloquentQuery()->pluck('id')->all(),
        );

        $this->assertContains($suo->getKey(), $visti, 'Non vede nemmeno il proprio invito.');

        $this->assertNotContains(
            $altrui->getKey(),
            $visti,
            '🚨 Sta vedendo l\'invito di un altro trainer, con dentro l\'email di una persona '
            .'che non e\' sua.',
        );
    }

    /**
     * 🚫 **Revocare libera il posto**, ed è il motivo per cui la pagina esiste.
     *
     * ⚠️ Un invito valido tiene un posto finché non scade: senza la revoca, un
     * link mandato alla persona sbagliata costava un posto per sette giorni.
     */
    #[Test]
    public function revocare_un_invito_libera_il_posto(): void
    {
        $trainer = $this->conPianoDaTrainer($this->unIndipendente());
        $servizio = app(InvitiDelTrainer::class);

        $prima = $servizio->postiRimasti($trainer->fresh());

        $invito = $servizio->invita($trainer->fresh(), 'allievo@esempio.test');

        $this->assertSame(
            $prima - 1,
            $servizio->postiRimasti($trainer->fresh()),
            'Un invito valido non sta tenendo un posto: allora la revoca non serve a niente.',
        );

        $servizio->revoca($invito);

        $this->assertSame(
            $prima,
            $servizio->postiRimasti($trainer->fresh()),
            'Revocato l\'invito, il posto non e\' tornato libero.',
        );

        $this->assertFalse($invito->fresh()->eValido());
    }
}
