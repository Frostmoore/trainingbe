<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AuditAction;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Una riga del registro delle azioni sensibili.
 *
 * Usa `BelongsToTenant` come tutti: dentro una palestra si vedono le proprie
 * righe, e il pannello di piattaforma — che gira senza contesto — le vede
 * tutte. Il trait riempie anche `tenant_id` alla creazione, ma `AuditLogger` lo
 * passa comunque esplicito: un'azione del super admin sull'utente di una
 * palestra avviene **senza contesto**, e senza passarlo la riga finirebbe
 * globale, cioe' invisibile proprio alla palestra che ha il diritto di sapere.
 *
 * 🚨 **Immutabile per costruzione.** `$guarded = []` con `updated_at` assente e
 * `save()` bloccato dopo la creazione: una riga di audit modificabile non vale
 * niente come prova.
 */
class AuditLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'action' => AuditAction::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Nessun aggiornamento e nessuna cancellazione: se un giorno servisse
        // davvero cancellare (conservazione dei dati), si fa con una migration
        // dichiarata, non da un punto qualsiasi del codice.
        static::updating(fn (): bool => false);
        static::deleting(fn (): bool => false);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Chi ha agito, leggibile anche se l'utente non esiste piu'. */
    public function actorName(): string
    {
        return $this->actor_label ?? $this->actor_email ?? 'sistema';
    }

    public function scopeOfAction(Builder $query, AuditAction $action): Builder
    {
        return $query->where('action', $action->value);
    }
}
