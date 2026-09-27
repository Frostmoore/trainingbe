<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\RichiesteDiContatto\Pages;

use App\Enums\Notifica;
use App\Enums\StatoDellaRichiesta;
use App\Filament\God\Resources\RichiesteDiContatto\RichiestaDiContattoResource;
use App\Mail\RispostaAllaRichiestaMail;
use App\Models\RichiestaDiContatto;
use App\Models\RispostaAllaRichiesta;
use App\Services\Notifiche\LeNotifiche;
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

    /**
     * 🆕 📲 **E una push, se chi ha scritto ha l'app** — voce 62, 27/09/2026.
     *
     * ══ 🚨 CHI HA SCRITTO DAL SITO PUO' NON AVERE UN ACCOUNT ══════════════
     *
     * ⚠️ `RichiestaDiContatto` ha **solo un'email**: chi compila il modulo del
     * sito non è per forza iscritto. ⛔ Quindi questa notifica è **un in più**, e
     * la mail resta il canale vero — 💡 che è anche la ragione per cui un
     * fallimento qui non tocca l'esito mostrato in pannello: quello parla della
     * mail, che è la cosa che deve arrivare.
     *
     * ══ 🔑 LA RICERCA PER EMAIL STA NELLA PORTA ═══════════════════════════
     *
     * 💡 `LeNotifiche::aQuestaEmail()` fa il lavoro: trova **tutti** gli account
     * con quell'indirizzo — `users.email` è unica per palestra, non per
     * piattaforma — e manda a ognuno. ⛔ Stava scritto qui a mano, poi è servito
     * anche ai due inviti: tre copie della stessa ricerca, e la prima copiata
     * male avrebbe avvisato l'account sbagliato senza dare nessun errore.
     *
     * ══ ⛔ E IL TESTO NON RIPETE LA RISPOSTA ═══════════════════════════════
     *
     * 🚨 La risposta può contenere qualunque cosa — anche dati di un contenzioso.
     * ⛔ Su uno schermo bloccato la leggerebbe chiunque. 💡 Si dice che c'è una
     * risposta; il testo sta nella mail, dietro la casella di posta.
     *
     * ⚠️ E vale il vincolo di `aQuestaEmail()`: le email **non sono verificate**,
     * quindi da qui non passa niente di riservato.
     */
    private function avvisaSulTelefono(RichiestaDiContatto $richiesta): void
    {
        try {
            app(LeNotifiche::class)->aQuestaEmail(
                Notifica::RispostaARichiesta,
                (string) $richiesta->email,
                'Ti abbiamo risposto',
                'Abbiamo risposto alla tua richiesta: trovi il messaggio nella posta.',
            );
        } catch (Throwable $e) {
            Log::warning('Risposta a una richiesta: push non partita.', [
                'richiesta' => $richiesta->getKey(),
                'errore' => $e->getMessage(),
            ]);
        }
    }

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

                    $this->avvisaSulTelefono($richiesta);

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
