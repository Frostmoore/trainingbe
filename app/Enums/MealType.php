<?php

declare(strict_types=1);

namespace App\Enums;

use Illuminate\Support\Carbon;

/**
 * I sei momenti in cui si mangia.
 *
 * Sei e non tre: gli spuntini sono dove finisce la meta' delle calorie che
 * nessuno registra, e senza una casella dedicata l'utente li scrive dentro il
 * pranzo o non li scrive affatto. `PreWorkout` e `PostWorkout` sono separati
 * perche' in una palestra sono prescrizioni, non abitudini: un piano alimentare
 * li tratta come pasti a se'.
 */
enum MealType: string
{
    case Breakfast = 'breakfast';
    case MorningSnack = 'morning_snack';
    case Lunch = 'lunch';
    case AfternoonSnack = 'afternoon_snack';
    case Dinner = 'dinner';
    case EveningSnack = 'evening_snack';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => 'Colazione',
            self::MorningSnack => 'Spuntino del mattino',
            self::Lunch => 'Pranzo',
            self::AfternoonSnack => 'Merenda',
            self::Dinner => 'Cena',
            self::EveningSnack => 'Spuntino serale',
        };
    }

    /** L'ordine con cui compaiono nel diario: quello della giornata. */
    public function position(): int
    {
        return match ($this) {
            self::Breakfast => 1,
            self::MorningSnack => 2,
            self::Lunch => 3,
            self::AfternoonSnack => 4,
            self::Dinner => 5,
            self::EveningSnack => 6,
        };
    }

    /**
     * Gli orari di inizio predefiniti dei sei pasti.
     *
     * Sono i valori dell'app storica, scelti su abitudini italiane. Diventano il
     * fondo su cui si sovrappongono quelli personalizzati dell'utente: chi
     * imposta solo la cena non deve reinserire anche le altre cinque.
     *
     * @var array<string, string>
     */
    public const DEFAULT_HOURS = [
        'breakfast' => '07:00',
        'morning_snack' => '10:30',
        'lunch' => '13:00',
        'afternoon_snack' => '16:30',
        'dinner' => '20:00',
        'evening_snack' => '22:30',
    ];

    /**
     * Il pasto plausibile a una certa ora, con gli orari **di serie**.
     *
     * ⚠️ Da usare solo dove non c'e' un utente (seeder, pannello, calcoli
     * generici). Quando l'utente c'e', usare `fromProfile()`: altrimenti gli
     * orari che ha impostato non hanno alcun effetto.
     */
    public static function fromHour(int $hour): self
    {
        return self::fromProfile(
            Carbon::createFromTime($hour, 0),
            null,
        );
    }

    /**
     * Il pasto a una certa ora **secondo gli orari di questa persona**.
     *
     * 🚨 Fino alla fase C questa funzione non esisteva e `profiles.meal_hours`
     * era una colonna che si salvava, si leggeva nell'API e **non veniva usata
     * da nessuna riga di codice**: chi personalizzava gli orari vedeva il campo
     * salvarsi e non cambiava niente. Le soglie erano scritte a mano dentro
     * `fromHour()`.
     *
     * L'algoritmo sceglie **l'ultimo pasto gia' cominciato**. Il caso che
     * costringe a non usare un semplice `match` di soglie e' la notte fonda:
     * alle 2:00 nessun pasto della giornata e' ancora cominciato, e il pasto
     * giusto e' **l'ultimo** (lo spuntino serale di chi e' rimasto sveglio), non
     * la colazione di un giorno che deve ancora iniziare.
     *
     * @param  array<string, string>|null  $mealHours  `['lunch' => '12:30', …]`;
     *                                                 le chiavi sconosciute e gli
     *                                                 orari malformati si ignorano.
     */
    public static function fromProfile(Carbon $quando, ?array $mealHours): self
    {
        $minuti = $quando->hour * 60 + $quando->minute;

        $inizi = [];

        foreach (self::cases() as $caso) {
            $orario = self::orarioValido($mealHours[$caso->value] ?? null)
                ?? self::DEFAULT_HOURS[$caso->value];

            [$hh, $mm] = explode(':', $orario);
            $inizi[$caso->value] = ((int) $hh * 60) + (int) $mm;
        }

        asort($inizi);

        // Il fondo e' l'ULTIMO pasto in ordine di orario: e' la risposta giusta
        // per le ore piccole, prima che qualunque pasto sia cominciato.
        $scelto = array_key_last($inizi);

        foreach ($inizi as $pasto => $inizio) {
            if ($inizio <= $minuti) {
                $scelto = $pasto;
            }
        }

        return self::from($scelto);
    }

    /**
     * `HH:MM` se l'orario e' scritto bene, altrimenti `null`.
     *
     * Un orario malformato non deve far fallire l'inserimento di un cibo: si
     * ricade sul valore di serie. La validazione severa sta nel punto in cui il
     * profilo si salva (`UpdateProfileRequest`), che e' dove l'utente puo'
     * ancora correggere.
     */
    private static function orarioValido(mixed $valore): ?string
    {
        if (! is_string($valore) || preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $valore) !== 1) {
            return null;
        }

        return $valore;
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];

        foreach (self::ordered() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }

    /** @return list<self> */
    public static function ordered(): array
    {
        $casi = self::cases();
        usort($casi, static fn (self $a, self $b): int => $a->position() <=> $b->position());

        return $casi;
    }
}
