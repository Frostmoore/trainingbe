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

        /*
         * 🆕 **Due, non cinque** — 21/09/2026. 📌 *«Dobbiamo rivedere il numero
         * massimo di trainers per ciascun piano: Palestra basic 2, Advanced 4,
         * Pro 6, su misura 20»*.
         *
         * ⛔ Cinque posti trainer su dieci iscritti voleva dire **metà della
         * palestra con l'AI accesa e non fatturata**: `PianoAttivo::haLaAi()`
         * guarda il piano del tenant, `ContoDegliIscritti::quanti()` conta solo
         * `UserRole::Member`.
         */
        $this->assertSame(2, $palestra->max_trainers, 'i trainer non sono contati');
    }

    /**
     * 🚨 **I posti trainer sono pochi, e crescono col piano** — 21/09/2026.
     *
     * ── ⛔ Cosa diceva prima questo test ──────────────────────────────────────
     *
     * Diceva il contrario: *«i posti trainer non sono mai meno di un terzo dei
     * posti iscritto»*, perché *«una palestra deve poter inserire più o meno
     * tutti i suoi trainer anche se vuole dare l'abbonamento a pochissimi
     * allievi»*. Con 10/5, 30/10 e 60/20 reggeva.
     *
     * 🚨 **Reggeva una regola sbagliata.** Un posto trainer non è un posto
     * gratis: è un posto **con l'AI accesa che nessuno fattura**. Il conto del
     * 20/09 (`ai:misura-i-costi`) lo ha reso visibile — col su misura senza
     * tetto una palestra da 101 persone poteva registrarle tutte come trainer e
     * pagare 183 € invece di 297,80 €.
     *
     * ── ✅ Le tre regole che il listino nuovo rispetta ────────────────────────
     *
     * 1. **Almeno un posto trainer**, sempre: una palestra senza istruttori non
     *    è una palestra, e un piano a zero non si vende.
     * 2. **Un tetto c'è sempre**, anche sul su misura: `null` è quello che ha
     *    aperto il buco. ⚠️ Il codice continua a *saper leggere* `null` (i piani
     *    cuciti a mano dal pannello god possono averlo); è il **listino
     *    pubblico** che non può più venderlo.
     * 3. **Non si scende mai salendo di prezzo**: chi paga di più non può avere
     *    meno posti di chi paga di meno. 💡 È la regola che protegge dal
     *    refuso, non dalla scelta — i numeri li decide il committente
     *    (2 / 4 / 6 / 20), la monotonia no.
     *
     * 💡 Il rapporto sugli iscritti non si prova più: era un vincolo di
     * generosità, e la generosità qui è **il costo di inferenza non fatturato**.
     * Chi ha bisogno di più istruttori scrive — c'è il modulo di contatto.
     */
    #[Test]
    public function i_posti_trainer_sono_pochi_e_crescono_col_piano(): void
    {
        /*
         * ⚠️ Ordinati per `price_cents`, con il su misura **ultimo**: il suo
         * canone di listino è 0 (si paga a iscritto), quindi l'ordinamento per
         * prezzo lo metterebbe primo e la monotonia fallirebbe per finta.
         */
        $piani = Plan::query()->pubblici()->diTipo(PlanKind::Gym)->orderBy('price_cents')->get()
            ->sortBy(fn (Plan $p): int => $p->aConsumo() ? PHP_INT_MAX : $p->price_cents)
            ->values();

        $prima = 0;

        foreach ($piani as $piano) {
            $this->assertNotNull(
                $piano->max_trainers,
                "{$piano->code}: nessun tetto ai trainer, ed è il buco che abbiamo chiuso il 21/09",
            );

            $this->assertGreaterThanOrEqual(
                1,
                $piano->max_trainers,
                "{$piano->code}: una palestra senza posti trainer non è una palestra",
            );

            $this->assertGreaterThanOrEqual(
                $prima,
                $piano->max_trainers,
                "{$piano->code}: costa di più del piano prima e offre meno posti trainer",
            );

            $prima = $piano->max_trainers;
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
     * minimo di 100»*.
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
        $this->assertNull($consumo->max_members, 'un piano a consumo non ha tetti sugli iscritti');

        /*
         * 🆕 **Venti, non «illimitati»** — 21/09/2026. 📌 *«Palestra su misura:
         * 20 trainers (tanto c'è scrivici)»*.
         *
         * 🚨 Qui `null` era il buco vero: gli iscritti li paghi uno per uno, i
         * trainer no, e senza tetto la palestra da 101 persone poteva
         * registrarle **tutte come trainer** — 183 € di minimo invece di 297,80.
         * ⚠️ Sopra venti non si dice di no: si fa un piano su misura dal
         * pannello god, coi posti che decide il committente.
         */
        $this->assertSame(20, $consumo->max_trainers, 'il su misura ha un tetto anche lui');
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

    /**
     * 🆕 🔒 **NESSUN PREZZO IN VENDITA STA NEL SEEDER** — 3b-Z.6, 24/09/2026.
     *
     * 📌 Il committente: *«lo puoi anche buttare via e indirizzare tutto a
     * listino»*. 💡 Era già così — i piani pubblici leggono da
     * `config/listino.php` — e questo test è ciò che lo **tiene** così.
     *
     * ══ ⛔ IL DIFETTO CHE RENDE IMPOSSIBILE ═════════════════════════════════
     *
     * 🚨 Scrivere un prezzo a mano nel seeder «per fare in fretta». Da quel
     * momento ci sono **due verità**: il listino che il sito mostra e il numero
     * in tabella che decide cosa si addebita. ⚠️ Non danno nessun errore, e la
     * differenza si scopre da una fattura sbagliata.
     *
     * ⚖️ **I ritirati sono esclusi di proposito**: `is_public = false`, non si
     * vendono più, e il loro prezzo è quello **con cui furono venduti**.
     * ⛔ Farli leggere dal listino vorrebbe dire che ritoccando un prezzo di
     * oggi si riscrive quanto pagò qualcuno l'anno scorso.
     */
    #[Test]
    public function i_prezzi_in_vendita_vengono_tutti_dal_listino(): void
    {
        $dalListino = [];

        foreach (config('listino.palestre.piani') as $p) {
            $dalListino[$p['codice']] = $p['prezzo_cent'];
        }

        foreach (config('listino.trainer.piani') as $p) {
            $dalListino[$p['codice']] = $p['prezzo_cent'];
        }

        /*
         * 💡 I piani a consumo e quello gratuito hanno `price_cents = 0` per
         * definizione: non hanno un canone, e quello che si paga sta altrove.
         */
        $senzaCanone = [
            Plan::FREE,
            config('listino.palestre.a_consumo.codice'),
            config('listino.trainer.a_consumo.codice'),
        ];

        $fuoriPosto = [];

        foreach (Plan::query()->where('is_public', true)->get() as $piano) {
            if (in_array($piano->code, $senzaCanone, true)) {
                continue;
            }

            if ($piano->code === Plan::PLUS) {
                // Il singolo ha la sua chiave, non sta in un elenco di pacchetti.
                $atteso = (int) config('listino.singolo_cent');
            } else {
                $atteso = $dalListino[$piano->code] ?? null;
            }

            if ($atteso === null) {
                $fuoriPosto[] = "{$piano->code}: in vendita ma NON nel listino";

                continue;
            }

            if ((int) $piano->price_cents !== $atteso) {
                $fuoriPosto[] = "{$piano->code}: in tabella {$piano->price_cents}, nel listino {$atteso}";
            }
        }

        $this->assertSame(
            [],
            $fuoriPosto,
            "Un prezzo in vendita non viene dal listino:\n".implode("\n", $fuoriPosto),
        );
    }
}
