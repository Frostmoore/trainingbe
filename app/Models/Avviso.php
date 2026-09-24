<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AzioneDellAvviso;
use App\Models\Contracts\HaUnPubblico;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * 📣 **Un avviso in-app** — blocco B4, 24/09/2026.
 *
 * 📌 Il committente, il 23/09: *«io (god) devo poter mandare notifiche a chi
 * voglio io … facendo apparire una modale in-app con un tasto per approvare e
 * uno per rifiutare»*.
 *
 * ══ 🚨 IL PUBBLICO NON E' SUO: E' CONDIVISO ════════════════════════════════
 *
 * 💡 `bersagli()` e `condizioni()` puntano alle **stesse tabelle** delle
 * promozioni, rese generiche il 24/09. ⛔ *«Usiamo gli stessi che possono essere
 * destinatari di una promozione»* non era una cosa da costruire: era una cosa da
 * **puntare**.
 *
 * ⚠️ E non si è fatto il contrario — l'avviso che punta a una promozione —
 * perché avrebbe voluto dire creare una **promozione finta** per ogni avviso che
 * non regala niente, falsando ogni numero dell'elenco delle promozioni.
 */
class Avviso extends Model implements HaUnPubblico
{
    protected $table = 'avvisi';

    protected $fillable = [
        'titolo', 'testo',
        'tasto_approva', 'tasto_rifiuta',
        'azione', 'url', 'promozione_id',
        'inizia_il', 'scade_il',
        'urgente', 'anche_push', 'attivo',
    ];

    protected function casts(): array
    {
        return [
            'azione' => AzioneDellAvviso::class,
            'inizia_il' => 'datetime',
            'scade_il' => 'datetime',
            'urgente' => 'boolean',
            'anche_push' => 'boolean',
            'attivo' => 'boolean',
        ];
    }

    /** @return MorphMany<PromozioneBersaglio, $this> */
    public function bersagli(): MorphMany
    {
        return $this->morphMany(PromozioneBersaglio::class, 'pubblico');
    }

    /** @return MorphMany<PromozioneCondizione, $this> */
    public function condizioni(): MorphMany
    {
        return $this->morphMany(PromozioneCondizione::class, 'pubblico');
    }

    /** @return HasMany<AvvisoDecisione, $this> */
    public function decisioni(): HasMany
    {
        return $this->hasMany(AvvisoDecisione::class);
    }

    /** @return BelongsTo<Promozione, $this> */
    public function promozione(): BelongsTo
    {
        return $this->belongsTo(Promozione::class);
    }

    /**
     * 📅 **In piedi adesso**: acceso e dentro la finestra.
     *
     * 💡 Le due date sono facoltative: `null` vuol dire «da sempre» e «per
     * sempre». ⚠️ Un avviso scaduto **non si mostra più** — *«la palestra è
     * chiusa domani»* letto una settimana dopo non è un avviso, è rumore.
     *
     * @param  Builder<Avviso>  $query
     * @return Builder<Avviso>
     */
    public function scopeInPiedi(Builder $query): Builder
    {
        return $query
            ->where('attivo', true)
            ->where(fn (Builder $q) => $q->whereNull('inizia_il')->orWhere('inizia_il', '<=', now()))
            ->where(fn (Builder $q) => $q->whereNull('scade_il')->orWhere('scade_il', '>', now()));
    }

    /**
     * ⚠️ Un avviso **non è pronto** se la sua azione non ha il suo bersaglio.
     *
     * ⛔ `link` senza indirizzo apre il vuoto; `promo` senza promozione promette
     * un regalo che non arriva. 🚨 Meglio non mandarlo che mandarlo rotto: chi
     * lo riceve non ha modo di capire che l'errore non è suo.
     */
    public function eCompleto(): bool
    {
        return match ($this->azione) {
            AzioneDellAvviso::Link => filled($this->url),
            AzioneDellAvviso::Promo => $this->promozione_id !== null,
            AzioneDellAvviso::Dismiss => true,
        };
    }
}
