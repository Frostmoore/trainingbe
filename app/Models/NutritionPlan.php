<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Il piano alimentare prescritto a un iscritto.
 *
 * Come `WorkoutPlan`: `member_id` null = modello della palestra, e assegnare
 * significa copiare. Il perche' e' scritto per esteso in `WorkoutPlan::assignTo()`.
 */
class NutritionPlan extends Model
{
    use BelongsToTenant;
    use HasFactory;
    use SoftDeletes;

    protected $attributes = [
        'status' => 'draft',
    ];

    protected $fillable = [
        'tenant_id', 'member_id', 'created_by', 'name', 'notes',
        'target_kcal', 'target_protein_g', 'target_carbs_g', 'target_fat_g',
        'status', 'starts_at', 'ends_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
            'published_at' => 'datetime',
            'target_kcal' => 'integer',
            'target_protein_g' => 'integer',
            'target_carbs_g' => 'integer',
            'target_fat_g' => 'integer',
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

    public function meals(): HasMany
    {
        return $this->hasMany(NutritionPlanMeal::class)->orderBy('position');
    }

    // ───────────────────────── stato ─────────────────────────

    public function isTemplate(): bool
    {
        return $this->member_id === null;
    }

    /**
     * Il piano e' quello valido oggi?
     *
     * Le date sono facoltative: un piano senza scadenza resta valido, ed e' il
     * caso normale — la palestra lo sostituisce quando serve, non a calendario.
     */
    public function isActiveOn(Carbon $date): bool
    {
        if ($this->status !== PlanStatus::Published) {
            return false;
        }

        if ($this->starts_at !== null && $date->lt($this->starts_at)) {
            return false;
        }

        return ! ($this->ends_at !== null && $date->gt($this->ends_at));
    }

    public function publish(): void
    {
        $this->status = PlanStatus::Published;
        $this->published_at ??= now();
        $this->save();
    }

    /** Copia il modello addosso a un iscritto. Vedi `WorkoutPlan::assignTo()`. */
    public function assignTo(User $member, ?User $by = null): self
    {
        $copia = $this->replicate(['published_at']);
        $copia->member_id = $member->getKey();
        $copia->tenant_id = $member->tenant_id;
        $copia->created_by = $by?->getKey() ?? $this->created_by;
        $copia->status = PlanStatus::Draft;
        $copia->save();

        foreach ($this->meals()->with('items')->get() as $pasto) {
            $nuovoPasto = $pasto->replicate();
            $nuovoPasto->nutrition_plan_id = $copia->getKey();
            $nuovoPasto->save();

            foreach ($pasto->items as $item) {
                $nuovo = $item->replicate();
                $nuovo->nutrition_plan_meal_id = $nuovoPasto->getKey();
                $nuovo->save();
            }
        }

        return $copia;
    }

    /**
     * Il piano attivo di un iscritto oggi, se ce n'e' uno.
     *
     * Se ce ne fosse piu' d'uno vince il piu' recente: e' l'unica scelta che non
     * richiede all'utente di sapere che ne ha due.
     */
    public static function activeFor(User|int $member, ?Carbon $date = null): ?self
    {
        $date ??= Carbon::today();
        $id = $member instanceof User ? $member->getKey() : $member;

        return static::query()
            ->where('member_id', $id)
            ->where('status', PlanStatus::Published->value)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', $date))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $date))
            ->orderByDesc('published_at')
            ->first();
    }

    public function scopeTemplates(Builder $query): Builder
    {
        return $query->whereNull('member_id');
    }

    public function scopeForMember(Builder $query, User|int $member): Builder
    {
        return $query->where('member_id', $member instanceof User ? $member->getKey() : $member);
    }
}
