<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\UserRole;
use App\Filament\Gym\Resources\InvitiDeiTrainer\InvitoDelTrainerResource;
use App\Filament\Gym\Resources\Members\MemberResource;
use App\Filament\Gym\Resources\Members\Pages\ListMembers;
use App\Filament\Gym\Resources\Members\Pages\ViewMember;
use App\Filament\Gym\Resources\PlanImports\PlanImportResource;
use App\Filament\Gym\Widgets\GymOverview;
use App\Filament\Gym\Widgets\IlGiroDellaPalestra;
use App\Filament\Gym\Widgets\IPostiDellaPalestra;
use App\Filament\Gym\Widgets\LoSlotDaDecidere;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reporting\IContiDellaPalestra;
use App\Services\Tenancy\AllenareInUnaPalestra;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Services\Tenancy\LoSlotDelTrainer;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
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
        $this->ctx()->runAs($palestra, function () use ($libero): void {
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

    // ───────────────── 5. i tasti d'invito, e che siano quelli giusti ─────

    /**
     * 🐛 **A un trainer indipendente si mostrava il tasto della palestra.**
     *
     * 📌 Il committente, dal suo pannello: *«Se clicco su "Invita un iscritto"
     * mi dice che solo la palestra può invitare»*.
     *
     * ⛔ `UnInvito` parla con `InvitiInPalestra`, che a uno spazio personale
     * risponde *«un tenant personale non è una palestra»*. 🚨 Giustissimo lato
     * servizio — ma il rifiuto arrivava **dopo** aver aperto la finestra, cioè
     * dopo avergli fatto credere che si potesse.
     *
     * 💡 Il test guarda **tutti e due** i tasti: che ci sia quello giusto non
     * basta, se accanto resta quello che non funziona.
     */
    #[Test]
    public function a_un_indipendente_si_mostra_il_tasto_giusto(): void
    {
        $libero = $this->conPianoDaTrainer($this->unIndipendente());

        $this->actingAs($libero);

        $this->ctx()->runAs($libero->tenant, function (): void {
            Filament::setCurrentPanel('admin');

            Livewire::test(ListMembers::class)
                ->assertActionVisible('invitaAllievo')
                ->assertActionHidden('invita');
        });
    }

    /** ✅ E a chi amministra una palestra si mostra quello della palestra. */
    #[Test]
    public function a_una_palestra_si_mostra_il_suo(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@olimpo.test');

        $this->actingAs($capo);

        $this->ctx()->runAs($palestra, function (): void {
            Filament::setCurrentPanel('admin');

            Livewire::test(ListMembers::class)
                ->assertActionVisible('invita')
                ->assertActionHidden('invitaAllievo');
        });
    }

    /**
     * 🎁 **«Regalagli gettoni» c'era, e non si vedeva.**
     *
     * 📌 Il committente: *«Già che c'è scritto tanto vale che gli possa
     * regalare dei gettoni, no? Altrimenti togliamo proprio la scheda da dentro
     * la pagina "Scheda" dell'iscritto»*.
     *
     * ⛔ La sezione «Gettoni» mostrava i numeri **e diceva** *«glieli puoi
     * regalare»*, con il tasto per farlo nascosto a chi non amministra una
     * palestra. 🚨 Una pagina che descrive un'azione che non offre è peggio
     * di una pagina che tace.
     */
    #[Test]
    public function un_indipendente_puo_regalare_gettoni_al_suo_allievo(): void
    {
        $altrove = $this->creaPalestra('Altrove', 'altrove');
        $libero = $this->conPianoDaTrainer($this->unIndipendente());
        $allievo = $this->unAllievoAltrove($libero, $altrove);

        Filament::setCurrentPanel('admin');
        $this->actingAs($libero);
        $this->ctx()->set($libero->tenant);

        Livewire::test(ViewMember::class, ['record' => $allievo->getKey()])
            ->assertActionVisible('regala_gettoni');
    }

    /** ⛔ Ma un trainer di palestra no: quei soldi sarebbero i suoi e l'iscritto della palestra. */
    #[Test]
    public function un_trainer_di_palestra_invece_non_regala_gettoni(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');

        /*
         * ⚠️ **Il contesto si mette con `set()`, non dentro `runAs()`.**
         *
         * 🚨 Con la chiusura il montaggio di `ViewMember` falliva con
         * *«Attempt to read property "mountedActions" on null»*: il componente
         * non nasce, e l'asserzione guarda il vuoto. 💡 È il modo che usano
         * già gli altri test della pagina (`DisiscrivereNonCancellaTest`), e
         * qui si segue quello invece di inventarne un terzo.
         */
        Filament::setCurrentPanel('admin');
        $this->actingAs($trainer);
        $this->ctx()->set($palestra);

        Livewire::test(ViewMember::class, ['record' => $iscritto->getKey()])
            ->assertActionHidden('regala_gettoni');
    }

    // ───────────────── 6. l'elenco e la scheda devono dire la stessa cosa ──

    /**
     * 🚨 **Ogni riga dell'elenco si deve poter aprire.**
     *
     * ⛔ Il **16/09** il committente ha deciso che un trainer di palestra vede
     * **tutti** gli iscritti, in lettura: *«tutti in lettura, ma le schede e i
     * piani entrano nel suo profilo personale, non può assegnarli dal server»*.
     * `ScopedToTrainer` è stato allargato, **`UserPolicy::view()` no**.
     *
     * 💡 Risultato: l'elenco mostrava l'iscritto e la scheda lo rifiutava. ⚠️
     * Un elenco che mostra righe che non si possono aprire è peggio di un
     * elenco corto: fa sembrare rotto il pannello, e chi guarda non può farci
     * niente.
     *
     * 🔍 Trovato il 24/09 perché un test non riusciva a **montare** la
     * scheda come trainer di palestra.
     *
     * 🚨 **Il test confronta le due risposte**, e è questo che lo rende
     * utile: guardare solo la policy direbbe «può vedere» senza accorgersi se un
     * giorno l'elenco si restringe, e guardare solo l'elenco direbbe «c'è»
     * senza accorgersi se la policy si chiude. ⛔ Il difetto è **la distanza
     * fra le due**, non una delle due.
     */
    #[Test]
    public function tutto_quello_che_e_in_elenco_si_puo_aprire(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');

        // ⚠️ **Non assegnato a lui**: è il caso che si rompeva.
        $altrui = $this->creaUtente($palestra, UserRole::Member, 'non-suo@olimpo.test');

        $this->actingAs($trainer);

        $this->ctx()->runAs($palestra, function () use ($trainer, $altrui): void {
            $inElenco = MemberResource::getEloquentQuery()
                ->whereKey($altrui->getKey())
                ->exists();

            $this->assertTrue($inElenco, 'Dal 16/09 un trainer vede tutta la palestra in elenco.');

            $this->assertTrue(
                $trainer->can('view', $altrui),
                '🚨 L\'elenco lo mostra e la scheda lo rifiuta: aprendo quella riga si '
                .'prende un errore, e non c\'e\' niente da fare per rimediare.',
            );
        });
    }

    /** ⛔ Ma resta in **lettura**: vedere non è toccare. */
    #[Test]
    public function ma_resta_in_lettura(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');
        $altrui = $this->creaUtente($palestra, UserRole::Member, 'non-suo@olimpo.test');

        $this->actingAs($trainer);

        $this->ctx()->runAs($palestra, function () use ($trainer, $altrui): void {
            $this->assertFalse($trainer->can('update', $altrui));
            $this->assertFalse($trainer->can('delete', $altrui));
        });
    }

    // ───────────────── 7. «e l'import da PDF funziona qui?» ───────────────

    /**
     * 📄 **Il menù dell'import offriva metà degli allievi.**
     *
     * 📌 Il committente, guardando il pannello di una trainer indipendente:
     * *«a questo punto mi sorge il dubbio: sei sicuro che l'import da pdf
     * funzioni qui?»*. ⛔ **Aveva ragione a dubitare.**
     *
     * `PlanImportResource::iscritti()` cercava *«chi ha il ruolo member in
     * questo tenant»* — la strada di una palestra. 🚨 Su una trainer con due
     * allievi ne offriva **uno**: quello nato nel suo spazio. L'altro, che
     * abita altrove, spariva **in silenzio**.
     *
     * ⚠️ Su una schermata che assegna un programma di allenamento, il nome
     * mancante è **la persona che non lo riceve** — e il menù si apre lo
     * stesso, con dentro un nome, quindi non c'è niente che lo segnali.
     *
     * 💡 **Era la seconda copia della stessa regola.** La prima
     * (`MemberResource`) era stata corretta il 23/09; questa no, perché nessuno
     * sapeva che ce n'era una seconda. Ora la domanda si fa in un posto solo,
     * `ChiPossoSeguire`, e **questo test guarda che le due schermate diano la
     * stessa risposta** — non che una delle due sia giusta.
     */
    #[Test]
    public function limport_offre_gli_stessi_allievi_dell_elenco(): void
    {
        $altrove = $this->creaPalestra('Altrove', 'altrove');
        $libero = $this->conPianoDaTrainer($this->unIndipendente());

        $fuori = $this->unAllievoAltrove($libero, $altrove);
        $dentro = $this->creaUtente($libero->tenant, UserRole::Member, 'in-casa@esempio.test');

        $dentro->assignedTrainers()->attach($libero->getKey(), [
            'tenant_id' => $libero->tenant_id,
            'assigned_at' => now(),
            'assigned_by' => $libero->getKey(),
        ]);

        $this->actingAs($libero);

        [$inElenco, $nelMenu] = $this->ctx()->runAs($libero->tenant, fn (): array => [
            MemberResource::getEloquentQuery()->pluck('id')->sort()->values()->all(),
            array_values(array_map('intval', array_keys(PlanImportResource::iscritti()))),
        ]);

        sort($nelMenu);

        $this->assertContains(
            $fuori->getKey(),
            $nelMenu,
            '🚨 L\'allievo che abita altrove non si può scegliere nell\'import: '
            .'e il menù si apre lo stesso, quindi nessuno se ne accorge.',
        );

        $this->assertSame(
            $inElenco,
            $nelMenu,
            'L\'elenco degli iscritti e il menù dell\'import dicono due cose diverse: '
            .'una delle due schermate sta mentendo, e non si sa quale.',
        );
    }

    // ───────────────── 4. il cruscotto ─────────────────

    /**
     * I numeri in cima al pannello, come `etichetta => valore`.
     *
     * ⚠️ Si chiama `getStats()` per riflessione invece di leggere l'HTML: un
     * `assertSee('2')` passerebbe anche se il «2» fosse la data di ieri.
     *
     * @return array<string, string>
     */
    private function iNumeriDelCruscotto(User $chi, Tenant $dove): array
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($chi);
        $this->ctx()->set($dove);

        $metodo = new \ReflectionMethod(GymOverview::class, 'getStats');
        $metodo->setAccessible(true);

        $numeri = [];

        foreach ($metodo->invoke(new GymOverview) as $stat) {
            $numeri[(string) $stat->getLabel()] = (string) $stat->getValue();
        }

        return $numeri;
    }

    /**
     * Le **note** sotto i numeri, che sono il posto dove si scrivono le regole
     * in italiano — e quindi dove si sbagliano.
     *
     * @return list<string>
     */
    private function leNoteDelCruscotto(User $chi, Tenant $dove): array
    {
        Filament::setCurrentPanel('admin');
        $this->actingAs($chi);
        $this->ctx()->set($dove);

        $metodo = new \ReflectionMethod(GymOverview::class, 'getStats');
        $metodo->setAccessible(true);

        return array_values(array_filter(array_map(
            static fn ($stat): string => (string) $stat->getDescription(),
            $metodo->invoke(new GymOverview),
        )));
    }

    /** Un allievo del trainer che vive **nello spazio del trainer**. */
    private function unAllievoInCasa(User $trainer, string $email = 'in-casa@esempio.test'): User
    {
        $allievo = $this->creaUtente($trainer->tenant, UserRole::Member, $email);

        $allievo->assignedTrainers()->attach($trainer->getKey(), [
            'tenant_id' => $trainer->tenant_id,
            'assigned_at' => now(),
            'assigned_by' => $trainer->getKey(),
        ]);

        return $allievo;
    }

    /**
     * 🚨 **Il cruscotto contava metà degli allievi.**
     *
     * 📌 Il committente, il 24/09: *«Nel pannello di giulia trainer mi dice
     * iscritti attivi 1. Qui credo proprio sia sbagliato, perché lei segue due
     * allievi, anche se uno è di una palestra non vedo perché non dovrebbe
     * essere conteggiato visto che la paga»*. 💡 E la regola, ripetuta: *«se un
     * allievo di una palestra si iscrive a un free trainer che non lavora in
     * quella palestra, allora il free trainer paga normalmente il suo spazio»*.
     *
     * ⛔ **Era la terza copia della stessa regola** — dopo l'elenco iscritti
     * (23/09) e la tendina dell'import (24/09): *«chi ha il ruolo member in
     * questo tenant»*. 🚨 E qui faceva il danno peggiore delle tre, perché un
     * numero solo non si può confrontare con niente: un elenco a cui manca un
     * nome si può notare, un «1» no.
     */
    #[Test]
    public function il_cruscotto_dell_indipendente_conta_anche_chi_abita_altrove(): void
    {
        $altrove = $this->creaPalestra('Altrove', 'altrove');
        $libero = $this->conPianoDaTrainer($this->unIndipendente());

        $this->unAllievoAltrove($libero, $altrove);
        $this->unAllievoInCasa($libero);

        $numeri = $this->iNumeriDelCruscotto($libero, $libero->tenant);

        $this->assertSame(
            '2',
            $numeri['I tuoi allievi'] ?? null,
            '🚨 Il cruscotto conta solo gli allievi che abitano nello spazio del trainer: '
            .'l\'altro lo paga e non lo vede, e il numero non dà nessun segno di essere monco.',
        );
    }

    /**
     * 🆕 **Il cruscotto conta lo spazio in cui sei, e basta.**
     *
     * 📌 Il committente, il 24/09: *«se l'hai organizzato a spazi, tanto vale
     * che nello spazio della palestra in dashboard si vedano solo gli allievi
     * della palestra. Idem per giulia»*.
     *
     * 🚨 Il caso forte è il **doppio cappello**: chi ha allievi suoi **e**
     * lavora in una palestra. ⛔ Dentro la palestra i suoi non si contano —
     * stanno nel suo spazio, e lì l'elenco «Iscritti» non li mostra nemmeno.
     */
    #[Test]
    public function nella_palestra_il_cruscotto_conta_gli_iscritti_della_palestra(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $coach = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');

        $this->creaUtente($palestra, UserRole::Member, 'uno@olimpo.test');
        $this->creaUtente($palestra, UserRole::Member, 'due@olimpo.test');

        // 🚨 E un allievo **suo**, che abita altrove: dentro la palestra non
        //    deve entrare nel conto, né togliere niente.
        $altrove = $this->creaPalestra('Altrove', 'altrove');
        $suo = $this->creaUtente($altrove, UserRole::Member, 'suo@altrove.test');

        $suo->assignedTrainers()->attach($coach->getKey(), [
            'tenant_id' => $coach->tenant_id,
            'assigned_at' => now(),
            'assigned_by' => $coach->getKey(),
        ]);

        $numeri = $this->iNumeriDelCruscotto(
            User::withoutGlobalScopes()->findOrFail($coach->getKey()),
            $palestra,
        );

        $this->assertSame(
            '2',
            $numeri['Iscritti attivi'] ?? null,
            '🚨 Nella palestra il cruscotto non conta gli iscritti della palestra.',
        );

        $this->assertCount(
            1,
            $numeri,
            '⛔ C\'è una seconda card che parla di un altro spazio: il pannello prende la forma '
            .'dello spazio in cui sei, e un numero che viene da fuori non si può seguire.',
        );
    }

    /**
     * ⛔ **E a schermo non compare mai la parola «assegnati».**
     *
     * 🚨 Questo test non guarda un numero: guarda **le parole**. 📌 La regola
     * del committente — *«I TRAINER DELLA PALESTRA NON HANNO ALLIEVI
     * ASSEGNATI, SEGUONO TUTTI»* — è stata violata tre volte, e l'ultima volta
     * nel posto peggiore: nel **testo** della card.
     *
     * ⚠️ Un test sui numeri non l'avrebbe vista: i numeri erano giusti.
     */
    #[Test]
    public function nel_cruscotto_non_si_parla_mai_di_allievi_assegnati(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $coach = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');
        $this->creaUtente($palestra, UserRole::Member, 'uno@olimpo.test');

        $scritte = implode(' | ', array_merge(
            array_keys($this->iNumeriDelCruscotto($coach, $palestra)),
            $this->leNoteDelCruscotto($coach, $palestra),
        ));

        $this->assertStringNotContainsStringIgnoringCase(
            'assegnat',
            $scritte,
            '⛔ Il cruscotto scrive «assegnati» a un trainer di palestra: gli iscritti della '
            ."palestra li segue tutti, e quel testo gli fa cercare una schermata che non esiste.\n"
            .'A schermo c\'è: '.$scritte,
        );
    }

    /**
     * 🆕 **E lo stesso trainer, a casa sua, conta i SUOI — ovunque abitino.**
     *
     * 🚨 È il rovescio del test qui sopra, e sono **due spazi della stessa
     * persona**: dentro la palestra i suoi non si vedono, nel suo spazio ci sono
     * tutti — anche quelli che abitano in una palestra.
     *
     * ⛔ Il conto non si incrocia con nessuna palestra: incrociando, l'allievo
     * suo che abita altrove sparirebbe **in silenzio**.
     */
    #[Test]
    public function nel_suo_spazio_lo_stesso_trainer_conta_i_suoi(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $coach = $this->conPianoDaTrainer($this->unIndipendente('doppio@esempio.test'));

        // Il secondo cappello: trainer **dentro** la palestra, restando a casa sua.
        DB::table('model_has_roles')->updateOrInsert([
            'role_id' => DB::table('roles')
                ->where('name', UserRole::Trainer->value)
                ->where('tenant_id', $palestra->getKey())
                ->value('id'),
            'model_type' => User::class,
            'model_id' => $coach->getKey(),
            'tenant_id' => $palestra->getKey(),
        ], []);

        $this->unAllievoInCasa($coach, 'in-casa@doppio.test');
        $this->unAllievoAltrove($coach, $palestra);

        $fresco = User::withoutGlobalScopes()->findOrFail($coach->getKey());

        $this->assertSame(
            '2',
            $this->iNumeriDelCruscotto($fresco, $fresco->tenant)['I tuoi allievi'] ?? null,
            '🚨 Nel suo spazio manca l\'allievo che abita in una palestra: è suo e lo paga lui.',
        );
    }

    /**
     * 💶 **I conti dello spazio sono di chi lo paga.**
     *
     * 📌 Il committente: *«su quella di giulia ci devono essere le stesse cards
     * che riguardano i guadagni e le spese che avrebbe una palestra (di base è
     * solo una palestra con un solo trainer)»*.
     *
     * ⛔ **E restano chiusi al trainer dipendente**, che è la ragione per cui
     * erano chiusi a tutti: là dentro c'è il margine del suo datore di lavoro su
     * ogni cliente che segue.
     */
    #[Test]
    public function i_conti_li_vede_chi_paga_lo_spazio_non_chi_ci_lavora(): void
    {
        $libero = $this->conPianoDaTrainer($this->unIndipendente());

        $this->actingAs($libero);
        $this->ctx()->set($libero->tenant);

        $this->assertTrue(IPostiDellaPalestra::canView(), 'Il trainer indipendente non vede i conti del proprio spazio.');
        $this->assertTrue(IlGiroDellaPalestra::canView(), 'Il trainer indipendente non vede spesa e guadagno del proprio spazio.');

        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $coach = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');

        $this->actingAs($coach);
        $this->ctx()->set($palestra);

        $this->assertFalse(IPostiDellaPalestra::canView(), '🚨 Un trainer dipendente si vede il margine della palestra su ogni cliente.');
        $this->assertFalse(IlGiroDellaPalestra::canView(), '🚨 Un trainer dipendente si vede spesa e guadagno del suo datore di lavoro.');
    }

    /**
     * 🚨 **Il tasto d'invito dentro la card dei posti era quello della palestra.**
     *
     * ⛔ `InvitiInPalestra::invita()` rifiuta uno spazio personale — *«Solo una
     * palestra può invitare»* — ed è **lo stesso errore** che il committente ha
     * trovato il 24/09 nella pagina degli iscritti. 💡 Aperto il cruscotto al
     * trainer indipendente, quel messaggio sarebbe uscito da un secondo posto.
     */
    #[Test]
    public function nel_cruscotto_l_indipendente_trova_il_suo_tasto_d_invito(): void
    {
        $libero = $this->conPianoDaTrainer($this->unIndipendente());

        Filament::setCurrentPanel('admin');
        $this->actingAs($libero);
        $this->ctx()->set($libero->tenant);

        $this->assertSame(
            'Invita un allievo',
            (string) (new IPostiDellaPalestra)->invitaIscrittoAction()->getLabel(),
            '🚨 Il tasto è quello della palestra: cliccandolo esce «Solo una palestra può invitare».',
        );
    }

    // ───────────────── 5. lo slot da decidere ─────────────────

    /**
     * 🎟️ **La domanda sullo slot, adesso anche nel pannello.**
     *
     * 📌 Il committente, il 24/09: *«Ok, metti la modale anche sul pannello»*.
     *
     * ⛔ **Prima esisteva solo nell'app** (`TrainerController::slotDaDecidere`):
     * un trainer che lavora dal pannello non l'avrebbe vista mai, la decisione
     * restava appesa e lui **continuava a pagare** un posto che forse non
     * voleva.
     *
     * 💡 Il caso: il trainer viene assunto in una palestra dove sta già un suo
     * allievo. Lo fa nascere `AllenareInUnaPalestra::assumi()`, che è la strada
     * vera — qui non si scrive nessuna riga a mano, o si proverebbe solo che so
     * scrivere una `update`.
     */
    #[Test]
    public function la_domanda_sullo_slot_si_vede_anche_dal_pannello(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $coach = $this->conPianoDaTrainer($this->unIndipendente());

        // Un suo allievo che è **iscritto alla palestra** dove sta per entrare.
        $allievo = $this->unAllievoAltrove($coach, $palestra);

        $fresco = User::withoutGlobalScopes()->findOrFail($coach->getKey());

        $this->assertSame(
            1,
            app(AllenareInUnaPalestra::class)->assumi($fresco, $palestra),
            'La sovrapposizione non è nata: il test proverebbe il vuoto.',
        );

        Filament::setCurrentPanel('admin');
        $this->actingAs($fresco);
        $this->ctx()->set($fresco->tenant);

        $this->assertTrue(
            LoSlotDaDecidere::canView(),
            '🚨 La domanda sullo slot non compare nel pannello: resta appesa e intanto lui paga.',
        );

        /*
         * 🚨 `assertActionMounted()` **prima**: prova che la modale si apre **da
         * sola**, come nell'app. ⛔ Senza, il test passerebbe anche se la
         * domanda ci fosse solo dietro un pulsante da cercare — che è il
         * difetto da cui nasce tutto questo.
         */
        Livewire::test(LoSlotDaDecidere::class)
            ->assertActionMounted('decidiLoSlot')

            /*
             * ⚠️ **E la card sotto c'è comunque.** 🚨 Una modale chiusa per
             * sbaglio porterebbe via l'unico modo di rispondere, e la domanda
             * tornerebbe invisibile com'era prima: è il difetto da cui nasce
             * tutto questo widget.
             */
            ->assertSee($allievo->name)
            ->assertSee('Decidi')
            ->callMountedAction(['tengo' => true])
            ->assertHasNoActionErrors();

        $this->assertDatabaseHas('trainer_member', [
            'trainer_id' => $fresco->getKey(),
            'member_id' => $allievo->getKey(),
            'disattivato_il' => null,
        ]);

        $this->assertFalse(
            app(LoSlotDelTrainer::class)->daDecidere($fresco)->isNotEmpty(),
            'Dopo la risposta la domanda si ripresenta: verrebbe chiesta all\'infinito.',
        );
    }

    /**
     * ⛔ **E liberare il posto chiude il rapporto**, che è l'altra metà.
     *
     * 🚨 Le due risposte non sono «ok» e «annulla»: una costa soldi, l'altra
     * costa un allievo. 💡 Per questo il test le prova tutte e due — e guarda
     * `disattivato_il`, cioè la conseguenza, non il fatto che il pulsante
     * esista.
     */
    #[Test]
    public function liberare_il_posto_chiude_il_rapporto(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $coach = $this->conPianoDaTrainer($this->unIndipendente());
        $allievo = $this->unAllievoAltrove($coach, $palestra);

        $fresco = User::withoutGlobalScopes()->findOrFail($coach->getKey());
        app(AllenareInUnaPalestra::class)->assumi($fresco, $palestra);

        Filament::setCurrentPanel('admin');
        $this->actingAs($fresco);
        $this->ctx()->set($fresco->tenant);

        /*
         * ⚠️ `['tengo' => false]` è **esattamente** quello che manda il pulsante
         * rosso: `makeModalSubmitAction('liberoIlPosto', ['tengo' => false])`.
         * 💡 Il test percorre la stessa strada, non una scorciatoia.
         */
        Livewire::test(LoSlotDaDecidere::class)
            ->assertActionMounted('decidiLoSlot')
            ->callMountedAction(['tengo' => false])
            ->assertHasNoActionErrors();

        $this->assertNotNull(
            DB::table('trainer_member')
                ->where('trainer_id', $fresco->getKey())
                ->where('member_id', $allievo->getKey())
                ->value('disattivato_il'),
            '🚨 Ha liberato il posto ma il rapporto è ancora aperto: continuerebbe a pagarlo.',
        );
    }

    /** ⛔ E a chi non ha niente da decidere il riquadro non si mostra. */
    #[Test]
    public function senza_sovrapposizioni_il_riquadro_non_c_e(): void
    {
        $libero = $this->conPianoDaTrainer($this->unIndipendente());
        $this->unAllievoInCasa($libero);

        Filament::setCurrentPanel('admin');
        $this->actingAs($libero);
        $this->ctx()->set($libero->tenant);

        $this->assertFalse(LoSlotDaDecidere::canView());
    }

    /**
     * 💶 **Il divisore del «ti costa per ogni allievo» è lo stesso del piano.**
     *
     * ⚠️ Se qui ne contasse uno e il piano due, il riquadro direbbe il **doppio**
     * del costo reale per allievo — e sarebbe un numero plausibile, quindi
     * nessuno lo verificherebbe.
     */
    #[Test]
    public function i_conti_dividono_per_gli_allievi_veri(): void
    {
        $altrove = $this->creaPalestra('Altrove', 'altrove');
        $libero = $this->conPianoDaTrainer($this->unIndipendente());

        $this->unAllievoAltrove($libero, $altrove);
        $this->unAllievoInCasa($libero);

        $fresco = User::withoutGlobalScopes()->findOrFail($libero->getKey());

        $this->assertSame(
            2,
            app(IContiDellaPalestra::class)->quantiAllievi($libero->tenant),
            'Il conto dei soldi vede un allievo solo: il costo per allievo uscirebbe doppio.',
        );

        $this->assertSame(
            2,
            app(InvitiDelTrainer::class)->quantiAllieviHa($fresco),
            'Il conto dei posti e quello dei conti devono dare lo stesso numero.',
        );
    }
}
