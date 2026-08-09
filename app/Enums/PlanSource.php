<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Da dove arriva una scheda.
 *
 * Serve a una domanda operativa precisa: quando un import da PDF (B7) produce
 * schede sbagliate, bisogna poterle ritrovare **tutte** senza andare a memoria.
 * Con una sola colonna la query e' immediata; ricostruendolo dai media allegati
 * o dalla data si sbaglia.
 */
enum PlanSource: string
{
    case Manual = 'manual';
    case PdfImport = 'pdf_import';
    case Ai = 'ai';

    public function label(): string
    {
        return match ($this) {
            self::Manual => 'Compilata a mano',
            self::PdfImport => 'Importata da PDF',
            self::Ai => 'Generata con AI',
        };
    }
}
