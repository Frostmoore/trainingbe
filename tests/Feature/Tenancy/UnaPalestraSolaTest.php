<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\Exceptions\SeiGiaInUnaPalestraException;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Services\Tenancy\InvitiInPalestra;
use App\Services\Tenancy\UnisciAUnaPalestra;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🏛️ **Una palestra sola, e l'invito a una seconda chiede** — regola 8.1.4,
 * 18/09/2026.
 *
 * > *«Assicurati che un allievo possa essere iscritto a una sola palestra. Nel
 * > caso di invito a una seconda palestra, la pagina dell'invito gli deve
 * > chiedere se desidera lasciare la palestra precedente.»*
 *
 * ══ 🚨 COSA SUCCEDEVA PRIMA ═══════════════════════════════════════════════
 *
 * ⛔ Il secondo ingresso veniva rifiutato **e basta**: *«Sei già iscritto a una
 * palestra. Per cambiarla, parlane con loro.»* — nessuna domanda, nessuna
 * strada, e un invito buono in mano che non portava da nessuna parte.
 */
final class UnaPalestraSolaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /** @return array{0: Tenant, 1: User} la palestra e il suo capo */
    private function palestra(string $nome, string $slug, string $codice): array
    {
        $p = $this->creaPalestra($nome, $slug, $codice);

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $p->getKey())
            ->update(['plan_id' => Plan::query()->where('code', 'gym_50')->firstOrFail()->id]);

        return [$p->fresh(), $this->creaUtente($p, UserRole::GymAdmin, "capo-{$slug}@esempio.test")];
    }

    private function invito(Tenant $palestra, User $capo): string
    {
        return $this->ctx()->runAs(
            $palestra,
            fn () => app(InvitiInPalestra::class)->invita($palestra, $capo),
        )->token;
    }

    /**
     * 🚨 **Il server si ferma e dice quale**, invece di rifiutare e basta.
     *
     * ⚠️ E la persona resta dov'era: una domanda non è un trasloco.
     */
    #[Test]
    public function chi_e_gia_in_una_palestra_riceve_la_domanda(): void
    {
        [$olimpo] = $this->palestra('Olimpo', 'olimpo', 'OLIM2345');
        [$ares, $capoAres] = $this->palestra('Ares', 'ares', 'ARES2345');

        $iscritto = $this->creaUtente($olimpo, UserRole::Member, 'cambia@esempio.test');

        $risposta = $this->actingAs($iscritto, 'sanctum')
            ->postJson('/api/v1/inviti-palestra/'.$this->invito($ares, $capoAres).'/accetta');

        $risposta->assertStatus(409)
            ->assertJsonPath('code', SeiGiaInUnaPalestraException::CODICE)
            ->assertJsonPath('palestra_attuale', 'Olimpo');

        $this->assertSame($olimpo->getKey(), $iscritto->fresh()->tenant_id, 'la domanda lo ha gia spostato');
    }

    /**
     * ✅ **Con il sì, cambia palestra** — e ne ha una sola.
     */
    #[Test]
    public function con_il_si_lascia_la_prima_ed_entra_nella_seconda(): void
    {
        [$olimpo] = $this->palestra('Olimpo', 'olimpo', 'OLIM2345');
        [$ares, $capoAres] = $this->palestra('Ares', 'ares', 'ARES2345');

        $iscritto = $this->creaUtente($olimpo, UserRole::Member, 'si@esempio.test');

        $this->actingAs($iscritto, 'sanctum')
            ->postJson('/api/v1/inviti-palestra/'.$this->invito($ares, $capoAres).'/accetta', [
                'lascia_la_precedente' => true,
            ])
            ->assertOk();

        $this->assertSame($ares->getKey(), $iscritto->fresh()->tenant_id, 'non e entrato nella seconda');

        /*
         * 🚨 **Una sola**: nella prima non è più iscritto. Due ruoli da iscritto
         * in due palestre vorrebbero dire due palestre che lo vedono e lo
         * contano — e due fatture a consumo che lo pagano.
         */
        $this->assertFalse(
            $this->ctx()->runAs($olimpo, function () use ($iscritto): bool {
                $fresco = User::withoutGlobalScopes()->findOrFail($iscritto->getKey());
                $fresco->unsetRelation('roles');

                return $fresco->hasRole(UserRole::Member->value);
            }),
            'e ancora iscritto alla prima palestra',
        );
    }

    /**
     * ⛔ **Il `join_code` resta rifiutato** — 8.1.4.f.
     *
     * 💡 La domanda esiste solo sull'invito, che la palestra nuova ha emesso
     * apposta. Il codice digitato di nascosto resta la cosa che era: un modo
     * di portarsi via i dati da una palestra senza che nessuno lo sappia.
     */
    #[Test]
    public function col_codice_non_si_cambia_palestra(): void
    {
        [$olimpo] = $this->palestra('Olimpo', 'olimpo', 'OLIM2345');
        [$ares] = $this->palestra('Ares', 'ares', 'ARES2345');

        $iscritto = $this->creaUtente($olimpo, UserRole::Member, 'codice@esempio.test');

        $this->expectException(ValidationException::class);

        app(UnisciAUnaPalestra::class)($iscritto, $ares->join_code);
    }

    /**
     * ⚠️ **Lo spazio di un trainer non è una palestra**: chi viene da lì entra
     * senza domande.
     *
     * 📌 *«un allievo deve poter essere iscritto a infiniti free_trainer e una
     * sola palestra, anche contemporaneamente»*.
     */
    #[Test]
    public function dallo_spazio_di_un_trainer_si_entra_senza_domande(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Coach', 'coach-8-1-4@esempio.test',
            ['password' => TestCase::FAKE_PASSWORD], UserRole::FreeTrainer,
        );

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::TRAINER_30)->firstOrFail()->id,
            'starts_at' => now()->subYear(),
        ]);

        $allievo = app(CreaTenantPersonale::class)(
            'Allievo', 'allievo-8-1-4@esempio.test',
            ['password' => TestCase::FAKE_PASSWORD], UserRole::FreeUser,
        );

        app(InvitiDelTrainer::class)->riscattaPerChiEsiste(
            app(TenantContext::class)->runAs(
                $trainer->fresh()->tenant,
                fn () => app(InvitiDelTrainer::class)->invita($trainer->fresh()),
            )->token,
            $allievo->fresh(),
        );

        [$ares, $capoAres] = $this->palestra('Ares', 'ares', 'ARES2345');

        $this->actingAs($allievo->fresh(), 'sanctum')
            ->postJson('/api/v1/inviti-palestra/'.$this->invito($ares, $capoAres).'/accetta')
            ->assertOk();

        $this->assertSame($ares->getKey(), $allievo->fresh()->tenant_id);
    }

    /**
     * 🚨 **Cambiare palestra non rompe la pausa dell'abbonamento** — 8.1.4 con
     * 8.1.6.
     *
     * ⚠️ Il passaggio è *esci + entra*: l'uscita **riprende** l'abbonamento
     * personale, l'ingresso lo **rimette in pausa**. Se uno dei due mancasse, chi
     * cambia palestra si ritroverebbe o **abbonato e coperto due volte**, o con
     * l'abbonamento **perso**. Nessuno dei due darebbe errore.
     */
    #[Test]
    public function cambiando_palestra_labbonamento_resta_in_pausa(): void
    {
        [$olimpo, $capoOlimpo] = $this->palestra('Olimpo', 'olimpo', 'OLIM2345');
        [$ares, $capoAres] = $this->palestra('Ares', 'ares', 'ARES2345');

        $abbonato = app(CreaTenantPersonale::class)(
            'Abbonato', 'abbonato-8-1-4@esempio.test',
            ['password' => TestCase::FAKE_PASSWORD], UserRole::FreeUser,
        );

        $riga = PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $abbonato->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::PLUS)->firstOrFail()->id,
            'starts_at' => now()->subDays(5),
            'ends_at' => now()->addDays(25),
        ]);

        app(InvitiInPalestra::class)->accetta($this->invito($olimpo, $capoOlimpo), $abbonato->fresh());

        $this->assertNotNull($riga->fresh()->in_pausa_dal, 'entrando nella prima non si e messo in pausa');

        app(InvitiInPalestra::class)->accetta(
            $this->invito($ares, $capoAres),
            $abbonato->fresh(),
            lasciaLaPrecedente: true,
        );

        $dopo = $riga->fresh();

        $this->assertNotNull($dopo->in_pausa_dal, 'cambiando palestra labbonamento si e riattivato');
        $this->assertSame(
            $ares->getKey(),
            (int) $dopo->in_pausa_per_tenant_id,
            'la pausa punta ancora alla palestra vecchia: uscendo dalla nuova non riprenderebbe',
        );
        $this->assertFalse($dopo->eAttivo(), 'coperto due volte: dalla palestra e dal suo abbonamento');
    }
}
