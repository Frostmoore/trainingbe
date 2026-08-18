<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una campagna pubblicitaria nel catalogo — 18/08/2026. **M5.1.**
 *
 * 🚨 **Non ha `BelongsToTenant`**, per la stessa ragione di `ProfiloPubblico`:
 * il catalogo la legge mentre risponde a **chiunque**, anche a chi non ha un
 * account, e il `TenantScope` la renderebbe invisibile.
 *
 * ⚠️ L'isolamento in scrittura lo fanno il pannello e la coppia XOR: una
 * campagna si modifica solo dalla propria pagina, e quella pagina la trova con
 * `tenant_id` o `user_id` **esplicito**, mai dal contesto.
 */
class Campagna extends Model
{
    /** ⚠️ Esplicita: Laravel direbbe `campagnas`. */
    protected $table = 'campagne';

    protected $fillable = [
        'tenant_id', 'user_id', 'attiva',
        'budget_mensile_cent', 'speso_mese_cent', 'costo_visualizzazione_cent',
        'mese', 'esaurita_il',
    ];

    protected function casts(): array
    {
        return [
            'attiva' => 'boolean',
            'budget_mensile_cent' => 'integer',
            'speso_mese_cent' => 'integer',
            'costo_visualizzazione_cent' => 'integer',
            'esaurita_il' => 'datetime',
        ];
    }

    public function visualizzazioni(): HasMany
    {
        return $this->hasMany(Visualizzazione::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Il mese in corso nella forma conservata: `2026-08`. */
    public static function meseCorrente(): string
    {
        return now()->format('Y-m');
    }

    /**
     * Quanto resta da spendere questo mese, in centesimi.
     *
     * 🚨 Se il mese conservato non e' quello corrente, il conto **riparte da
     * zero**: e' l'azzeramento pigro. ⚠️ Un job mensile avrebbe voluto dire che
     * se non gira — server fermo, scheduler rotto — le campagne restano spente
     * per un mese intero senza che nessuno se ne accorga.
     */
    public function residuoCent(): int
    {
        $speso = $this->mese === self::meseCorrente() ? $this->speso_mese_cent : 0;

        return max(0, $this->budget_mensile_cent - $speso);
    }

    /**
     * Puo' ancora comparire in cima?
     *
     * 🚨 **Tre condizioni, e servono tutte**: accesa, con budget residuo, e con
     * abbastanza residuo da pagare **almeno una** visualizzazione. ⚠️ Senza la
     * terza, una campagna con un centesimo residuo e un costo di due
     * comparirebbe all'infinito senza mai riuscire a pagarsi.
     */
    public function puoComparire(): bool
    {
        return $this->attiva && $this->residuoCent() >= $this->costo_visualizzazione_cent;
    }

    /** @param  Builder<Campagna>  $query */
    public function scopeAttive(Builder $query): void
    {
        $query->where('attiva', true);
    }
}
