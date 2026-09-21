<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'abbonamento di un tenant a un piano — F4.1.
 *
 * 🚨 **Usa `BelongsToTenant`**, e non è una formalità: `TenantIsolationTest`
 * enumera i modelli con `tenant_id` e pretende il trait. Senza, una palestra
 * vedrebbe gli abbonamenti — e quindi i piani e i prezzi — di tutte le altre.
 *
 * ── ⚠️ Perché la storia si conserva invece di sovrascrivere una colonna ────
 *
 * Perché la domanda «perché a marzo questo cliente aveva l'AI e ad aprile no?»
 * deve avere una risposta. Con una sola colonna `tenants.plan_id` quella
 * risposta sarebbe «non si sa»: l'unico stato conservato sarebbe l'ultimo.
 */
class PlanSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'plan_id', 'starts_at', 'ends_at',
        'stripe_subscription_id', 'stripe_customer_id', 'rinnova',

        // 🆕 3b-AH 8.1.6 — la pausa di chi entra in una palestra.
        'in_pausa_dal', 'in_pausa_per_tenant_id', 'restavano_giorni',

        // 🆕 🎁 P3 di plan_promozioni.md — il premio «prezzo bloccato».
        'prezzo_bloccato',
    ];

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'in_pausa_dal' => 'datetime',
            'restavano_giorni' => 'integer',
            'prezzo_bloccato' => 'boolean',

            // 🆕 §9.4 — la prima volta che un rinnovo è stato rifiutato.
            'pagamento_fallito_il' => 'datetime',
        ];
    }

    /**
     * 🆕 ⏸️ **È fermo perché questa persona è entrata in una palestra?**
     *
     * ⚠️ Diverso da «scaduto»: un abbonamento in pausa **riprenderà**, e la
     * data di fine non è stata toccata. Vedi `LaPausaDellAbbonamento`.
     */
    public function eInPausa(): bool
    {
        return $this->in_pausa_dal !== null;
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Gli abbonamenti in corso **adesso**.
     *
     * 🚨 `ends_at IS NULL` significa **«non scade»**, non «scaduto». Leggerlo al
     * contrario spegnerebbe l'AI a ogni cliente che non ha una data di fine —
     * cioè a tutti quelli che pagano regolarmente.
     *
     * ⚠️ E `starts_at <= now()`: un abbonamento firmato oggi e valido dal primo
     * del mese prossimo non deve dare accesso da subito.
     */
    public function scopeAttivi(Builder $query): Builder
    {
        return $query
            /*
             * 🆕 🚨 **Un abbonamento in pausa NON è attivo** — 3b-AH 8.1.6.
             *
             * ⛔ Senza questa riga la pausa sarebbe solo decorativa: la persona
             * entra in palestra, viene coperta dalla palestra, e **resta anche
             * abbonata** — due coperture per la stessa cosa, e nessun errore.
             *
             * ⚠️ Sta **nello scope** e non nei chiamanti perché i chiamanti sono
             * tanti — `PianoAttivo`, `AddebitoDelMese`, `InvitiInPalestra`,
             * `Cassa` — e basterebbe dimenticarsene in uno.
             */
            ->whereNull('in_pausa_dal')
            ->where('starts_at', '<=', now())
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('ends_at')
                ->orWhere('ends_at', '>', now())
            );
    }

    public function eAttivo(): bool
    {
        if ($this->eInPausa()) {
            return false;
        }

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        return $this->ends_at === null || $this->ends_at->isFuture();
    }
}
