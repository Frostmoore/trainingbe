<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il token di un telefono per le notifiche push — B8.5.
 *
 * `tenant_id` e' nullable soltanto perche' lo e' anche quello dell'utente (il
 * super admin non ha palestra), ma nella pratica un token nasce sempre dentro
 * una palestra: e' l'app degli iscritti a mandarlo.
 */
class DeviceToken extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'user_id', 'token', 'platform', 'last_used_at'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
