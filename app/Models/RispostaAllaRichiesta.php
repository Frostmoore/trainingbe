<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ✉️ Una risposta mandata a chi ci ha scritto dal sito — F9.3, 23/09/2026.
 *
 * ⚠️ Nessun tenant: le richieste dal sito sono della **piattaforma**, e le vede
 * solo il super admin. Muore con la sua richiesta (vedi la migrazione).
 */
class RispostaAllaRichiesta extends Model
{
    protected $table = 'risposte_alle_richieste';

    protected $fillable = ['richiesta_di_contatto_id', 'scritta_da', 'oggetto', 'testo', 'mandata'];

    protected function casts(): array
    {
        return ['mandata' => 'boolean'];
    }

    public function richiesta(): BelongsTo
    {
        return $this->belongsTo(RichiestaDiContatto::class, 'richiesta_di_contatto_id');
    }

    public function autore(): BelongsTo
    {
        return $this->belongsTo(User::class, 'scritta_da');
    }
}
