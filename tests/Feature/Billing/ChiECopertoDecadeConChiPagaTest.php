<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\PlanKind;
use App\Enums\TenantKind;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\PianoAttivo;
use App\Services\Billing\Stripe\Cassa;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * AH.3 e AH.5 -- due regole che si DANNO PER SCONTATE, e quindi si provano.
 *
 * -- PERCHE' UN FILE SOLO PER VERIFICARE --
 *
 * Il piano dice, per tutte e due: "gia' cosi', da verificare -- non da
 * costruire". E' esattamente il genere di frase che invecchia male: vale finche'
 * nessuno tocca il pezzo sotto, e AH.1 ha spostato mezzo modello.
 *
 *   AH.3  chi e' coperto da qualcun altro non vede i comandi di chi paga
 *   AH.5  quando chi paga smette, tutti quelli nel suo spazio decadono
 *         INSIEME A LUI, nello stesso istante
 *
 * La differenza fra "funziona" e "funziona e lo sappiamo" e' questo file.
 *
 * -- E IL CASO PIU' FACILE DA SBAGLIARE --
 *
 * Una persona coperta DA DUE PARTI quando UNA SOLA viene a mancare: l'iscritto
 * di una palestra che ha anche un trainer esterno. Se il trainer smette di
 * pagare, quella persona NON deve perdere niente -- la paga la palestra.
 */
final class ChiECopertoDecadeConChiPagaTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function piano(): PianoAttivo
    {
        return app(PianoAttivo::class);
    }

    /**
     * Lo spazio di un trainer che paga, con un allievo dentro.
     *
     * @return array{0: Tenant, 1: User, 2: User}
     */
    private function trainerCheEPaga(?string $fine = null, bool $rinnova = false): array
    {
        $spazio = $this->creaPalestra('Coach', 'coach');
        $spazio->update(['kind' => TenantKind::Personal->value]);

        $trainer = $this->creaUtente($spazio->fresh(), UserRole::FreeTrainer, 'coach@test.it');
        $spazio->forceFill(['proprietario_id' => $trainer->getKey()])->save();

        $allievo = $this->creaUtente($spazio->fresh(), UserRole::Member, 'allievo@test.it');

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $spazio->getKey())
            ->delete();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $spazio->getKey(),
            'plan_id' => $this->pianoTrainer()->getKey(),
            'starts_at' => now()->subMonth(),
            'ends_at' => $fine,
            'rinnova' => $rinnova,
        ]);

        return [$spazio->fresh(), $trainer->fresh(), $allievo->fresh()];
    }

    private function pianoTrainer(): Plan
    {
        return Plan::query()->where('code', Plan::TRAINER_10)->first()
            ?? Plan::create([
                'code' => Plan::TRAINER_10,
                'name' => 'Trainer - 10 allievi',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => true,
                'max_members' => 10,
                'price_cents' => 2999,
                'is_public' => true,
            ]);
    }

    // ───────────────────────── AH.3 ─────────────────────────

    /**
     * AH.3 -- e la verifica e' sul PAYLOAD, non sul metodo.
     *
     * Cassa::loPuoGestire() e' gia' provata altrove. Quello che questo test
     * guarda e' cosa arriva all'app: se un giorno qualcuno leggesse la regola
     * giusta e scrivesse la chiave sbagliata, nessun test sul metodo se ne
     * accorgerebbe e il pulsante tornerebbe a comparire.
     */
    #[Test]
    public function un_allievo_non_vede_i_comandi_di_chi_paga(): void
    {
        [, , $allievo] = $this->trainerCheEPaga();

        $this->comeApp($allievo)
            ->getJson('/api/v1/billing/listino')
            ->assertOk()
            ->assertJsonPath('data.abbonato', true)
            ->assertJsonPath('data.abbonamento_attivo.rinnovabile', false)
            ->assertJsonPath('data.abbonamento_attivo.gestibile', false)
            ->assertJsonPath('data.chi_pagava', 'trainer');
    }

    /** E il portale di Stripe non gli si apre nemmeno. */
    #[Test]
    public function e_il_portale_non_gli_si_apre(): void
    {
        [, , $allievo] = $this->trainerCheEPaga();

        self::assertNull(app(Cassa::class)->clienteDi($allievo));
    }

    /** Il trainer invece li vede tutti: e' lui che paga. */
    #[Test]
    public function il_trainer_invece_li_vede(): void
    {
        [, $trainer] = $this->trainerCheEPaga();

        $this->comeApp($trainer)
            ->getJson('/api/v1/billing/listino')
            ->assertOk()
            ->assertJsonPath('data.abbonamento_attivo.rinnovabile', true)
            ->assertJsonPath('data.chi_pagava', 'io');
    }

    /** AH.4 -- e a un iscritto di palestra si dice «palestra», non «trainer». */
    #[Test]
    public function a_un_iscritto_di_palestra_si_dice_palestra(): void
    {
        $palestra = $this->creaPalestra();
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');
        $iscritto = $this->creaUtente($palestra->fresh(), UserRole::Member, 'iscritto@test.it');

        $this->comeApp($iscritto)
            ->getJson('/api/v1/billing/listino')
            ->assertOk()
            ->assertJsonPath('data.chi_pagava', 'palestra');

        /*
         * IL CASO CHE L'ORDINE DELLE CONDIZIONI PUO' SBAGLIARE: il gym_admin
         * sta dentro una palestra, e guardando il TIPO per primo gli si direbbe
         * che paga qualcun altro -- che e' lui.
         */
        $this->comeApp($capo)
            ->getJson('/api/v1/billing/listino')
            ->assertOk()
            ->assertJsonPath('data.chi_pagava', 'io');
    }

    // ───────────────────────── AH.5 ─────────────────────────

    /**
     * AH.5 -- decadono INSIEME, nello stesso istante.
     *
     * Non c'e' nessun lavoro che propaghi la scadenza agli allievi, ed e'
     * giusto cosi': l'abbonamento sta sul tenant, e quando finisce finisce per
     * tutti quelli che ci stanno dentro. Questo test lo inchioda, perche' e'
     * proprio la cosa che si da' per scontata.
     */
    #[Test]
    public function quando_il_trainer_scade_decade_anche_l_allievo(): void
    {
        [, $trainer, $allievo] = $this->trainerCheEPaga(now()->addDay()->toDateTimeString());

        self::assertTrue($this->piano()->eAbbonato($trainer));
        self::assertTrue($this->piano()->eAbbonato($allievo));

        $this->travel(2)->days();

        self::assertFalse($this->piano()->eAbbonato($trainer->fresh()));
        self::assertFalse(
            $this->piano()->eAbbonato($allievo->fresh()),
            'l\'allievo e\' rimasto abbonato dopo la scadenza del suo trainer',
        );
    }

    /**
     * E CON IL RINNOVO ACCESO valgono anche per lui i 7 giorni di grazia.
     *
     * La grazia serve a non far comparire "il tuo abbonamento e' scaduto" a
     * qualcuno che il rinnovo non l'ha mai spento, mentre Stripe sta ancora
     * riprovando il pagamento. Se valesse solo per chi paga e non per chi e'
     * coperto, la modale sbagliata comparirebbe proprio a chi non puo' farci
     * niente.
     *
     * -- PERCHE' PASSA DAL WEBHOOK E NON DA UNA RIGA SCRITTA A MANO --
     *
     * La grazia NON e' una regola di lettura: e' scritta dentro `ends_at`
     * quando Stripe comunica la fine del periodo (`Cassa::conLaGrazia()`).
     * Costruire la riga a mano con `ends_at = domani` e pretendere la grazia
     * proverebbe una regola che non esiste -- e il test sarebbe rosso per il
     * motivo sbagliato, come e' successo scrivendolo.
     *
     * Quindi si passa dalla strada vera: il rinnovo arriva, la riga si allunga,
     * e l'allievo eredita quella data senza che nessuno gliela propaghi.
     */
    #[Test]
    public function e_la_grazia_vale_anche_per_l_allievo(): void
    {
        [$spazio, , $allievo] = $this->trainerCheEPaga(
            now()->addDay()->toDateTimeString(),
            rinnova: true,
        );

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $spazio->getKey())
            ->update(['stripe_subscription_id' => 'sub_del_coach']);

        $finePeriodo = now()->addDays(30);

        app(Cassa::class)->applica([
            'type' => 'invoice.paid',
            'data' => ['object' => [
                'id' => 'in_test',
                'subscription' => 'sub_del_coach',
                'lines' => ['data' => [['period' => ['end' => $finePeriodo->timestamp]]]],
            ]],
        ]);

        // Tre giorni DOPO la fine del periodo pagato: siamo dentro la grazia.
        $this->travelTo($finePeriodo->copy()->addDays(3));

        self::assertTrue(
            $this->piano()->eAbbonato($allievo->fresh()),
            'la grazia non e\' arrivata a chi e\' coperto',
        );

        // Oltre la grazia, decade anche lui.
        $this->travelTo($finePeriodo->copy()->addDays(10));

        self::assertFalse($this->piano()->eAbbonato($allievo->fresh()));
    }

    /**
     * IL CASO PIU' FACILE DA SBAGLIARE: coperto da due parti, una sola viene a
     * mancare.
     *
     * Un iscritto di palestra che si fa seguire anche da un trainer esterno.
     * Se il trainer smette di pagare, questa persona NON deve perdere niente:
     * la paga la palestra, e il rapporto con il trainer e' un'altra cosa.
     */
    #[Test]
    public function chi_e_coperto_da_due_parti_non_decade_per_una_sola(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');
        $iscritto = $this->creaUtente($palestra->fresh(), UserRole::Member, 'iscritto@test.it');

        [$spazio, $trainer] = $this->trainerCheEPaga(now()->addDay()->toDateTimeString());

        // Il rapporto attraversa i tenant: e' il caso di AH.1-ter.
        $iscritto->assignedTrainers()->attach($trainer->getKey(), [
            'tenant_id' => $spazio->getKey(),
            'assigned_at' => now(),
            'assigned_by' => $trainer->getKey(),
        ]);

        $this->travel(30)->days();

        self::assertFalse($this->piano()->eAbbonato($trainer->fresh()));
        self::assertTrue(
            $this->piano()->eAbbonato($iscritto->fresh()),
            'la palestra paga: la scadenza del trainer non lo riguarda',
        );
    }
}
