<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Pages;

use App\Filament\God\Resources\Plans\PlanResource;
use App\Filament\God\Resources\Plans\Widgets\ChiHaIlPiano;
use App\Filament\God\Resources\Plans\Widgets\GliAttiviDelPiano;
use App\Models\Plan;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * 💶 **La pagina di un piano** — F9.2, 23/09/2026.
 *
 * 📌 *«Non esiste ma deve esistere · deve contenere il prezzo · la temporalità ·
 * i tetti per iscritti e trainer · questo a listino · un grafico di quanti sono
 * attivi · una tabella con quali utenti ce l'hanno attivo, quando scade, quanto
 * costa e quante volte è stato rinnovato»*.
 *
 * In alto il modulo (prezzo, «ogni quanto», posti, «in vendita»); sotto il
 * grafico degli attivi e la tabella di chi ce l'ha. La usa anche la pagina di
 * un piano su misura (`EditPianoSuMisura`).
 */
class EditPlan extends EditRecord
{
    protected static string $resource = PlanResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Plan $piano */
        $piano = $this->getRecord();

        return $piano->name;
    }

    protected function getHeaderActions(): array
    {
        return [];
    }

    /**
     * 🚨 **Un piano di listino toccato da qui non lo riscrive più il seeder.**
     * ⛔ Senza, il prezzo cambiato tornerebbe quello del file al primo deploy.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        /** @var Plan $piano */
        $piano = $this->getRecord();

        if (! $piano->cucito_a_mano) {
            $data['modificato_a_mano'] = true;
        }

        return $data;
    }

    protected function getFooterWidgets(): array
    {
        return [
            GliAttiviDelPiano::class,
            ChiHaIlPiano::class,
        ];
    }
}
