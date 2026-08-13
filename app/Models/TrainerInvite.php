<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * L'invito di un trainer indipendente a **una persona** — F6.2.
 *
 * 🚨 Usa `BelongsToTenant`: senza, un trainer vedrebbe gli inviti di tutti gli
 * altri — cioè gli indirizzi email dei clienti dei suoi concorrenti.
 */
class TrainerInvite extends Model
{
    use BelongsToTenant;

    /** Quanto vive un invito. Una settimana: il tempo di leggere un messaggio. */
    public const GIORNI_DI_VITA = 7;

    protected $fillable = ['tenant_id', 'trainer_id', 'token', 'email', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    public function accettatoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }

    /**
     * 🚨 **Le tre condizioni, in un posto solo.**
     *
     * Ripeterle nei controller vorrebbe dire che il giorno in cui se ne aggiunge
     * una quarta bisogna ricordarsi di tutti i punti — e uno dimenticato è un
     * invito che continua a funzionare quando non dovrebbe.
     */
    public function eValido(): bool
    {
        return $this->used_at === null
            && $this->revoked_at === null
            && $this->expires_at->isFuture();
    }

    public function scopeValidi(Builder $query): Builder
    {
        return $query
            ->whereNull('used_at')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now());
    }

    /**
     * Un segreto lungo, non un codice da dettare al telefono.
     *
     * ⚠️ Al contrario di `Tenant::generateJoinCode()`, qui **non** si tolgono i
     * caratteri ambigui: questo non si legge ad alta voce, viaggia in un link.
     * Toglierli ridurrebbe l'alfabeto — cioè la difesa — per un problema che
     * questo codice non ha.
     */
    public static function generaToken(): string
    {
        do {
            $token = Str::random(32);
        } while (static::withoutGlobalScopes()->where('token', $token)->exists());

        return $token;
    }
}
