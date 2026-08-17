<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Un alimento del catalogo condiviso — 17/08/2026.
 *
 * 🚨 **Non ha `BelongsToTenant`, ed e' voluto.** «Petto di pollo, 165 kcal per
 * 100 g» non e' un dato di una palestra: e' un fatto, e vale uguale per tutte.
 * Un filtro per tenant renderebbe il catalogo inutile a chi si allena da solo,
 * che di tenant ne ha uno tutto suo con dentro se stesso.
 *
 * ⚠️ E non ha `user_id`: il legame persona ↔ alimento sta in `food_entries`,
 * dove deve stare — cosi' il giorno in cui il diario si sposta sul telefono
 * (Parte I) si porta via anche quello, invece di lasciarne una copia qui.
 */
class Food extends Model
{
    public const ORIGINE_MANUALE = 'manuale';

    public const ORIGINE_AI = 'ai';

    /** Quante **persone diverse** servono perche' un alimento diventi pubblico. */
    public const CONFERME_PER_PUBBLICARE = 2;

    protected $fillable = [
        'chiave', 'nome', 'marca',
        'kcal_100', 'protein_100', 'carbs_100', 'fat_100',
        'basis', 'origine', 'fonte', 'conferme', 'usi', 'pubblico',
    ];

    protected function casts(): array
    {
        return [
            'kcal_100' => 'float',
            'protein_100' => 'float',
            'carbs_100' => 'float',
            'fat_100' => 'float',
            'conferme' => 'integer',
            'usi' => 'integer',
            'pubblico' => 'boolean',
        ];
    }

    /**
     * Gli alimenti che questa persona puo' vedere.
     *
     * 🚨 I pubblici, **piu' i propri**: quello che una persona ha scritto lo
     * deve ritrovare subito, anche se nessun altro l'ha ancora confermato.
     * ⚠️ Senza la seconda meta', chi inserisce un alimento nuovo lo vedrebbe
     * sparire e lo riscriverebbe da capo ogni volta — e il catalogo non
     * arriverebbe mai a due conferme, perche' la stessa persona non conta due
     * volte.
     */
    public function scopeVisibiliA(Builder $query, User $utente): Builder
    {
        return $query->where(function (Builder $q) use ($utente): void {
            $q->where('pubblico', true)
                ->orWhereExists(fn ($sub) => $sub
                    ->selectRaw('1')
                    ->from('food_entries')
                    ->whereColumn('food_entries.food_id', 'foods.id')
                    ->where('food_entries.user_id', $utente->getKey()));
        });
    }
}
