<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlanKind;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Il listino — F4.1 della Parte B (D4), esteso a tre livelli in G1 (D5-D8).
 *
 * 🚨 **Il pavimento del prezzo è già misurato**: `STIMA-COSTI-AI.md` dice
 * ≈ 1,15 $/mese per utente attivo che usa l'AI, ≈ 57 $/mese per una palestra da
 * 50 iscritti. Qualunque piano **con** AI deve stare sopra quel numero, e il
 * gratuito **senza** AI ci sta sotto per costruzione.
 *
 * 💡 È la ragione per cui B2 («il gratuito non ha l'AI») non è solo una scelta
 * commerciale: è ciò che rende il gratuito sostenibile per sempre invece che
 * fino al primo mese in cui qualcuno lo usa davvero.
 *
 * ── 📐 Come sono tarate le quote (D6, D7) ─────────────────────────────────
 *
 * Da `STIMA-COSTI-AI.md` §4, l'utente **medio** fa ≈ 10,6 chiamate al giorno di
 * cui ≈ 1 con foto: ≈ 320 chiamate al mese, ≈ 30 con foto. Il tetto è
 * **400 di cui 40 con foto** — sopra il medio, sotto il pesante — e costa nel
 * caso peggiore:
 *
 *     360 × 0,0024 $ + 40 × 0,0146 $ ≈ 1,45 $/mese per persona
 *
 * ⚠️ **I prezzi restano segnaposto**, e vanno decisi dal committente prima di
 * pubblicare il sito (F9). Ciò che **non** è un segnaposto è `ai_enabled`:
 * quello è il requisito B2 ed è definitivo. Le quote nemmeno: sono ricavate dai
 * consumi misurati, e cambiarle è una decisione commerciale, non un ritocco.
 *
 * ── ⚠️ Due piani ritirati, e perché non cancellati ────────────────────────
 *
 * `trainer_pro` e `gym` avevano **nessun limite** di allievi a prezzo fisso. Con
 * la quota per allievo (D8) il costo per noi cresce con gli allievi mentre il
 * prezzo sta fermo: sono piani che perdono soldi tanto più quanto hanno
 * successo.
 *
 * 🚨 **Restano in tabella**: `plan_subscriptions` punta a `plans.id`, e
 * cancellarli lascerebbe senza piano chi ce l'ha — cioè, nella fotografia del
 * 13/08/2026, entrambe le palestre vere. `is_public = false` li toglie dal
 * listino senza togliere niente a nessuno.
 *
 * 🚨 **`updateOrCreate` per codice.** Rilanciare il seeder non deve creare
 * doppioni né azzerare gli abbonamenti in corso.
 */
class PlanSeeder extends Seeder
{
    /**
     * Il tetto di chiamate mensili per persona, e quante possono essere foto.
     *
     * 💡 Sono le stesse per tutti i piani a pagamento **di proposito**: quello
     * che cambia fra un tier e l'altro è **quante persone** si possono seguire,
     * non quanto può usare ciascuna. Un trainer che passa al tier superiore
     * compra più allievi, non allievi migliori — ed è più facile da spiegare e
     * da fatturare.
     */
    private const CHIAMATE = 400;

    private const CHIAMATE_FOTO = 40;

    public function run(): void
    {
        foreach ($this->listino() as $piano) {
            Plan::updateOrCreate(['code' => $piano['code']], $piano);
        }
    }

    /** @return list<array<string, mixed>> */
    private function listino(): array
    {
        return [
            // ── Persona ────────────────────────────────────────────────────
            [
                'code' => Plan::FREE,
                'name' => 'Gratuito',
                'kind' => PlanKind::Person,
                // 🚨 Il `false` che regge il modello commerciale. Vedi sopra.
                'ai_enabled' => false,
                'ai_monthly_tokens_per_member' => null,
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
                'ai_monthly_tokens_per_member' => null,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                'price_cents' => 499,
                'is_public' => true,
            ],

            // ── Trainer indipendente — il tier è il numero di allievi (D5) ──
            [
                'code' => Plan::TRAINER_FREE,
                'name' => 'Trainer — gratuito',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => false,
                'max_members' => 3,
                'ai_monthly_calls_per_member' => null,
                'ai_monthly_photo_calls_per_member' => null,
                'price_cents' => 0,
                'is_public' => true,
            ],
            [
                'code' => Plan::TRAINER_10,
                'name' => 'Trainer — 10 allievi',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => true,
                'max_members' => 10,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                // Costo nel caso peggiore: 10 × 1,45 $ ≈ 14,5 $.
                'price_cents' => 2999,
                'is_public' => true,
            ],
            [
                'code' => Plan::TRAINER_30,
                'name' => 'Trainer — 30 allievi',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => true,
                'max_members' => 30,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                // Costo nel caso peggiore: 30 × 1,45 $ ≈ 43,5 $.
                'price_cents' => 6999,
                'is_public' => true,
            ],
            [
                'code' => Plan::TRAINER_PRO,
                'name' => 'Trainer — pro (ritirato)',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => true,
                // ⚠️ `null` = allievi illimitati. È la ragione del ritiro.
                'max_members' => null,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                'price_cents' => 1999,
                // 🚨 Fuori dal listino, dentro la tabella. Vedi la nota in testa.
                'is_public' => false,
            ],

            // ── Palestra — il tier è il numero di trainer (D5) ──────────────
            [
                'code' => Plan::GYM_5,
                'name' => 'Palestra — 5 trainer',
                'kind' => PlanKind::Gym,
                'ai_enabled' => true,
                // ⚠️ NON `max_members`: per una palestra il limite è per trainer.
                'max_trainers' => 5,
                'max_members_per_trainer' => 40,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                /*
                 * 5 × 40 = 200 persone. Pavimento misurato: ≈ 87 €/mese di AI
                 * al 30% di utilizzo pieno.
                 *
                 * ⚠️ Il segnaposto era 99,99 € — sopra il pavimento di appena
                 * il 13%. Alzato a 199,99 € per avere il margine che hanno i
                 * tier trainer. Vedi la nota di `every_paid_plan_stays_above_
                 * the_measured_cost`.
                 */
                'price_cents' => 19999,
                'is_public' => true,
            ],
            [
                'code' => Plan::GYM_15,
                'name' => 'Palestra — 15 trainer',
                'kind' => PlanKind::Gym,
                'ai_enabled' => true,
                'max_trainers' => 15,
                'max_members_per_trainer' => 40,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                /*
                 * 🚨 **Qui il test ha trovato un prezzo che perdeva soldi.**
                 *
                 * 15 × 40 = 600 persone, pavimento ≈ 261 €/mese di AI: il
                 * segnaposto era **249,99 €**, cioe' **sotto il costo**. Non
                 * sarebbe stato un errore visibile da nessuna parte — nel
                 * codice tutto tornava, nei dati pure. Si sarebbe visto solo
                 * nella fattura del fornitore, mesi dopo, sul cliente piu'
                 * grande.
                 *
                 * ⚠️ Il numero resta un segnaposto commerciale: quello che il
                 * test difende non e' il prezzo, e' il fatto che stia sopra il
                 * costo misurato.
                 */
                'price_cents' => 49999,
                'is_public' => true,
            ],
            [
                'code' => Plan::GYM,
                'name' => 'Palestra (ritirato)',
                'kind' => PlanKind::Gym,
                'ai_enabled' => true,
                // ⚠️ Nessun limite di trainer né di allievi: la ragione del ritiro.
                'max_trainers' => null,
                'max_members_per_trainer' => null,
                'ai_monthly_calls_per_member' => self::CHIAMATE,
                'ai_monthly_photo_calls_per_member' => self::CHIAMATE_FOTO,
                'price_cents' => 4999,
                'is_public' => false,
            ],
        ];
    }
}
