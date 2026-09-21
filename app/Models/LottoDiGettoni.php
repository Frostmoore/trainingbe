<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ⏳ **Gettoni regalati che scadono** — 24/09/2026, fase P2 di `plan_promozioni.md`.
 *
 * Esiste solo per i gettoni con una scadenza (§4.5 del piano). ⚠️ Non è il
 * saldo: il saldo resta `users.ai_credits`. Il lotto dice **quanti di quei
 * gettoni** sono ancora di questo regalo, e quindi quanti se ne tolgono a
 * scadenza.
 *
 * 🚨 L'invariante: la somma dei `gettoni_restanti` aperti di una persona non
 * supera mai il suo saldo — la tiene `PortafoglioGettoni`.
 */
class LottoDiGettoni extends Model
{
    protected $table = 'lotti_di_gettoni';

    protected $fillable = [
        'user_id', 'promozione_assegnazione_id', 'gettoni_iniziali', 'gettoni_restanti', 'scade_il', 'chiuso_il',
    ];

    protected function casts(): array
    {
        return [
            'gettoni_iniziali' => 'integer',
            'gettoni_restanti' => 'integer',
            'scade_il' => 'datetime',
            'chiuso_il' => 'datetime',
        ];
    }

    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')->withoutGlobalScopes([TenantScope::class]);
    }

    public function assegnazione(): BelongsTo
    {
        return $this->belongsTo(PromozioneAssegnazione::class, 'promozione_assegnazione_id');
    }

    /** Quelli ancora aperti: con gettoni dentro, non chiusi. */
    public function scopeAperti(Builder $query): Builder
    {
        return $query->whereNull('chiuso_il')->where('gettoni_restanti', '>', 0);
    }
}
