<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\MealType;
use App\Http\Controllers\Controller;
use App\Models\Profile;
use App\Models\User;
use App\Services\Nutrition\CalorieCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Il profilo dell'iscritto, letto e scritto dall'app — C1.
 *
 * 🚨 **Perche' questa classe e' la prima della fase C.** Senza sesso, eta',
 * altezza e livello di attivita' non esiste BMR, quindi non esiste TDEE, quindi
 * non esiste un target calorico. Fino a ieri il profilo si poteva modificare
 * **solo dal pannello della palestra**: nell'app il diario mostrava «Nessun
 * obiettivo impostato», che era corretto e inutile, perche' non offriva il modo
 * di impostarlo. Diario, dashboard e calendario dipendono tutti da qui.
 *
 * ⚠️ **Non c'e' nessun id nelle rotte**: si legge e si scrive sempre e solo il
 * proprio profilo, preso da `$request->user()`. Un id in ingresso sarebbe una
 * cosa da autorizzare, e la cosa piu' semplice da autorizzare e' quella che non
 * si puo' chiedere.
 */
class ProfileController extends Controller
{
    public function __construct(private readonly CalorieCalculator $calc) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->rappresenta($request->user())]);
    }

    /**
     * ══ 🆕 🚨 IL PROFILO NON SI SCRIVE PIU' QUI — 19/09/2026 ══════════════
     *
     * ⛔ Qui si salvavano sesso, data di nascita, altezza, livello di
     * attivita', obiettivo, peso obiettivo e orari dei pasti. 📌 Il
     * committente: *«assicurati che non ci scriva niente di personale
     * (esattamente come peso altezza e data di nascita)»*. Da `fe v18.1.0`
     * stanno sul telefono (`ProfiloSulTelefono`).
     *
     * 💡 **410 e non un «ok» che ignora i campi**: un'app vecchia che salva
     * crederebbe di aver salvato, e alla prossima apertura ritroverebbe i
     * campi vuoti senza sapere perche'. Cosi' invece lo dice.
     */
    public function update(Request $request): JsonResponse
    {
        return response()->json([
            'message' => __('Il profilo adesso resta sul tuo telefono: aggiorna l\'app.'),
            'code' => 'profilo_sul_telefono',
        ], 410);
    }

    /**
     * 🆕 **Toglie dal server i dati personali del profilo** — 19/09/2026.
     *
     * 💡 La chiama l'app **dopo** averli copiati sul telefono e riletti
     * (`TraslocoDelProfilo`): prima si sposta, poi si cancella, mai il
     * contrario.
     *
     * ⚠️ Restano le note dello staff (`notes`): le scrive la palestra, non la
     * persona.
     */
    public function destroy(Request $request): JsonResponse
    {
        $request->user()?->profile?->forceFill([
            'sex' => null,
            'birthdate' => null,
            'height_cm' => null,
            'activity_level' => null,
            'goal' => null,
            'target_weight_kg' => null,
            'meal_hours' => null,
        ])->save();

        return response()->json(['message' => __('Tolti dal server.')]);
    }

    /**
     * Il profilo piu' tutto cio' che se ne deduce.
     *
     * 🚨 **I valori derivati li calcola il server, non l'app.** L'applicazione
     * storica duplica Mifflin-St Jeor in JavaScript per l'anteprima live, ed e'
     * debito: il giorno che cambia una costante in `CalorieCalculator`, il
     * client mente finche' non lo si aggiorna e nessun test se ne accorge.
     *
     * @return array<string, mixed>
     */
    private function rappresenta(User $utente): array
    {
        $profilo = $utente->profile;
        /*
         * 🚨 **`null`, e non e' una dimenticanza** — S5.5.
         *
         * Il peso non sta piu' sul server (D9-bis), quindi `weight_kg` entra
         * SEMPRE in `missing` e `derived` e' SEMPRE `null`. L'app se li calcola
         * da sola con `CalcolatoreCalorie`, che ha il peso nel proprio archivio.
         *
         * ⚠️ **Il contratto non cambia forma**, e questo e' voluto: `missing` e
         * `derived` esistevano gia' e l'app li sapeva gia' leggere. Chi legge la
         * risposta non vede un endpoint nuovo — vede un profilo a cui manca un
         * pezzo, che e' una situazione che sapeva gia' gestire.
         */
        $peso = null;
        $mancanti = $this->mancanti($profilo, $peso);

        return [
            'sex' => $profilo?->sex,
            'birthdate' => $profilo?->birthdate?->toDateString(),
            'age' => $profilo?->age(),
            'height_cm' => $profilo?->height_cm,
            'activity_level' => $profilo?->activity_level,
            'goal' => $profilo?->goal,
            'target_weight_kg' => $profilo?->target_weight_kg !== null
                ? (float) $profilo->target_weight_kg
                : null,

            // Sempre i sei pasti, con i valori di serie dove l'utente non ha
            // scelto: cosi' l'app disegna il modulo senza dover conoscere i
            // default, che altrimenti finirebbero scritti anche in Dart.
            //
            // 🚨 **Sempre esattamente sei, mai di piu'.** In scrittura le chiavi
            // sconosciute le scarta `UpdateProfileRequest`, ma nella colonna
            // possono essercene di vecchie scritte da altrove — il seeder
            // dimostrativo ne aveva tre in italiano, che non corrispondevano a
            // nessun pasto e quindi non avevano alcun effetto, ma comparivano
            // nella risposta facendo sembrare che i pasti fossero nove. Una
            // lettura piu' permissiva della scrittura e' un modo per far vedere
            // all'app dati che l'app non sa cosa siano.
            'meal_hours' => $this->orariCompleti($profilo?->meal_hours),

            // Il peso non sta nel profilo: e' una serie storica (`body_metrics`),
            // e qui compare solo come ultimo valore noto, perche' senza non si
            // calcola niente.
            'weight_kg' => $peso,

            'derived' => $mancanti === [] ? $this->derivati($profilo, $peso) : null,

            /*
             * 🚨 `missing` non e' un lusso: e' l'elenco dei campi che mancano
             * per calcolare il target. Senza, l'app puo' solo dire «manca
             * qualcosa» e lasciare la persona a indovinare quale — ed e' il tipo
             * di schermata che si chiude senza completare niente.
             */
            'missing' => $mancanti,

            'options' => [
                'activity_levels' => Profile::ACTIVITY_LEVELS,
                'goals' => Profile::GOALS,
            ],
        ];
    }

    /**
     * I sei orari dei pasti: quelli scelti dall'utente sopra quelli di serie.
     *
     * Le chiavi che non sono un `MealType` si scartano — vedi la nota in
     * `rappresenta()`.
     *
     * @param  array<string, string>|null  $scelti
     * @return array<string, string>
     */
    private function orariCompleti(?array $scelti): array
    {
        $validi = array_intersect_key(
            $scelti ?? [],
            MealType::DEFAULT_HOURS,
        );

        return array_merge(MealType::DEFAULT_HOURS, $validi);
    }

    /**
     * I campi che mancano per calcolare il fabbisogno.
     *
     * L'ordine e' quello in cui conviene chiederli, non quello della tabella.
     *
     * @return list<string>
     */
    private function mancanti(?Profile $profilo, ?float $peso): array
    {
        $mancanti = [];

        if ($peso === null) {
            $mancanti[] = 'weight_kg';
        }

        if ($profilo?->sex === null) {
            $mancanti[] = 'sex';
        }

        if ($profilo?->birthdate === null) {
            $mancanti[] = 'birthdate';
        }

        if ($profilo?->height_cm === null) {
            $mancanti[] = 'height_cm';
        }

        return $mancanti;
    }

    /**
     * BMI, BMR, TDEE, target e macro.
     *
     * ⚠️ `computedTargets()` vive sul modello e lo usa anche il pannello: qui non
     * si rifa' il conto, si aggiunge solo il BMI, che il pannello non mostra.
     *
     * @return array<string, mixed>
     */
    private function derivati(Profile $profilo, float $peso): array
    {
        $t = $profilo->computedTargets($peso);

        if ($t === null) {
            return [];
        }

        return [
            'bmi' => $this->calc->bmi($peso, (float) $profilo->height_cm),
            'bmr' => $t['bmr'],
            'tdee' => $t['tdee'],
            'target_kcal' => $t['kcal'],
            'macros' => [
                'protein_g' => $t['protein_g'],
                'carbs_g' => $t['carbs_g'],
                'fat_g' => $t['fat_g'],
            ],
        ];
    }
}
