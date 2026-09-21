<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 💰 **Un pagamento arrivato davvero** — 22/09/2026, fase F3.
 *
 * 🚨 **Una riga qui vuol dire che i soldi ci sono.** Non «fattura emessa», non
 * «checkout aperto»: pagato. ⛔ Un registro che conta gli incassi previsti è un
 * registro che non torna con il conto in banca.
 *
 * ⚠️ Usa `BelongsToTenant` come gli altri movimenti di soldi: l'incasso è
 * **della palestra** che l'ha fatto, e una palestra non deve vedere quelli
 * delle altre.
 */
class PagamentoIncassato extends Model
{
    use BelongsToTenant;

    protected $table = 'pagamenti_incassati';

    public const CANONE = 'canone';

    public const GETTONI = 'gettoni';

    public const CONSUMO = 'consumo';

    protected $fillable = [
        'tenant_id', 'user_id', 'tipo', 'importo_cent', 'sconto_cent', 'cosa',
        'stripe_id', 'pagato_il',
    ];

    protected function casts(): array
    {
        return [
            'importo_cent' => 'integer',
            'sconto_cent' => 'integer',
            'pagato_il' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    public function scopeDelTipo(Builder $query, string $tipo): Builder
    {
        return $query->where('tipo', $tipo);
    }
}
