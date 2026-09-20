<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlanKind;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Il listino, seminato **da `config/listino.php`** — rifatto il 13/09/2026.
 *
 * ══ ✅ UN SOLO POSTO IN CUI STANNO I NUMERI ═══════════════════════════════
 *
 * ⛔ **Fino al 12/09 i listini erano due**: `config/listino.php` lo leggeva solo
 * il sito, e questa classe — con numeri diversi e un'unità di misura diversa —
 * governava tutti i cancelli. Una palestra leggeva *«25 posti a 109,90»*,
 * comprava, e finiva su un piano da **349,99** che contava i **trainer**.
 *
 * 🚨 Non era un difetto che desse errore: erano **due promesse diverse**, e non
 * c'era nessun modo di accorgersene se non da un cliente.
 *
 * ✅ Adesso i numeri stanno **solo** in configurazione, e questa classe li
 * trascrive in tabella. Cambiare un prezzo è cambiare una riga di quel file.
 *
 * ══ 🚨 UN PIANO NON SI CANCELLA MAI: SI RITIRA ════════════════════════════
 *
 * `plan_subscriptions.plan_id` punta a `plans.id`: cancellare un piano
 * lascerebbe **senza piano** chi ce l'ha. ⛔ `is_public = false` lo toglie dal
 * listino senza togliere niente a nessuno.
 *
 * 💡 È la stessa regola già applicata a `trainer_pro` e a `gym` in G1, e dal
 * 13/09 vale anche per `trainer_free`, `gym_5` e `gym_15`.
 *
 * ══ 📌 IL MODELLO, DECISO IL 13/09/2026 ═══════════════════════════════════
 *
 * *«Ogni piano per le palestre ha un tot di slot per trainer e iscritti. Quegli
 * iscritti riceveranno un abbonamento in omaggio, e i trainer potranno agire
 * come trainer ricevendo l'abbonamento trainer gratuitamente.»*
 *
 * 🚨 **Due tetti, e valgono tutti e due.** Per un trainer indipendente il piano
 * ha **solo** slot allievo: *«il trainer sarà solo lui»*.
 */
class PlanSeeder extends Seeder
{
    /**
     * Le chiamate AI comprese in un abbonamento, per persona e per mese.
     *
     * 💡 È **la quota dell'abbonamento**, non del piano di chi lo regala: un
     * iscritto coperto da una palestra ha lo stesso tetto di un abbonato
     * singolo. ⛔ Le funzioni **a richiesta** non stanno qui: si pagano a
     * gettoni.
     */
    private const CHIAMATE = 150;

    private const CHIAMATE_FOTO = 15;

    public function run(): void
    {
        foreach ($this->listino() as $piano) {
            Plan::updateOrCreate(['code' => $piano['code']], $piano);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listino(): array
    {
        return array_merge(
            $this->persone(),
            $this->trainer(),
            $this->palestre(),
            $this->ritirati(),
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function persone(): array
    {
        return [
            [
                'code' => Plan::FREE,
                'name' => 'Gratuito',
                'kind' => PlanKind::Person,
                'ai_enabled' => false,
                'ai_monthly_calls_per_member' => null,
                'ai_monthly_photo_calls_per_member' => null,
                'price_cents' => 0,
                'is_public' => true,
            ],
            [
                'code' => Plan::PLUS,
                'name' => 'Plus',
                'kind' => PlanKind::Person,
                'ai_enabled' => true,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,

                /*
                 * 💡 **Il perno del listino.** È il numero che chiunque può
                 * vedere e con cui chiunque fa il confronto: ogni slot di un
                 * piano deve costare **meno** di questo, o alla palestra
                 * conviene dire ai suoi iscritti «abbonatevi da soli».
                 */
                'price_cents' => (int) config('listino.singolo_cent'),
                'is_public' => true,
            ],
        ];
    }

    /**
     * I piani da trainer indipendente: **solo slot allievo**.
     *
     * @return array<int, array<string, mixed>>
     */
    private function trainer(): array
    {
        $piani = array_map(
            fn (array $p): array => [
                'code' => $p['codice'],
                'name' => $p['nome'],
                'kind' => PlanKind::Trainer,
                'ai_enabled' => true,
                'max_members' => $p['allievi'],

                /*
                 * ⛔ **`max_trainers` resta `null` e non è una dimenticanza**:
                 * nel piano di un trainer indipendente il trainer è **lui**, e
                 * non ce ne sono altri da contare.
                 */
                'max_trainers' => null,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                'price_cents' => $p['prezzo_cent'],
                'is_public' => true,
            ],
            config('listino.trainer.piani'),
        );

        /*
         * 🆕 **Anche i trainer hanno il loro «su misura»** — 20/09/2026.
         *
         * 📌 *«Pacchetto Personalizzato (min. 30 allievi): 2,80€/mese per
         * allievo + 10€/mese»*. ⛔ Prima esisteva solo per le palestre.
         */
        $misura = config('listino.trainer.a_consumo');

        $piani[] = [
            'code' => $misura['codice'],
            'name' => $misura['nome'],
            'kind' => PlanKind::Trainer,
            'ai_enabled' => true,
            'max_members' => null,
            'max_trainers' => null,

            /*
             * 🚨 `price_cents = 0` **non vuol dire gratis**: il canone fisso sta
             * in `canone_cent`, e quello che si paga davvero dipende da quanti
             * allievi si seguono. Chi mostra un prezzo chiede prima
             * `Plan::aConsumo()`.
             */
            'price_cents' => 0,
            'prezzo_iscritto_cent' => $misura['prezzo_iscritto_cent'],
            'canone_cent' => $misura['canone_cent'],
            'minimo_iscritti' => $misura['minimo_iscritti'],

            'ai_monthly_calls_per_member' => self::CHIAMATE,
            'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
            'is_public' => true,
        ];

        return $piani;
    }

    /**
     * I piani da palestra: **due tetti**, più quello a consumo.
     *
     * @return array<int, array<string, mixed>>
     */
    private function palestre(): array
    {
        $piani = array_map(
            fn (array $p): array => [
                'code' => $p['codice'],
                'name' => $p['nome'],
                'kind' => PlanKind::Gym,
                'ai_enabled' => true,

                /*
                 * 🚨 **Tutti e due, e tutti e due veri.** Fino al 12/09
                 * `max_members` era `null` su ogni piano palestra, quindi gli
                 * iscritti erano **infiniti**: si vendevano scaglioni di
                 * trainer e si regalava il resto.
                 */
                'max_members' => $p['iscritti'],
                'max_trainers' => $p['trainer'],

                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                'price_cents' => $p['prezzo_cent'],
                'is_public' => true,
            ],
            config('listino.palestre.piani'),
        );

        $consumo = config('listino.palestre.a_consumo');

        $piani[] = [
            'code' => $consumo['codice'],
            'name' => $consumo['nome'],
            'kind' => PlanKind::Gym,
            'ai_enabled' => true,

            /*
             * ⚠️ **Nessun tetto sugli iscritti, ed è il punto del piano**: si
             * paga per ognuno a cui si regala l'abbonamento, con un minimo.
             *
             * 🔄 **I trainer invece un tetto ce l'hanno** dal 21/09/2026: erano
             * illimitati, e illimitato più non fatturato voleva dire che una
             * palestra poteva registrare gli iscritti come trainer e pagare il
             * minimo invece del dovuto. 💡 Sopra il tetto si concorda: c'è il
             * modulo di contatto, e dal pannello god si cuce un piano apposta.
             */
            'max_members' => null,
            'max_trainers' => $consumo['trainer'],

            /*
             * 🚨 **`price_cents = 0` e non è gratis.** Un piano a consumo non ha
             * un canone: quello che si paga sta in `prezzo_iscritto_cent`, e
             * chi legge solo `price_cents` deve trovarci **zero canone**, non un
             * numero inventato.
             */
            'price_cents' => 0,
            'prezzo_iscritto_cent' => $consumo['prezzo_iscritto_cent'],
            'canone_cent' => $consumo['canone_cent'],
            'minimo_iscritti' => $consumo['minimo_iscritti'],

            'ai_monthly_calls_per_member' => self::CHIAMATE,
            'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
            'is_public' => true,
        ];

        return $piani;
    }

    /**
     * ⚠️ **I ritirati si seminano lo stesso.**
     *
     * 🚨 Un'installazione nuova deve avere in tabella anche i piani che nessuno
     * può più comprare: senza, un ripristino da backup di un abbonamento vecchio
     * troverebbe un `plan_id` che non esiste. 💡 Il prezzo e la capienza sono
     * quelli con cui furono venduti, e non si toccano.
     *
     * @return array<int, array<string, mixed>>
     */
    private function ritirati(): array
    {
        return [
            [
                'code' => Plan::TRAINER_FREE,
                'name' => 'Trainer — gratuito (ritirato)',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => false,
                'max_members' => 3,
                'ai_monthly_calls_per_member' => null,
                'ai_monthly_photo_calls_per_member' => null,
                'price_cents' => 0,
                'is_public' => false,
            ],
            [
                'code' => Plan::TRAINER_10,
                'name' => 'Trainer — 10 allievi (ritirato)',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => true,
                'max_members' => 10,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                'price_cents' => 2999,
                'is_public' => false,
            ],
            [
                'code' => Plan::TRAINER_PRO,
                'name' => 'Trainer — pro (ritirato)',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => true,
                'max_members' => null,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                'price_cents' => 1999,
                'is_public' => false,
            ],
            [
                'code' => Plan::GYM_5,
                'name' => 'Palestra — 5 trainer (ritirato)',
                'kind' => PlanKind::Gym,
                'ai_enabled' => true,
                'max_trainers' => 5,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                'price_cents' => 34999,
                'is_public' => false,
            ],
            [
                'code' => Plan::GYM_15,
                'name' => 'Palestra — 15 trainer (ritirato)',
                'kind' => PlanKind::Gym,
                'ai_enabled' => true,
                'max_trainers' => 15,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                'price_cents' => 99999,
                'is_public' => false,
            ],
            [
                'code' => Plan::GYM,
                'name' => 'Palestra (ritirato)',
                'kind' => PlanKind::Gym,
                'ai_enabled' => true,
                'max_trainers' => null,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                'price_cents' => 4999,
                'is_public' => false,
            ],
        ];
    }
}
