<?php

declare(strict_types=1);

namespace App\Models;

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

    protected $fillable = [
        'workout_plan_id', 'exercise_id', 'position', 'sets', 'reps',
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
