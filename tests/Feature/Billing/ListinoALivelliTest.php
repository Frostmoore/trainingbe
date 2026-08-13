<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\PlanKind;
use App\Models\Plan;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il listino a tre livelli — G1 (D5, D6, D7).
 *
 * 🚨 **Cosa prova davvero questa classe.** Non che le colonne esistano — quello
 * lo direbbe la migration da sola. Prova le tre cose che, sbagliate, non danno
 * errore:
 *
 * 1. che `max_members` e `max_members_per_trainer` **non si confondano** — il
 *    giorno che si confondono, un limite sbagliato non lancia niente: limita
 *    male;
 * 2. che le convenzioni `null` = «scendi» e `0` = «illimitato» **valgano anche
 *    qui**, perche' un `0` letto come «zero chiamate» spegne l'AI a un cliente
 *    che l'ha pagata;
 * 3. che i piani ritirati **siano ancora comprabili da chi li ha gia'**, cioe'
 *    che `is_public = false` non sia diventato «cancellato».
 */
final class ListinoALivelliTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function piano(string $codice): Plan
    {
        return Plan::query()->where('code', $codice)->firstOrFail();
    }

    #[Test]
    public function a_gym_plan_caps_members_per_trainer_not_members(): void
    {
        $palestra = $this->piano(Plan::GYM_5);

        // 🚨 Il cuore di D5: la palestra limita **per trainer**.
        $this->assertSame(5, $palestra->max_trainers);
        $this->assertSame(50, $palestra->max_members_per_trainer);
        $this->assertSame(50, $palestra->tettoAllieviPerTrainer());

        /*
         * ⚠️ E **non** tocca `max_members`, che significa un'altra cosa. Se un
         * giorno qualcuno lo valorizzasse «per comodita'», `InvitiDelTrainer`
         * lo leggerebbe come «quanti allievi puo' seguire questo trainer in
         * proprio» e limiterebbe la persona sbagliata.
         */
        $this->assertNull($palestra->max_members);
    }

    #[Test]
    public function a_trainer_plan_caps_its_own_members(): void
    {
        $dieci = $this->piano(Plan::TRAINER_10);

        $this->assertSame(10, $dieci->max_members);

        // ⚠️ Un piano trainer non ha trainer sotto di se': le due colonne della
        // palestra restano vuote, e chiedere il tetto per trainer torna `null`.
        $this->assertNull($dieci->max_trainers);
        $this->assertNull($dieci->max_members_per_trainer);
        $this->assertNull($dieci->tettoAllieviPerTrainer());
    }

    #[Test]
    public function asking_a_trainer_plan_for_the_gym_cap_is_null_not_a_number(): void
    {
        /*
         * 🚨 Il caso che tiene in piedi la catena. `tettoAllieviPerTrainer()` su
         * un piano trainer **deve** tornare `null` anche se la colonna fosse
         * valorizzata per sbaglio: `null` vuol dire «non lo decide questo
         * livello», e la catena scende. Un numero vorrebbe dire «deciso», e la
         * catena si fermerebbe sul valore sbagliato.
         */
        $dieci = $this->piano(Plan::TRAINER_10);
        $dieci->forceFill(['max_members_per_trainer' => 999])->save();

        $this->assertSame(PlanKind::Trainer, $dieci->fresh()->kind);
        $this->assertNull($dieci->fresh()->tettoAllieviPerTrainer());
    }

    #[Test]
    public function the_photo_cap_is_a_sub_limit_of_the_general_one(): void
    {
        foreach ([Plan::PLUS, Plan::TRAINER_10, Plan::TRAINER_30, Plan::GYM_5, Plan::GYM_15] as $codice) {
            $piano = $this->piano($codice);

            $this->assertNotNull($piano->chiamateAlMese(), "{$codice} senza tetto di chiamate");
            $this->assertNotNull($piano->chiamateConFotoAlMese(), "{$codice} senza tetto foto");

            // 🚨 D7 — le foto sono **dentro** il totale, non accanto. Un
            // sotto-limite piu' grande del limite non e' un tetto: e' un errore
            // di listino che nessuno vedrebbe finche' non arriva la fattura.
            $this->assertLessThanOrEqual(
                $piano->chiamateAlMese(),
                $piano->chiamateConFotoAlMese(),
                "{$codice}: le foto superano il totale",
            );
        }
    }

    #[Test]
    public function the_free_plan_decides_nothing_because_it_has_no_ai(): void
    {
        $free = $this->piano(Plan::FREE);

        $this->assertFalse($free->ai_enabled);

        /*
         * ⚠️ `null` e non `0`. Sono due cose diverse e la differenza conta: `0`
         * vorrebbe dire **illimitato**, cioe' l'opposto esatto. Il gratuito non
         * decide niente perche' non arriva nemmeno a `MemberAiQuota`:
         * `RequirePlanWithAi` lo ferma prima.
         */
        $this->assertNull($free->chiamateAlMese());
        $this->assertNull($free->chiamateConFotoAlMese());
    }

    #[Test]
    public function zero_still_means_unlimited(): void
    {
        $piano = $this->piano(Plan::PLUS);
        $piano->forceFill([
            'ai_monthly_calls_per_member' => 0,
            'ai_monthly_photo_calls_per_member' => 0,
        ])->save();

        // 🚨 Il modello restituisce `0`, non `null`: la traduzione «0 =
        // illimitato» e' di `MemberAiQuota`, e deve restare in un posto solo.
        $this->assertSame(0, $piano->fresh()->chiamateAlMese());
        $this->assertSame(0, $piano->fresh()->chiamateConFotoAlMese());
    }

    #[Test]
    public function the_retired_plans_are_out_of_the_price_list_but_still_in_the_table(): void
    {
        foreach ([Plan::TRAINER_PRO, Plan::GYM] as $codice) {
            $piano = $this->piano($codice);

            $this->assertFalse($piano->is_public, "{$codice} e' ancora nel listino");

            /*
             * 🚨 Ma esiste, ed e' il punto: `plan_subscriptions` punta a
             * `plans.id`. Cancellarlo lascerebbe senza piano entrambe le
             * palestre vere della fotografia del 13/08/2026 — che scenderebbero
             * al gratuito, cioe' **senza AI**, pur pagando.
             */
            $this->assertNotNull($piano->chiamateAlMese(), "{$codice} ritirato E svuotato");
        }

        $pubblici = Plan::query()->pubblici()->pluck('code')->all();

        $this->assertNotContains(Plan::TRAINER_PRO, $pubblici);
        $this->assertNotContains(Plan::GYM, $pubblici);
        $this->assertContains(Plan::TRAINER_10, $pubblici);
        $this->assertContains(Plan::GYM_5, $pubblici);
    }

    #[Test]
    public function every_paid_plan_stays_above_the_measured_cost(): void
    {
        /*
         * 🚨 **Il pavimento del prezzo, come test.** `STIMA-COSTI-AI.md` §4 misura
         * ≈ **3,08 $/mese** per persona al tetto di **450 chiamate di cui 45**
         * con allegato — il tetto deciso dal committente il 13/08/2026.
         *
         * ⚠️ **Era 1,45, ed era vecchio di tre giorni**: il prompt del cibo era
         * cresciuto del 329% con il classificatore alimentare e nessuno aveva
         * rimisurato. Il documento non aveva un errore — aveva **smesso di
         * essere aggiornato**, che e' peggio perche' continua a rispondere.
         * Un piano che sta sotto quel numero perde soldi a ogni cliente, per
         * sempre — ed e' un errore che non si vede nel codice ne' nei dati:
         * si vede solo nella fattura del fornitore, mesi dopo.
         *
         * ⚠️ I prezzi sono segnaposto e cambieranno. Questo test non fissa i
         * prezzi: fissa la **regola** che li lega al costo.
         */
        $costoPerPersonaCent = 308;

        foreach (Plan::query()->pubblici()->where('ai_enabled', true)->get() as $piano) {
            $persone = match ($piano->kind) {
                PlanKind::Person => 1,
                PlanKind::Trainer => $piano->max_members ?? 1,
                PlanKind::Gym => ($piano->max_trainers ?? 1) * ($piano->max_members_per_trainer ?? 1),
            };

            /*
             * ⚠️ Non tutti gli allievi consumano tutto: si assume che il 30%
             * arrivi al tetto. E' la stessa prudenza dei tre profili di
             * `STIMA-COSTI-AI.md`, dove il «pesante» e' una minoranza.
             */
            $costoAtteso = (int) round($persone * $costoPerPersonaCent * 0.30);

            $this->assertGreaterThan(
                $costoAtteso,
                $piano->price_cents,
                "{$piano->code}: {$piano->price_cents} cent non copre {$costoAtteso} cent di AI",
            );
        }
    }

    #[Test]
    public function seeding_twice_does_not_duplicate_nor_reset(): void
    {
        $prima = Plan::query()->count();
        $idPlus = $this->piano(Plan::PLUS)->id;

        $this->seed(PlanSeeder::class);

        $this->assertSame($prima, Plan::query()->count());

        /*
         * 🚨 **Lo stesso `id`, ed e' la cosa che conta.** Un seeder che
         * cancellasse e ricreasse i piani lascerebbe `plan_subscriptions`
         * puntare a righe che non esistono piu' — cioe' toglierebbe il piano a
         * ogni cliente pagante, in silenzio.
         */
        $this->assertSame($idPlus, $this->piano(Plan::PLUS)->id);
    }
}
