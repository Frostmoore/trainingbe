<?php

declare(strict_types=1);

namespace App\Filament\Gym\Pages;

use App\Models\PlanSubscription;
use App\Models\User;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Billing\Stripe\PianiInVendita;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
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

        $consumo = collect($listino['piani'])->firstWhere('codice', $codice)['a_consumo'] ?? false;

        try {
            if ($listino['puo_cambiare'] && ! $consumo) {
                $vendita->cambia($this->utente(), $codice);

                Notification::make()
                    ->title('Piano cambiato')
                    ->body('Stripe calcola da solo la differenza per i giorni che restano.')
                    ->success()
                    ->send();

                return;
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

    /**
     * La gestione di Stripe: carta, ricevute, disdetta.
     *
     * 💡 Il portale e non pulsanti nostri, per la ragione scritta su
     * `Cassa::portale()`: «disdici» è anche «cambia carta» e «scarica le
     * ricevute», e rifarlo vorrebbe dire sbagliarne un pezzo.
     */
    public function gestisci(): void
    {
        try {
            $this->redirect(app(Cassa::class)->portale($this->utente(), url()->current()));
        } catch (Throwable $e) {
            report($e);

            Notification::make()->title('Non riesco ad aprire la gestione. Riprova fra poco.')->danger()->send();
        }
    }

    public function haUnClienteStripe(): bool
    {
        return app(Cassa::class)->clienteDi($this->utente()) !== null;
    }

    private function utente(): User
    {
        /** @var User */
        return auth()->user();
    }
}
