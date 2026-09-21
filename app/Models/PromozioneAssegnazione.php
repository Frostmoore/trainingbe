<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrigineAssegnazione;
use App\Enums\StatoAssegnazione;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🧾 **Una persona che ha preso una promozione** — 24/09/2026, fase P1.
 *
 * Il massimo per persona, il budget, i numeri e la revoca si contano da qui
 * (§4.3 del piano).
 *
 * ⚠️ **Non usa `BelongsToTenant`**, come `Promozione`: le assegnazioni si
 * leggono dal pannello di piattaforma, dove un contesto di tenant non c'è.
 *
 * ⛔ **Non si cancella**: si revoca. Chi ha avuto uno sconto ha una ricevuta
 * che lo nomina, e quella ricevuta deve restare spiegabile.
 */
class PromozioneAssegnazione extends Model
{
    protected $table = 'promozione_assegnazioni';

    protected $fillable = [
        'promozione_id', 'user_id', 'tenant_id', 'stato', 'origine', 'riferimento',
        'gettoni', 'scontato_cent', 'plan_subscription_id', 'scade_il', 'prossimo_accredito_il',
        'usata_il', 'revocata_il', 'revocata_da_id',
    ];

    protected $attributes = [
        'stato' => 'assegnata',
        'gettoni' => 0,
        'scontato_cent' => 0,
    ];

    protected function casts(): array
    {
        return [
            'stato' => StatoAssegnazione::class,
            'origine' => OrigineAssegnazione::class,
            'gettoni' => 'integer',
            'scontato_cent' => 'integer',
            'scade_il' => 'datetime',
            'prossimo_accredito_il' => 'datetime',
            'usata_il' => 'datetime',
            'revocata_il' => 'datetime',
        ];
    }

    public function promozione(): BelongsTo
    {
        return $this->belongsTo(Promozione::class);
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScopes([TenantScope::class]);
    }

    public function revocataDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revocata_da_id')->withoutGlobalScopes([TenantScope::class]);
    }

    /** Quelle che contano per i limiti e per i numeri: né revocate né scadute. */
    public function scopeCheContano(Builder $query): Builder
    {
        return $query->whereIn('stato', StatoAssegnazione::cheContano());
    }
}
