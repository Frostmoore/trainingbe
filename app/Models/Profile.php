<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Nutrition\CalorieCalculator;
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

    /**
     * I livelli di attivita' ammessi, con l'etichetta italiana.
     *
     * 🚨 **Questa e' la fonte unica.** Prima della fase C lo stesso elenco viveva
     * in tre posti — il form di Filament, il `match` di `activityForFormula()` e
     * un commento nella migration — e nessuno dei tre sapeva degli altri.
     * Aggiungere un livello in uno solo produce un valore che il pannello offre e
     * l'API rifiuta, oppure che l'API accetta e la formula traduce in
     * `sedentary` **senza dirlo**: il fabbisogno risulta piu' basso del vero e
     * nessun errore compare da nessuna parte.
     *
     * Chi aggiunge una voce qui deve aggiungerla anche in `activityForFormula()`.
     *
     * @var array<string, string>
     */
    public const ACTIVITY_LEVELS = [
        'sedentary' => 'Sedentario',
        'light' => 'Leggero (1-2 allenamenti)',
        'moderate' => 'Moderato (3-4)',
        'active' => 'Attivo (5-6)',
        'very_active' => 'Atleta (due sedute al giorno)',
    ];

    /**
     * Gli obiettivi ammessi dal profilo, con l'etichetta italiana.
     *
     * ⚠️ `cut` (definizione) NON e' qui: si imposta dal piano alimentare, non dal
     * profilo. Vedi `goalForFormula()`.
     *
     * @var array<string, string>
     */
    public const GOALS = [
        'lose_weight' => 'Dimagrire',
        'maintain' => 'Mantenere',
        'gain_muscle' => 'Aumentare massa',
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
     * 🚨 Delega a `CalorieCalculator::ACTIVITY` invece di riscrivere la tabella.
     * Averla in due posti significa che prima o poi ne cambia uno solo, e da
     * quel momento il fabbisogno calcolato dal pannello e quello calcolato
     * dall'API sono due numeri diversi senza che nessun test se ne accorga.
     */
    public function activityMultiplier(): float
    {
        return CalorieCalculator::ACTIVITY[$this->activityForFormula()];
    }

    // ───────────────────── traduzione verso le formule ─────────────────────
    //
    // 🚨 I valori salvati in questa tabella e quelli attesi da
    // `CalorieCalculator` NON coincidono, ed e' un fatto storico: la tabella
    // nasce in B1.4, il calcolatore e' un port dell'app precedente con il suo
    // vocabolario. Invece di migrare i dati — che vorrebbe dire toccare righe
    // gia' in produzione su staging — la traduzione sta qui, in un punto solo e
    // dichiarato. Chi aggiunge un livello o un obiettivo deve aggiornare
    // **questi tre metodi**, e i test di `ProfileTargetsTest` falliscono se non
    // lo fa.

    /** `m`/`f` → il vocabolario di Mifflin-St Jeor. */
    public function sexForFormula(): string
    {
        return $this->sex === 'm' ? 'male' : 'female';
    }

    /** `very_active` qui e' `athlete` nel calcolatore. */
    public function activityForFormula(): string
    {
        return match ($this->activity_level) {
            'light' => 'light',
            'moderate' => 'moderate',
            'active' => 'active',
            'very_active' => 'athlete',
            default => 'sedentary',
        };
    }

    /**
     * `lose_weight`/`gain_muscle` → `lose`/`bulk`.
     *
     * `cut` non ha corrispondente in questa tabella: e' un obiettivo che si
     * imposta dal piano alimentare, non dal profilo.
     */
    public function goalForFormula(): string
    {
        return match ($this->goal) {
            'lose_weight' => 'lose',
            'gain_muscle' => 'bulk',
            default => 'maintain',
        };
    }

    /**
     * Il fabbisogno e i macro di questa persona.
     *
     * `null` quando mancano gli ingredienti della formula: senza altezza, data
     * di nascita o peso non si calcola niente, e restituire un numero inventato
     * sarebbe peggio che non restituirne nessuno — l'utente ci costruirebbe
     * sopra una dieta.
     *
     * @return array{kcal: int, protein_g: int, carbs_g: int, fat_g: int, bmr: float, tdee: float}|null
     */
    public function computedTargets(?float $weightKg = null): ?array
    {
        $peso = $weightKg ?? $this->user?->latestWeight();
        $eta = $this->age();

        if ($peso === null || $eta === null || $this->height_cm === null) {
            return null;
        }

        $calc = app(CalorieCalculator::class);

        $bmr = $calc->bmr($this->sexForFormula(), $peso, (float) $this->height_cm, $eta);
        $tdee = $calc->tdee($bmr, $this->activityForFormula());
        $kcal = $calc->calorieTarget($tdee, $this->goalForFormula());

        return array_merge(
            ['kcal' => $kcal, 'bmr' => $bmr, 'tdee' => $tdee],
            $calc->macros($kcal, $this->goalForFormula()),
        );
    }
}
