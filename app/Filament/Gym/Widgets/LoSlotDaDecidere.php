<?php

declare(strict_types=1);

namespace App\Filament\Gym\Widgets;

use App\Models\User;
use App\Services\Tenancy\LoSlotDelTrainer;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Widgets\Widget;
use Illuminate\Support\Collection;

/**
 * 🎟️ **«Tengo il posto o lo libero?» — adesso anche nel pannello** — 24/09/2026.
 *
 * 📌 Il committente, dopo aver provato il doppio cappello: *«Ok, metti la
 * modale anche sul pannello»*.
 *
 * ══ 🚨 PERCHE' SERVIVA: LA DOMANDA ESISTEVA SOLO NELL'APP ═══════════════════
 *
 * ⛔ `LoSlotDelTrainer` sa chiedere e sa registrare la risposta dal 18/09, ma
 * l'unica bocca che la faceva era `TrainerController` — cioè **l'app**. 🚨 Un
 * trainer che lavora dal pannello non l'avrebbe vista mai: la decisione restava
 * appesa e lui **continuava a pagare** un posto che forse non voleva.
 *
 * 💡 Trovato provando `luca@esempio.test`, il trainer col doppio cappello: era
 * stato assunto in una palestra dove stava già una sua allieva, e la domanda
 * era lì, invisibile.
 *
 * ══ ⚠️ PERCHE' UNA MODALE **E** UNA CARD ════════════════════════════════════
 *
 * 🚨 La modale si apre da sola all'ingresso, come nell'app. ⛔ Ma resta anche
 * una card sotto, e non è ridondanza: una modale che si chiude — per sbaglio,
 * con Esc, o perché il browser ricarica — porterebbe via **l'unico** modo di
 * rispondere, e la domanda tornerebbe invisibile com'era prima.
 *
 * 💡 La scelta **non ha un valore di serie**, come nell'app: costa soldi in un
 * verso e un allievo nell'altro, e sceglierla al posto suo sarebbe deciderla.
 * Per questo la modale non si chiude «confermando», e chiuderla non decide
 * niente.
 *
 * ══ 💡 UNA PERSONA PER VOLTA, E IL PERCHE' ══════════════════════════════════
 *
 * ⛔ L'app le mette in elenco dentro una modale sola. Qui no: una modale di
 * Filament ha **un** pulsante di conferma, e infilarci dentro due bottoni per
 * riga vorrebbe dire costruire a mano una tabella di azioni dentro un modale —
 * cioè la parte più fragile del framework. 💡 Una domanda per volta, e appena
 * si risponde si riapre sulla successiva: sono due gesti identici, e nessuno
 * resta indietro.
 */
class LoSlotDaDecidere extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected string $view = 'filament.gym.widgets.lo-slot-da-decidere';

    protected int|string|array $columnSpan = 'full';

    /**
     * 🚨 **Prima di tutto il resto.** È l'unica cosa del cruscotto che chiede
     * una risposta invece di raccontare un numero.
     */
    protected static ?int $sort = -2;

    /**
     * ══ 🚨 NIENTE CARICAMENTO IN DIFFERITA — e non è un'ottimizzazione ══════
     *
     * ⛔ I widget di Filament nascono **lazy** (`CanBeLazy::$isLazy = true`):
     * la pagina disegna un riquadro **vuoto** e il contenuto arriva dopo, con
     * una seconda richiesta.
     *
     * 📏 Misurato il 24/09 sul pannello vero: in cima al cruscotto c'era un
     * rettangolo **vuoto**, senza titolo e senza pulsanti, e **nessuna modale**
     * — mentre in PHP il test vedeva la card e l'azione. 🚨 Due verità diverse
     * sulla stessa pagina, e quella che conta è la seconda.
     *
     * ⚠️ E il danno non è estetico: `mount()` — che è dove la modale si apre da
     * sola — **gira solo quando il widget si carica davvero**. Una domanda che
     * deve comparire all'ingresso non può aspettare un secondo giro.
     */
    protected static bool $isLazy = false;

    public static function canView(): bool
    {
        return self::daDecidere()->isNotEmpty();
    }

    /**
     * 🚨 La modale si apre **da sola**, come nell'app all'avvio.
     *
     * ⚠️ `mountAction()` e non un `dispatch` dal Blade: montata qui, la
     * domanda c'è già al primo disegno della pagina — con un evento ci sarebbe
     * un istante in cui il cruscotto si vede senza, e chi legge veloce
     * comincerebbe a fare altro.
     */
    public function mount(): void
    {
        $prima = self::daDecidere()->first();

        if ($prima !== null) {
            $this->mountAction('decidiLoSlot', ['member' => (int) $prima->member_id]);
        }
    }

    /**
     * Le domande aperte per chi sta guardando.
     *
     * ⚠️ **Non filtra per spazio**, ed è voluto: la domanda riguarda **lui**,
     * non il posto in cui si trova adesso. 💡 Chi ha due cappelli la deve
     * trovare anche quando sta guardando il suo spazio personale, o la vedrebbe
     * solo entrando nella palestra che l'ha fatta nascere.
     *
     * @return Collection<int, object>
     */
    public static function daDecidere(): Collection
    {
        $utente = auth()->user();

        if (! $utente instanceof User) {
            return collect();
        }

        return app(LoSlotDelTrainer::class)->daDecidere($utente);
    }

    /** @return array<string, mixed> */
    protected function getViewData(): array
    {
        return ['voci' => self::daDecidere()];
    }

    /**
     * La domanda, con le due risposte.
     *
     * 💡 I testi sono **gli stessi dell'app** (`scelta_dello_slot.dart`), parola
     * per parola: è la stessa decisione, e due spiegazioni diverse della stessa
     * cosa fanno dubitare che sia la stessa cosa.
     */
    public function decidiLoSlotAction(): Action
    {
        return Action::make('decidiLoSlot')
            ->modalHeading(fn (array $arguments): string => $this->voce($arguments) === null
                ? 'Non c\'è più niente da decidere'
                : 'Un tuo allievo è entrato nella tua palestra')
            ->modalDescription(fn (array $arguments): ?string => $this->descrizione($arguments))
            ->modalIcon('heroicon-o-ticket')

            /*
             * ⛔ Il pulsante di conferma è **una** delle due risposte, non «ok»:
             * un «conferma» qui non vorrebbe dire niente.
             */
            ->modalSubmitActionLabel('Tengo il posto')
            ->modalCancelActionLabel('Decido dopo')
            ->extraModalFooterActions(fn (Action $azione): array => [
                $azione->makeModalSubmitAction('liberoIlPosto', ['tengo' => false])
                    ->label('Libero il posto')
                    ->color('danger'),
            ])
            ->action(function (array $arguments): void {
                $this->rispondi($arguments, tengo: (bool) ($arguments['tengo'] ?? true));
            });
    }

    /**
     * Registra la risposta e **riapre sulla prossima**, se ce n'è un'altra.
     *
     * 🚨 `decidi()` risponde `false` quando non c'era niente da decidere — già
     * risposto da un'altra parte, magari dall'app, mentre questa pagina era
     * aperta. ⛔ In quel caso non si dice «fatto»: si dice che non c'era niente
     * da fare, o la persona crederebbe di aver deciso adesso una cosa decisa
     * ieri, forse nell'altro verso.
     */
    private function rispondi(array $arguments, bool $tengo): void
    {
        $utente = auth()->user();
        $allievo = User::withoutGlobalScopes()->find($arguments['member'] ?? null);

        if (! $utente instanceof User || $allievo === null) {
            return;
        }

        $fatto = app(LoSlotDelTrainer::class)->decidi($utente, $allievo, $tengo);

        if (! $fatto) {
            Notification::make()
                ->title('Su questa persona non c\'era più niente da decidere')
                ->body('Può essere stata decisa dall\'app mentre questa pagina era aperta.')
                ->warning()
                ->send();

            return;
        }

        Notification::make()
            ->title($tengo
                ? "Tieni il posto di {$allievo->name}"
                : "Hai liberato il posto di {$allievo->name}")
            ->body($tengo
                ? 'Continui a pagarlo: se uscirà dalla palestra resterà tuo.'
                : 'Non lo paghi più. Se uscirà dalla palestra non tornerà da te: per riaverlo dovrai rimandargli un invito.')
            ->success()
            ->send();

        $prossima = self::daDecidere()->first();

        if ($prossima !== null) {
            $this->replaceMountedAction('decidiLoSlot', ['member' => (int) $prossima->member_id]);
        }
    }

    /** La riga in sospeso di cui parla la modale aperta. */
    private function voce(array $arguments): ?object
    {
        $id = (int) ($arguments['member'] ?? 0);

        return self::daDecidere()->firstWhere('member_id', $id);
    }

    /**
     * ⚠️ **Cosa comporta, detto prima della domanda.** Senza, *«tieni il
     * posto?»* è una domanda a cui non si può rispondere — è la stessa lezione
     * scritta nella modale dell'app.
     */
    private function descrizione(array $arguments): ?string
    {
        $voce = $this->voce($arguments);

        if ($voce === null) {
            return 'Qualcuno ha già risposto da un\'altra parte: chiudi pure.';
        }

        return "{$voce->nome} continua a seguirti come prima. Ora però è anche iscritto a "
            ."{$voce->palestra}, che copre il suo posto.\n\n"
            ."Se tieni il posto, continui a pagarlo: quando uscirà dalla palestra resterà tuo.\n"
            .'Se lo liberi, non lo paghi più: quando uscirà dalla palestra non tornerà da te.';
    }
}
