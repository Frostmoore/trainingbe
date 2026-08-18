<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un comune italiano — 18/08/2026. **M1.1.**
 *
 * 🚨 **Non ha `BelongsToTenant`, ed e' voluto.** Che Bologna stia in Emilia-
 * Romagna non e' un dato di una palestra: e' un fatto pubblico, uguale per
 * tutte. Un filtro per tenant renderebbe la tabella invisibile a chiunque.
 *
 * ⚠️ **E non ha `SoftDeletes`.** I comuni non si cancellano: si spengono con
 * `attivo = false` — vedi la migrazione. Cancellare un comune fuso vorrebbe dire
 * azzerare la citta' sul profilo di chi lo aveva scelto, cioe' rompere il dato
 * di una persona per un riordino amministrativo che non la riguarda.
 */
class Comune extends Model
{
    /**
     * ⚠️ Esplicita, e serve: Laravel pluralizzerebbe «Comune» in `comunes`.
     * La migrazione crea `comuni`, e senza questa riga il modello non trova
     * niente — con un errore che parla di una tabella che nessuno ha mai scritto.
     * Stessa trappola gia' pagata su `Food` → `foods`.
     */
    protected $table = 'comuni';

    protected $fillable = [
        'codice', 'nome', 'nome_altro', 'chiave', 'chiave_altro',
        'provincia', 'provincia_nome', 'regione', 'cap', 'popolazione',
        'lat', 'lng', 'attivo',
    ];

    protected function casts(): array
    {
        return [
            'lat' => 'float',
            'lng' => 'float',
            'popolazione' => 'integer',
            'attivo' => 'boolean',
        ];
    }

    /**
     * Come si scrive per esteso: «Bologna (BO)».
     *
     * 💡 La provincia ci va **sempre**, anche per i capoluoghi noti. Ci sono otto
     * comuni che si chiamano `Castro`, quattro `Samone`, e senza la sigla un
     * elenco di risultati sarebbe una fila di righe identiche fra cui non si puo'
     * scegliere.
     */
    public function esteso(): string
    {
        return "{$this->nome} ({$this->provincia})";
    }

    /** Se ha le coordinate. ⚠️ 57 comuni su 7.896 non ce le hanno: vedi `Vicinanza`. */
    public function haCoordinate(): bool
    {
        return $this->lat !== null && $this->lng !== null;
    }

    /** @param  Builder<Comune>  $query */
    public function scopeAttivi(Builder $query): void
    {
        $query->where('attivo', true);
    }
}
