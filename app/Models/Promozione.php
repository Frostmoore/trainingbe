<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ComeSiAttiva;
use App\Enums\GenereDiPromozione;
use App\Enums\StatoPromozione;
use App\Enums\SuCosaVale;
use App\Enums\TipoDiPremio;
use App\Models\Contracts\HaUnPubblico;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * 🎁 **Una promozione** — 22/09/2026, fase F2.
 *
 * ⚠️ **NON usa `BelongsToTenant`**, come `Plan`: una promozione è della
 * piattaforma anche quando punta a una palestra sola. Chi ne è il bersaglio lo
 * dicono i `bersagli`, non la colonna di un tenant.
 *
 * 🚨 **Lo sconto non si applica leggendo questo modello**: si passa da
 * `LePromozioni`, che sa mettere in fila più promozioni e sa dove si
 * ferma. ⛔ Un `prezzo * (1 - valore/100)` scritto in una vista è il modo in
 * cui un giorno si vende sotto costo.
 *
 * 🆕 **P1 di `plan_promozioni.md`, 24/09/2026**: quello che dà non sta più
 * sulla riga (`tipo_sconto`, `valore`, `su_cosa` sono state tolte) ma nei
 * **premi** — una promozione può darne più d'uno. E chi l'ha presa resta
 * scritto nelle **assegnazioni**: da lì il massimo per persona e il budget.
 */
class Promozione extends Model implements HaUnPubblico
{
    protected $table = 'promozioni';

    protected $fillable = [
        'codice', 'nome', 'descrizione', 'genere',
        'cumulabile', 'priorita', 'attivazione', 'evento', 'evento_parametri',
        'stato', 'inizia_il', 'scade_il',
        'massimo_usi', 'massimo_per_persona', 'budget_cent', 'budget_gettoni',
        'avviso', 'visibile_in', 'creata_da_id',
    ];

    protected $attributes = [
        'stato' => 'attiva',
        'attivazione' => 'automatica',
        'genere' => 'abbonamenti',
        'cumulabile' => false,
        'priorita' => 0,
        'usata_volte' => 0,
    ];

    protected function casts(): array
    {
        return [
            'genere' => GenereDiPromozione::class,
            'attivazione' => ComeSiAttiva::class,
            'stato' => StatoPromozione::class,
            'cumulabile' => 'boolean',
            'priorita' => 'integer',
            'massimo_usi' => 'integer',
            'massimo_per_persona' => 'integer',
            'budget_cent' => 'integer',
            'budget_gettoni' => 'integer',
            'usata_volte' => 'integer',
            'evento_parametri' => 'array',
            'avviso' => 'array',
            'visibile_in' => 'array',
            'inizia_il' => 'datetime',
            'scade_il' => 'datetime',
            'eseguita_il' => 'datetime',
        ];
    }

    /**
     * 🎯 I bersagli — 🆕 **morph dal 24/09/2026** (blocco B4).
     *
     * ⚠️ Erano `hasMany`. Ora la stessa tabella serve anche agli **avvisi
     * in-app**: la domanda *«chi rientra?»* è identica per tutti e due, e
     * duplicarla vorrebbe dire vederla divergere al primo ritocco.
     *
     * ⛔ L'alternativa era far puntare l'avviso a una **promozione finta**:
     * l'elenco delle promozioni si sarebbe riempito di righe che promozioni non
     * sono, e ogni numero letto lì dentro sarebbe stato falso.
     */
    public function bersagli(): MorphMany
    {
        return $this->morphMany(PromozioneBersaglio::class, 'pubblico');
    }

    /** 🆕 P1 — cosa dà: uno o più premi. */
    public function premi(): HasMany
    {
        return $this->hasMany(PromozionePremio::class);
    }

    /** 🆕 P5 — le condizioni sui destinatari, in AND. ⚠️ Morph come i bersagli. */
    public function condizioni(): MorphMany
    {
        return $this->morphMany(PromozioneCondizione::class, 'pubblico');
    }

    /** 🆕 P1 — chi l'ha presa. */
    public function assegnazioni(): HasMany
    {
        return $this->hasMany(PromozioneAssegnazione::class);
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
            && ! $this->eEsaurita()
            && ! $this->eFuoriBudget();
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

    /**
     * 🆕 P1 — **Il budget è finito?** Opzione 35 del piano.
     *
     * 💡 Si somma dalle assegnazioni che contano (non revocate, non scadute):
     * una revoca restituisce il budget, perché quei soldi non sono stati dati.
     */
    public function eFuoriBudget(): bool
    {
        if ($this->budget_cent === null && $this->budget_gettoni === null) {
            return false;
        }

        $speso = $this->assegnazioni()->cheContano()
            ->selectRaw('COALESCE(SUM(scontato_cent), 0) as cent, COALESCE(SUM(gettoni), 0) as gettoni')
            ->first();

        return ($this->budget_cent !== null && (int) $speso?->cent >= $this->budget_cent)
            || ($this->budget_gettoni !== null && (int) $speso?->gettoni >= $this->budget_gettoni);
    }

    /** 🆕 P2 — ha almeno un premio che si consegna senza acquisto (e costruito)? */
    public function haRegali(string $aChi = 'destinatario'): bool
    {
        return $this->premiPer($aChi)->contains(fn (PromozionePremio $p): bool => $p->tipo->eUnRegalo() && $p->tipo->costruito());
    }

    /** 🆕 P2 — ha almeno un premio che si usa pagando (e costruito)? */
    public function haPremiDAcquisto(string $aChi = 'destinatario'): bool
    {
        return $this->premiPer($aChi)->contains(fn (PromozionePremio $p): bool => $p->tipo->siUsaAllAcquisto() && $p->tipo->costruito());
    }

    /**
     * 🆕 P6 — i premi di **chi**: `destinatario` (sempre, tranne «porta un
     * amico») o `chi_ha_invitato`.
     *
     * @return Collection<int, PromozionePremio>
     */
    public function premiPer(string $aChi = 'destinatario'): Collection
    {
        return $this->premi->filter(fn (PromozionePremio $p): bool => ($p->a_chi ?? 'destinatario') === $aChi)->values();
    }

    /**
     * 🆕 🔓 P6 — **Sblocca uno sconto?** (opzione 18). Una promozione così non si
     * usa pagando da sola: lo sconto si **guadagna** con l'evento, e vale solo
     * per chi l'ha guadagnato.
     */
    public function haSblocchi(): bool
    {
        return $this->premi->contains(fn (PromozionePremio $p): bool => $p->tipo === TipoDiPremio::SbloccaSconto);
    }

    /**
     * 🆕 P2 — **Ha un premio d'acquisto in sospeso?**
     *
     * Una promozione che regala subito **e** sconta un acquisto: il regalo
     * crea l'assegnazione, e lo sconto resta da usare su quella stessa
     * assegnazione. ⛔ Senza, col massimo per persona a 1 il regalo chiuderebbe
     * la porta allo sconto.
     */
    public function haUnAcquistoInSospeso(User $utente): ?PromozioneAssegnazione
    {
        /*
         * 🆕 P6 — lo sconto sbloccato può scadere: `giorni` del premio
         * `sblocca_sconto`, contati da quando è stato guadagnato.
         */
        $giorni = (int) $this->premi
            ->filter(fn (PromozionePremio $p): bool => $p->tipo === TipoDiPremio::SbloccaSconto)
            ->max('giorni');

        return $this->assegnazioni()
            ->cheContano()
            ->where('user_id', $utente->getKey())
            ->where('acquisto_in_sospeso', true)
            ->when($giorni > 0, fn ($q) => $q->where('created_at', '>=', now()->subDays($giorni)))
            ->oldest()
            ->first();
    }

    /** 🆕 P2 — si può usare pagando: o la prende ora, o ne ha una in sospeso. */
    public function laPuoUsareAllAcquisto(User $utente): bool
    {
        // 🆕 P6 — uno sconto sbloccato si usa solo da chi l'ha guadagnato.
        if ($this->haSblocchi()) {
            return $this->haUnAcquistoInSospeso($utente) !== null;
        }

        return $this->laPuoPrendereAncora($utente) || $this->haUnAcquistoInSospeso($utente) !== null;
    }

    /**
     * 🆕 P2 — **Quanti gettoni si possono ancora regalare** col budget. `null` = nessun tetto.
     */
    public function gettoniRestantiNelBudget(): ?int
    {
        if ($this->budget_gettoni === null) {
            return null;
        }

        $dati = (int) $this->assegnazioni()->cheContano()->sum('gettoni');

        return max(0, $this->budget_gettoni - $dati);
    }

    /** 🆕 P1 — quante volte questa persona l'ha già presa (revocate e scadute escluse). */
    public function usiDi(User $utente): int
    {
        return $this->assegnazioni()->cheContano()->where('user_id', $utente->getKey())->count();
    }

    /**
     * 🆕 P1 — **Questa persona può prenderla ancora?** Opzione 34 del piano.
     *
     * ⛔ Senza, una promozione automatica si riapplicava a ogni acquisto della
     * stessa persona finché non finiva il tetto totale.
     */
    public function laPuoPrendereAncora(User $utente): bool
    {
        return $this->massimo_per_persona === null || $this->usiDi($utente) < $this->massimo_per_persona;
    }

    /** Perché non si può usare — o `null` se si può. */
    public function perchePiuNonVale(): ?string
    {
        return match (true) {
            $this->stato === StatoPromozione::Sospesa => 'Questa offerta è sospesa.',
            $this->stato === StatoPromozione::Disattivata => 'Questa offerta non è più valida.',
            $this->eEsaurita() => 'Questa offerta è esaurita.',
            $this->eScaduta() => 'Questa offerta è scaduta.',
            $this->eFuoriBudget() => 'Questa offerta è esaurita.',
            ! $this->eNelPeriodo() => 'Questa offerta non è ancora cominciata.',
            default => null,
        };
    }

    /**
     * Vale su questo tipo di acquisto?
     *
     * 🆕 P1 — lo dicono i **premi**: vero se almeno uno agisce su quell'acquisto.
     */
    public function valeSu(SuCosaVale $cosa): bool
    {
        return $this->premi->contains(fn (PromozionePremio $p): bool => $p->suCosaVale() === $cosa);
    }

    /**
     * 💰 Quanto toglie da questo importo, in centesimi.
     *
     * 🆕 P1 — somma **in fila** i premi di sconto che valgono su questo
     * acquisto (di solito uno): ognuno sull'importo che resta, mai sotto zero.
     */
    public function scontoSu(int $importoCent, SuCosaVale $cosa, ?int $pacchettoId = null, ?int $planId = null): int
    {
        $resta = max(0, $importoCent);

        foreach ($this->premiDiSconto($cosa, $pacchettoId, $planId) as $premio) {
            $resta -= $premio->scontoSu($resta);
        }

        return max(0, $importoCent) - $resta;
    }

    /**
     * I premi di sconto che valgono su questo acquisto.
     *
     * @return list<PromozionePremio>
     */
    public function premiDiSconto(SuCosaVale $cosa, ?int $pacchettoId = null, ?int $planId = null): array
    {
        return $this->premi
            ->filter(fn (PromozionePremio $p): bool => $p->tipo->eUnoSconto()
                && $p->tipo->costruito()
                && $p->suCosaVale() === $cosa
                && $p->valeSulPacchetto($pacchettoId)
                && $p->valeSulPiano($planId))
            ->values()
            ->all();
    }

    /** Come si legge: i premi uniti da « + ». */
    public function scrittoBene(): string
    {
        return $this->premi->map(fn (PromozionePremio $p): string => $p->scrittoBene())->join(' + ') ?: 'nessun premio';
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
