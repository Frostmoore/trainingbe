<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Da dove arriva una voce del diario alimentare.
 *
 * 🚨 **Non e' statistica: e' la colonna che rende misurabile l'aderenza al
 * piano.** `source = 'plan'` collega il consuntivo alla prescrizione, e la
 * domanda che vale davvero per una palestra — «quanto ha seguito il piano che
 * gli ho dato?» — diventa una join invece di una stima a occhio.
 *
 * Serve anche per una cosa piu' prosaica: quando un modello AI comincia a
 * sbagliare le stime, bisogna poter ritrovare **tutte** le voci che ha prodotto.
 */
enum FoodSource: string
{
    case Manual = 'manual';
    case AiText = 'ai_text';
    case AiPhoto = 'ai_photo';
    case Favorite = 'favorite';
    case Plan = 'plan';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Inserito a mano',
            self::AiText => 'Riconosciuto dal testo',
            self::AiPhoto => 'Riconosciuto dalla foto',
            self::Favorite => 'Dai preferiti',
            self::Plan => 'Dal piano alimentare',
        };
    }

    /** L'ha prodotta un modello? Serve a ritrovarle tutte quando serve. */
    public function isAi(): bool
    {
        return $this === self::AiText || $this === self::AiPhoto;
    }
}
