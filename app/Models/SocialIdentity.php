<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SocialProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il collegamento fra un account esterno e una persona.
 *
 * 🚨 **Nessun global scope di tenant, ed e' voluto.** La ricerca per
 * `(provider, provider_user_id)` avviene **prima** che esista una sessione,
 * quando non si sa ancora di quale palestra si parli: uno scope filtrerebbe su
 * un contesto vuoto e non troverebbe mai niente, facendo creare un account nuovo
 * a ogni accesso. Il tenant si ricava dopo, da `user->tenant_id`, che e' l'unica
 * verita' (ADR-04).
 */
class SocialIdentity extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'provider_user_id', 'email', 'name', 'last_login_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => SocialProvider::class,
            'last_login_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
