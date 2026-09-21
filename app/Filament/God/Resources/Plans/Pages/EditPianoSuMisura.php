<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Pages;

use App\Filament\God\Resources\Plans\PianoSuMisuraResource;

/** La pagina di un piano su misura: la stessa di un piano di listino (grafico e tabella compresi). */
class EditPianoSuMisura extends EditPlan
{
    protected static string $resource = PianoSuMisuraResource::class;
}
