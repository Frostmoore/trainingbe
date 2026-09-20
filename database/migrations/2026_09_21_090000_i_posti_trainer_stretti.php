<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🚨 **I posti trainer, stretti** — 21/09/2026.
 *
 * 📌 Il committente: *«non ha senso così. Dobbiamo rivedere il numero massimo di
 * trainers per ciascun piano: Palestra basic 2, Advanced 4, Pro 6, su misura
 * 20»*.
 *
 * ── ⛔ Perché erano sbagliati ──────────────────────────────────────────────
 *
 * Perché **un posto trainer è un posto iscritto che non si fattura**:
 * `PianoAttivo::haLaAi()` guarda il piano del **tenant**, quindi trainer e
 * titolare hanno l'AI inclusa come tutti; `ContoDegliIscritti::quanti()` conta
 * **solo** `UserRole::Member`.
 *
 * | | prima | adesso | in regalo |
 * |---|---|---|---|
 * | Basic | 10 iscritti, **5** trainer | 2 | 50% → **20%** |
 * | Advanced | 30, **10** | 4 | 33% → **13%** |
 * | Pro | 60, **20** | 6 | 33% → **10%** |
 * | Su misura | **senza tetto** | 20 | ⛔ illimitato → un numero |
 *
 * 🚨 Il su misura era il buco vero: senza tetto **e** non fatturato, una
 * palestra poteva registrare i propri iscritti come trainer e pagare il minimo
 * (183 €) invece del dovuto (297,80 € con 101 iscritti).
 *
 * ── ⚠️ Chi ha già più trainer di così non li perde ────────────────────────
 *
 * Questa migration **non tocca nessun utente**: cambia solo il tetto dei piani.
 * Una palestra che oggi ha cinque trainer sul Basic se li tiene tutti e cinque
 * — `postiTrainerRimasti()` dirà zero, e non potrà aggiungerne altri finché non
 * scende. 💡 Togliere l'accesso a gente che lavora per far tornare un conto
 * sarebbe il modo giusto di perdere un cliente.
 */
return new class extends Migration
{
    /** I tetti nuovi, scritti qui e non letti da `config/listino.php`. */
    private const TETTI = [
        'gym_10' => 2,
        'gym_30' => 4,
        'gym_60' => 6,
        'gym_consumo' => 20,
    ];

    /** Quelli vecchi, per poter tornare indietro davvero. */
    private const PRIMA = [
        'gym_10' => 5,
        'gym_30' => 10,
        'gym_60' => 20,
        'gym_consumo' => null,
    ];

    public function up(): void
    {
        foreach (self::TETTI as $codice => $quanti) {
            DB::table('plans')->where('code', $codice)->update(['max_trainers' => $quanti]);
        }
    }

    public function down(): void
    {
        foreach (self::PRIMA as $codice => $quanti) {
            DB::table('plans')->where('code', $codice)->update(['max_trainers' => $quanti]);
        }
    }
};
