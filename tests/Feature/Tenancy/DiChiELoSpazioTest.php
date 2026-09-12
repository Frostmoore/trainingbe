<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\TenantKind;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\EsciDaUnaPalestra;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Services\Tenancy\UnisciAUnaPalestra;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Di chi e' questo spazio -- 3b-AH, Passo 1, 12/09/2026.
 *
 * -- COSA DIFENDE QUESTO FILE --
 *
 * Tre gesti che spostano persone e dati fra tenant:
 *
 *   UnisciAUnaPalestra   porta le mie righe dentro una palestra
 *   EsciDaUnaPalestra    mi tira fuori da uno spazio di qualcun altro
 *   AccountController::gym   mi dice chi mi copre, e come andarmene
 *
 * Tutti e tre chiedevano "questo tenant e' di tipo personale?". Era la stessa
 * domanda di "e' mio" soltanto finche' in un tenant personale ci stava UNA
 * PERSONA SOLA.
 *
 * -- LA FALLA CHE AH.1 STAVA PER APRIRE --
 *
 * `UnisciAUnaPalestra` sposta TUTTE le righe del tenant di partenza. Quando gli
 * allievi di un trainer vivranno nel tenant del trainer -- che resta
 * `personal` -- un allievo che digita il codice di una palestra si sarebbe
 * portato dietro il tenant INTERO del suo trainer: le schede degli altri
 * allievi, gli esercizi, tutto. Nessun errore, e i dati di venti persone dentro
 * una palestra che non c'entra niente.
 *
 * I test qui sotto costruiscono quella situazione a mano, adesso, prima che
 * AH.1 la renda normale.
 */
final class DiChiELoSpazioTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /** Lo spazio di un trainer: personale, suo, con un allievo dentro. */
    private function spazioDiUnTrainer(): array
    {
        $tenant = $this->creaPalestra('Coach', 'coach', 'COAC2345');
        $tenant->update(['kind' => TenantKind::Personal->value]);

        $trainer = $this->creaUtente($tenant->fresh(), UserRole::FreeTrainer, 'coach@test.it');
        $tenant->forceFill(['proprietario_id' => $trainer->getKey()])->save();

        $allievo = $this->creaUtente($tenant->fresh(), UserRole::Member, 'allievo@test.it');

        return [$tenant->fresh(), $trainer, $allievo];
    }

    /**
     * LA FALLA, provata dal lato dei dati e non del messaggio d'errore.
     *
     * Non basta che la chiamata fallisca: il test guarda che la scheda del
     * trainer sia rimasta dov'era. Un test che verifica solo l'eccezione
     * passerebbe anche se il rifiuto arrivasse DOPO aver spostato le righe.
     */
    #[Test]
    public function un_allievo_non_puo_portare_lo_spazio_del_trainer_dentro_una_palestra(): void
    {
        [$spazio, $trainer, $allievo] = $this->spazioDiUnTrainer();

        $scheda = $this->ctx()->runAs($spazio, fn (): WorkoutPlan => WorkoutPlan::create([
            'tenant_id' => $spazio->getKey(),
            'member_id' => $trainer->getKey(),
            'created_by' => $trainer->getKey(),
            'name' => 'La scheda del trainer',
        ]));

        $palestra = $this->creaPalestra('Altra', 'altra', 'ALTR2345');

        try {
            app(UnisciAUnaPalestra::class)($allievo, $palestra->join_code);
            self::fail('un allievo ha portato il tenant del suo trainer dentro una palestra');
        } catch (ValidationException) {
            // atteso
        }

        self::assertSame(
            (int) $spazio->getKey(),
            (int) $scheda->fresh()->tenant_id,
            'la scheda del trainer e\' finita dentro la palestra',
        );

        self::assertSame((int) $spazio->getKey(), (int) $allievo->fresh()->tenant_id);
    }

    /**
     * Nemmeno il trainer, che pure e' il proprietario: nel suo spazio c'e'
     * gente. Portarlo dentro una palestra non e' una cosa che si fa digitando
     * un codice.
     */
    #[Test]
    public function e_nemmeno_il_trainer_che_ne_e_il_proprietario(): void
    {
        [, $trainer] = $this->spazioDiUnTrainer();

        $palestra = $this->creaPalestra('Altra', 'altra', 'ALTR2345');

        $this->expectException(ValidationException::class);

        app(UnisciAUnaPalestra::class)($trainer, $palestra->join_code);
    }

    /**
     * Lo stesso vale per il capo di una palestra: il suo tenant e' suo, ma ci
     * stanno dentro i suoi iscritti.
     */
    #[Test]
    public function un_gym_admin_non_porta_la_sua_palestra_dentro_un_altra(): void
    {
        $mia = $this->creaPalestra();
        $capo = $this->creaUtente($mia, UserRole::GymAdmin, 'capo@test.it');
        $this->creaUtente($mia->fresh(), UserRole::Member, 'iscritto@test.it');

        $altra = $this->creaPalestra('Altra', 'altra', 'ALTR2345');

        $this->expectException(ValidationException::class);

        app(UnisciAUnaPalestra::class)($capo, $altra->join_code);
    }

    /** Chi e' davvero solo nel suo spazio entra come prima. */
    #[Test]
    public function chi_e_solo_nel_suo_spazio_entra_in_una_palestra(): void
    {
        $tizio = app(CreaTenantPersonale::class)('Tizio', 'tizio@test.it', [
            'password' => TestCase::FAKE_PASSWORD,
        ]);

        $palestra = $this->creaPalestra();

        $entrato = app(UnisciAUnaPalestra::class)($tizio, $palestra->join_code);

        self::assertSame((int) $palestra->getKey(), (int) $entrato->getKey());
        self::assertSame((int) $palestra->getKey(), (int) $tizio->fresh()->tenant_id);
    }

    /**
     * L'ALTRA META': dallo spazio di qualcun altro si esce.
     *
     * Con la domanda vecchia -- "il tenant e' personale?" -- a questa persona
     * l'uscita sarebbe stata negata, e sarebbe rimasta dentro lo spazio di
     * qualcun altro senza nessun modo di andarsene.
     */
    #[Test]
    public function un_allievo_puo_uscire_dallo_spazio_del_suo_trainer(): void
    {
        [$spazio, , $allievo] = $this->spazioDiUnTrainer();

        $suo = app(EsciDaUnaPalestra::class)($allievo);

        self::assertNotSame((int) $spazio->getKey(), (int) $suo->getKey());
        self::assertTrue($suo->fresh()->eDi($allievo->fresh()));
    }

    /**
     * AH.2 -- uscire scioglie il legame, ma NON cancella la riga.
     *
     * Qui c'era una `delete()`, e contraddiceva D5 -- "il legame resta, la
     * storia si conserva, il canale si chiude" -- che
     * `InvitiDelTrainer::disattiva()` applica sulla STESSA tabella. Due regole
     * opposte sulla stessa riga, e la differenza si vedeva solo dallo storico
     * che spariva.
     */
    #[Test]
    public function uscendo_il_rapporto_si_disattiva_e_non_si_cancella(): void
    {
        [$spazio, $trainer, $allievo] = $this->spazioDiUnTrainer();

        $this->ctx()->runWithoutTenant(fn () => DB::table('trainer_member')->insert([
            'tenant_id' => $spazio->getKey(),
            'trainer_id' => $trainer->getKey(),
            'member_id' => $allievo->getKey(),
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        app(EsciDaUnaPalestra::class)($allievo);

        $riga = DB::table('trainer_member')
            ->where('tenant_id', $spazio->getKey())
            ->where('member_id', $allievo->getKey())
            ->first();

        self::assertNotNull($riga, 'la riga era stata cancellata invece che disattivata');
        self::assertNotNull($riga->disattivato_il, 'il rapporto era rimasto in piedi');
    }

    /**
     * E LO SLOT SI LIBERA -- AH.1-ter.
     *
     * E' la meta' che rende sicura la disattivazione: se gli slot contassero
     * anche le righe disattivate, un trainer si ritroverebbe i posti occupati
     * da rapporti finiti per sempre, senza nessun errore. Finche' chi usciva
     * veniva cancellato il conto tornava per caso.
     */
    #[Test]
    public function e_lo_slot_si_libera(): void
    {
        [$spazio, $trainer, $allievo] = $this->spazioDiUnTrainer();

        $this->ctx()->runWithoutTenant(fn () => DB::table('trainer_member')->insert([
            'tenant_id' => $spazio->getKey(),
            'trainer_id' => $trainer->getKey(),
            'member_id' => $allievo->getKey(),
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        /*
         * Un piano con un tetto vero: senza, `postiRimasti()` risponde `null`
         * -- "senza limite" -- e il test non proverebbe niente restando verde.
         */
        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $spazio->getKey())
            ->update(['plan_id' => Plan::query()->where('code', Plan::TRAINER_10)->firstOrFail()->getKey()]);

        $prima = app(InvitiDelTrainer::class)->postiRimasti($trainer->fresh());

        self::assertNotNull($prima, 'il piano di prova deve avere un tetto, o il test non prova niente');

        app(EsciDaUnaPalestra::class)($allievo);

        $dopo = app(InvitiDelTrainer::class)->postiRimasti($trainer->fresh());

        self::assertSame($prima + 1, $dopo, 'lo slot non si era liberato');
    }

    /** Dal proprio spazio non si esce: non c'e' nessuno da cui uscire. */
    #[Test]
    public function dal_proprio_spazio_non_si_esce(): void
    {
        $tizio = app(CreaTenantPersonale::class)('Tizio', 'tizio@test.it', [
            'password' => TestCase::FAKE_PASSWORD,
        ]);

        $this->expectException(ValidationException::class);

        app(EsciDaUnaPalestra::class)($tizio);
    }

    #[Test]
    public function la_scheda_della_palestra_dice_chi_ti_copre(): void
    {
        [$spazio, , $allievo] = $this->spazioDiUnTrainer();

        $this->comeApp($allievo)
            ->getJson('/api/v1/account/gym')
            ->assertOk()
            ->assertJsonPath('data.slug', $spazio->slug);
    }

    /** Al proprietario non si mostra niente: da casa propria non si esce. */
    #[Test]
    public function ma_al_proprietario_non_mostra_niente(): void
    {
        [, $trainer] = $this->spazioDiUnTrainer();

        $this->comeApp($trainer)
            ->getJson('/api/v1/account/gym')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /** E l'iscritto di una palestra continua a vedere la sua, come sempre. */
    #[Test]
    public function l_iscritto_di_una_palestra_vede_la_sua(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');

        $iscritto = $this->creaUtente($palestra->fresh(), UserRole::Member, 'iscritto@test.it');

        $this->comeApp($iscritto)
            ->getJson('/api/v1/account/gym')
            ->assertOk()
            ->assertJsonPath('data.slug', $palestra->slug);
    }

    /**
     * Un tenant senza proprietario non e' di nessuno, quindi non e' nemmeno
     * "mio": chi ci sta dentro puo' uscirne, e non puo' portarselo altrove.
     *
     * La direzione dell'errore conta: un dato mancante deve togliere poteri,
     * non darne.
     */
    #[Test]
    public function un_tenant_orfano_non_diventa_di_chi_ci_sta_dentro(): void
    {
        $palestra = $this->creaPalestra();
        $tizio = $this->creaUtente($palestra, UserRole::Member, 'tizio@test.it');

        $palestra->forceFill(['proprietario_id' => null])->save();

        $altra = $this->creaPalestra('Altra', 'altra', 'ALTR2345');

        try {
            app(UnisciAUnaPalestra::class)($tizio, $altra->join_code);
            self::fail('un tenant senza proprietario si e\' lasciato portare via');
        } catch (ValidationException) {
            // atteso
        }

        self::assertSame((int) $palestra->getKey(), (int) $tizio->fresh()->tenant_id);
    }

    /** E il super admin, che di tenant non ne ha, non entra e non esce. */
    #[Test]
    public function il_super_admin_non_entra_e_non_esce(): void
    {
        $god = $this->creaSuperAdmin();
        $palestra = $this->creaPalestra();

        $rotti = 0;

        foreach ([
            fn (): Tenant => app(UnisciAUnaPalestra::class)($god, $palestra->join_code),
            fn (): Tenant => app(EsciDaUnaPalestra::class)($god),
        ] as $gesto) {
            try {
                $gesto();
            } catch (ValidationException) {
                $rotti++;
            }
        }

        self::assertSame(2, $rotti);
        self::assertNull(User::withoutGlobalScopes()->findOrFail($god->getKey())->tenant_id);
    }
}
