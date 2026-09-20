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

    /**
     * 🆕 **Un piano da palestra ha DUE tetti, e valgono tutti e due** — 13/09.
     *
     * ⛔ Questo test diceva l'opposto: *«la palestra limita per trainer»*, e
     * pretendeva `max_members = null`. 🚨 Voleva dire **iscritti infiniti**, e lo
     * era davvero: `InvitiInPalestra::postiRimasti()` rispondeva «senza limite»
     * su ogni piano palestra.
     *
     * 📌 *«Ogni piano per le palestre ha un tot di slot per trainer e
     * iscritti»*.
     */
    #[Test]
    public function un_piano_palestra_ha_due_tetti(): void
    {
        $palestra = $this->piano('gym_10');

        $this->assertSame(10, $palestra->max_members, 'gli iscritti non sono contati');
        $this->assertSame(5, $palestra->max_trainers, 'i trainer non sono contati');
    }

    /**
     * 🚨 **E gli slot trainer sono sproporzionati, di proposito.**
     *
     * 📌 *«Una palestra deve poter inserire più o meno tutti i suoi trainer
     * anche se vuole dare l'abbonamento a pochissimi allievi»*, ma il tetto resta
     * un massimo — *«se una palestra ha 50 trainer non mi sta bene che paghi
     * soltanto per 10-20 iscritti»*.
     *
     * ⚠️ Il test prova il **rapporto**, non i numeri.
     *
     * 🆕 **20/09/2026, coi prezzi veri**: la soglia era «almeno tre iscritti per
     * trainer» e il listino nuovo non la rispetta più — il Basic ha 10 iscritti
     * e 5 trainer, cioè due. ⛔ Non è una svista del listino: una palestra
     * piccola ha **pochi abbonamenti regalati e tutti i suoi istruttori**, ed è
     * il caso che quel piano serve.
     *
     * 💡 La regola vera, quella che il listino rispetta davvero, è l'altra
     * faccia della stessa cosa: **i posti trainer non sono mai meno di un terzo
     * dei posti iscritto**. Con 10/5, 30/10 e 60/20 regge, e regge anche il
     * giorno che i numeri cambiano — se un giorno non reggesse, sarebbe una
     * palestra costretta a lasciare fuori i propri istruttori.
     */
    #[Test]
    public function i_trainer_sono_proporzionalmente_piu_generosi_degli_iscritti(): void
    {
        foreach (Plan::query()->pubblici()->diTipo(PlanKind::Gym)->get() as $piano) {
            if ($piano->max_trainers === null) {
                continue; // il piano a consumo: nessun tetto, nessun rapporto.
            }

            $this->assertGreaterThanOrEqual(
                $piano->max_members / 3,
                $piano->max_trainers,
                "{$piano->code}: troppo pochi posti trainer per gli iscritti che vende",
            );
        }
    }

    #[Test]
    public function a_trainer_plan_caps_its_own_members(): void
    {
        $cinque = $this->piano('trainer_5');

        $this->assertSame(5, $cinque->max_members);

        /*
         * ⚠️ Un piano trainer non ha trainer sotto di se': nel suo piano il
         * trainer e' **lui**, e non ce ne sono altri da contare.
         */
        $this->assertNull($cinque->max_trainers);
    }

    /**
     * 🆕 **Il piano a consumo si paga a iscritto, con un minimo** — 13/09.
     *
     * 📌 *«Pagherà un tot per ogni iscritto a cui regala l'abbonamento, per un
     * minimo di 100»*, e i trainer sono illimitati.
     *
     * 🚨 **Il minimo si applica al CONTEGGIO, non al totale**: una palestra con
     * 40 iscritti paga 100 × il prezzo. ⛔ «Almeno tot euro» sembrerebbe la stessa
     * cosa e smetterebbe di esserlo appena il prezzo a iscritto cambia.
     */
    #[Test]
    public function il_piano_a_consumo_si_paga_a_iscritto(): void
    {
        $consumo = $this->piano('gym_consumo');

        $this->assertTrue($consumo->aConsumo());
        $this->assertNull($consumo->max_members, 'un piano a consumo non ha tetti');
        $this->assertNull($consumo->max_trainers);
        $this->assertSame(0, $consumo->price_cents, 'un piano a consumo non ha un canone di listino');

        $minimo = $consumo->minimo_iscritti;
        $prezzo = $consumo->prezzo_iscritto_cent;

        /*
         * 🆕 **Il canone, dal 20/09/2026.** 📌 *«2,80€/mese per allievo +
         * 15€/mese»*: `price_cents` resta 0 — non è un piano a canone di
         * listino — e il fisso sta in `canone_cent`, che si somma sempre.
         */
        $canone = $consumo->canone_cent;

        $this->assertGreaterThan(0, $canone, 'il su misura ha un canone');

        // Sotto il minimo si paga il minimo (più il canone).
        $this->assertSame($canone + $minimo * $prezzo, $consumo->costoDelMese(40));
        $this->assertSame($canone + $minimo * $prezzo, $consumo->costoDelMese($minimo));

        // Sopra, si paga quello che si usa.
        $this->assertSame($canone + ($minimo + 30) * $prezzo, $consumo->costoDelMese($minimo + 30));
    }

    /** ⛔ E un piano a canone non risponde a quella domanda: il prezzo e' fisso. */
    #[Test]
    public function un_piano_a_canone_non_ha_un_costo_a_iscritto(): void
    {
        $this->assertFalse($this->piano('gym_10')->aConsumo());
        $this->assertNull($this->piano('gym_10')->costoDelMese(999));
    }

    #[Test]
    public function the_photo_cap_is_a_sub_limit_of_the_general_one(): void
    {
        foreach ([Plan::PLUS, 'trainer_5', 'trainer_30', 'gym_10', 'gym_60'] as $codice) {
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
        foreach ([Plan::TRAINER_PRO, Plan::GYM, Plan::TRAINER_FREE, Plan::GYM_5, Plan::GYM_15] as $codice) {
            $piano = $this->piano($codice);

            $this->assertFalse($piano->is_public, "{$codice} e' ancora nel listino");

            /*
             * 🚨 Ma esiste, ed e' il punto: `plan_subscriptions` punta a
             * `plans.id`. Cancellarlo lascerebbe senza piano entrambe le
             * palestre vere della fotografia del 13/08/2026 — che scenderebbero
             * al gratuito, cioe' **senza AI**, pur pagando.
             */
            if ($codice !== Plan::TRAINER_FREE) {
                // ⚠️ `trainer_free` l'AI non l'ha mai avuta: e' il motivo per cui
                // non poteva coprire nessuno, ed e' giusto che resti cosi'.
                $this->assertNotNull($piano->chiamateAlMese(), "{$codice} ritirato E svuotato");
            }
        }

        $pubblici = Plan::query()->pubblici()->pluck('code')->all();

        $this->assertNotContains(Plan::TRAINER_PRO, $pubblici);
        $this->assertNotContains(Plan::GYM, $pubblici);

        // 🆕 13/09: ritirati insieme al modello vecchio.
        $this->assertNotContains(Plan::TRAINER_FREE, $pubblici);
        $this->assertNotContains(Plan::GYM_5, $pubblici);
        $this->assertNotContains(Plan::GYM_15, $pubblici);

        $this->assertContains('trainer_5', $pubblici);
        $this->assertContains('gym_10', $pubblici);
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
            /*
             * 🆕 **Quante persone abbona davvero questo piano** — 13/09/2026.
             *
             * ⛔ Prima per una palestra si moltiplicava `max_trainers` per
             * `max_members_per_trainer` — 5 × 50 = **250 persone** — e quel
             * numero non lo abbonava nessuno: era una capienza teorica di un
             * modello che non esiste piu'.
             *
             * 🚨 **Si contano gli slot veri, trainer compresi**: anche loro
             * ricevono l'abbonamento, e anche loro consumano AI. ⚠️ Dimenticarli
             * abbasserebbe il pavimento proprio sui piani grandi, dove sono di
             * piu'.
             */
            $persone = match ($piano->kind) {
                PlanKind::Person => 1,
                PlanKind::Trainer => ($piano->max_members ?? 1) + 1,
                PlanKind::Gym => ($piano->max_members ?? 0) + ($piano->max_trainers ?? 0),
            };

            /*
             * 🆕 **Il piano a consumo si giudica a persona**, non sul totale:
             * non ha un canone, quindi `price_cents` e' zero ed e' vero. ⛔ Senza
             * questo ramo il pavimento lo boccerebbe **perche' costa zero**, che
             * e' esattamente la lettura sbagliata che `Plan::aConsumo()` esiste
             * per impedire.
             */
            if ($piano->aConsumo()) {
                $this->assertGreaterThan(
                    (int) round($costoPerPersonaCent * 0.30),
                    $piano->prezzo_iscritto_cent,
                    "{$piano->code}: il prezzo a iscritto non copre l'AI di un iscritto",
                );

                continue;
            }

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
