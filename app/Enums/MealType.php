<?php

declare(strict_types=1);

namespace App\Enums;

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

    /** Il pasto plausibile a una certa ora: il default quando l'app non lo dice. */
    public static function fromHour(int $hour): self
    {
        return match (true) {
            $hour < 10 => self::Breakfast,
            $hour < 12 => self::MorningSnack,
            $hour < 15 => self::Lunch,
            $hour < 18 => self::AfternoonSnack,
            $hour < 22 => self::Dinner,
            default => self::EveningSnack,
        };
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
