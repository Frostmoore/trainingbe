<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Promozioni\Pages;

use App\Enums\StatoPromozione;
use App\Filament\God\Resources\Promozioni\PromozioneResource;
use App\Filament\God\Resources\Promozioni\Widgets\ChiLHaPresa;
use App\Filament\God\Resources\Promozioni\Widgets\INumeriDellaPromozione;
use App\Models\Promozione;
use App\Services\Promozioni\RevocaDellaPromozione;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;

/**
 * ✏️ **La pagina di una promozione** — 🆕 P7 di `plan_promozioni.md`.
 *
 * In alto i **numeri** (persone, gettoni, scontato, incassato), in mezzo il
 * modulo, sotto **chi l'ha presa** con la revoca. Come la pagina del piano (F9):
 * una pagina sola invece di «vedi» e «modifica».
 */
class EditPromozione extends EditRecord
{
    protected static string $resource = PromozioneResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Promozione $promozione */
        $promozione = $this->getRecord();

        return $promozione->nome;
    }

    protected function getHeaderWidgets(): array
    {
        return [INumeriDellaPromozione::class];
    }

    protected function getFooterWidgets(): array
    {
        return [ChiLHaPresa::class];
    }

    protected function getHeaderActions(): array
    {
        return [
            /*
             * ⛔ **Disattivare è definitivo** (sospendere no): una promozione
             * disattivata non si spende più, ma resta — le ricevute la nominano.
             */
            Action::make('disattiva')
                ->label('Disattiva')
                ->icon('heroicon-m-archive-box-x-mark')
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->stato !== StatoPromozione::Disattivata)
                ->requiresConfirmation()
                ->modalDescription('Non si spende più, e non tornerà attiva: resta nell\'elenco per le ricevute che la nominano. Quello che è già stato dato resta dato.')
                ->action(function (): void {
                    $this->getRecord()->forceFill(['stato' => StatoPromozione::Disattivata])->save();
                    $this->refreshFormData(['stato']);

                    Notification::make()->title('Disattivata')->success()->send();
                }),

            Action::make('revocaATutti')
                ->label('Revoca a tutti')
                ->icon('heroicon-m-arrow-uturn-left')
                ->color('danger')
                ->visible(fn (): bool => $this->getRecord()->assegnazioni()->cheContano()->exists())
                ->requiresConfirmation()
                ->modalHeading('Revocare la promozione a tutti quelli che l\'hanno?')
                ->modalDescription('Per ognuno: via i gettoni regalati ancora nel saldo, fine di abbonamenti e upgrade regalati, via i posti. Gli sconti già pagati restano.')
                ->modalSubmitActionLabel('Revoca a tutti')
                ->action(function (): void {
                    $esito = app(RevocaDellaPromozione::class)->revocaATutti($this->getRecord(), auth()->user());

                    Notification::make()
                        ->title('Revocata a '.$esito['persone'].' '.($esito['persone'] === 1 ? 'persona' : 'persone'))
                        ->body($esito['gettoni'] > 0 ? "Tolti {$esito['gettoni']} gettoni." : null)
                        ->success()
                        ->send();
                }),
        ];
    }
}
