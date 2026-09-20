<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ✍️ Una liberatoria firmata — art. 28 GDPR, 20/09/2026.
 *
 * ⚠️ **Non usa `BelongsToTenant`**, ed è voluto: la firma segue **la persona**,
 * non la palestra. Un trainer che cambia palestra ha già firmato, e il suo atto
 * non deve sparire dalla vista solo perché il contesto è cambiato.
 */
class LiberatoriaFirmata extends Model
{
    protected $table = 'liberatorie_firmate';

    protected $fillable = [
        'user_id',
        'tenant_id',
        'tipo',
        'versione',
        'accettata_il',
        'ip',
        'user_agent',
    ];

    protected function casts(): array
    {
        return ['accettata_il' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
