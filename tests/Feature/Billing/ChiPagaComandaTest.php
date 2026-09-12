<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Services\Billing\Stripe\Cassa;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Chi paga comanda: nessun altro tocca l'abbonamento -- 10/09/2026.
 *
 * -- Il modello, come l'ha descritto il committente --
 *
 * "Un utente allievo di una palestra sta pagando la palestra per il suo
 * abbonamento, quindi deve avere un abbonamento pieno. Idem un allievo di un
 * trainer. Le differenze tra questi sono che l'utente individuale paga
 * personalmente, l'allievo di una palestra o di un trainer hanno un abbonamento
 * pagato dalla palestra o dal trainer."
 *
 * E: "La palestra deve poter disdire o modificare il suo abbonamento come
 * vuole."
 *
 * -- Cosa difende questo file --
 *
 * Il confine fra CHI USA l'abbonamento e CHI LO PAGA. L'abbonamento sta sul
 * TENANT, non sulla persona: senza questo confine, ogni iscritto di una
 * palestra puo' toccare l'abbonamento di TUTTI.
 *
 * Non e' teoria: fino al 10/09 la guardia guardava il tipo di tenant SOLO
 * quando non c'era Stripe di mezzo. Il giorno in cui una palestra paga con
 * Stripe, ognuno dei suoi iscritti avrebbe potuto spegnere il rinnovo per
 * tutti, aprire il portale con le fatture della palestra, e cambiarle la carta.
 * Latente -- quindi invisibile provando -- fino al primo pagamento vero.
 */
final class ChiPagaComandaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /** Una palestra che paga con Stripe: e' il caso che oggi non esiste ancora. */
    private function palestraCheePaga(Tenant $palestra): void
    {
        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->id)
            ->delete();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->id,
            'plan_id' => Plan::query()->where('code', Plan::PLUS)->firstOrFail()->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
            'stripe_subscription_id' => 'sub_finto',
            'stripe_customer_id' => 'cus_finto',
            'rinnova' => true,
        ]);
    }

    #[Test]
    public function un_iscritto_non_gestisce_l_abbonamento_della_palestra(): void
    {
        $palestra = $this->creaPalestra();
        $this->palestraCheePaga($palestra);

        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@test.it');

        self::assertFalse(
            Cassa::loPuoGestire($iscritto),
            'un iscritto avrebbe potuto disdire per tutta la palestra',
        );

        // E il portale con le fatture non gli si apre nemmeno.
        self::assertNull(app(Cassa::class)->clienteDi($iscritto));
    }

    #[Test]
    public function ma_l_amministratore_della_palestra_si(): void
    {
        $palestra = $this->creaPalestra();
        $this->palestraCheePaga($palestra);

        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it');

        self::assertTrue(Cassa::loPuoGestire($capo));
        self::assertSame('cus_finto', app(Cassa::class)->clienteDi($capo));
    }

    /**
     * Il trainer indipendente ha un tenant personale: il pacchetto allievi lo
     * compra lui e lo gestisce lui.
     *
     * == AGGIORNATO IL 12/09/2026 -- 3b-AH, Passo 0 ==
     *
     * Prima questo test diceva "chi sta in un tenant personale lo gestisce", e
     * passava perche' in un tenant personale ci stava UNA PERSONA SOLA:
     * "personale" voleva dire "tuo".
     *
     * Da AH.1 in poi non e' piu' vero -- nel tenant di un trainer ci stanno
     * anche i suoi allievi -- e quella frase sarebbe diventata "ogni allievo
     * gestisce l'abbonamento del suo trainer". La domanda giusta non e' di che
     * TIPO e' il tenant: e' di CHI e'.
     */
    #[Test]
    public function il_proprietario_di_un_tenant_personale_lo_gestisce(): void
    {
        $suo = $this->creaPalestra('Personale', 'personale', 'PERS2345');
        $suo->update(['kind' => 'personal']);

        $tizio = $this->creaUtente($suo->fresh(), UserRole::FreeUser, 'tizio@test.it');

        $suo->forceFill(['proprietario_id' => $tizio->getKey()])->save();

        self::assertTrue(Cassa::loPuoGestire($tizio->fresh()));
    }

    /**
     * IL CASO CHE AH.1 STA PER CREARE, provato adesso che il danno non c'e'
     * ancora: un allievo dentro il tenant personale del suo trainer.
     *
     * Il tenant e' `personal` e NON e' suo. Con la regola vecchia -- "tenant
     * personale -> chiunque ci stia dentro" -- questa persona avrebbe potuto
     * spegnere il rinnovo del trainer e aprire il portale con le sue fatture.
     */
    #[Test]
    public function ma_un_allievo_dentro_quel_tenant_no(): void
    {
        $delTrainer = $this->creaPalestra('Trainer', 'trainer-suo', 'TRAI2345');
        $delTrainer->update(['kind' => 'personal']);

        $trainer = $this->creaUtente($delTrainer->fresh(), UserRole::FreeTrainer, 'coach@test.it');
        $delTrainer->forceFill(['proprietario_id' => $trainer->getKey()])->save();

        $allievo = $this->creaUtente($delTrainer->fresh(), UserRole::Member, 'allievo@test.it');

        self::assertFalse(
            Cassa::loPuoGestire($allievo->fresh()),
            'un allievo avrebbe potuto disdire per il suo trainer',
        );

        self::assertTrue(Cassa::loPuoGestire($trainer->fresh()));
    }
}
