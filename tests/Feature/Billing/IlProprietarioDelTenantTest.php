<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\TenantKind;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\EsciDaUnaPalestra;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Chi paga per questo tenant -- 3b-AH, Passo 0, 12/09/2026.
 *
 * -- COSA DIFENDE QUESTO FILE --
 *
 * Una domanda sola: "questo spazio e' tuo?". Prima se ne facevano due, e si
 * deducevano da cose diverse -- il tipo del tenant per i personali, il ruolo
 * `gym_admin` per le palestre.
 *
 * Funzionava finche' in un tenant personale ci stava una persona sola:
 * "personale" voleva dire "tuo". Da AH.1 in poi nel tenant di un trainer ci
 * stanno anche i suoi allievi, e quella deduzione diventa "ogni allievo puo'
 * disdire l'abbonamento del suo trainer".
 *
 * -- IL TEST CHE CONTA DAVVERO --
 *
 * `il_proprietario_resta_tale_anche_stando_in_un_altro_tenant`. E' l'unico che
 * fallisce se qualcuno toglie `withoutGlobalScopes` dalla relazione
 * `Tenant::proprietario()`, ed e' il caso per cui la colonna e' stata aggiunta:
 * un trainer che lavora in una palestra e possiede il tenant dei suoi clienti.
 */
final class IlProprietarioDelTenantTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    #[Test]
    public function chi_si_registra_da_solo_e_il_proprietario_del_suo_spazio(): void
    {
        $tizio = app(CreaTenantPersonale::class)('Tizio', 'tizio@test.it', [
            'password' => TestCase::FAKE_PASSWORD,
        ]);

        $suo = Tenant::query()->findOrFail($tizio->tenant_id);

        self::assertSame((int) $tizio->getKey(), (int) $suo->proprietario_id);
        self::assertTrue($suo->eDi($tizio));
        self::assertTrue(Cassa::loPuoGestire($tizio));
    }

    #[Test]
    public function l_amministratore_e_il_proprietario_della_palestra(): void
    {
        $palestra = $this->creaPalestra();
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');

        self::assertTrue($palestra->fresh()->eDi($capo));
        self::assertTrue(Cassa::loPuoGestire($capo));
    }

    #[Test]
    public function un_iscritto_non_e_il_proprietario_di_niente(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');

        $iscritto = $this->creaUtente($palestra->fresh(), UserRole::Member, 'iscritto@test.it');

        self::assertFalse($palestra->fresh()->eDi($iscritto));
        self::assertFalse(Cassa::loPuoGestire($iscritto));
    }

    /**
     * Un tenant senza proprietario non e' di NESSUNO.
     *
     * Il caso che questo test chiude e' una riga sola: se `eDi()` fosse scritto
     * come `$this->proprietario_id === $utente?->getKey()`, due `null` si
     * uguaglierebbero -- "il tenant senza proprietario appartiene all'utente
     * senza account" -- e sarebbe un cancello aperto per distrazione, dentro un
     * metodo che decide chi puo' disdire.
     */
    #[Test]
    public function un_tenant_senza_proprietario_non_e_di_nessuno(): void
    {
        $palestra = $this->creaPalestra();
        $orfano = $this->creaUtente($palestra, UserRole::Member, 'orfano@test.it');

        $palestra->forceFill(['proprietario_id' => null])->save();

        self::assertFalse($palestra->fresh()->eDi($orfano));
        self::assertFalse($palestra->fresh()->eDi(null));
    }

    /**
     * IL TEST PER CUI LA COLONNA ESISTE.
     *
     * Un trainer che lavora dentro una palestra (`users.tenant_id` = la
     * palestra) e possiede lo spazio dove vivono i suoi clienti. Il
     * proprietario del suo tenant NON ci sta dentro: nessuna deduzione dal
     * tipo o dai ruoli puo' arrivarci, solo una colonna che lo dice.
     */
    #[Test]
    public function il_proprietario_resta_tale_anche_stando_in_un_altro_tenant(): void
    {
        $palestra = $this->creaPalestra();
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'coach@test.it');

        $portfolio = $this->creaPalestra('Portfolio', 'portfolio', 'PORT2345');
        $portfolio->forceFill([
            'kind' => TenantKind::Personal,
            'proprietario_id' => $trainer->getKey(),
        ])->save();

        /*
         * Letto DENTRO il contesto della palestra, che e' la situazione vera:
         * il trainer sta lavorando li'. Senza `withoutGlobalScopes` sulla
         * relazione, `proprietario` risponderebbe `null` e questo test
         * fallirebbe -- che e' esattamente cio' che deve fare.
         */
        $letto = $this->ctx()->runAs($palestra, fn (): Tenant => Tenant::query()->findOrFail($portfolio->getKey()));

        self::assertTrue($letto->eDi($trainer));
        self::assertSame((int) $trainer->getKey(), (int) $letto->proprietario->getKey());
    }

    /**
     * La colonna non si scrive per assegnazione di massa.
     *
     * Una `update()` con dei dati che arrivano da fuori non deve poter cambiare
     * chi paga. `proprietario_id` sta fuori dal `$fillable` apposta, e questo
     * test e' il solo modo per accorgersi che qualcuno ce l'ha rimesso.
     */
    #[Test]
    public function la_proprieta_non_si_cambia_per_assegnazione_di_massa(): void
    {
        $palestra = $this->creaPalestra();
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');
        $furbo = $this->creaUtente($palestra->fresh(), UserRole::Member, 'furbo@test.it');

        $palestra->fresh()->update(['proprietario_id' => $furbo->getKey()]);

        self::assertSame((int) $capo->getKey(), (int) $palestra->fresh()->proprietario_id);
    }

    #[Test]
    public function il_comando_tace_se_ogni_tenant_ha_un_proprietario(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');

        $this->artisan('abbonamenti:tenant-senza-proprietario')->assertExitCode(0);
    }

    /**
     * Un tenant senza proprietario e senza abbonamento e' sopportabile; uno che
     * STA PAGANDO e' un guasto, perche' e' una persona che non puo' piu'
     * gestire cio' che paga.
     */
    #[Test]
    public function il_comando_fallisce_se_chi_paga_non_ha_un_proprietario(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');

        $palestra->forceFill(['proprietario_id' => null])->save();

        $this->artisan('abbonamenti:tenant-senza-proprietario')->assertExitCode(1);
    }

    #[Test]
    public function ma_un_tenant_orfano_che_non_paga_niente_non_e_un_guasto(): void
    {
        $palestra = $this->creaPalestra();

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->delete();

        self::assertNull($palestra->fresh()->proprietario_id);

        $this->artisan('abbonamenti:tenant-senza-proprietario')->assertExitCode(0);
    }

    /**
     * Cancellare l'account del proprietario NON porta via il tenant con dentro
     * i dati di tutti gli altri.
     *
     * La chiave esterna e' `nullOnDelete` e non `cascadeOnDelete`: un `cascade`
     * qui sarebbe una cancellazione di massa a una `DELETE` di distanza, e non
     * darebbe nessun errore.
     */
    #[Test]
    public function cancellare_il_proprietario_non_cancella_il_tenant(): void
    {
        $palestra = $this->creaPalestra();
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');

        User::withoutGlobalScopes()->where('id', $capo->getKey())->forceDelete();

        $rimasto = Tenant::query()->find($palestra->getKey());

        self::assertNotNull($rimasto, 'il tenant e\' sparito insieme al suo proprietario');
        self::assertNull($rimasto->proprietario_id);
    }

    #[Test]
    public function chi_esce_da_una_palestra_possiede_lo_spazio_nuovo(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');

        $iscritto = $this->creaUtente($palestra->fresh(), UserRole::Member, 'iscritto@test.it');

        $personale = app(EsciDaUnaPalestra::class)($iscritto);

        self::assertTrue($personale->fresh()->eDi($iscritto->fresh()));
        self::assertTrue(Cassa::loPuoGestire($iscritto->fresh()));
    }

    /** Il super admin non ha tenant, quindi non possiede niente e non gestisce niente. */
    #[Test]
    public function il_super_admin_non_gestisce_nessun_abbonamento(): void
    {
        $god = $this->creaSuperAdmin();

        self::assertFalse(Cassa::loPuoGestire($god));
        self::assertFalse(Cassa::loPuoGestire(null));
    }

    /** Il piano gratuito non basta a far fallire il comando: non e' "chi paga". */
    #[Test]
    public function un_orfano_sul_piano_gratuito_non_fa_fallire_il_comando(): void
    {
        $palestra = $this->creaPalestra();

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->delete();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->getKey(),
            'plan_id' => Plan::query()->where('code', Plan::FREE)->firstOrFail()->getKey(),
            'starts_at' => now()->subYear(),
        ]);

        $this->artisan('abbonamenti:tenant-senza-proprietario')->assertExitCode(0);
    }
}
