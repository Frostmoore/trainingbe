<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Promozioni\Pages;

use App\Filament\God\Resources\Promozioni\PromozioneResource;
use App\Filament\God\Resources\Promozioni\Schemas\ModuloDellaPromozione;
use Filament\Resources\Pages\CreateRecord;
use Filament\Resources\Pages\CreateRecord\Concerns\HasWizard;

/**
 * 🎁 **Nuova promozione, a passi** — 24/09/2026, fase P1 di `plan_promozioni.md`.
 *
 * 📌 *«su promozioni->nuovo ci deve essere una scelta: a) Gettoni b) Abbonamenti
 * c) entrambe. Dopo di che, devo avere un form diverso per ciascuna scelta»*.
 *
 * Il primo passo è la scelta; il secondo mostra solo i premi di quel genere.
 * I campi stanno in `ModuloDellaPromozione`, gli stessi della modifica.
 */
class CreatePromozione extends CreateRecord
{
    use HasWizard;

    protected static string $resource = PromozioneResource::class;

    protected static ?string $title = 'Nuova promozione';

    protected function getSteps(): array
    {
        return ModuloDellaPromozione::passi();
    }

    /** Chi l'ha creata: resta scritto, come in F2. */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['creata_da_id'] = auth()->id();

        return $data;
    }
}
