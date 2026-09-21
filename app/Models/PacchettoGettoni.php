<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * 🎟️ **Un taglio di gettoni in vendita** — 22/09/2026, fase F2.
 *
 * 📌 *«mi serve una sezione per impostare i prezzi dei pacchetti di token»*.
 *
 * ⚠️ **NON usa `BelongsToTenant`**, come `Plan`: il listino è della
 * piattaforma, non di un cliente.
 *
 * 🚨 **I prezzi non si leggono da qui direttamente**: si passa da
 * `Listino::pacchettiGettoni()`, che è il punto unico da cui li prendono sito,
 * app e Stripe. Leggere il modello a mano in una terza sede è il modo in cui
 * nascono due listini che si contraddicono.
 */
class PacchettoGettoni extends Model
{
    protected $table = 'pacchetti_gettoni';

    protected $fillable = [
        'codice', 'nome', 'gettoni', 'prezzo_cent', 'nota', 'attivo', 'ordine',
        'modificato_a_mano',
    ];

    protected $attributes = [
        'attivo' => true,
        'ordine' => 0,
        'modificato_a_mano' => false,
    ];

    protected function casts(): array
    {
        return [
            'gettoni' => 'integer',
            'prezzo_cent' => 'integer',
            'ordine' => 'integer',
            'attivo' => 'boolean',
            'modificato_a_mano' => 'boolean',
        ];
    }

    /** Quelli che si possono comprare, nell'ordine in cui si mostrano. */
    public function scopeInVendita(Builder $query): Builder
    {
        return $query->where('attivo', true)->orderBy('ordine')->orderBy('gettoni');
    }

    /**
     * Quanto costa **un** gettone, in centesimi.
     *
     * 💡 È il numero che fa scegliere, e nessuno ha voglia di calcolarselo:
     * «2.000 per 40,00 €» non dice niente, «2 centesimi l'uno» dice tutto.
     *
     * ⚠️ Diviso per zero non si può: un taglio da zero gettoni non dovrebbe
     * esistere, ma se qualcuno lo scrive non deve far esplodere una pagina che
     * sta vendendo.
     */
    public function centPerGettone(): float
    {
        return $this->gettoni === 0 ? 0.0 : $this->prezzo_cent / $this->gettoni;
    }
}
