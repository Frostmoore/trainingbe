<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Scopes\TenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ✂️ **Un periodo in cui la palestra ha tolto l'abbonamento a un iscritto** —
 * 23/09/2026, F5.
 *
 * ⚠️ **Si registra il «tolto», non il «dato»**: nessuna riga = coperto. Il
 * perché per esteso sta nella migrazione — in breve, una tabella delle
 * coperture andrebbe scritta a **ogni** ingresso in palestra, e basta
 * dimenticarne uno perché un iscritto nuovo risulti senza abbonamento.
 *
 * 💡 Lo governa `AbbonamentiDegliIscritti`; qui c'è solo la forma.
 */
class AbbonamentoTolto extends Model
{
    use BelongsToTenant;

    protected $table = 'abbonamenti_tolti';

    protected $fillable = [
        'tenant_id', 'user_id', 'dal', 'al', 'tolto_da_id', 'ridato_da_id',
    ];

    protected function casts(): array
    {
        return [
            'dal' => 'datetime',
            'al' => 'datetime',
        ];
    }

    public function iscritto(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id')
            ->withoutGlobalScopes([TenantScope::class]);
    }

    /**
     * I periodi ancora aperti: **l'abbonamento è tolto adesso**.
     *
     * @param  Builder<AbbonamentoTolto>  $query
     */
    public function scopeAperti(Builder $query): void
    {
        $query->whereNull('al');
    }
}
