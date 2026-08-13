<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PlanKind;
use App\Models\Plan;
use Illuminate\Database\Seeder;

/**
 * Il listino — F4.1 della Parte B, decisione D4.
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
 * ⚠️ **I prezzi qui sono segnaposto**, e vanno decisi dal committente prima di
 * pubblicare il sito (F9). Ciò che **non** è un segnaposto è `ai_enabled`:
 * quello è il requisito B2, ed è già definitivo.
 *
 * 🚨 **`updateOrCreate` per codice.** Rilanciare il seeder non deve creare
 * doppioni né azzerare gli abbonamenti in corso, che puntano a `plans.id`.
 */
class PlanSeeder extends Seeder
{
    public function run(): void
    {
        $piani = [
            [
                'code' => Plan::FREE,
                'name' => 'Gratuito',
                'kind' => PlanKind::Person,
                // 🚨 Il `false` che regge il modello commerciale. Vedi sopra.
                'ai_enabled' => false,
                'ai_monthly_tokens_per_member' => null,
                'price_cents' => 0,
            ],
            [
                'code' => Plan::PLUS,
                'name' => 'Plus',
                'kind' => PlanKind::Person,
                'ai_enabled' => true,
                // ⚠️ `null` = «non lo decide il piano»: si scende al default di
                // sistema. Metterci un numero qui vorrebbe dire cambiare il
                // tetto di tutti i Plus con una migrazione invece che con una
                // riga di configurazione.
                'ai_monthly_tokens_per_member' => null,
                'price_cents' => 499,
            ],
            [
                'code' => Plan::TRAINER_FREE,
                'name' => 'Trainer — gratuito',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => false,
                'max_members' => 3,
                'price_cents' => 0,
            ],
            [
                'code' => Plan::TRAINER_PRO,
                'name' => 'Trainer — pro',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => true,
                // `null` = utenti illimitati (F6.5).
                'max_members' => null,
                'price_cents' => 1999,
            ],
            [
                'code' => Plan::GYM,
                'name' => 'Palestra',
                'kind' => PlanKind::Gym,
                'ai_enabled' => true,
                'price_cents' => 4999,
            ],
        ];

        foreach ($piani as $piano) {
            Plan::updateOrCreate(['code' => $piano['code']], $piano);
        }
    }
}
