<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un movimento del portafoglio gettoni — G2, D16.
 *
 * 🚨 **Il registro esiste perche' i soldi si contestano.** Un saldo che si puo'
 * solo ricalcolare sommando tutto non si puo' discutere: il giorno in cui un
 * cliente dice «mi mancano dei gettoni», serve poter dire quanti ne aveva **a
 * quel movimento li'**, e quale chiamata li ha consumati.
 *
 * ⚠️ **Le righe non si modificano e non si cancellano.** Un registro
 * riscrivibile non e' un registro: e' un'opinione. Una rettifica e' un
 * movimento **nuovo** con causale `rettifica`, non una `update()` su una riga
 * vecchia — cosi' resta scritto anche che qualcuno ha corretto, e quando.
 */
class AiCreditMovement extends Model
{
    use BelongsToTenant;

    public const ACQUISTO = 'acquisto';

    public const CONSUMO = 'consumo';

    public const RETTIFICA = 'rettifica';

    protected $fillable = [
        'tenant_id', 'user_id', 'delta', 'saldo_dopo', 'causale',
        'ai_usage_log_id', 'operatore_id', 'nota',
    ];

    protected function casts(): array
    {
        return [
            'delta' => 'integer',
            'saldo_dopo' => 'integer',
        ];
    }

    /**
     * 🆕 **Di chi sono questi gettoni** — 16/09/2026.
     *
     * 📌 Il committente: *«ovvio che i gettoni sono soltanto di chi li
     * compra»*. ⛔ Fino al 15/09 il proprietario era il **tenant**, e il
     * registro non sapeva dire di chi fosse un gettone.
     */
    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withoutGlobalScopes([TenantScope::class]);
    }

    /**
     * ⚠️ **Dove e' successo**, non di chi e'.
     *
     * 💡 Serve a ricostruire una storia — in quale palestra stava quella
     * persona quando li ha spesi — e resta apposta: il proprietario e'
     * `utente()`.
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** La chiamata che ha consumato questi gettoni. `null` per gli accrediti. */
    public function usageLog(): BelongsTo
    {
        return $this->belongsTo(AiUsageLog::class, 'ai_usage_log_id');
    }

    /** Chi ha fatto l'accredito a mano. `null` per i consumi. */
    public function operatore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'operatore_id');
    }

    public function eUnAccredito(): bool
    {
        return $this->delta > 0;
    }
}
