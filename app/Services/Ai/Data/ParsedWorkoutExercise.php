<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

use App\Enums\MuscleGroup;
use App\Models\PlanExercise;
use App\Support\Training\SerieDallaPrescrizione;

/**
 * Un esercizio letto da un PDF, prima della riconciliazione con la libreria.
 *
 * `confidence` e' **per riga** e non solo per scheda: in un PDF impaginato male
 * tre esercizi su venti sono illeggibili e diciassette sono perfetti. Con una
 * sola confidenza complessiva o si butta via tutto o si accetta tutto; con
 * quella per riga la pagina di revisione (B7.4) puo' evidenziare esattamente le
 * tre righe da guardare.
 *
 * ══ 🆕 🚨 UNA RIGA PER SERIE — 23/09/2026 ═════════════════════════════════
 *
 * 📌 Il committente: *«Se una scheda dice hack squat 5x8 non deve diventare
 * "serie 5" "ripetizioni 8" ma deve aggiungere 5 righe di serie con 8
 * ripetizioni … Deve essere una riga per serie e il numero di ripetizioni
 * giusto»*.
 *
 * ⛔ **Via `sets`, `reps`, `rest_sec`, `target_weight`** come dati: l'esercizio
 * ha solo `serie`, una riga per serie, con le ripetizioni **come numero**. Il
 * riassunto nel formato vecchio esce ancora da `toArray()` — ricalcolato dalle
 * righe con `PlanExercise::riassuntoDelle()`, la stessa funzione del modello — per
 * chi lo legge ancora; non si scrive più a mano da nessuna parte.
 *
 * 💡 **L'app non cambia**: il suo adattatore (`serieDellEsercizio`) usa `serie`
 * quando c'è. Da oggi l'import dal telefono si apre già riga per riga.
 */
final readonly class ParsedWorkoutExercise
{
    /** I tre modi di caricare, come `PlanExercise::carico`. */
    public const CARICHI = ['peso', 'niente', 'iso'];

    /**
     * @param  list<array{reps: ?int, weight: ?float, iso_sec: ?int, rest_sec: ?int}>  $serie  mai vuoto
     * @param  list<string>  $secondaryMuscles
     */
    public function __construct(
        public string $name,
        public array $serie,
        public string $carico,
        public ?string $notes,

        /**
         * 🆕 3b-A.3.4 — i muscoli, come li ha detti il modello.
         *
         * 🚨 Gia' **validati contro l'enum** in `fromArray`: quello che arriva e'
         * testo di un modello, e un `pettorali` scritto in italiano finirebbe
         * dritto nella libreria come gruppo muscolare inesistente.
         *
         * ⚠️ `null` vuol dire «non lo so», e resta diverso da un elenco vuoto.
         */
        public ?MuscleGroup $muscleGroup,

        public array $secondaryMuscles,

        public float $confidence,

        /**
         * 🆕 **A quale giorno appartiene**, contando da 1 — K2, 03/09/2026.
         *
         * 🚨 Sta sull'esercizio e non in una struttura a parte perche' e' cosi'
         * che una scheda e' scritta su carta: un elenco di righe sotto delle
         * intestazioni.
         *
         * ⚠️ **Uno, e non zero né `null`.** Una scheda a giorno singolo e' una
         * scheda con tutti gli esercizi sul giorno 1.
         */
        public int $day = 1,
    ) {}

    /**
     * Da una risposta del modello, da una bozza salvata o dal modulo di revisione.
     *
     * ⚠️ **Tre formati in ingresso, uno in uscita.** `serie` quando c'è; altrimenti
     * il formato vecchio (`sets` × `reps`) si **espande** in righe
     * (`SerieDallaPrescrizione`): le bozze salvate prima del 23/09 e una
     * risposta del modello senza righe diventano righe come tutte le altre.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $serie = self::righe($data['serie'] ?? null);

        if ($serie === []) {
            $serie = SerieDallaPrescrizione::espandi(
                isset($data['sets']) && is_numeric($data['sets']) ? (int) $data['sets'] : null,
                isset($data['reps']) ? (string) $data['reps'] : null,
                isset($data['rest_sec']) && is_numeric($data['rest_sec']) ? (int) $data['rest_sec'] : null,
                isset($data['target_weight']) && is_numeric($data['target_weight']) ? (float) $data['target_weight'] : null,
            );
        }

        $carico = (string) ($data['carico'] ?? '');

        if (! in_array($carico, self::CARICHI, true)) {
            // 💡 Righe fatte solo di secondi sono un'isometria; il resto è peso.
            $soloSecondi = array_filter($serie, static fn (array $r): bool => $r['iso_sec'] !== null && $r['reps'] === null);
            $carico = count($soloSecondi) === count($serie) ? 'iso' : 'peso';
        }

        return new self(
            name: trim((string) ($data['name'] ?? '')),
            serie: $serie,
            carico: $carico,
            notes: isset($data['notes']) && trim((string) $data['notes']) !== '' ? (string) $data['notes'] : null,
            muscleGroup: isset($data['muscle_group'])
                ? MuscleGroup::tryFrom((string) $data['muscle_group'])
                : null,

            // ⛔ I valori che l'enum non conosce si buttano, non si tengono: una
            // libreria con dentro `pettorali` non si colora e non si filtra.
            secondaryMuscles: array_values(array_filter(array_map(
                static fn (mixed $v): ?string => MuscleGroup::tryFrom((string) $v)?->value,
                (array) ($data['secondary_muscles'] ?? []),
            ))),

            confidence: (float) ($data['confidence'] ?? 0.0),

            /*
             * ⚠️ `max(1, ...)` e non una validazione che scarta la riga: un
             * `day: 0` e' un difetto di numerazione, non un esercizio falso.
             */
            day: max(1, (int) ($data['day'] ?? 1)),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'serie' => $this->serie,
            'carico' => $this->carico,
            'notes' => $this->notes,
            'muscle_group' => $this->muscleGroup?->value,
            'secondary_muscles' => $this->secondaryMuscles,
            'confidence' => $this->confidence,
            'day' => $this->day,

            // ⚠️ Solo il riassunto, ricalcolato: per chi legge ancora il formato vecchio.
            ...PlanExercise::riassuntoDelle($this->serie),
        ];
    }

    /**
     * Le righe, ripulite: ripetizioni **intere** (mai «6-10»), numeri o `null`.
     *
     * ⚠️ Una riga tutta vuota si tiene: «fai una serie, quante ne riesci» è una
     * prescrizione. Una riga che non è nemmeno un array si butta.
     *
     * @return list<array{reps: ?int, weight: ?float, iso_sec: ?int, rest_sec: ?int}>
     */
    private static function righe(mixed $grezze): array
    {
        if (! is_array($grezze)) {
            return [];
        }

        $numero = static fn (mixed $v): ?int => is_numeric($v) ? (int) round((float) $v) : null;

        $righe = [];

        foreach ($grezze as $r) {
            if (! is_array($r)) {
                continue;
            }

            $righe[] = [
                'reps' => $numero($r['reps'] ?? null),
                'weight' => is_numeric($r['weight'] ?? null) ? (float) $r['weight'] : null,
                'iso_sec' => $numero($r['iso_sec'] ?? null),
                'rest_sec' => $numero($r['rest_sec'] ?? null),
            ];
        }

        return array_slice($righe, 0, SerieDallaPrescrizione::MASSIMO);
    }
}
