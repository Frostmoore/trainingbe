<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una persona ha visto una scheda sponsorizzata, un dato giorno — M5.2.
 *
 * 🚨 **Una riga per (campagna, giorno, persona)**, con un vincolo unico che e'
 * il conteggio stesso: il secondo inserimento fallisce, e quel fallimento
 * **e'** la regola «una persona al giorno vale una visualizzazione».
 *
 * ⚠️ Contiene **chi ha visto cosa**: e' un dato personale, e per questo si
 * conserva tredici mesi e non per sempre (`listino.pubblicita.mesi_di_dettaglio`).
 * Il conto del mese vive in `campagne.speso_mese_cent` e sopravvive alla
 * cancellazione del dettaglio — cioe' la fattura resta corretta senza dover
 * conservare chi era.
 */
class Visualizzazione extends Model
{
    protected $table = 'visualizzazioni';

    protected $fillable = ['campagna_id', 'user_id', 'giorno'];

    protected function casts(): array
    {
        return ['giorno' => 'date'];
    }

    public function campagna(): BelongsTo
    {
        return $this->belongsTo(Campagna::class);
    }
}
