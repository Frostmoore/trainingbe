<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Rules\AlMassimoTreAlternative;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * «Questa riga puo' essere sostituita da un'altra dello stesso tipo» — G4.6, D2.
 *
 * ── 🎯 Una regola sola, cinque livelli ────────────────────────────────────
 *
 * Lo usano `WorkoutPlanDay`, `PlanExercise`, `NutritionPlanDay`,
 * `NutritionPlanMeal` e `NutritionPlanItem`. In tutti e cinque l'alternativa e'
 * **una riga della stessa tabella** con `alternativa_di_id` che punta
 * all'originale.
 *
 * 💡 Il guadagno vero: un'alternativa di giornata **non e' un caso speciale da
 * programmare**. E' un giorno, con i suoi pasti dentro, che sa di essere
 * l'alternativa di un altro.
 *
 * ── 🚨 `scopeSoloPrincipali()` — il metodo che evita il difetto piu' probabile
 *
 * Senza `whereNull('alternativa_di_id')`, **ogni** elenco — l'app, il pannello,
 * la busta cifrata, i totali — mostrerebbe le alternative **come righe vere**: un
 * piano da 4 pasti ne mostrerebbe 11, e sembrerebbe un piano scritto male invece
 * di un guasto.
 *
 * ⚠️ E' la prima cosa da controllare ogni volta che si scrive una query su una
 * di queste cinque tabelle.
 */
trait PuoAvereAlternative
{
    /**
     * Quante alternative si possono dare alla stessa riga — D2.
     *
     * ⚠️ Non c'e' modo di esprimerlo come vincolo di database in MariaDB: la
     * regola applicativa (`App\Rules\AlMassimoTreAlternative`) e' l'unica difesa,
     * e va usata in **tutte** le form request o meta' delle porte resta aperta.
     */
    public const MAX_ALTERNATIVE = AlMassimoTreAlternative::MAX;

    /** Le alternative **a** questa riga. */
    public function alternative(): HasMany
    {
        return $this->hasMany(static::class, 'alternativa_di_id')->orderBy('position');
    }

    /** La riga che questa sostituisce, se e' un'alternativa. */
    public function originale(): BelongsTo
    {
        return $this->belongsTo(static::class, 'alternativa_di_id');
    }

    public function eUnAlternativa(): bool
    {
        return $this->alternativa_di_id !== null;
    }

    /**
     * Solo le righe vere, senza le alternative.
     *
     * 🚨 **Da usare in ogni elenco.** Vedi la nota in testa al trait: senza,
     * un piano da 4 pasti ne mostra 11.
     */
    public function scopeSoloPrincipali(Builder $query): Builder
    {
        return $query->whereNull('alternativa_di_id');
    }

    /** Le sole alternative, per chi le vuole a parte. */
    public function scopeSoloAlternative(Builder $query): Builder
    {
        return $query->whereNotNull('alternativa_di_id');
    }
}
