<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\PlanKind;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Un `trainer` di palestra non e' un `free_trainer` -- 3b-AH, AH.1-bis.
 *
 * -- IL BUCO CHE AH.1 AVEVA APERTO --
 *
 * Da AH.1 chi riscatta l'invito di un trainer NASCE DENTRO IL TENANT DEL
 * TRAINER. Per un trainer di palestra quel tenant e' la palestra.
 *
 * Quindi un trainer dipendente avrebbe potuto creare inviti personali e far
 * entrare gente dentro la palestra saltando InvitiInPalestra::postiRimasti(),
 * cioe' il tetto dei posti che la palestra paga. Il suo conteggio guarda gli
 * allievi assegnati A LUI, non gli iscritti della palestra: con tre trainer,
 * una palestra da cinquanta posti se ne sarebbe ritrovati centocinquanta.
 *
 * Nessun errore da nessuna parte, e la palestra se ne accorgerebbe dal
 * conteggio o dalla fattura.
 *
 * Prima di AH.1 non era un buco: l'invitato nasceva in un tenant personale suo
 * e nella palestra non entrava.
 *
 * -- LA DOMANDA GIUSTA --
 *
 * "Questo spazio e' tuo?" (Tenant::eDi), non "che ruolo hai?". E' la regola 1
 * di AH: quello che conta non e' il ruolo, e' chi paga per quello spazio.
 */
final class UnTrainerDiPalestraNonEIndipendenteTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function inviti(): InvitiDelTrainer
    {
        return app(InvitiDelTrainer::class);
    }

    private function trainerIndipendente(): User
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Coach',
            'coach@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $piano = Plan::query()->where('code', Plan::TRAINER_10)->first()
            ?? Plan::create([
                'code' => Plan::TRAINER_10,
                'name' => 'Trainer - 10 allievi',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => true,
                'max_members' => 10,
                'price_cents' => 2999,
                'is_public' => true,
            ]);

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => $piano->id,
            'starts_at' => now()->subYears(2),
        ]);

        return $trainer->fresh();
    }

    /**
     * LA GUARDIA: il trainer di palestra non crea inviti personali.
     *
     * I suoi allievi sono gli iscritti della palestra, che l'amministratore gli
     * assegna dal pannello. Chi lavora in palestra e vuole clienti suoi si
     * abbona da free_trainer -- le due cose non sono mutuamente esclusive.
     */
    #[Test]
    public function un_trainer_di_palestra_non_puo_invitare_allievi_suoi(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');

        $trainer = $this->creaUtente($palestra->fresh(), UserRole::Trainer, 'staff@test.it');

        $this->expectException(ValidationException::class);

        $this->inviti()->invita($trainer);
    }

    /** E dall'app arriva un 422, non un 500. */
    #[Test]
    public function e_dall_app_e_un_errore_di_validazione(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');

        $trainer = $this->creaUtente($palestra->fresh(), UserRole::Trainer, 'staff@test.it');

        $this->comeApp($trainer)
            ->postJson('/api/v1/trainer/invites', [])
            ->assertStatus(422);

        self::assertSame(0, TrainerInvite::withoutGlobalScopes()->count());
    }

    /** Il trainer indipendente invece continua a poterlo fare. */
    #[Test]
    public function il_trainer_indipendente_invece_si(): void
    {
        $trainer = $this->trainerIndipendente();

        $invito = $this->inviti()->invita($trainer);

        self::assertSame($trainer->tenant_id, $invito->tenant_id);
    }

    /**
     * LA SECONDA SERRATURA: un invito nato valido non e' un permesso perpetuo.
     *
     * Fra la creazione e l'uso passa fino a una settimana, e in mezzo il
     * trainer puo' essere entrato in una palestra. Questo test costruisce
     * proprio quel caso -- invito creato quando lo spazio era suo, riscattato
     * quando non lo e' piu' -- e copre anche gli inviti nati prima che la
     * guardia esistesse.
     */
    #[Test]
    public function un_invito_creato_prima_non_si_usa_dopo(): void
    {
        $trainer = $this->trainerIndipendente();
        $invito = $this->inviti()->invita($trainer);

        // Il trainer passa a lavorare per una palestra: lo spazio non e' piu' suo.
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');

        $trainer->forceFill(['tenant_id' => $palestra->getKey()])->save();

        $this->postJson('/api/v1/auth/register-with-invite', [
            'token' => $invito->token,
            'name' => 'Nuovo',
            'email' => 'nuovo@esempio.test',
            'username' => 'nuovo',
            'password' => self::FAKE_PASSWORD,
            'password_confirmation' => self::FAKE_PASSWORD,
            'age_confirmed' => true,
            'terms_accepted' => true,
        ])->assertStatus(422);

        /*
         * LA VERIFICA CHE CONTA e' questa, non lo status: nessuno e' entrato
         * nella palestra. Un test che guardasse solo il 422 passerebbe anche se
         * l'utente fosse stato creato e poi il rifiuto fosse arrivato dopo.
         */
        self::assertSame(
            0,
            User::withoutGlobalScopes()->where('email', 'nuovo@esempio.test')->count(),
            'qualcuno e\' entrato nella palestra da un invito personale',
        );
    }

    /**
     * E un trainer di palestra non vede nessuno dei comandi di chi paga.
     *
     * Gia' garantito da Cassa::loPuoGestire() -- il suo tenant non e' suo -- ma
     * AH.1-bis chiede di provarlo, perche' e' il genere di cosa che si da' per
     * scontata e non si controlla.
     */
    #[Test]
    public function e_non_tocca_l_abbonamento_della_palestra(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');

        $trainer = $this->creaUtente($palestra->fresh(), UserRole::Trainer, 'staff@test.it');

        self::assertFalse(Cassa::loPuoGestire($trainer));
        self::assertNull(app(Cassa::class)->clienteDi($trainer));

        $this->comeApp($trainer)
            ->getJson('/api/v1/billing/listino')
            ->assertOk()
            ->assertJsonPath('data.abbonamento_attivo.rinnovabile', false);
    }
}
