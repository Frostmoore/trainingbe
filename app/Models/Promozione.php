<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\StatoPromozione;
use App\Enums\SuCosaVale;
use App\Enums\TipoDiSconto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * 🎁 **Una promozione** — 22/09/2026, fase F2.
 *
 * ⚠️ **NON usa `BelongsToTenant`**, come `Plan`: una promozione è della
 * piattaforma anche quando punta a una palestra sola. Chi ne è il bersaglio lo
 * dicono i `bersagli`, non la colonna di un tenant.
 *
 * 🚨 **Lo sconto non si applica leggendo questo modello**: si passa da
 * `LoScontoDaApplicare`, che sa mettere in fila più promozioni e sa dove si
 * ferma. ⛔ Un `prezzo * (1 - valore/100)` scritto in una vista è il modo in
 * cui un giorno si vende sotto costo.
 */
class Promozione extends Model
{
    protected $table = 'promozioni';

    protected $fillable = [
        'codice', 'nome', 'descrizione', 'tipo_sconto', 'valore', 'su_cosa',
        'cumulabile', 'attivazione', 'stato', 'inizia_il', 'scade_il',
        'massimo_usi', 'creata_da_id',
    ];

    protected $attributes = [
        'stato' => 'attiva',
        'attivazione' => 'automatica',
        'cumulabile' => false,
        'usata_volte' => 0,
    ];

    protected function casts(): array
    {
        return [
            'tipo_sconto' => TipoDiSconto::class,
            'stato' => StatoPromozione::class,
            'su_cosa' => 'array',
            'cumulabile' => 'boolean',
            'valore' => 'integer',
            'massimo_usi' => 'integer',
            'usata_volte' => 'integer',
            'inizia_il' => 'datetime',
            'scade_il' => 'datetime',
        ];
    }

    public function bersagli(): HasMany
    {
        return $this->hasMany(PromozioneBersaglio::class);
    }

    /**
     * 🚨 Un codice che non si indovina: è quello che apre l'offerta.
     *
     * 💡 Leggibile a voce (niente `l` e `1` insieme) perché qualcuno lo detterà
     * al telefono, ma lungo abbastanza da non provarlo a caso.
     */
    public static function nuovoCodice(): string
    {
        return Str::upper(Str::random(10));
    }

    // ───────────────────────── se vale ─────────────────────────

    /**
     * **Si può usare adesso?**
     *
     * ⚠️ Tre domande diverse, e si tengono separate perché portano a tre
     * messaggi diversi da mostrare: spenta, fuori tempo, esaurita.
     */
    public function eSpendibile(?Carbon $quando = null): bool
    {
        return $this->stato === StatoPromozione::Attiva
            && $this->eNelPeriodo($quando)
            && ! $this->eEsaurita();
    }

    public function eNelPeriodo(?Carbon $quando = null): bool
    {
        $quando ??= Carbon::now();

        if ($this->inizia_il !== null && $quando->lt($this->inizia_il)) {
            return false;
        }

        return $this->scade_il === null || $quando->lt($this->scade_il);
    }

    public function eScaduta(?Carbon $quando = null): bool
    {
        return $this->scade_il !== null && ($quando ?? Carbon::now())->gte($this->scade_il);
    }

    public function eEsaurita(): bool
    {
        return $this->massimo_usi !== null && $this->usata_volte >= $this->massimo_usi;
    }

    /** Perché non si può usare — o `null` se si può. */
    public function perchePiuNonVale(): ?string
    {
        return match (true) {
            $this->stato === StatoPromozione::Sospesa => 'Questa offerta è sospesa.',
            $this->stato === StatoPromozione::Disattivata => 'Questa offerta non è più valida.',
            $this->eEsaurita() => 'Questa offerta è esaurita.',
            $this->eScaduta() => 'Questa offerta è scaduta.',
            ! $this->eNelPeriodo() => 'Questa offerta non è ancora cominciata.',
            default => null,
        };
    }

    /** Vale su questo tipo di acquisto? */
    public function valeSu(SuCosaVale $cosa): bool
    {
        return in_array($cosa->value, (array) $this->su_cosa, true);
    }

    /**
     * 💰 Quanto toglie da questo importo, in centesimi.
     *
     * 🚨 **Non scende mai sotto zero**: uno sconto a importo più grande del
     * prezzo non regala la differenza, la lascia a zero. ⛔ Senza questa riga
     * un fisso da 50 € su un pacchetto da 3 € darebbe un importo negativo, e
     * Stripe lo rifiuterebbe **dopo** aver mostrato il prezzo al cliente.
     */
    public function scontoSu(int $importoCent): int
    {
        $sconto = $this->tipo_sconto === TipoDiSconto::Percentuale
            ? (int) round($importoCent * $this->valore / 100)
            : $this->valore;

        return max(0, min($sconto, $importoCent));
    }

    /** Come si legge lo sconto: «20%» o «5,00 €». */
    public function scrittoBene(): string
    {
        return $this->tipo_sconto->scrivi($this->valore);
    }

    // ───────────────────────── query ─────────────────────────

    /** Quelle spendibili **adesso**: attive, nel periodo, non esaurite. */
    public function scopeSpendibili(Builder $query, ?Carbon $quando = null): Builder
    {
        $quando ??= Carbon::now();

        return $query
            ->where('stato', StatoPromozione::Attiva->value)
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('inizia_il')->orWhere('inizia_il', '<=', $quando))
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('scade_il')->orWhere('scade_il', '>', $quando))
            ->where(fn (Builder $q): Builder => $q
                ->whereNull('massimo_usi')->orWhereColumn('usata_volte', '<', 'massimo_usi'));
    }
}
