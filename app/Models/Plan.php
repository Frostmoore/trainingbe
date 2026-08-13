<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanKind;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un piano del listino — F4.1.
 *
 * ⚠️ **NON usa `BelongsToTenant`**, come `Tenant`: il listino è della
 * piattaforma, non di un cliente. Se avesse `tenant_id`, `TenantIsolationTest`
 * pretenderebbe il trait — ed è giusto che lo pretenda, quindi la colonna non
 * c'è proprio.
 *
 * 🚨 **I codici sono la fonte di verità, i nomi no.** «Plus» un giorno si
 * chiamerà «Pro» perché lo decide il marketing; `plans.code = 'plus'` resta.
 * Nel codice e nei test si cerca sempre per `code`.
 */
class Plan extends Model
{
    /** Il gratuito: è il piano di chi non ne ha scelto nessuno. */
    public const FREE = 'free';

    /** Persona che paga: stima da testo e da foto, consiglio del giorno. */
    public const PLUS = 'plus';

    public const TRAINER_FREE = 'trainer_free';

    public const TRAINER_PRO = 'trainer_pro';

    public const GYM = 'gym';

    protected $fillable = [
        'code', 'name', 'kind', 'ai_enabled',
        'ai_monthly_tokens_per_member', 'max_members', 'price_cents', 'is_public',
    ];

    protected $attributes = [
        // ⚠️ Come su `Tenant`: i default del DB si applicano all'INSERT, non
        // all'oggetto in memoria. Senza, `Plan::make()->ai_enabled` sarebbe
        // `null` — e `null` su un cancello di sicurezza è un `false` che sembra
        // un errore di configurazione invece di una decisione.
        'ai_enabled' => false,
        'kind' => 'person',
        'price_cents' => 0,
        'is_public' => true,
    ];

    protected function casts(): array
    {
        return [
            'kind' => PlanKind::class,
            'ai_enabled' => 'boolean',
            'is_public' => 'boolean',
            'ai_monthly_tokens_per_member' => 'integer',
            'max_members' => 'integer',
            'price_cents' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(PlanSubscription::class);
    }

    public function scopePubblici(Builder $query): Builder
    {
        return $query->where('is_public', true);
    }

    public function scopeDiTipo(Builder $query, PlanKind $kind): Builder
    {
        return $query->where('kind', $kind->value);
    }

    /** Il prezzo in euro, per il listino. */
    public function prezzoEuro(): float
    {
        return $this->price_cents / 100;
    }

    public function eGratuito(): bool
    {
        return $this->price_cents === 0;
    }
}
