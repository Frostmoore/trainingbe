<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tempo\GiornoLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
     * Il piano e' quello valido in un dato giorno?
     *
     * Le date sono facoltative: un piano senza scadenza resta valido, ed e' il
     * caso normale — la palestra lo sostituisce quando serve, non a calendario.
     *
     * ⚠️ **Oggi non lo chiama nessuno**: il percorso vivo passa da
     * `activeFor()`, che fa lo stesso confronto in SQL. E' stato migrato a
     * `GiornoLocale` lo stesso, perche' un metodo pubblico rimasto indietro e'
     * una trappola armata: il primo che lo usa reintroduce il confine in UTC
     * senza che niente glielo segnali.
     */
    public function isActiveOn(GiornoLocale $giorno): bool
    {
        if ($this->status !== PlanStatus::Published) {
            return false;
        }

        // Il confronto e' fra etichette `Y-m-d`: `starts_at` e `ends_at` sono
        // colonne `date`, non istanti.
        if ($this->starts_at !== null && $giorno->etichetta < $this->starts_at->toDateString()) {
            return false;
        }

        return ! ($this->ends_at !== null && $giorno->etichetta > $this->ends_at->toDateString());
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
    /**
     * @param  GiornoLocale|null  $giorno  `null` = oggi **per questo iscritto**
     */
    public static function activeFor(User $member, ?GiornoLocale $giorno = null): ?self
    {
        /*
         * ⚠️ Il parametro si e' ristretto da `User|int` a `User` in A3, e non e'
         * un vezzo: per sapere quando comincia «oggi» serve il fuso della
         * persona, e da un id non si ricava. Con un intero l'unico ripiego
         * sarebbe stato UTC — cioe' il difetto che questa fase chiude.
         */
        $giorno ??= $member->giornoDiOggi();

        return static::query()
            ->where('member_id', $member->getKey())
            ->where('status', PlanStatus::Published->value)
            ->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhereDate('starts_at', '<=', $giorno->etichetta))
            ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhereDate('ends_at', '>=', $giorno->etichetta))
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
