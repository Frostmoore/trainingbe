<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una serie effettivamente eseguita.
 *
 * Come `PlanExercise`, **non ha `tenant_id`**: si raggiunge solo attraverso la
 * sessione. La motivazione per esteso, con i suoi limiti, e' scritta in
 * `PlanExercise` — vale identica qui.
 *
 * Il vincolo `UNIQUE(sessione, esercizio, numero)` non e' un capriccio: l'app
 * rimanda lo stesso salvataggio quando la rete va e viene, e senza il vincolo la
 * stessa serie comparirebbe due volte nello storico. Con il vincolo, il
 * salvataggio diventa un UPSERT e il caso peggiore e' una scrittura in piu'.
 */
class SessionSet extends Model
{
    use HasFactory;

    protected $fillable = [
        'workout_session_id', 'exercise_id', 'set_number',
        'reps', 'weight', 'duration_sec', 'rest_sec', 'done_at',
    ];

    protected function casts(): array
    {
        return [
            'set_number' => 'integer',
            'reps' => 'integer',
            'weight' => 'float',
            'duration_sec' => 'integer',
            'rest_sec' => 'integer',
            'done_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(WorkoutSession::class, 'workout_session_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(Exercise::class);
    }

    /** Il volume della serie: peso × ripetizioni. Zero se manca uno dei due. */
    public function volume(): float
    {
        return (float) ($this->weight ?? 0) * (float) ($this->reps ?? 0);
    }
}
