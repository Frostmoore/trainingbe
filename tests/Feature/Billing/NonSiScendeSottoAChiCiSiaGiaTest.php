<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\PlanKind;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Stripe\PianiInVendita;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🚫 **Non si scende sotto chi c'è già** — 27/09/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * 📌 Il committente: *«non si può andare a un piano per il quale si hanno troppi
 * iscritti. Ho un piano Palestra Advanced e 20 iscritti con 3 trainer. Non posso
 * cambiare piano in palestra basic, perché quello ha il limite di 10 iscritti e 2
 * trainer. **Per poterlo selezionare**, devo prima liberare gli slot in eccesso.
 * Idem per i trainer»*.
 *
 * ══ 🚨 COSA C'ERA E COSA MANCAVA ══════════════════════════════════════════
 *
 * ✅ La regola **esisteva** (`pretendiCheCiStianoTutti()`), e contava iscritti **e**
 * trainer. ⛔ Ma mancavano due cose, e la seconda costava soldi veri:
 *
 * | # | Il buco |
 * |---|---|
 * | 1 | girava **solo sul cambio** di un canone attivo su Stripe |
 * | 2 | esisteva **solo come eccezione**: la si scopriva cliccando |
 *
 * 🚨 **Il primo è il grave.** Chi arrivava dall'altra strada — abbonamento scaduto,
 * o mai avuto — poteva **comprare** un piano troppo piccolo. ⛔ E pagarlo: il
 * rifiuto sarebbe arrivato al primo cambio, a soldi già usciti.
 *
 * 💡 Ora la regola è **una domanda** (`percheNonCiStanno()`) che il lancio usa, il
 * listino espone e la pagina mostra: ⚠️ il pulsante e il rifiuto non possono dire
 * due cose diverse, perché vengono dallo stesso metodo.
 */
class NonSiScendeSottoAChiCiSiaGiaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🎯 **Il caso del committente, parola per parola**: 20 iscritti e 3 trainer non
     * entrano in un piano da 10 e 2.
     */
    #[Test]
    public function un_piano_troppo_piccolo_non_si_puo_scegliere(): void
    {
        [$palestra, $capo] = $this->unaPalestraPiena(iscritti: 20, trainer: 3);

        $this->pianoDaPalestra('gym_basic_prova', posti: 10, trainer: 2);

        $voce = $this->voceDelListino($capo, 'gym_basic_prova');

        self::assertNotNull(
            $voce['troppo_piccolo'] ?? null,
            '🚨 Il listino offre un piano che non tiene i suoi iscritti: '
            .'lo sceglierebbe, e scoprirebbe il problema dopo.',
        );

        self::assertStringContainsString(
            '20',
            (string) $voce['troppo_piccolo'],
            '⚠️ Il messaggio deve dire QUANTI sono: «troppi» non dice cosa liberare.',
        );
    }

    /**
     * 🏋️ **E lo stesso vale per i TRAINER** — *«idem per i trainer»*.
     *
     * ⚠️ Serve un test suo: i posti degli iscritti e quelli dei trainer sono due
     * colonne diverse, e la seconda è quella che si dimentica. ⛔ Qui gli iscritti
     * ci stanno: se il test cadesse, vorrebbe dire che il conto dei trainer non
     * viene fatto affatto.
     */
    #[Test]
    public function anche_troppi_trainer_bloccano_il_piano(): void
    {
        [$palestra, $capo] = $this->unaPalestraPiena(iscritti: 4, trainer: 3);

        $this->pianoDaPalestra('gym_pochi_trainer', posti: 100, trainer: 2);

        $voce = $this->voceDelListino($capo, 'gym_pochi_trainer');

        self::assertNotNull(
            $voce['troppo_piccolo'] ?? null,
            '🚨 Gli iscritti ci stanno ma i trainer no, e il piano risulta scegliibile: '
            .'il conto dei trainer non viene fatto.',
        );

        self::assertStringContainsString('trainer', (string) $voce['troppo_piccolo']);
    }

    /** ✅ Il controllo: un piano abbastanza grande si scegliere. */
    #[Test]
    public function un_piano_abbastanza_grande_si_puo_scegliere(): void
    {
        [$palestra, $capo] = $this->unaPalestraPiena(iscritti: 4, trainer: 1);

        $this->pianoDaPalestra('gym_grande_prova', posti: 100, trainer: 10);

        $voce = $this->voceDelListino($capo, 'gym_grande_prova');

        self::assertNull(
            $voce['troppo_piccolo'] ?? null,
            '⛔ Un piano che tiene tutti risulta bloccato: '.($voce['troppo_piccolo'] ?? ''),
        );
    }

    /**
     * 🚨 **IL BUCO GRAVE: anche chi COMPRA viene fermato.**
     *
     * ⛔ Fino al 27/09 il controllo girava solo sul **cambio**. Chi aveva
     * l'abbonamento scaduto — o non l'aveva mai avuto — apriva il pagamento di un
     * piano troppo piccolo e lo **pagava**: il rifiuto arrivava al primo cambio, a
     * soldi già usciti.
     *
     * 💡 Il test guarda che `apri()` **lanci**, cioè che il pagamento non si apra
     * nemmeno. ⚠️ Non serve Stripe: l'eccezione arriva prima.
     */
    #[Test]
    public function chi_compra_non_puo_aprire_il_pagamento_di_un_piano_troppo_piccolo(): void
    {
        [$palestra, $capo] = $this->unaPalestraPiena(iscritti: 20, trainer: 3);

        /* ⛔ Nessun abbonamento: è la strada dell'acquisto, non del cambio. */
        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->delete();

        $this->pianoDaPalestra('gym_basic_acquisto', posti: 10, trainer: 2);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/libera qualche posto|togline qualcuno/');

        app(PianiInVendita::class)->apri($capo->fresh(), 'gym_basic_acquisto');
    }

    // ───────────────────────── aiuti ─────────────────────────

    /**
     * @return array{0: Tenant, 1: User}
     */
    private function unaPalestraPiena(int $iscritti, int $trainer): array
    {
        $palestra = $this->creaPalestra('Piena', 'piena');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@piena.test');

        for ($i = 1; $i <= $iscritti; $i++) {
            $this->creaUtente($palestra, UserRole::Member, "iscritto{$i}@piena.test");
        }

        for ($i = 1; $i <= $trainer; $i++) {
            $this->creaUtente($palestra, UserRole::Trainer, "trainer{$i}@piena.test");
        }

        return [$palestra, $capo];
    }

    private function pianoDaPalestra(string $codice, int $posti, int $trainer): Plan
    {
        return Plan::query()->where('code', $codice)->first()
            ?? Plan::create([
                'code' => $codice,
                'name' => 'Prova '.$codice,
                'kind' => PlanKind::Gym,
                'ai_enabled' => true,
                'max_members' => $posti,
                'max_trainers' => $trainer,
                'price_cents' => 4900,
                'is_public' => true,
            ]);
    }

    /**
     * 🔎 La voce del listino, **come la vede la pagina**.
     *
     * ⚠️ Non si interroga `percheNonCiStanno()` direttamente: ⛔ un test sul metodo
     * passerebbe anche se il campo non arrivasse mai al listino, e la pagina
     * continuerebbe a offrire il piano. 💡 È la stessa distinzione fra «il metodo
     * risponde giusto» e «la porta si apre».
     *
     * @return array<string, mixed>
     */
    private function voceDelListino(User $chi, string $codice): array
    {
        $listino = app(PianiInVendita::class)->listinoPer($chi);

        $voce = collect($listino['piani'])->firstWhere('codice', $codice);

        self::assertIsArray($voce, "⛔ Il piano {$codice} non è nel listino: il test non prova niente.");

        self::assertArrayHasKey(
            'troppo_piccolo',
            $voce,
            '⛔ Il listino non dice se un piano è troppo piccolo: la pagina non può saperlo.',
        );

        return $voce;
    }
}
