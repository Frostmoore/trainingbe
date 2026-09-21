<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Pages;

use App\Enums\AuditAction;
use App\Filament\Gym\Resources\Members\MemberResource;
use App\Filament\Gym\Resources\Members\Widgets\LaStoriaDellIscritto;
use App\Filament\Gym\Support\LAbbonamentoDellIscritto;
use App\Filament\Support\MandaIlReset;
use App\Models\PacchettoGettoni;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Tenancy\EsciDaUnaPalestra;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

/**
 * 👤 **La pagina di un iscritto** — 23/09/2026, fasi F5.6, F5.7 e F5.8.
 *
 * ⛔ **Ha sostituito la pagina di modifica**, e il perché per esteso sta in
 * `SchedaDellIscritto`: la palestra non deve poter cambiare l'identità né la
 * password di una persona. 💡 Quello che resta è il **rapporto**: sospendere,
 * disiscrivere, regalare gettoni, mandare il link della password.
 */
class ViewMember extends ViewRecord
{
    protected static string $resource = MemberResource::class;

    protected function getHeaderActions(): array
    {
        return [
            /*
             * 🔑 **F5.6** — *«mi serve un tasto per mandare il reset password»*,
             * anche qui. 💡 È lo stesso di `/god`: la regola per cui il pannello
             * non vede e non sceglie la password sta scritta una volta sola.
             */
            MandaIlReset::perUtente(),

            // ✂️ *«mi serve il tasto per togliere l'abbonamento all'allievo»*.
            LAbbonamentoDellIscritto::make(),

            $this->regalaGettoni(),
            $this->sospendi(),
            $this->disiscrivi(),
        ];
    }

    /**
     * 📊 *«un grafico con gli abbonamenti nel tempo»* e *«un grafico con la
     * revenue da quell'allievo nel tempo»* — F5.7. Stanno sotto la scheda:
     * chi apre la pagina cerca prima un dato preciso.
     */
    protected function getFooterWidgets(): array
    {
        return [
            LaStoriaDellIscritto::class,
        ];
    }

    /**
     * ══ 🎁 «COMPRA GETTONI PER LUI» — R1, fase F5.8 ═══════════════════════
     *
     * 📌 *«voglio che la palestra possa acquistare dei token da dare
     * all'allievo … Quelli acquistati in questo modo devono andare direttamente
     * all'allievo»*.
     *
     * ══ 🚨 CHI PAGA E CHI RICEVE SONO DUE PERSONE DIVERSE ════════════════
     *
     * Paga **chi preme** (il gestore, col cliente Stripe della palestra);
     * riceve **l'iscritto**, sul suo portafoglio personale. ⛔ Da quel momento
     * i gettoni sono suoi: la palestra non può riprenderseli, e se lui se ne va
     * se li porta via.
     *
     * ⚠️ **Il prezzo lo decide il listino, mai la pagina**: qui si sceglie solo
     * *quale taglio*, e l'importo lo mette `Cassa` leggendo `pacchetti_gettoni`.
     * 🚨 Un prezzo che arriva dal browser è un prezzo che si può cambiare dal
     * browser.
     *
     * ✅ **A5**: all'iscritto non si scrive niente — i gettoni arrivano in
     * silenzio, come un accredito qualunque.
     */
    private function regalaGettoni(): Action
    {
        return Action::make('regala_gettoni')
            ->label('Regalagli gettoni')
            ->icon('heroicon-m-gift')
            ->color('success')
            ->visible(fn (): bool => auth()->user()?->isGymAdmin() === true)
            ->modalHeading(fn (User $record): string => "Comprare gettoni per {$record->name}?")
            ->modalDescription(
                'Paghi tu, e i gettoni vanno direttamente a lui. ⚠️ Da quel momento sono suoi: '
                .'non puoi riprenderteli, e se lascia la palestra se li porta via. '
                .'Non gli arriva nessun messaggio: se li ritrova nell\'app.'
            )
            ->modalSubmitActionLabel('Vai al pagamento')
            ->schema([
                Select::make('gettoni')
                    ->label('Quanti')
                    ->options(fn (): array => self::tagli())
                    ->required()
                    ->native(false)
                    ->helperText('I tagli sono quelli del listino: il prezzo non si cambia da qui.'),
            ])
            ->action(function (User $record, array $data) {
                $chiPaga = auth()->user();

                if (! $chiPaga instanceof User) {
                    return null;
                }

                try {
                    $url = app(Cassa::class)->apri(
                        $chiPaga,
                        Cassa::GETTONI,
                        gettoni: (int) $data['gettoni'],
                        perConto: $record,
                    );
                } catch (Throwable $e) {
                    report($e);

                    /*
                     * ⚠️ **Il messaggio vero.** Chi guarda è chi amministra la
                     * palestra: se Stripe ha detto «no such price», è l'unica
                     * informazione che gli dice cosa fare.
                     */
                    Notification::make()
                        ->title('Pagamento non aperto')
                        ->body($e->getMessage())
                        ->danger()
                        ->persistent()
                        ->send();

                    return null;
                }

                app(AuditLogger::class)->log(
                    AuditAction::GettoniRegalatiDallaPalestra,
                    $record,
                    ['gettoni' => (int) $data['gettoni'], 'da' => $chiPaga->getKey()],
                    tenant: $record->tenant_id,
                );

                /*
                 * 🚨 **Si esce verso Stripe, e i gettoni NON sono ancora
                 * accreditati.** L'accredito lo fa il webhook a pagamento
                 * avvenuto: accreditarli qui vorrebbe dire regalarli a chi apre
                 * la pagina e poi chiude il browser.
                 */
                return redirect()->away($url);
            });
    }

    private function sospendi(): Action
    {
        return Action::make('sospendi')
            ->label(fn (User $record): string => $record->is_active ? 'Sospendi' : 'Riattiva')
            ->icon(fn (User $record): string => $record->is_active ? 'heroicon-m-pause-circle' : 'heroicon-m-play-circle')
            ->color(fn (User $record): string => $record->is_active ? 'warning' : 'success')
            ->visible(fn (): bool => auth()->user()?->isGymAdmin() === true)
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => $record->is_active
                ? "Sospendere {$record->name}?"
                : "Riattivare {$record->name}?")
            /*
             * 🚨 **Corretto il 23/09/2026: qui c'era scritta una cosa falsa.**
             *
             * ⛔ La frase diceva *«resta iscritto e continua a occupare un posto
             * sul vostro piano»*. Non è vero: `ContoDegliIscritti` conta solo
             * gli account **attivi**, quindi un iscritto sospeso **libera** il
             * posto. 💡 L'ha fatto notare la rilettura delle richieste del
             * committente, non un test — ed è il genere di frase che fa pagare
             * a qualcuno un piano più grande di quello che gli serve.
             */
            ->modalDescription(fn (User $record): string => $record->is_active
                ? 'Non entrerà più nell\'app finché non lo riattivi, e le sessioni aperte si chiudono subito. '
                  .'Resta iscritto, ma mentre è sospeso non occupa un posto sul vostro piano.'
                : 'Tornerà a entrare, e riprenderà a occupare un posto. Dovrà rifare l\'accesso.')
            ->action(function (User $record): void {
                $spegnimento = $record->is_active;

                $record->forceFill(['is_active' => ! $spegnimento])->save();

                if ($spegnimento) {
                    /*
                     * 🚨 Senza questa riga la sospensione non tocca chi è già
                     * dentro l'app: resterebbe collegato col suo token finché
                     * non esce da solo.
                     */
                    $record->tokens()->delete();
                }

                app(AuditLogger::class)->log(
                    AuditAction::UserDeactivated,
                    $record,
                    ['email' => $record->email, 'ora' => $spegnimento ? 'sospeso' : 'riattivato'],
                    tenant: $record->tenant_id,
                );

                Notification::make()
                    ->title($spegnimento ? "{$record->name} è sospeso" : "{$record->name} è di nuovo attivo")
                    ->success()
                    ->send();
            });
    }

    /**
     * ⚠️ **«Disiscrivi», non «Elimina»** — D4.
     *
     * 📌 *«Una palestra deve poter AL MASSIMO disiscrivere un allievo! Non deve
     * poter cancellare del tutto il suo account, anche perché l'account
     * personale non dipende in alcun modo dalla palestra»*.
     */
    private function disiscrivi(): Action
    {
        return Action::make('disiscrivi')
            ->label('Disiscrivi')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->visible(fn (): bool => auth()->user()?->isGymAdmin() === true)
            ->requiresConfirmation()
            ->modalHeading(fn (User $record): string => "Disiscrivere {$record->name}?")
            ->modalDescription(
                'Esce dalla palestra e torna nel suo spazio personale, con il suo account e i suoi dati. '
                .'Libera un posto sul vostro piano. Per rientrare gli servirà un nuovo invito.'
            )
            ->modalSubmitActionLabel('Disiscrivi')
            ->action(function (User $record) {
                $palestra = $record->tenant_id;

                app(EsciDaUnaPalestra::class)($record);

                app(AuditLogger::class)->log(
                    AuditAction::IscrittoDisiscritto,
                    $record,
                    ['email' => $record->email],
                    tenant: $palestra,
                );

                Notification::make()->title("{$record->name} non è più iscritto.")->success()->send();

                return redirect(MemberResource::getUrl('index'));
            });
    }

    /**
     * I tagli in vendita, presi dal listino.
     *
     * ⛔ Non una lista scritta qui: i prezzi vivono nel database da F2, e una
     * seconda copia nel pannello sarebbe il difetto dei **due listini** che
     * F2 è servita a chiudere.
     *
     * @return array<int, string>
     */
    private static function tagli(): array
    {
        return PacchettoGettoni::query()
            ->inVendita()
            ->orderBy('gettoni')
            ->get()
            ->mapWithKeys(fn (PacchettoGettoni $p): array => [
                $p->gettoni => $p->gettoni.' gettoni · '.number_format($p->prezzo_cent / 100, 2, ',', '.').' €',
            ])
            ->all();
    }
}
