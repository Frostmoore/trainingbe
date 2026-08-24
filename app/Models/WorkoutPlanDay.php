<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\PuoAvereAlternative;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un giorno di una scheda — G4 (D2).
 *
 * ⚠️ **Nessun `tenant_id` qui**, come per `PlanExercise`: la riga non e'
 * raggiungibile se non attraverso la scheda, che ce l'ha. Vale finche' nessuno
 * carica queste righe per id diretto — ed e' il motivo per cui i controller
 * partono **sempre** dalla scheda.
 *
 * 💡 `name` puo' essere `null`: una scheda a un solo giorno non deve mostrare
 * un'intestazione che il trainer non ha scritto.
 */
class WorkoutPlanDay extends Model
{
    use PuoAvereAlternative;

    protected $fillable = [
        'workout_plan_id', 'alternativa_di_id', 'position', 'name', 'notes',
    ];

    /**
     * 🚨 Come per `PlanExercise`: rinominare un giorno o spostarlo **e' una
     * modifica alla scheda**, e il telefono deve poterlo sapere. Vedi la nota
     * lunga li' — 3b-B.16.5.
     *
     * @var list<string>
     */
    protected $touches = ['plan'];

    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlan::class, 'workout_plan_id');
    }

    /**
     * Gli esercizi di questo giorno — **solo i principali**.
     *
     * 🚨 Le alternative agli esercizi si leggono da `PlanExercise::alternative()`,
     * non da qui: se comparissero in questo elenco, un giorno da 6 esercizi ne
     * mostrerebbe 15.
     */
    public function exercises(): HasMany
    {
        return $this->hasMany(PlanExercise::class, 'workout_plan_day_id')
            ->whereNull('alternativa_di_id')
            ->orderBy('position');
    }

    /** Tutti gli esercizi, alternative comprese: serve a chi salva o duplica. */
    public function exercisesConAlternative(): HasMany
    {
        return $this->hasMany(PlanExercise::class, 'workout_plan_day_id')->orderBy('position');
    }
}
