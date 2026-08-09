<?php

declare(strict_types=1);

namespace App\Filament\Auth;

use App\Models\User;
use App\Support\Dev\QuickLogin;
use Filament\Actions\Action;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Auth\Pages\Login as BaseLogin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * La schermata di accesso, unica per tutti i ruoli.
 *
 * È registrata su **entrambi** i pannelli: chi arriva da `/god/login` e chi
 * arriva da `/admin/login` vede la stessa cosa, e `RoleAwareLoginResponse`
 * decide dove mandarlo. All'utente non si chiede di sapere in anticipo che tipo
 * di amministratore è.
 */
class Login extends BaseLogin
{
    /**
     * Può entrare chi ha accesso ad **almeno un** pannello.
     *
     * Il comportamento di serie controlla solo il pannello corrente: chiamando
     * `/admin/login`, un super admin verrebbe rifiutato — pur potendo entrare
     * in `/god` — con un messaggio da credenziali sbagliate. Con un login solo
     * quel controllo va allargato, e lo smistamento lo fa la risposta.
     *
     * Gli **iscritti** restano fuori, con un messaggio che dice cosa fare
     * invece di «credenziali non valide», che sarebbe falso e li farebbe
     * riprovare all'infinito.
     */
    protected function isUserAllowedToAccessPanel(Authenticatable $user): bool
    {
        if (! $user instanceof User) {
            return false;
        }

        if ($user->isMember()) {
            throw ValidationException::withMessages([
                'data.email' => __('Gli iscritti accedono dall\'app, non da qui.'),
            ]);
        }

        return $user->canAccessPanel(filament()->getPanel('god'))
            || $user->canAccessPanel(filament()->getPanel('admin'));
    }

    /**
     * I pulsanti di accesso rapido, sotto quello di login.
     *
     * Compaiono solo se `QuickLogin::enabled()`, che in produzione risponde
     * `false` a prescindere dalla configurazione.
     */
    protected function getFormActions(): array
    {
        $azioni = parent::getFormActions();

        foreach (QuickLogin::candidates() as $i => $c) {
            $azioni[] = Action::make('quick_login_'.$i)
                ->label($c['label'])
                ->badge()
                ->color('gray')
                ->tooltip($c['description'].' — '.$c['email'])
                ->action(fn (): ?LoginResponse => $this->quickLogin($c['email']));
        }

        return $azioni;
    }

    /**
     * Autentica senza password.
     *
     * 🚨 L'unico punto del sistema in cui si ottiene una sessione senza
     * credenziali. Tre controlli, e nessuno è ridondante:
     *  - `QuickLogin::enabled()` — spento in produzione a prescindere;
     *  - `QuickLogin::resolve()` — solo indirizzi presenti nell'elenco proposto,
     *    altrimenti sarebbe «entra come chiunque, basta il suo indirizzo»;
     *  - `isUserAllowedToAccessPanel()` — le stesse regole del login normale.
     */
    protected function quickLogin(string $email): ?LoginResponse
    {
        if (! QuickLogin::enabled()) {
            return null;
        }

        $user = QuickLogin::resolve($email);

        if ($user === null || ! $this->isUserAllowedToAccessPanel($user)) {
            return null;
        }

        Auth::guard(filament()->getAuthGuard())->login($user);
        session()->regenerate();

        return app(LoginResponse::class);
    }

    public function getSubheading(): ?string
    {
        if (! QuickLogin::enabled()) {
            return null;
        }

        return __('Ambiente di sviluppo: puoi entrare con un profilo di prova.');
    }
}
