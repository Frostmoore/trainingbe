<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenantOrGlobal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 🔗 **Un piano su misura offerto a un cliente, col link per pagarlo** —
 * 21/09/2026.
 *
 * 📌 *«certo che il piano cucito a mano deve essere fatto con stripe»*.
 *
 * ── 🚨 Cosa autorizza, esattamente ────────────────────────────────────────
 *
 * Il **token** rende comprabile un piano che non è a listino, e **solo a chi ha
 * il link**. ⛔ L'alternativa sarebbe mettere il piano fra i pubblici, cioè far
 * comprare a chiunque il prezzo cucito per uno.
 *
 * ⚠️ **Non è un'autenticazione**: il link dice *quale* piano si può comprare,
 * non *chi* lo compra. Chi paga resta il proprietario dello spazio
 * (`Cassa::loPuoGestire()`), e se l'offerta è intestata a un tenant nessun
 * altro spazio può usarla.
 *
 * ⚠️ **NON usa `BelongsToTenant` ma `BelongsToTenantOrGlobal`**, come
 * `exercises`: `tenant_id` è nullable perché un'offerta può essere fatta a chi
 * non è ancora cliente — si iscrive dal link, e lo spazio nasce lì.
 */
class OffertaDiPiano extends Model
{
    use BelongsToTenantOrGlobal;

    protected $table = 'offerte_di_piano';

    protected $fillable = [
        'plan_id', 'token', 'tenant_id', 'email',
        'richiesta_di_contatto_id', 'creata_da_id', 'scade_il',
    ];

    protected function casts(): array
    {
        return [
            'scade_il' => 'datetime',
            'accettata_il' => 'datetime',
        ];
    }

    /**
     * 🚨 32 caratteri dal generatore crittografico, come i token degli inviti.
     *
     * ⛔ Niente `uniqid()` né `md5(time())`: un token indovinabile è un piano
     * fuori listino comprabile da chiunque provi.
     */
    public static function nuovoToken(): string
    {
        return Str::random(32);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    public function richiesta(): BelongsTo
    {
        return $this->belongsTo(RichiestaDiContatto::class, 'richiesta_di_contatto_id');
    }

    /**
     * Si può ancora usare?
     *
     * 💡 Due motivi per dire di no, e **si tengono distinti** nei messaggi:
     * «già usata» e «scaduta» portano a due telefonate diverse.
     */
    public function eValida(): bool
    {
        return $this->accettata_il === null && ! $this->eScaduta();
    }

    public function eScaduta(): bool
    {
        return $this->scade_il !== null && $this->scade_il->isPast();
    }

    /** Il motivo del rifiuto, da mostrare a chi apre il link. */
    public function perchePiuNonVale(): ?string
    {
        return match (true) {
            $this->accettata_il !== null => 'Questo link è già stato usato: il piano è attivo.',
            $this->eScaduta() => 'Questo link è scaduto. Scrivici e te ne mandiamo un altro.',
            default => null,
        };
    }

    /**
     * 🚨 **Segnata accettata dal webhook, non da chi apre il checkout.**
     *
     * ⛔ Bruciarla all'apertura vorrebbe dire che chi chiude la pagina di Stripe
     * senza pagare resta col link morto e il piano non attivo: un cliente perso
     * per un ripensamento di due secondi.
     */
    public function segnaAccettata(?Carbon $quando = null): void
    {
        $this->accettata_il = $quando ?? Carbon::now();
        $this->save();
    }

    /** Le offerte ancora spendibili. */
    public function scopeValide(Builder $query): Builder
    {
        return $query
            ->whereNull('accettata_il')
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('scade_il')
                ->orWhere('scade_il', '>', Carbon::now())
            );
    }

    /** L'indirizzo da mandare al cliente. */
    public function link(): string
    {
        return rtrim((string) config('app.url'), '/').'/offerta/'.$this->token;
    }
}
