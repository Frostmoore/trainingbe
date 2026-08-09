<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MuscleGroup;
use App\Models\Concerns\BelongsToTenantOrGlobal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Un esercizio: della piattaforma (`tenant_id` null) o di una palestra.
 *
 * Ogni palestra vede i propri **piu'** i globali, ma puo' modificare solo i
 * propri — e quel limite sta nella policy, non nello scope: uno scope di lettura
 * non e' il posto dove far rispettare i permessi di scrittura, perche' un
 * update non passa necessariamente da una select.
 */
class Exercise extends Model
{
    use BelongsToTenantOrGlobal;
    use HasFactory;
    use SoftDeletes;

    protected $attributes = [
        'is_custom' => false,
    ];

    protected $fillable = [
        'tenant_id', 'created_by', 'name', 'muscle_group', 'equipment',
        'description', 'is_custom', 'met',
    ];

    protected function casts(): array
    {
        return [
            'muscle_group' => MuscleGroup::class,
            'is_custom' => 'boolean',
            'met' => 'float',
        ];
    }

    protected static function booted(): void
    {
        // 🚨 La forma canonica si ricalcola a ogni scrittura del nome, qui e non
        // nei chiamanti: `ExerciseMatcher` (B7.3) cerca **solo** su questa
        // colonna, e una riga salvata da un punto che si e' dimenticato di
        // aggiornarla diventa un esercizio invisibile al matcher — che quindi ne
        // creerebbe un duplicato al primo import.
        static::saving(function (self $exercise): void {
            if ($exercise->isDirty('name') || $exercise->slug_normalized === null) {
                $exercise->slug_normalized = self::normalize((string) $exercise->name);
            }
        });
    }

    // ───────────────────────── normalizzazione ─────────────────────────

    /**
     * Il nome ridotto a forma confrontabile.
     *
     * «Panca Piana», «panca  piana» e «Panca piana!» devono dare la stessa
     * stringa. Non tocca i sinonimi («bench press»): quelli sono un problema di
     * vocabolario e li risolve `ExerciseMatcher`, che pero' parte da qui.
     */
    public static function normalize(string $name): string
    {
        $s = Str::of($name)->ascii()->lower()->toString();
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    // ───────────────────────── relazioni ─────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ───────────────────────── query ─────────────────────────

    /** Ordinamento utile a un elenco: prima i globali, poi per nome. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('tenant_id IS NOT NULL')->orderBy('name');
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $norm = self::normalize($term);

        return $query->where(function (Builder $q) use ($term, $norm): void {
            $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('slug_normalized', 'like', '%'.$norm.'%');
        });
    }

    /** Il MET dell'esercizio, o quello generico se non e' noto. */
    public function metOrDefault(float $default): float
    {
        return $this->met !== null && $this->met > 0 ? (float) $this->met : $default;
    }
}
