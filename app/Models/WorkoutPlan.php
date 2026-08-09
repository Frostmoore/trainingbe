<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Una scheda di allenamento.
 *
 * `member_id` null = **modello** riutilizzabile della palestra; valorizzato =
 * scheda di quella persona. Assegnare significa **copiare** il modello, non
 * puntarci: vedi `assignTo()`, dove c'e' scritto perche'.
 */
class WorkoutPlan extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    protected $attributes = [
        'status' => 'draft',
        'source' => 'manual',
    ];

    protected $fillable = [
        'tenant_id', 'member_id', 'created_by', 'name', 'notes',
        'status', 'source', 'starts_at', 'ends_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'source' => PlanSource::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
            'published_at' => 'datetime',
        ];
    }

    // ───────────────────────── relazioni ─────────────────────────

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(PlanExercise::class)->orderBy('position');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    // ───────────────────────── stato ─────────────────────────

    public function isTemplate(): bool
    {
        return $this->member_id === null;
    }

    public function isVisibleToMember(): bool
    {
        return $this->member_id !== null && $this->status->isVisibleToMember();
    }

    /**
     * Pubblica: da qui in poi l'iscritto la vede nell'app.
     *
     * `published_at` si scrive **solo la prima volta**: e' la data in cui la
     * scheda e' entrata in vigore, e ripubblicandola dopo una correzione non
     * deve spostarsi — altrimenti «da quando segue questo programma?» non ha
     * piu' risposta.
     */
    public function publish(): void
    {
        $this->status = PlanStatus::Published;
        $this->published_at ??= now();
        $this->save();
    }

    public function archive(): void
    {
        $this->status = PlanStatus::Archived;
        $this->save();
    }

    /**
     * Assegna il modello a un iscritto, **copiandolo**.
     *
     * 🚨 Copiare e non condividere. Se venti persone puntassero alla stessa
     * riga, la prima personalizzazione — un peso diverso, un esercizio tolto
     * per un infortunio — le cambierebbe tutte. E' esattamente il motivo per cui
     * esiste la distinzione fra modello e scheda assegnata: il modello e' un
     * punto di partenza, non un contratto vincolante.
     */
    public function assignTo(User $member, ?User $by = null): self
    {
        $copia = $this->replicate(['published_at']);
        $copia->member_id = $member->getKey();
        $copia->tenant_id = $member->tenant_id;
        $copia->created_by = $by?->getKey() ?? $this->created_by;
        $copia->status = PlanStatus::Draft;
        $copia->save();

        foreach ($this->exercises as $riga) {
            $nuova = $riga->replicate();
            $nuova->workout_plan_id = $copia->getKey();
            $nuova->save();
        }

        return $copia;
    }

    // ───────────────────────── query ─────────────────────────

    public function scopeTemplates(Builder $query): Builder
    {
        return $query->whereNull('member_id');
    }

    public function scopeAssigned(Builder $query): Builder
    {
        return $query->whereNotNull('member_id');
    }

    public function scopeForMember(Builder $query, User|int $member): Builder
    {
        return $query->where('member_id', $member instanceof User ? $member->getKey() : $member);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PlanStatus::Published->value);
    }
}
