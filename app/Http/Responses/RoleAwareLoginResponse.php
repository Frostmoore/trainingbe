<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Dopo il login, ognuno al suo pannello.
 *
 * Il form di accesso è **uno solo**: non si chiede all'utente di sapere in
 * anticipo se è «amministratore di piattaforma» o «di palestra», che è una
 * distinzione nostra e non sua. Entra, e il sistema lo porta dove deve andare.
 *
 * L'ordine dei controlli va dal più potente al meno potente, perché un super
 * admin **è anche** un amministratore per molte policy: invertirlo lo
 * porterebbe nel pannello sbagliato.
 */
class RoleAwareLoginResponse implements LoginResponse
{
    public function toResponse($request): RedirectResponse
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->isSuperAdmin()) {
            return redirect()->intended(filament()->getPanel('god')->getUrl());
        }

        if ($user->isGymAdmin() || $user->isTrainer()) {
            return redirect()->intended(filament()->getPanel('admin')->getUrl());
        }

        // Gli iscritti non hanno un pannello: usano l'app. Qui non ci arrivano
        // perché il login li rifiuta prima (vedi Login::isUserAllowedToAccessPanel),
        // ma se ci arrivassero è meglio rimandarli fuori che lasciarli su una
        // pagina bianca senza capire cosa è successo.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->with('status', __(
            'Gli iscritti accedono dall\'app, non da qui.'
        ));
    }
}
