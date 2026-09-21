<?php

declare(strict_types=1);

namespace App\Filament\Gym\Pages;

use App\Enums\TipoDiRichiesta;
use App\Mail\RichiestaDalPannello;
use App\Models\Fattura;
use App\Models\PlanSubscription;
use App\Models\RichiestaDiContatto;
use App\Models\User;
use App\Services\Billing\Exceptions\AbbonamentoNonSuStripeException;
use App\Services\Billing\Stripe\CambioDellaCarta;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Billing\Stripe\PianiInVendita;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * 💳 **Il tuo piano** — §9.6, 18/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * ⛔ Fino al 18/09 una palestra o un trainer **non avevano nessun posto dove
 * pagare il proprio piano**: il sito lo mostrava coi prezzi, e basta. Entravano a
 * mano. 📌 Il committente: *«va sistemato tutto, in tutti gli scenari»*.
 *
 * ══ ⚠️ LO SPAZIO E' SEMPRE IL TUO ═════════════════════════════════════════
 *
 * La pagina mostra il piano dello spazio **di cui sei titolare** — la palestra per
 * chi la possiede, il proprio giro per un trainer — anche se nel pannello stai
 * guardando un'altra palestra dove alleni. 💡 È la stessa regola della vendita
 * (`PianiInVendita::listinoPer()`): si mostra solo quello che si può comprare.
 *
 * ⛔ **Chi non è titolare non la vede**: un trainer dipendente non sceglie il piano
 * della palestra che lo paga.
 */
class IlTuoPiano extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCreditCard;

    protected static ?string $navigationLabel = 'Il tuo piano';

    protected static ?string $title = 'Il tuo piano';

    protected static ?int $navigationSort = 94;

    protected string $view = 'filament.gym.pages.il-tuo-piano';

    /**
     * 🚨 `canAccess()` e non solo `shouldRegisterNavigation()`: nascondere la voce
     * non chiude la pagina, e l'indirizzo si digita.
     */
    public static function canAccess(): bool
    {
        $utente = auth()->user();

        return $utente instanceof User && Cassa::loPuoGestire($utente);
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /** @return array{piani: list<array<string, mixed>>, piano_attuale: ?string, puo_cambiare: bool, pagamento_fallito_il: ?string} */
    public function listino(): array
    {
        return app(PianiInVendita::class)->listinoPer($this->utente());
    }

    /** L'abbonamento in corso, per dire fino a quando e se si rinnova. */
    public function inCorso(): ?PlanSubscription
    {
        $tenantId = $this->utente()->tenant_id;

        return $tenantId === null ? null : PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->attivi()
            ->with('plan')
            ->orderByDesc('starts_at')
            ->first();
    }

    /**
     * Sceglie un piano: apre il pagamento, oppure lo cambia se ne ha già uno.
     *
     * ⚠️ **La strada la decide il server, non il pulsante**: chi ha già un canone
     * pagato su Stripe **cambia**, gli altri **aprono il pagamento**. 🚨 Lasciarlo
     * decidere alla pagina vorrebbe dire che un pulsante sbagliato apre un
     * secondo abbonamento — cioè due addebiti al mese.
     */
    public function scegli(string $codice): void
    {
        $vendita = app(PianiInVendita::class);
        $listino = $vendita->listinoPer($this->utente());

        $voce = collect($listino['piani'])->firstWhere('codice', $codice) ?? [];
        $consumo = $voce['a_consumo'] ?? false;

        // 🆕 F8.6 — il su misura si chiede, non si compra: anche se qualcuno
        // chiamasse `scegli` a mano, il server non apre il pagamento.
        if ($voce !== [] && $this->eSuMisura($voce)) {
            $this->mountAction('suMisura');

            return;
        }

        try {
            if ($listino['puo_cambiare'] && ! $consumo) {
                try {
                    $vendita->cambia($this->utente(), $codice);

                    Notification::make()
                        ->title('Piano cambiato')
                        ->body('Stripe calcola da solo la differenza per i giorni che restano.')
                        ->success()
                        ->send();

                    return;
                } catch (AbbonamentoNonSuStripeException) {
                    /*
                     * 🆕 F8.6 — il piano in corso ha un id Stripe che Stripe
                     * non conosce: per Stripe non c'è niente da cambiare. Si
                     * apre il pagamento qui sotto, e al ritorno
                     * `accendiIlCanone()` chiude il piano di prima.
                     */
                }
            }

            $this->redirect($vendita->apri($this->utente(), $codice, url()->current()));
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        } catch (Throwable $e) {
            report($e);

            Notification::make()
                ->title('Il pagamento non si è aperto')
                ->body('Riprova fra poco.')
                ->danger()
                ->send();
        }
    }

    /*
     * ⛔ **Via «Carte, ricevute e disdetta»** (il portale di Stripe) —
     * 23/09/2026, F8.5.
     *
     * 📌 *«Carte, ricevute e disdetta dà errore… al massimo ci mettiamo
     * fatture»*. 🔬 L'errore veniva da un cliente Stripe salvato che su Stripe
     * non esisteva (vedi `Cassa::clienteEsistente()`); il committente ha
     * comunque scelto di non avere il portale qui. Le ricevute ora sono **le
     * fatture** qui sotto, e la carta si cambia con `cambiaCarta()` (§116).
     * ⏳ Disdire resta da decidere.
     */

    /**
     * 🆕 💳 **Cambia il metodo di pagamento** — dopo F8.
     *
     * 📌 *«Ovviamente ci deve essere la possibilità di cambiare metodo di
     * pagamento»*. La carta si scrive sulla pagina di Stripe; al ritorno il
     * webhook la mette sul cliente **e** sugli abbonamenti (`CambioDellaCarta`).
     */
    public function cambiaCarta(): void
    {
        try {
            $this->redirect(app(CambioDellaCarta::class)->apri($this->utente(), url()->current()));
        } catch (InvalidArgumentException $e) {
            Notification::make()->title($e->getMessage())->warning()->send();
        } catch (Throwable $e) {
            report($e);

            Notification::make()->title('La pagina di Stripe non si è aperta')->body('Riprova fra poco.')->danger()->send();
        }
    }

    /**
     * C'è una carta da cambiare? 💡 Solo il database, senza chiamare Stripe a
     * ogni apertura di pagina: se l'id salvato non esiste più lo scopre
     * `cambiaCarta()`, e lo dice.
     */
    public function haUnMetodoDiPagamento(): bool
    {
        $tenantId = $this->utente()->tenant_id;

        return $tenantId !== null && PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('stripe_customer_id')
            ->exists();
    }

    /**
     * 🆕 🧾 Le fatture della palestra, dalla più recente — F8.4.
     *
     * 💡 Anche quelle **in attesa**: la palestra deve vedere che c'è un
     * pagamento senza fattura, e perché (le mancano i dati).
     *
     * @return Collection<int, Fattura>
     */
    public function fatture(): Collection
    {
        $tenantId = $this->utente()->tenant_id;

        if ($tenantId === null) {
            return new Collection;
        }

        return Fattura::query()
            ->where('tenant_id', $tenantId)
            ->orderByRaw("case when stato = 'in_attesa' then 0 else 1 end")
            ->orderByDesc('id')
            ->limit(36)
            ->get();
    }

    /**
     * Cosa manca per ricevere le fatture, in parole (vuoto = niente).
     *
     * @return list<string>
     */
    public function datiMancanti(): array
    {
        return $this->utente()->tenant?->datiDiFatturazioneMancanti() ?? [];
    }

    /**
     * 🆕 Il piano **su misura** delle palestre non si compra: si chiede — F8.6.
     *
     * 📌 *«per il piano palestra su misura, il tasto deve rimandare a un modulo
     * di contatto che manda un'email a me (info@smp-digital.it)»*.
     *
     * ⚠️ Solo per le **palestre**: il «Trainer su misura» resta com'è, perché
     * la richiesta parla del piano palestra.
     *
     * @param  array<string, mixed>  $piano  una voce di `listino()`
     */
    public function eSuMisura(array $piano): bool
    {
        return ($piano['a_consumo'] ?? false) === true
            && $this->utente()->tenant?->ePersonale() === false;
    }

    public function suMisuraAction(): Action
    {
        return Action::make('suMisura')
            ->label('Scrivici')
            ->icon('heroicon-m-envelope')
            ->modalHeading('Un piano su misura')
            ->modalDescription('Raccontaci com\'è la tua palestra: ti rispondiamo noi, di solito entro un giorno lavorativo.')
            ->modalSubmitActionLabel('Manda la richiesta')
            ->schema([
                TextInput::make('iscritti')->label('Quanti iscritti, più o meno')->numeric()->minValue(0)->maxValue(100000),
                TextInput::make('trainer')->label('Quanti trainer')->numeric()->minValue(0)->maxValue(100000),
                TextInput::make('telefono')->label('Un telefono, se preferisci essere chiamato')->tel()->maxLength(40),
                Textarea::make('messaggio')->label('Cosa ti serve')->required()->minLength(10)->maxLength(4000)->rows(5),
            ])
            ->action(function (array $data): void {
                $utente = $this->utente();

                /*
                 * 💡 La richiesta **resta anche nel database** («Richieste dal
                 * sito» in `/god`): se la mail non parte, o finisce nel log
                 * finché non c'è Zeptomail, non si perde.
                 */
                $richiesta = RichiestaDiContatto::create([
                    'tipo' => TipoDiRichiesta::Palestra,
                    'nome' => $utente->name,
                    'email' => $utente->email,
                    'telefono' => $data['telefono'] ?? null,
                    'organizzazione' => $utente->tenant?->name,
                    'iscritti' => filled($data['iscritti'] ?? null) ? (int) $data['iscritti'] : null,
                    'trainer' => filled($data['trainer'] ?? null) ? (int) $data['trainer'] : null,
                    'messaggio' => (string) $data['messaggio'],
                ]);

                try {
                    Mail::to((string) config('services.contatti.email'))->send(new RichiestaDalPannello($richiesta));
                } catch (Throwable $e) {
                    report($e);
                }

                Notification::make()
                    ->title('Richiesta mandata')
                    ->body('Ti rispondiamo a '.$utente->email.'.')
                    ->success()
                    ->send();
            });
    }

    private function utente(): User
    {
        /** @var User */
        return auth()->user();
    }
}
