<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dati antropometrici e obiettivi dell'iscritto.
 *
 * Alimenta il calcolo del fabbisogno calorico (B5.3) e i prompt dell'AI (B6):
 * eta', sesso, altezza e livello di attivita' sono gli ingredienti della
 * formula di Mifflin-St Jeor.
 */
class Profile extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = [
        'tenant_id', 'user_id', 'sex', 'birthdate', 'height_cm',
        'activity_level', 'goal', 'target_weight_kg', 'meal_hours', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'birthdate' => 'date',
            'height_cm' => 'integer',
            'target_weight_kg' => 'decimal:2',
            'meal_hours' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Eta' in anni compiuti, o null se non c'e' la data di nascita.
     *
     * Serve alle formule metaboliche, che senza eta' non si possono applicare:
     * meglio un null esplicito che un valore inventato che poi si propaga in un
     * fabbisogno calorico sbagliato.
     */
    public function age(): ?int
    {
        return $this->birthdate?->age;
    }

    /**
     * Moltiplicatore del metabolismo basale per livello di attivita'.
     *
     * Valori standard di Harris-Benedict. Il default e' `sedentary`, il piu'
     * basso: sovrastimare il consumo fa mangiare di piu' a chi vuole dimagrire,
     * ed e' l'errore che danneggia l'utente.
     */
    public function activityMultiplier(): float
    {
        return match ($this->activity_level) {
            'light' => 1.375,
            'moderate' => 1.55,
            'active' => 1.725,
            'very_active' => 1.9,
            default => 1.2,
        };
    }
}
