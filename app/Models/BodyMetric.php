<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Peso e misure di una persona in un giorno.
 *
 * Una riga al giorno per persona (vincolo `UNIQUE`): la seconda misura dello
 * stesso giorno e' una correzione, non un dato nuovo. Senza il vincolo, un
 * grafico del peso su un utente che si pesa due volte mostrerebbe un'oscillazione
 * che non e' successa.
 */
class BodyMetric extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'user_id', 'date',
        'weight_kg', 'body_fat_pct', 'waist_cm', 'chest_cm', 'arm_cm', 'thigh_cm',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'weight_kg' => 'float',
            'body_fat_pct' => 'float',
            'waist_cm' => 'float',
            'chest_cm' => 'float',
            'arm_cm' => 'float',
            'thigh_cm' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        return $query->where('user_id', $user instanceof User ? $user->getKey() : $user);
    }

    /**
     * L'ultimo peso noto di una persona.
     *
     * Sta qui e non in `User` perche' la conoscenza e' di questa tabella: se un
     * giorno il peso arrivasse anche da una bilancia collegata, il posto da
     * cambiare resterebbe uno solo.
     */
    public static function latestWeightFor(User|int $user): ?float
    {
        $riga = static::query()
            ->forUser($user)
            ->whereNotNull('weight_kg')
            ->orderByDesc('date')
            ->first();

        return $riga?->weight_kg;
    }
}
