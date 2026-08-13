<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiFeature;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Una chiamata AI, con quello che e' costata.
 *
 * Come `AuditLog`: niente `updated_at`, niente modifiche. Un contatore che si
 * puo' correggere non e' un contatore.
 */
class AiUsageLog extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'feature' => AiFeature::class,
            'input_tokens' => 'integer',
            'output_tokens' => 'integer',
            'cache_read_tokens' => 'integer',
            'cache_creation_tokens' => 'integer',
            'cost_millicents' => 'integer',
            'duration_ms' => 'integer',
            'success' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn (): bool => false);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** I token che contano per la quota: input + output. */
    /**
     * 🚨 **Tutti e quattro i tipi di token, e non due.**
     *
     * Fino al 13/08/2026 questo conto era `input + output`: ignorava sia i token
     * letti dalla cache sia — molto peggio — quelli di **creazione**, che si
     * pagano 1,25 volte l'input e sono i piu' cari dei tre.
     *
     * ⚠️ La conseguenza non era solo un contatore ottimista: e' da qui che passa
     * la **quota mensile**, cioe' la cosa che protegge la fattura. Una chiamata
     * con un prompt da cinquemila token risultava costata dodici, e il tetto non
     * la vedeva quasi.
     *
     * 💡 Si sommano **tutti**, ognuno per il proprio numero di token e non per il
     * proprio costo: la quota conta token, non soldi. Il costo, che pesa i tre
     * tipi in modo diverso, sta in `cost_millicents`.
     */
    public const COLONNE_TOKEN = 'input_tokens + output_tokens + cache_creation_tokens + cache_read_tokens';

    public function billableTokens(): int
    {
        return $this->input_tokens
            + $this->output_tokens
            + $this->cache_creation_tokens
            + $this->cache_read_tokens;
    }

    // ───────────────────────── query ─────────────────────────

    public function scopeInMonth(Builder $query, ?Carbon $month = null): Builder
    {
        $month ??= Carbon::now();

        return $query->whereBetween('created_at', [
            $month->copy()->startOfMonth(),
            $month->copy()->endOfMonth(),
        ]);
    }

    /**
     * I token consumati da una palestra nel mese.
     *
     * 🚨 `withoutGlobalScopes()` e un `where` esplicito: il conteggio deve
     * funzionare anche dal pannello di piattaforma, che gira **senza contesto**
     * — e li' il global scope non filtrerebbe niente, restituendo il totale di
     * tutti i clienti come se fosse di uno solo.
     */
    public static function tokensForTenant(int $tenantId, ?Carbon $month = null): int
    {
        return (int) static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->inMonth($month)
            ->sum(DB::raw(self::COLONNE_TOKEN));
    }

    /**
     * I token consumati da **una persona** nel mese — C20.
     *
     * 🚨 È questa la somma su cui poggia la quota, non quella per palestra: il
     * tetto è di ciascuno, e il consumo di uno non deve togliere niente agli
     * altri. `tokensForTenant()` resta, ma serve alla dashboard del pannello —
     * a **vedere** quanto spende una palestra, non a bloccare nessuno.
     */
    public static function tokensForUser(int $userId, ?Carbon $month = null): int
    {
        return (int) static::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->inMonth($month)
            ->sum(DB::raw(self::COLONNE_TOKEN));
    }

    /**
     * Le **chiamate** fatte da una persona nel mese — G2, D6.
     *
     * 🚨 **Da G2 e' questa la base della quota, non `tokensForUser()`.** I token
     * sono l'unita' del fornitore: nessuno compra «due milioni di token» e
     * nessuno sa dire se gli bastano. Una riga qui e' una chiamata, e le
     * chiamate le si conta da soli.
     *
     * ⚠️ **`tokensForUser()` resta e serve ancora**: e' quello che dice quanto
     * abbiamo speso davvero, cioe' la contabilita'. Quello che non fa piu' e'
     * decidere chi si ferma.
     *
     * 💡 Il filtro sui multimodali usa `AiFeature::isMultimodal()` invece di un
     * elenco scritto qui: il giorno che nasce una funzione con un allegato,
     * entra nel conteggio giusto senza che nessuno debba ricordarsene.
     *
     * @param  bool  $soloConAllegato  conta solo le chiamate multimodali (D7)
     */
    public static function callsForUser(int $userId, bool $soloConAllegato = false, ?Carbon $month = null): int
    {
        $query = static::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->inMonth($month);

        if ($soloConAllegato) {
            $query->whereIn('feature', array_map(
                static fn (AiFeature $f): string => $f->value,
                array_filter(AiFeature::cases(), static fn (AiFeature $f): bool => $f->isMultimodal()),
            ));
        }

        return $query->count();
    }

    public static function costForTenant(int $tenantId, ?Carbon $month = null): int
    {
        return (int) static::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->inMonth($month)
            ->sum('cost_millicents');
    }

    /** Il costo in euro/dollari leggibile: i millesimi di centesimo diviso 100.000. */
    public static function millicentsToCurrency(int $millicents): float
    {
        return round($millicents / 100_000, 2);
    }
}
