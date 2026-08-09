<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * I gruppi muscolari della libreria esercizi.
 *
 * Elenco chiuso e non stringa libera: e' il criterio con cui si cerca un
 * esercizio quando la libreria arriva a qualche centinaio di righe, e con un
 * campo libero «Pettorali», «Petto» e «petto» diventano tre filtri diversi nel
 * giro di due settimane — lo stesso motivo per cui esiste `ExerciseMatcher`
 * (B7.3) sul nome.
 */
enum MuscleGroup: string
{
    case Chest = 'chest';
    case Back = 'back';
    case Shoulders = 'shoulders';
    case Biceps = 'biceps';
    case Triceps = 'triceps';
    case Forearms = 'forearms';
    case Abs = 'abs';
    case Glutes = 'glutes';
    case Quads = 'quads';
    case Hamstrings = 'hamstrings';
    case Calves = 'calves';
    case FullBody = 'full_body';
    case Cardio = 'cardio';

    public function label(): string
    {
        return match ($this) {
            self::Chest => 'Petto',
            self::Back => 'Schiena',
            self::Shoulders => 'Spalle',
            self::Biceps => 'Bicipiti',
            self::Triceps => 'Tricipiti',
            self::Forearms => 'Avambracci',
            self::Abs => 'Addome',
            self::Glutes => 'Glutei',
            self::Quads => 'Quadricipiti',
            self::Hamstrings => 'Femorali',
            self::Calves => 'Polpacci',
            self::FullBody => 'Total body',
            self::Cardio => 'Cardio',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $case) {
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
