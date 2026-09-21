<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 💶 Un mese di piano a consumo, per una palestra — 3b-AH.6.9 (17/09/2026).
 *
 * 🚨 **Usa `BelongsToTenant`**, e non e' una formalita': `TenantIsolationTest`
 * enumera i modelli con `tenant_id` e pretende il trait. Senza, una palestra
 * vedrebbe **quanto pagano le altre** — cioe' quanti iscritti hanno, che e'
 * l'unico numero che nessuna palestra vuole far sapere ai concorrenti.
 *
 * ⚠️ Chi lo scrive gira **senza contesto** (un comando schedulato), quindi
 * passa sempre `tenant_id` a mano e legge con `withoutGlobalScopes()`. E' lo
 * stesso giro che fa `RinnovaGliAbbonamentiRegalati` su `PlanSubscription`.
 */
class AddebitoMensile extends Model
{
    use BelongsToTenant;

    protected $table = 'addebiti_mensili';

    /** Calcolato, non ancora mandato a Stripe. */
    public const DA_INCASSARE = 'da_incassare';

    /**
     * 🆕 **Stripe ha la fattura, ma non è ancora pagata** — §9.4, 18/09/2026.
     *
     * ⛔ Prima non esisteva: la fattura diventava `incassato` appena **emessa**,
     * e con la carta rifiutata il pannello diceva «Pagato» su una fattura mai
     * pagata. Adesso `incassato` lo scrive solo `invoice.paid`.
     */
    public const EMESSO = 'emesso';

    /** Pagata davvero: lo dice `invoice.paid`. */
    public const INCASSATO = 'incassato';

    /**
     * ⛔ **Non c'e' nessuno a cui mandarla.** Oggi le palestre entrano a mano e
     * non hanno un `stripe_customer_id`: l'addebito si calcola lo stesso e
     * resta qui, in chiaro, invece di sparire.
     */
    public const SENZA_CLIENTE = 'senza_cliente';

    /** Stripe ha detto di no. `errore` dice cosa. */
    public const FALLITO = 'fallito';

    protected $fillable = [
        'tenant_id', 'plan_id', 'periodo',
        'iscritti_contati', 'iscritti_fatturati',
        'prezzo_iscritto_cent', 'importo_cent',
        // 🆕 🎁 P3 di plan_promozioni.md — lo sconto sul consumo, e da quali promozioni.
        'sconto_cent', 'promozioni',
        'stato', 'stripe_invoice_id', 'errore', 'incassato_il',
    ];

    protected function casts(): array
    {
        return [
            'iscritti_contati' => 'integer',
            'iscritti_fatturati' => 'integer',
            'prezzo_iscritto_cent' => 'integer',
            'importo_cent' => 'integer',
            'sconto_cent' => 'integer',
            'incassato_il' => 'datetime',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function scopeDelPeriodo(Builder $query, string $periodo): Builder
    {
        return $query->where('periodo', $periodo);
    }

    /**
     * Quelli che aspettano ancora di essere mandati a Stripe.
     *
     * ⚠️ **`senza_cliente` ci sta dentro**: il giorno che quella palestra un
     * cliente ce l'ha, il mese vecchio si recupera senza ricalcolarlo. ⛔
     * `fallito` no — un errore di Stripe si guarda prima di riprovarlo, o si
     * riprova in cerchio contro lo stesso muro.
     */
    public function scopeDaIncassare(Builder $query): Builder
    {
        return $query->whereIn('stato', [self::DA_INCASSARE, self::SENZA_CLIENTE]);
    }

    /** L'importo in euro, per chi lo deve leggere. */
    public function importoEuro(): float
    {
        return $this->importo_cent / 100;
    }

    /**
     * 🚨 **Ha pagato il minimo invece dei suoi iscritti?**
     *
     * 💡 Serve a spiegarlo prima che lo chieda: una palestra con 40 iscritti su
     * un minimo di 100 vede una cifra che non torna con nessun numero che ha
     * sotto gli occhi.
     */
    public function haPagatoIlMinimo(): bool
    {
        return $this->iscritti_fatturati > $this->iscritti_contati;
    }
}
