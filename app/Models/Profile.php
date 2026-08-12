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
        'sedentary' => 'Sedentario (lavoro da fermo, niente allenamenti)',
        'light' => 'Leggermente attivo (1-2 allenamenti a settimana)',
        'moderate' => 'Moderatamente attivo (3-4 a settimana)',
        'active' => 'Molto attivo (5-6 a settimana)',
        'very_active' => 'Estremamente attivo (ogni giorno, o due sedute)',
    ];

    /**
     * Gli obiettivi ammessi dal profilo, con l'etichetta italiana.
     *
     * ── 🚨 Perche' sono cinque e non tre (12/08/2026) ─────────────────────
     *
     * Il committente: *«non e' una stima accurata se sono solo 3 scelte»*. Con
     * tre gradini l'unica alternativa a «mantenere» era un taglio del 15%, che e'
     * troppo per chi vuole andare piano e troppo poco per chi ha fretta — e chi
     * non si ritrova nel numero smette di fidarsi dell'app, non dell'obiettivo.
     *
     * La scala e' **simmetrica**: −20% / −10% / 0 / +10% / +20%. Su un TDEE di
     * 2.500 kcal fa 2.000 → 2.250 → 2.500 → 2.750 → 3.000.
     *
     * ⚠️ **La scelta resta della persona**, e nella maggior parte dei casi gliela
     * suggerira' il suo trainer: qui non si decide niente al posto suo, si
     * offrono gradini abbastanza fini da poterla esprimere.
     *
     * 💡 `cut` **non c'e' piu'**. C'era, valeva −25%, e il docblock diceva che si
     * impostava dal piano alimentare: nel piano alimentare non c'era una riga che
     * lo facesse. Il suo posto lo prende `lose_fast`.
     *
     * Chi aggiunge una voce qui deve aggiungerla anche in
     * `CalorieCalculator::GOAL_DELTA` e `::MACRO_SPLIT`.
     *
     * @var array<string, string>
     */
    public const GOALS = [
        'lose_fast' => 'Dimagrimento rapido',
        'lose_slow' => 'Dimagrimento graduale',
        'maintain' => 'Mantenimento',
        'gain_lean' => 'Aumento massa controllato',
        'gain_fast' => 'Aumento massa rapido',
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
     * L'obiettivo nel vocabolario del calcolatore.
     *
     * 🚨 **Dal 12/08/2026 i due vocabolari coincidono**, e questa e' una scelta,
     * non una coincidenza: la traduzione fra `lose_weight` e `lose` e' costata
     * un target di **mantenimento** a chi aveva scritto «voglio dimagrire», per
     * settimane, senza che niente lo segnalasse. Due elenchi da tenere allineati
     * a mano prima o poi divergono; uno solo no.
     *
     * ⚠️ Il metodo resta perche' deve tradurre i valori **salvati prima**
     * (`lose_weight`, `gain_muscle`, e i `lose`/`cut`/`bulk` interni), e perche'
     * l'app ne ha un ritratto fedele in `UserProfile.obiettivoPerFormula`.
     */
    public function goalForFormula(): string
    {
        $goal = CalorieCalculator::normalizzaObiettivo((string) $this->goal);

        // Il vocabolario precedente del profilo, per i dati non ancora migrati.
        $goal = match ($goal) {
            'lose_weight' => 'lose_slow',
            'gain_muscle' => 'gain_lean',
            default => $goal,
        };

        return isset(CalorieCalculator::GOAL_DELTA[$goal]) ? $goal : 'maintain';
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
        /*
         * 🚨 **Il server non sa piu' quanto pesa nessuno** — S5.5.
         *
         * `body_metrics` non esiste (decisione D9-bis). Senza un peso passato
         * esplicitamente qui non si calcola niente, e **va bene cosi'**: chi
         * chiede i target di una persona vera li calcola nell'app, con
         * `CalcolatoreCalorie` (il ritratto fedele di `CalorieCalculator`,
         * S5.1), che ha il peso in locale.
         *
         * ⚠️ Il parametro resta perche' serve al **pannello del trainer**, che
         * compone i **modelli** di piano su valori d'esempio — e i modelli non
         * sono dati personali (decisione D6). E' il motivo per cui
         * `CalorieCalculator` non e' stato cancellato dal backend.
         */
        $peso = $weightKg;
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
