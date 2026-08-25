<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Enums\MuscleGroup;
use App\Rules\AlMassimoTreAlternative;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * La scheda che l'iscritto si scrive da solo — C2.2.
 *
 * L'autorizzazione non sta qui: `store()` crea sempre una scheda propria, e
 * `update()` passa da `WorkoutPlanPolicy`, che è il posto dove vive la
 * distinzione fra «scheda mia» e «scheda del trainer».
 */
class WorkoutPlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:2000'],

            /*
             * ⛔ **`base_updated_at` NON esiste piu'** — 3b-B.17.7, 25/08/2026.
             *
             * Era il protocollo di conflitto di B.16.6: chi scriveva dichiarava
             * su quale versione stava lavorando e prendeva un 409 se nel
             * frattempo la scheda era cambiata. 🚨 Se n'e' andato con la
             * sincronizzazione che serviva: le schede dell'iscritto vivono sul
             * telefono, e **nessun client lo mandava** — era facoltativo, e
             * l'unico che avrebbe potuto mandarlo (il compositore del trainer)
             * non e' mai stato insegnato a farlo.
             *
             * 💡 Se un giorno servisse davvero — due trainer sullo stesso
             * modello — si rifa': la forma sta nel commit `v11.6.0`, e rifarla
             * costa meno che tenere in piedi un protocollo che nessuno parla.
             */
            // Il tetto non è pignoleria: `syncRows()` scrive una riga per
            // elemento, e senza limite una richiesta malformata (o un ciclo
            // nell'app) può generare migliaia di righe in una transazione sola.
            'exercises' => ['sometimes', 'array', 'max:60'],

            /*
             * ══ 🚨 LE REGOLE DELL'ESERCIZIO STANNO IN UN POSTO SOLO ════════
             *
             * ⛔ Fino al 25/08/2026 erano scritte **due volte**: qui per le
             * righe piatte, e in `regoleEsercizio()` per quelle dentro i
             * giorni. 🚨 Due copie della stessa regola diventano due regole
             * diverse alla prima modifica — ed e' successo appena si sono
             * aggiunte le serie riga per riga, che nella prima stesura
             * valevano solo per una delle due strade.
             */
            ...$this->regoleEsercizio('exercises.*'),

            // ── G4/G5 — D3: il promemoria privato del trainer ──────────────
            //
            // 🚨 Lo vede **solo chi l'ha scritto** (R4), non entra nella busta
            // cifrata, e l'interfaccia suggerisce le iniziali. Qui si valida
            // soltanto la lunghezza: la riservatezza e' compito della risorsa
            // e dello spoglio prima della cifratura.
            'rif_allievo' => ['nullable', 'string', 'max:120'],

            // ── G5.1 — i giorni, e le alternative (D2, D10) ────────────────
            //
            // ⚠️ **`days` e' facoltativo, e `exercises` resta.** L'app gia'
            // installata manda `exercises` piatto: toglierlo spegnerebbe il
            // salvataggio su ogni telefono non ancora aggiornato. Quando
            // arrivano i giorni si usano quelli, altrimenti gli esercizi
            // finiscono nel giorno predefinito — cioe' il comportamento di
            // prima, che resta corretto.
            'days' => ['sometimes', 'array', 'max:14'],
            'days.*.name' => ['nullable', 'string', 'max:120'],
            'days.*.notes' => ['nullable', 'string', 'max:2000'],
            'days.*.exercises' => ['sometimes', 'array', 'max:60'],

            ...$this->regoleEsercizio('days.*.exercises.*'),

            // D10 — le alternative a un esercizio.
            'days.*.exercises.*.alternatives' => ['sometimes', 'array', new AlMassimoTreAlternative],
            ...$this->regoleEsercizio('days.*.exercises.*.alternatives.*'),

            // D2 — le alternative a un giorno intero, con i loro esercizi.
            //
            // 💡 **Un solo livello**: un'alternativa non ha alternative. Se
            // l'avesse, «quale piano sto seguendo» smetterebbe di avere una
            // risposta unica — e nessun trainer scrive un piano cosi'.
            'days.*.alternatives' => ['sometimes', 'array', new AlMassimoTreAlternative],
            'days.*.alternatives.*.name' => ['nullable', 'string', 'max:120'],
            'days.*.alternatives.*.notes' => ['nullable', 'string', 'max:2000'],
            'days.*.alternatives.*.exercises' => ['sometimes', 'array', 'max:60'],
            ...$this->regoleEsercizio('days.*.alternatives.*.exercises.*'),
        ];
    }

    /**
     * Le regole di un esercizio, valide ovunque compaia.
     *
     * 💡 Scritte una volta sola perche' un esercizio e' un esercizio: nel
     * giorno, come alternativa, o dentro un giorno alternativo. Ripeterle tre
     * volte vorrebbe dire che tre copie possono divergere — e la prima a
     * divergere sarebbe quella meno usata, cioe' quella che nessuno prova.
     *
     * @return array<string, mixed>
     */
    private function regoleEsercizio(string $prefisso): array
    {
        return [
            "{$prefisso}.name" => ['required', 'string', 'max:160'],

            // 🆕 3b-A.3.4 — vedi la nota sulle righe piatte qui sopra.
            "{$prefisso}.muscle_group" => ['nullable', Rule::enum(MuscleGroup::class)],
            "{$prefisso}.secondary_muscles" => ['nullable', 'array', 'max:6'],
            "{$prefisso}.secondary_muscles.*" => [Rule::enum(MuscleGroup::class)],
            "{$prefisso}.sets" => ['nullable', 'integer', 'min:1', 'max:30'],
            // 🚨 Stringa, sempre: vedi la nota sopra su `reps`.
            "{$prefisso}.reps" => ['nullable', 'string', 'max:32'],
            "{$prefisso}.rest_sec" => ['nullable', 'integer', 'min:0', 'max:1200'],
            "{$prefisso}.duration_sec" => ['nullable', 'integer', 'min:0', 'max:7200'],
            "{$prefisso}.target_weight" => ['nullable', 'numeric', 'min:0', 'max:1000'],
            "{$prefisso}.notes" => ['nullable', 'string', 'max:200'],

            /*
             * ══ 🆕 LE SERIE, RIGA PER RIGA — 3b-D.10, 25/08/2026 ═══════════
             *
             * 📌 *«ogni serie deve avere Ripetizioni, Peso (o niente o Iso.) e
             * Recupero»*.
             *
             * ⚠️ **Facoltative, e devono restarlo**: l'app gia' installata
             * manda `sets` + `reps` e basta, e pretendere le righe le
             * spegnerebbe il salvataggio. 💡 Chi non le manda si comporta come
             * prima, e `PlanExercise::serieRighe()` le deriva leggendo.
             *
             * 🚨 **Il tetto e' `max:30` come `sets`**, e non e' un dettaglio:
             * senza, una richiesta malformata scriverebbe migliaia di righe
             * dentro una colonna JSON — piu' difficile da notare di migliaia di
             * righe in tabella, perche' non si vedono contando le righe.
             */
            "{$prefisso}.serie" => ['nullable', 'array', 'max:30'],
            "{$prefisso}.serie.*.reps" => ['nullable', 'integer', 'min:0', 'max:999'],
            "{$prefisso}.serie.*.weight" => ['nullable', 'numeric', 'min:0', 'max:1000'],
            "{$prefisso}.serie.*.iso_sec" => ['nullable', 'integer', 'min:0', 'max:7200'],
            "{$prefisso}.serie.*.rest_sec" => ['nullable', 'integer', 'min:0', 'max:1200'],

            /*
             * ⚠️ **Non e' deducibile dalle righe**: «peso con i campi vuoti» e
             * «a corpo libero» hanno le stesse righe e vogliono dire due cose
             * diverse.
             */
            "{$prefisso}.carico" => ['nullable', 'in:peso,niente,iso'],
        ];
    }

    /**
     * I giorni gia' ripuliti, o `null` se la richiesta non li porta.
     *
     * ⚠️ **`null` e array vuoto sono diversi**: `null` vuol dire «non toccare i
     * giorni» (richiesta vecchia, o modifica dei soli dati di testa), `[]` vuol
     * dire «la scheda non ha piu' giorni». Confonderli cancellerebbe una scheda
     * a ogni rinomina.
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

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => __('Dai un nome alla scheda.'),
            'exercises.*.name.required' => __('Ogni esercizio deve avere un nome.'),
            'exercises.max' => __('Una scheda non può avere più di 60 esercizi.'),
        ];
    }

    /**
     * Le righe già ripulite delle voci senza nome.
     *
     * L'editor dell'app tiene volentieri una riga vuota in fondo, pronta da
     * compilare: rifiutare il salvataggio per quella sarebbe un attrito
     * gratuito, quindi si scarta prima di validare.
     *
     * @return list<array<string, mixed>>
     */
    public function righe(): array
    {
        $righe = $this->validated()['exercises'] ?? [];

        return array_values(array_filter(
            $righe,
            static fn (array $r): bool => trim((string) ($r['name'] ?? '')) !== '',
        ));
    }

    protected function prepareForValidation(): void
    {
        $righe = $this->input('exercises');

        if (! is_array($righe)) {
            return;
        }

        $this->merge([
            'exercises' => array_values(array_filter(
                $righe,
                static fn (mixed $r): bool => is_array($r) && trim((string) ($r['name'] ?? '')) !== '',
            )),
        ]);
    }
}
