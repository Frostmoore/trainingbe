<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\MealType;
use App\Rules\AlMassimoTreAlternative;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Il piano alimentare che scrive un trainer — G5.4.
 *
 * 🚨 **Non esisteva niente di simile prima di G5.** I piani alimentari avevano
 * un editor nel pannello e **nessuna API di scrittura**: `GET /nutrition-plan`
 * (il piano dell'iscritto) e `eatMeal()` erano tutto. Un trainer non poteva
 * comporre un piano dall'app perche' non c'era dove mandarlo.
 *
 * ── L'albero, per intero ─────────────────────────────────────────────────
 *
 *     piano
 *       └ days[]              ← giorni, con le loro alternative
 *           ├ meals[]         ← pasti, con le loro alternative
 *           │   └ items[]     ← alimenti, con le loro alternative
 *           └ alternatives[]
 *
 * ⚠️ **Le alternative si fermano a un livello**: un'alternativa non ha
 * alternative. Se le avesse, «quale piano sto seguendo» smetterebbe di avere
 * una risposta unica — e nessun trainer scrive un piano cosi'.
 */
class NutritionPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        // L'autorizzazione sta nel controller, che sa distinguere «piano mio» da
        // «piano di un altro trainer della stessa palestra».
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],

            // D3 — il promemoria privato. Lo vede solo chi l'ha scritto (R4).
            'rif_allievo' => ['nullable', 'string', 'max:120'],

            'target_kcal' => ['nullable', 'integer', 'min:0', 'max:20000'],
            'target_protein_g' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'target_carbs_g' => ['nullable', 'integer', 'min:0', 'max:2000'],
            'target_fat_g' => ['nullable', 'integer', 'min:0', 'max:2000'],

            /*
             * ⚠️ **Il tetto non e' pignoleria.** La scrittura e' annidata e in
             * transazione: senza limiti, una richiesta malformata — o un ciclo
             * nell'app — genererebbe migliaia di righe in una volta sola,
             * tenendo il lock per tutto il tempo.
             */
            'days' => ['sometimes', 'array', 'max:14'],
            'days.*.name' => ['nullable', 'string', 'max:120'],
            'days.*.notes' => ['nullable', 'string', 'max:2000'],

            ...$this->regolePasti('days.*'),

            // D2 — le alternative a un giorno intero, coi loro pasti.
            'days.*.alternatives' => ['sometimes', 'array', new AlMassimoTreAlternative],
            'days.*.alternatives.*.name' => ['nullable', 'string', 'max:120'],
            'days.*.alternatives.*.notes' => ['nullable', 'string', 'max:2000'],
            ...$this->regolePasti('days.*.alternatives.*'),
        ];
    }

    /**
     * Le regole dei pasti di un giorno — vale sia per i giorni veri sia per le
     * loro alternative.
     *
     * 💡 Scritte una volta sola: un pasto e' un pasto, e due copie della stessa
     * regola divergono. La prima a divergere sarebbe quella dei giorni
     * alternativi, cioe' quella che nessuno prova.
     *
     * @return array<string, mixed>
     */
    private function regolePasti(string $p): array
    {
        return [
            "{$p}.meals" => ['sometimes', 'array', 'max:12'],
            ...$this->regoleUnPasto("{$p}.meals.*"),

            // Le alternative a un pasto intero, coi loro alimenti.
            "{$p}.meals.*.alternatives" => ['sometimes', 'array', new AlMassimoTreAlternative],
            ...$this->regoleUnPasto("{$p}.meals.*.alternatives.*"),
        ];
    }

    /** @return array<string, mixed> */
    private function regoleUnPasto(string $p): array
    {
        return [
            "{$p}.meal" => ['required', Rule::enum(MealType::class)],
            "{$p}.title" => ['nullable', 'string', 'max:160'],
            "{$p}.notes" => ['nullable', 'string', 'max:2000'],

            "{$p}.items" => ['sometimes', 'array', 'max:30'],
            ...$this->regoleAlimento("{$p}.items.*"),

            // D2 — le alternative a un singolo alimento.
            "{$p}.items.*.alternatives" => ['sometimes', 'array', new AlMassimoTreAlternative],
            ...$this->regoleAlimento("{$p}.items.*.alternatives.*"),
        ];
    }

    /**
     * Le regole di un alimento — identiche ovunque compaia.
     *
     * 🚨 **Un'alternativa ha gli stessi campi dell'alimento che sostituisce**, ed
     * e' il cuore di D2: un'alternativa senza macro non si puo' importare nel
     * diario, che e' esattamente cio' che il committente ha chiesto di poter
     * fare.
     *
     * @return array<string, mixed>
     */
    private function regoleAlimento(string $p): array
    {
        return [
            "{$p}.description" => ['required', 'string', 'max:255'],
            "{$p}.qty" => ['nullable', 'numeric', 'min:0', 'max:100000'],
            "{$p}.unit" => ['nullable', 'string', 'max:16'],
            "{$p}.grams" => ['nullable', 'numeric', 'min:0', 'max:100000'],
            "{$p}.kcal" => ['nullable', 'numeric', 'min:0', 'max:100000'],
            "{$p}.protein" => ['nullable', 'numeric', 'min:0', 'max:10000'],
            "{$p}.carbs" => ['nullable', 'numeric', 'min:0', 'max:10000'],
            "{$p}.fat" => ['nullable', 'numeric', 'min:0', 'max:10000'],

            /*
             * D13 — chi ha scritto questi valori.
             *
             * ⚠️ Di serie `manual`: se l'app non lo dice, li ha messi una
             * persona. Attribuire all'AI un lavoro che non ha fatto renderebbe
             * inutile la colonna il giorno che serve davvero — cioe' quando un
             * trainer vuole sapere cosa ha gia' controllato.
             */
            "{$p}.origine_valori" => ['nullable', Rule::in(['ai', 'manual'])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name.required' => __('Dai un nome al piano.'),
            'days.max' => __('Un piano non può avere più di 14 giorni.'),
        ];
    }

    /**
     * I giorni, o `null` se la richiesta non li porta.
     *
     * ⚠️ **`null` e `[]` sono diversi**: `null` vuol dire «non toccare i
     * giorni», `[]` vuol dire «il piano non ha più giorni». Confonderli
     * svuoterebbe un piano a ogni rinomina.
     *
     * @return list<array<string, mixed>>|null
     */
    public function giorni(): ?array
    {
        if (! $this->has('days')) {
            return null;
        }

        return array_values($this->validated()['days'] ?? []);
    }
}
