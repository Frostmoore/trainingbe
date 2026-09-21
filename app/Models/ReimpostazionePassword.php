<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🔑 **Una richiesta di reimpostazione della password** — 22/09/2026, fase F4.
 *
 * ⚠️ **NON usa `BelongsToTenant`**: non ha `tenant_id`, e non deve averlo. Una
 * persona che cambia la password lo fa **per sé**, non dentro una palestra — e
 * chi lavora in due spazi ha un account solo.
 *
 * 🔒 `impronta` è `sha256` del token: in chiaro sarebbe una password
 * temporanea leggibile da chiunque legga il database.
 */
class ReimpostazionePassword extends Model
{
    protected $table = 'reimpostazioni_password';

    protected $fillable = ['user_id', 'impronta', 'scade_il', 'chiesto_da_id'];

    protected function casts(): array
    {
        return [
            'scade_il' => 'datetime',
            'usato_il' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }
}
