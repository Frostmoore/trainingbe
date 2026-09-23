<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Filament\Gym\Pages\CambiaSpazio;
use App\Filament\Gym\Resources\Members\MemberResource;
use App\Http\Middleware\SpazioDelPannello;
use App\Http\Responses\RoleAwareLoginResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\ChiSiVede;
use App\Services\Tenancy\CreaTenantPersonale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🎩 I due cappelli nel pannello — 3b-AH.1-quater.8, 16/09/2026.
 *
 * ══ 📌 IL CASO ════════════════════════════════════════════════════════════
 *
 * Una persona può avere un **giro di allievi suo** e il ruolo di `trainer`
 * **dentro una palestra**. Sono indipendenti dal 12/09 (AH.1-quater.4).
 *
 * ══ ⛔ COSA SUCCEDEVA PRIMA ═══════════════════════════════════════════════
 *
 * `hasAppRole()` valutava **sempre** nel tenant della persona — cioè nel suo
 * spazio — quindi nella palestra non risultava un trainer. 🚨 Il pannello si
 * apriva (è pur sempre un `free_trainer`) e mostrava **una stanza vuota**: ogni
 * scoping lo trattava come «nessuno dei due», col suo ramo `1 = 0`.
 *
 * 💡 Nessun errore, nessun log: una porta che si apre sul niente.
 */
class DueCappelliNelPannelloTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /** Gli dà il ruolo di trainer **dentro** la palestra, lasciandolo dove sta. */
    private function allenaAnchePresso(User $chi, Tenant $palestra): void
    {
        DB::table('model_has_roles')->updateOrInsert([
            'role_id' => DB::table('roles')->where('name', UserRole::Trainer->value)->value('id'),
            'model_type' => User::class,
            'model_id' => $chi->getKey(),
            'tenant_id' => $palestra->getKey(),
        ], []);
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

    // ─────────────── 🎩 Il ruolo si legge dove si sta lavorando ───────────────

    /**
     * 🚨 **La stessa domanda, due risposte — ed è giusto così.**
     *
     * ⚠️ Prima `isTrainer()` rispondeva `false` ovunque, perché guardava sempre
     * lo spazio della persona.
     */
    #[Test]
    public function il_ruolo_dipende_dallo_spazio_in_cui_si_lavora(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();

        $this->allenaAnchePresso($trainer, $palestra);

        // 🏠 A casa sua è un trainer indipendente, non staff di nessuno.
        $this->ctx()->runAs($trainer->tenant, function () use ($trainer): void {
            $fresco = User::withoutGlobalScopes()->findOrFail($trainer->getKey());

            $this->assertTrue($fresco->isFreeTrainer(), 'Nel suo spazio non risulta un trainer indipendente.');
            $this->assertFalse($fresco->isTrainer(), 'Nel suo spazio risulta staff di una palestra.');
        });

        // 🏋️ In palestra è staff.
        $this->ctx()->runAs($palestra, function () use ($trainer): void {
            $fresco = User::withoutGlobalScopes()->findOrFail($trainer->getKey());

            $this->assertTrue($fresco->isTrainer(), 'Nella palestra dove allena non risulta un suo trainer.');
        });
    }

    /**
     * ⚖️ **E per chi ha un cappello solo non cambia niente.**
     *
     * 🚨 È la meta' che conta: la modifica tocca **solo** chi lavora in due
     * posti. Un gestore resta un gestore anche guardato da un altro spazio,
     * perche' il ruolo ce l'ha li' e basta.
     */
    #[Test]
    public function chi_ha_un_cappello_solo_risponde_come_prima(): void
    {
        $olimpo = $this->creaPalestra('Olimpo', 'olimpo');
        $zeus = $this->creaPalestra('Zeus', 'zeus');

        $capo = $this->creaUtente($olimpo, UserRole::GymAdmin, 'capo@olimpo.test');

        $this->ctx()->runAs($zeus, function () use ($capo): void {
            $fresco = User::withoutGlobalScopes()->findOrFail($capo->getKey());

            $this->assertFalse(
                $fresco->isGymAdmin(),
                'Il gestore di una palestra risulta gestore anche di un altra.',
            );
        });

        // E senza nessun contesto si ricade sul suo spazio, come prima.
        $this->ctx()->runWithoutTenant(function () use ($capo): void {
            $fresco = User::withoutGlobalScopes()->findOrFail($capo->getKey());

            $this->assertTrue($fresco->isGymAdmin(), 'Senza contesto il gestore non e piu tale.');
        });
    }

    // ─────────────── 🚪 La porta del pannello ───────────────

    /**
     * 🚨 **Il difetto vero: la stanza vuota.**
     *
     * Nel proprio spazio non ha iscritti; nella palestra li ha tutti. Prima
     * della correzione il secondo elenco era vuoto quanto il primo.
     */
    #[Test]
    public function nel_pannello_della_palestra_vede_gli_iscritti(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();

        $uno = $this->creaUtente($palestra, UserRole::Member, 'uno@olimpo.test');
        $due = $this->creaUtente($palestra, UserRole::Member, 'due@olimpo.test');

        $this->allenaAnchePresso($trainer, $palestra);

        $this->actingAs($trainer);

        // 🏠 Nel suo spazio non c'è nessun iscritto di palestra: giusto.
        $this->ctx()->runAs($trainer->tenant, function (): void {
            $this->assertSame([], MemberResource::getEloquentQuery()->pluck('id')->all());
        });

        // 🏋️ Nella palestra ci sono tutti e due.
        $this->ctx()->runAs($palestra, function () use ($uno, $due): void {
            $this->assertEqualsCanonicalizing(
                [$uno->getKey(), $due->getKey()],
                MemberResource::getEloquentQuery()->pluck('id')->all(),
                'Nel pannello della palestra il trainer non vede gli iscritti.',
            );
        });
    }

    /**
     * 📌 Il committente: *«tutti in lettura»*.
     *
     * 🚨 Anche un trainer **normale** della palestra li vede tutti adesso, non
     * solo gli assegnati: era la regola B3.5, ed e' stata cambiata apposta
     * perche' nell'app quello stesso trainer li vedeva gia' tutti.
     */
    #[Test]
    public function un_trainer_della_palestra_li_vede_tutti_non_solo_gli_assegnati(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');

        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');
        $uno = $this->creaUtente($palestra, UserRole::Member, 'uno@olimpo.test');
        $due = $this->creaUtente($palestra, UserRole::Member, 'due@olimpo.test');

        $this->actingAs($trainer);

        $this->ctx()->runAs($palestra, function () use ($uno, $due): void {
            $this->assertEqualsCanonicalizing(
                [$uno->getKey(), $due->getKey()],
                MemberResource::getEloquentQuery()->pluck('id')->all(),
                'Senza assegnazioni il trainer non vede nessuno.',
            );
        });
    }

    /**
     * ⚖️ **Ma non li tocca.** La lettura si e' allargata, il resto no.
     *
     * 🚨 E' la meta' che rende accettabile l'allargamento: quello che B3.5
     * proteggeva era il **poterci fare qualcosa**, e quello resta del gestore.
     */
    #[Test]
    public function ma_non_li_puo_modificare_ne_cancellare(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');

        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'uno@olimpo.test');

        $this->ctx()->runAs($palestra, function () use ($trainer, $iscritto): void {
            $chiGuarda = User::withoutGlobalScopes()->findOrFail($trainer->getKey());

            $this->assertFalse(app('Illuminate\Contracts\Auth\Access\Gate')
                ->forUser($chiGuarda)->allows('update', $iscritto), 'Un trainer puo modificare un iscritto.');

            $this->assertFalse(app('Illuminate\Contracts\Auth\Access\Gate')
                ->forUser($chiGuarda)->allows('delete', $iscritto), 'Un trainer puo cancellare un iscritto.');

            $this->assertFalse(app('Illuminate\Contracts\Auth\Access\Gate')
                ->forUser($chiGuarda)->allows('create', User::class), 'Un trainer puo creare un iscritto.');
        });
    }

    // ─────────────── 🔀 Il cambio di spazio ───────────────

    /**
     * 🚨 **La scelta si ricontrolla, non si crede.**
     *
     * ⚠️ Vive in sessione, ma ogni richiesta la verifica contro gli spazi di
     * cui quella persona fa parte davvero: una sessione copiata, o un ruolo
     * revocato dopo la scelta, non aprono niente.
     */
    #[Test]
    public function uno_spazio_che_non_e_suo_non_si_sceglie(): void
    {
        $olimpo = $this->creaPalestra('Olimpo', 'olimpo');
        $zeus = $this->creaPalestra('Zeus', 'zeus');

        $trainer = $this->unTrainerIndipendente();
        $this->allenaAnchePresso($trainer, $olimpo);

        $spazi = app(ChiSiVede::class)->spaziDi($trainer->fresh());

        $this->assertTrue($spazi->contains($olimpo->getKey()), 'La palestra dove allena non e fra i suoi spazi.');
        $this->assertTrue($spazi->contains($trainer->tenant_id), 'Il suo spazio non e fra i suoi spazi.');
        $this->assertFalse($spazi->contains($zeus->getKey()), 'Una palestra estranea e fra i suoi spazi.');
    }

    /**
     * ⚠️ **La pagina si vede solo a chi serve.**
     *
     * 💡 Con uno spazio solo sarebbe una scelta fra una cosa sola. E
     * `canAccess()` chiude anche l'indirizzo digitato a mano, non solo la voce.
     */
    #[Test]
    public function la_pagina_del_cambio_si_vede_solo_con_due_spazi(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $solo = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');

        $this->actingAs($solo);
        $this->ctx()->runAs($palestra, function (): void {
            $this->assertFalse(CambiaSpazio::canAccess(), 'La pagina del cambio si vede a chi ha un solo spazio.');
        });

        $doppio = $this->unTrainerIndipendente();
        $this->allenaAnchePresso($doppio, $palestra);

        $this->actingAs($doppio->fresh());
        $this->ctx()->runAs($doppio->tenant, function (): void {
            $this->assertTrue(CambiaSpazio::canAccess(), 'Chi ha due spazi non trova la pagina per cambiarli.');
        });
    }

    /**
     * 🚪 **Il ruolo revocato sfila la scelta da solo.**
     *
     * ⚠️ Nessuno deve ricordarsi di ripulire la sessione quando una palestra
     * congeda qualcuno: se lo ricordasse una volta su due, quella persona
     * continuerebbe a lavorare in uno spazio che non è più suo.
     */
    #[Test]
    public function chi_perde_il_ruolo_torna_nel_proprio_spazio(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();
        $this->allenaAnchePresso($trainer, $palestra);

        $this->assertSame(
            $palestra->getKey(),
            $this->spazioDopoIlMiddleware($trainer, $palestra->getKey()),
            'La scelta legittima non viene rispettata.',
        );

        // 🚪 La palestra lo congeda.
        DB::table('model_has_roles')
            ->where('model_id', $trainer->getKey())
            ->where('tenant_id', $palestra->getKey())
            ->delete();

        $this->assertSame(
            $trainer->tenant_id,
            $this->spazioDopoIlMiddleware($trainer->fresh(), $palestra->getKey()),
            'Dopo la revoca del ruolo continua a lavorare nella palestra.',
        );
    }

    /**
     * 🚨 **E la palestra sospesa non lo chiude fuori da tutto.**
     *
     * ⚠️ `EnsureTenantActive` gira dopo e sbarra chi ha in mano un tenant
     * inattivo: con la scelta ancora in sessione, quella persona non
     * raggiungerebbe nemmeno la pagina per tornare a casa sua. 💡 Un vicolo
     * cieco da cui si esce solo svuotando i cookie.
     */
    #[Test]
    public function una_palestra_sospesa_non_lo_lascia_chiuso_fuori(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $trainer = $this->unTrainerIndipendente();
        $this->allenaAnchePresso($trainer, $palestra);

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->assertSame(
            $trainer->tenant_id,
            $this->spazioDopoIlMiddleware($trainer->fresh(), $palestra->getKey()),
            'Con la palestra sospesa resta incastrato fuori dal pannello.',
        );
    }

    /**
     * Fa girare il middleware con quella scelta in sessione e dice dove si
     * finisce a lavorare.
     */
    private function spazioDopoIlMiddleware(User $utente, int $scelto): ?int
    {
        $richiesta = Request::create('/admin');
        $richiesta->setLaravelSession($this->app['session']->driver());
        $richiesta->session()->put(SpazioDelPannello::CHIAVE, $scelto);
        $richiesta->setUserResolver(fn (): User => $utente);

        // ⚠️ Il contesto di partenza è quello che mette `ResolveTenant`.
        $this->ctx()->set($utente->tenant);

        app(SpazioDelPannello::class)->handle($richiesta, fn (): Response => new Response);

        return $this->ctx()->id();
    }

    /**
     * 🚪 **E dopo il login ci finisce davvero, nel pannello.**
     *
     * ⛔ `urlFor()` guardava solo `isGymAdmin()` e `isTrainer()`: per un
     * trainer indipendente rispondeva `null`, e `toResponse()` a quel punto lo
     * slogga dicendogli *«gli iscritti accedono dall'app»*. 🚨 Una porta aperta
     * su un corridoio chiuso, con pure il messaggio sbagliato.
     *
     * ⚠️ Colpiva **anche chi ha due cappelli**: al login il contesto è il suo
     * spazio, dove è un `free_trainer`.
     */
    #[Test]
    public function un_trainer_indipendente_dopo_il_login_finisce_nel_pannello(): void
    {
        $solo = $this->unTrainerIndipendente();

        $this->assertNotNull(
            RoleAwareLoginResponse::urlFor($solo),
            'Un trainer indipendente viene rimandato fuori dal pannello che puo aprire.',
        );

        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $this->allenaAnchePresso($solo, $palestra);

        $this->assertNotNull(
            RoleAwareLoginResponse::urlFor($solo->fresh()),
            'Chi ha due cappelli viene rimandato fuori dal pannello.',
        );
    }

    /**
     * 🚨 **E il trainer di PALESTRA, che a casa sua non è niente.**
     *
     * 📌 Il committente, il 24/09, provando `trainer@palestra-demo.test`:
     * *«Non mi fa accedere, carica e mi rimette nella stessa pagina»*.
     *
     * ⛔ **Dal 18/09 (decisione 2) ogni trainer ha una casa sua**, e la casa di
     * un trainer di palestra è uno spazio **personale** dove il ruolo è
     * `free_user`. Al login il contesto è quello di casa, quindi:
     *
     * | Domanda | Risposta a casa sua |
     * |---|---|
     * | `isGymAdmin()` | no |
     * | `isTrainer()` | **no** — è `trainer` nella palestra, non a casa |
     * | `isFreeTrainer()` | no — è `free_user` |
     *
     * 🚨 `urlFor()` rispondeva `null`, `toResponse()` lo sloggava, e **nessun
     * trainer di palestra poteva più entrare nel pannello** — per sei giorni,
     * senza un errore, senza un messaggio, senza una riga nei registri.
     *
     * ⚠️ **È la terza volta che questo metodo sbaglia la stessa domanda**: il
     * 16/09 mancava `isFreeTrainer()` (il test qui sopra) e si aggiunse un
     * terzo predicato. 💡 Il difetto non era il predicato mancante: era
     * **chiedere i ruoli nello spazio di casa** a gente che lavora altrove.
     * `allena()` guarda in **tutti** gli spazi, e per questo il test lo prova
     * proprio nel contesto di casa.
     */
    #[Test]
    public function anche_un_trainer_di_palestra_dopo_il_login_finisce_nel_pannello(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');

        // La forma vera dal 18/09: casa personale + ruolo `trainer` nella palestra.
        $coach = app(CreaTenantPersonale::class)(
            'Tommaso Coach',
            'tommaso@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeUser,
        );

        $this->allenaAnchePresso($coach, $palestra);

        $fresco = User::withoutGlobalScopes()->findOrFail($coach->getKey());

        /*
         * ⚠️ **Nel contesto di casa**, che è dove si trova il login: provarlo
         * dentro la palestra nasconderebbe il difetto, perché lì
         * `isTrainer()` risponde di sì e la domanda sbagliata sembra giusta.
         */
        $dove = $this->ctx()->runAs(
            $fresco->tenant,
            fn (): ?string => RoleAwareLoginResponse::urlFor($fresco),
        );

        $this->assertNotNull(
            $dove,
            '🚨 Un trainer di palestra viene sloggato subito dopo il login: da fuori si vede '
            .'solo «carica e torna indietro», senza nessun errore da nessuna parte.',
        );

        $this->assertStringContainsString('/admin', $dove);
    }

    /** ⚠️ La chiave in sessione è una sola, e la conoscono in due. */
    #[Test]
    public function la_chiave_di_sessione_e_quella_che_legge_il_middleware(): void
    {
        $this->assertSame('spazio_del_pannello', SpazioDelPannello::CHIAVE);
    }
}
