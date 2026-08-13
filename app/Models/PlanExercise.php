<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PuoAvereAlternative;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una riga di una scheda: quale esercizio, quante serie, quante ripetizioni.
 *
 * 🚨 **Non ha `tenant_id`, ed e' una decisione con un prezzo.**
 *
 * La riga esiste solo dentro una scheda, che il tenant ce l'ha; duplicarlo qui
 * significherebbe tenere due copie allineate, e due copie che possono divergere
 * sono peggio di una sola. Ma questo vale **soltanto** finche' nessuno carica
 * queste righe per id diretto: `PlanExercise::find($id)` non e' filtrato da
 * nessuno scope, e da un id preso da una richiesta HTTP si arriverebbe alla
 * riga di un'altra palestra.
 *
 * **La regola, quindi, e': queste righe si raggiungono solo attraverso la
 * scheda** (`$plan->exercises`), mai per id. Dove serve un id — l'editor del
 * pannello, l'API — si carica prima la scheda (che e' filtrata) e si cerca
 * dentro. `TenantIsolationTest` non puo' accorgersi di una violazione qui,
 * quindi la protezione e' quella scritta sopra e i test di dominio (B4.6) che
 * la verificano.
 */
class PlanExercise extends Model
{
    use HasFactory;
    use PuoAvereAlternative;

    protected $fillable = [
        'workout_plan_id', 'workout_plan_day_id', 'alternativa_di_id',
        'exercise_id', 'position', 'sets', 'reps',
        'rest_sec', 'target_weight', 'duration_sec', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'sets' => 'integer',
            'rest_sec' => 'integer',
            'duration_sec' => 'integer',
            'target_weight' => 'float',
        ];
    }

    /**
     * Il giorno a cui appartiene — G4.
     *
     * ⚠️ `workout_plan_id` resta e non e' ridondanza inutile: e' cio' che
     * permette di trovare tutti gli esercizi di una scheda con una query sola,
     * senza passare dai giorni. Le due colonne devono restare d'accordo, e a
     * tenerle d'accordo e' il controller che scrive.
     */
    /**
     * Il giorno si ricava dalla scheda quando chi scrive non lo dice — G4.
     *
     * ── 🚨 Perche' un hook e non «ricordarselo ogni volta» ────────────────
     *
     * `workout_plan_day_id` e' `NOT NULL` per una ragione forte: un esercizio
     * senza giorno non lo mostra nessuna schermata. Ma i punti che scrivono
     * esercizi sono sei — controller, import da PDF, comando di seed, copia dei
     * modelli, factory, fixture dei test — e **pretendere che tutti se lo
     * ricordino e' il modo di scoprire che uno non se l'e' ricordato**.
     *
     * 💡 Non e' magia che nasconde un errore: il giorno si ricava dalla
     * **scheda che la riga dichiara gia'**, quindi non puo' finire su un piano
     * sbagliato. E' la stessa forma di `BelongsToTenant`, che riempie
     * `tenant_id` dal contesto invece di chiederlo a ogni chiamante.
     *
     * ⚠️ Il vincolo `NOT NULL` **resta**, ed e' la garanzia vera: questo hook
     * fa in modo che non venga mai violato, non lo sostituisce.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $riga): void {
            if ($riga->workout_plan_day_id !== null || $riga->workout_plan_id === null) {
                return;
            }

            $piano = WorkoutPlan::withoutGlobalScopes()->find($riga->workout_plan_id);

            $riga->workout_plan_day_id = $piano?->giornoPredefinito()?->getKey();
        });
    }

    public function day(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlanDay::class, 'workout_plan_day_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlan::class, 'workout_plan_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** «3 × 8-12» oppure «3 × 45s»: la prescrizione in una riga. */
    public function prescription(): string
    {
        $serie = $this->sets !== null ? $this->sets.' × ' : '';

        if ($this->reps !== null && $this->reps !== '') {
            return $serie.$this->reps;
        }

        if ($this->duration_sec !== null) {
            return $serie.$this->duration_sec.'s';
        }

        return $serie !== '' ? rtrim($serie, ' × ') : '—';
    }
}
