<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A cosa serve una chiamata AI.
 *
 * 🚨 **E' la chiave con cui si sceglie il modello.** Non tutte le richieste
 * meritano lo stesso: riconoscere «due uova e una fetta di pane» e' un compito
 * banale che un modello piccolo fa bene, leggere un PDF di allenamento
 * impaginato a tabelle non lo e'. Senza questa distinzione l'unica scelta
 * possibile e' un modello solo per tutto — e allora o si paga troppo per le cose
 * facili o si sbaglia sulle difficili.
 *
 * E' anche la dimensione con cui si guarda il consumo: «quanto ci costa il
 * riconoscimento delle foto?» e' una domanda con una risposta solo se questa
 * colonna esiste.
 */
enum AiFeature: string
{
    case FoodText = 'food_text';
    case FoodPhoto = 'food_photo';
    case WorkoutKcal = 'workout_kcal';
    case DailyAdvice = 'daily_advice';
    case PdfImport = 'pdf_import';

    public function label(): string
    {
        return match ($this) {
            self::FoodText => 'Cibo da testo',
            self::FoodPhoto => 'Cibo da foto',
            self::WorkoutKcal => 'Calorie allenamento',
            self::DailyAdvice => 'Consiglio del giorno',
            self::PdfImport => 'Import scheda PDF',
        };
    }

    /**
     * La richiesta manda immagini o documenti?
     *
     * Serve a due decisioni pratiche: il limite di dimensione da imporre prima
     * dell'invio, e l'esclusione dei modelli senza visione.
     */
    public function isMultimodal(): bool
    {
        return $this === self::FoodPhoto || $this === self::PdfImport;
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
