<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\RichiesteDiContatto\Pages;

use App\Enums\StatoDellaRichiesta;
use App\Filament\God\Resources\RichiesteDiContatto\RichiestaDiContattoResource;
use App\Mail\RispostaAllaRichiestaMail;
use App\Models\RichiestaDiContatto;
use App\Models\RispostaAllaRichiesta;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * ✉️ Una richiesta dal sito, per intero, e la risposta — F9.3, 23/09/2026.
 *
 * 📌 *«deve contenere il testo completo della richiesta · deve permettermi di
 * rispondere direttamente dal pannello»*.
 *
 * 💡 **Rispondere prende in carico**: una richiesta a cui si è risposto non è
 * più «nuova», e lasciarla tale la farebbe contare nel numero accanto al menu
 * come se nessuno l'avesse guardata.
 */
class ViewRichiestaDiContatto extends ViewRecord
{
    protected static string $resource = RichiestaDiContattoResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var RichiestaDiContatto $r */
        $r = $this->getRecord();

        return $r->nome.($r->organizzazione ? ' · '.$r->organizzazione : '');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('rispondi')
                ->label('Rispondi')
                ->icon('heroicon-m-paper-airplane')
                ->modalHeading(fn (): string => 'Rispondi a '.$this->getRecord()->email)
                ->modalDescription('Parte una mail a chi ha scritto. Se ti risponde, la risposta arriva a '.config('services.contatti.email').'.')
                ->modalSubmitActionLabel('Manda')
                ->fillForm(fn (): array => ['oggetto' => 'La tua richiesta a Training Companion'])
                ->schema([
                    TextInput::make('oggetto')->label('Oggetto')->required()->maxLength(200),
                    Textarea::make('testo')->label('Testo')->required()->rows(10)->maxLength(10000),
                ])
                ->action(function (array $data): void {
                    /** @var RichiestaDiContatto $richiesta */
                    $richiesta = $this->getRecord();

                    $risposta = RispostaAllaRichiesta::query()->create([
                        'richiesta_di_contatto_id' => $richiesta->getKey(),
                        'scritta_da' => auth()->id(),
                        'oggetto' => $data['oggetto'],
                        'testo' => $data['testo'],
                        'mandata' => false,
                    ]);

                    try {
                        Mail::to($richiesta->email)->send(new RispostaAllaRichiestaMail($risposta));
                        $partita = config('mail.default') !== 'log';
                    } catch (Throwable $e) {
                        Log::error('Risposta a una richiesta: mail non partita.', ['richiesta' => $richiesta->getKey(), 'errore' => $e->getMessage()]);
                        $partita = false;
                    }

                    $risposta->forceFill(['mandata' => $partita])->save();

                    if ($richiesta->stato === StatoDellaRichiesta::Nuova) {
                        $richiesta->forceFill(['stato' => StatoDellaRichiesta::Presa, 'presa_in_carico_il' => Carbon::now()])->save();
                    }

                    /*
                     * ⚠️ Onesto come ovunque: con `MAIL_MAILER=log` la mail
                     * finisce nel registro del server e nessuno la riceve.
                     */
                    Notification::make()
                        ->title($partita ? 'Risposta mandata' : '⚠️ La risposta non è partita davvero')
                        ->body($partita ? null : 'La posta non è ancora collegata: il testo resta salvato qui sotto.')
                        ->status($partita ? 'success' : 'warning')
                        ->send();

                    $this->refreshFormData(['stato', 'presa_in_carico_il']);
                    $this->getRecord()->load('risposte');
                }),

            RichiestaDiContattoResource::aggiorna()->color('gray'),
        ];
    }
}
