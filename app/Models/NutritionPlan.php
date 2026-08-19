<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanStatus;
use App\Enums\TipoPianoAlimentare;
use App\Models\Concerns\BelongsToTenant;
use App\Support\Tempo\GiornoLocale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

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
        /*
         * 🚨 **Consigli, non piano.** Il default e' il tipo che si puo'
         * scrivere: cosi' un punto del codice che dimenticasse di indicarlo
         * crea un documento **piu' povero** del dovuto, non uno che qualcuno
         * non aveva il titolo di scrivere.
         *
         * ⚠️ Nella migrazione il default e' l'opposto (`piano`), e non e' una
         * contraddizione: la' si stava dando un nome a righe **gia' esistenti**,
         * qui si sta creando qualcosa di nuovo.
         */
        'tipo' => 'consigli',
    ];

    protected $fillable = [
        // G4 — D3 (il promemoria privato) e D15 (l'identita' stabile).
        'rif_allievo', 'origine_id',
        'tenant_id', 'member_id', 'created_by', 'name', 'tipo', 'notes',
        'target_kcal', 'target_protein_g', 'target_carbs_g', 'target_fat_g',
        'status', 'starts_at', 'ends_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'tipo' => TipoPianoAlimentare::class,
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

    /**
     * L'identita' stabile nasce con il piano — G4, D15.
     *
     * 🚨 **Non basta che la crei il controller.** Un piano nasce anche dal
     * pannello Filament, dai seeder, dall'import PDF e dai test: sei punti, e
     * pretendere che tutti si ricordino di generare un ULID e' il modo di
     * scoprire che uno non se l'e' ricordato — con il sintomo peggiore, cioe'
     * un piano che il telefono **non riconosce** e affianca invece di
     * sostituire.
     *
     * ⚠️ La colonna e' `unique` e nullable: piu' righe a `null` sono ammesse da
     * MariaDB, quindi l'indice da solo non protegge niente. Questo hook si'.
     *
     * 💡 Stessa forma di `PlanExercise::booted()`: l'invariante si rende
     * impossibile da violare, invece di limitarsi a rilevarlo.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $piano): void {
            $piano->origine_id ??= (string) Str::ulid();
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** I giorni del piano — **solo i principali** (G4, D2). */
    public function days(): HasMany
    {
        return $this->hasMany(NutritionPlanDay::class)
            ->whereNull('alternativa_di_id')
            ->orderBy('position');
    }

    /** Tutti i giorni, alternative comprese. */
    public function daysConAlternative(): HasMany
    {
        return $this->hasMany(NutritionPlanDay::class)->orderBy('position');
    }

    /**
     * Tutti i pasti del piano, di tutti i giorni — **senza le alternative**.
     *
     * 🚨 Vedi la nota gemella su `WorkoutPlan::exercises()`: da D2 le
     * alternative sono righe di questa stessa tabella, e senza il filtro un
     * piano da 4 pasti ne mostrerebbe 11.
     */
    public function meals(): HasMany
    {
        return $this->hasMany(NutritionPlanMeal::class)
            ->whereNull('alternativa_di_id')
            ->orderBy('position');
    }

    /** Tutti i pasti, alternative comprese. */
    public function mealsConAlternative(): HasMany
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
        $copia = $this->replicate(['published_at', 'origine_id']);
        $copia->member_id = $member->getKey();
        $copia->tenant_id = $member->tenant_id;
        $copia->created_by = $by?->getKey() ?? $this->created_by;
        $copia->status = PlanStatus::Draft;

        // 🚨 Identita' nuova, non copiata — D15. Vedi `WorkoutPlan::assignTo()`.
        $copia->origine_id = (string) Str::ulid();
        $copia->save();

        $this->copiaIGiorniIn($copia);

        return $copia;
    }

    /**
     * Il primo giorno del piano, creandolo se non c'e' — G4.
     *
     * 🚨 `nutrition_plan_meals.nutrition_plan_day_id` e' `NOT NULL`: ogni punto
     * che scrive pasti passa di qui.
     */
    public function giornoPredefinito(): NutritionPlanDay
    {
        return $this->days()->first()
            ?? $this->days()->create(['position' => 0]);
    }

    /**
     * Copia giorni, pasti, alimenti e alternative dentro un altro piano.
     *
     * 🚨 **Due passate per livello, e sono tre livelli.** Le alternative sono
     * righe della stessa tabella e puntano a cio' che sostituiscono: copiate
     * alla cieca, resterebbero agganciate all'originale. Vedi la nota lunga su
     * `WorkoutPlan::copiaIGiorniIn()`.
     */
    private function copiaIGiorniIn(self $destinazione): void
    {
        $mappaGiorni = [];

        foreach ($this->daysConAlternative()->whereNull('alternativa_di_id')->get() as $giorno) {
            $nuovo = $giorno->replicate();
            $nuovo->nutrition_plan_id = $destinazione->getKey();
            $nuovo->alternativa_di_id = null;
            $nuovo->save();

            $mappaGiorni[$giorno->getKey()] = $nuovo->getKey();
            $this->copiaIPastiIn($giorno, $nuovo, $destinazione);
        }

        foreach ($this->daysConAlternative()->whereNotNull('alternativa_di_id')->get() as $giorno) {
            $nuovo = $giorno->replicate();
            $nuovo->nutrition_plan_id = $destinazione->getKey();
            $nuovo->alternativa_di_id = $mappaGiorni[$giorno->alternativa_di_id] ?? null;
            $nuovo->save();

            $this->copiaIPastiIn($giorno, $nuovo, $destinazione);
        }
    }

    private function copiaIPastiIn(NutritionPlanDay $da, NutritionPlanDay $a, self $destinazione): void
    {
        $mappaPasti = [];

        foreach ($da->mealsConAlternative()->whereNull('alternativa_di_id')->get() as $pasto) {
            $nuovo = $pasto->replicate();
            $nuovo->nutrition_plan_id = $destinazione->getKey();
            $nuovo->nutrition_plan_day_id = $a->getKey();
            $nuovo->alternativa_di_id = null;
            $nuovo->save();

            $mappaPasti[$pasto->getKey()] = $nuovo->getKey();
            $this->copiaGliAlimentiIn($pasto, $nuovo);
        }

        foreach ($da->mealsConAlternative()->whereNotNull('alternativa_di_id')->get() as $pasto) {
            $nuovo = $pasto->replicate();
            $nuovo->nutrition_plan_id = $destinazione->getKey();
            $nuovo->nutrition_plan_day_id = $a->getKey();
            $nuovo->alternativa_di_id = $mappaPasti[$pasto->alternativa_di_id] ?? null;
            $nuovo->save();

            $this->copiaGliAlimentiIn($pasto, $nuovo);
        }
    }

    private function copiaGliAlimentiIn(NutritionPlanMeal $da, NutritionPlanMeal $a): void
    {
        $mappa = [];

        foreach ($da->itemsConAlternative()->whereNull('alternativa_di_id')->get() as $item) {
            $nuovo = $item->replicate();
            $nuovo->nutrition_plan_meal_id = $a->getKey();
            $nuovo->alternativa_di_id = null;
            $nuovo->save();

            $mappa[$item->getKey()] = $nuovo->getKey();
        }

        foreach ($da->itemsConAlternative()->whereNotNull('alternativa_di_id')->get() as $item) {
            $nuovo = $item->replicate();
            $nuovo->nutrition_plan_meal_id = $a->getKey();
            $nuovo->alternativa_di_id = $mappa[$item->alternativa_di_id] ?? null;
            $nuovo->save();
        }
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
