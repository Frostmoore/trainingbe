<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dopo il login, ognuno al suo pannello.
 *
 * Il form di accesso è **uno solo**: non si chiede all'utente di sapere in
 * anticipo se è «amministratore di piattaforma» o «di palestra», che è una
 * distinzione nostra e non sua. Entra, e il sistema lo porta dove deve andare.
 */
class RoleAwareLoginResponse implements LoginResponse
{
    /**
     * ⚠️ **Nessun tipo di ritorno dichiarato, ed è deliberato.**
     *
     * Il login di Filament passa da Livewire, e lì `redirect()` restituisce un
     * `Livewire\Features\SupportRedirects\Redirector` — che non è un
     * `RedirectResponse` e **nemmeno** un `Response` di Symfony. Qualunque tipo
     * stretto fa esplodere il login con un `TypeError`, e **solo nel percorso
     * reale**: chiamando il metodo da un test, fuori da Livewire, funziona.
     *
     * Il contratto `Responsable` non dichiara un tipo proprio per questo
     * motivo. Qui si fa lo stesso.
     *
     * @return mixed
     */
    public function toResponse($request)
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->isSuperAdmin()) {
            return redirect()->to($this->destinazione('god'));
        }

        if ($user->isGymAdmin() || $user->isTrainer()) {
            return redirect()->to($this->destinazione('admin'));
        }

        // Gli iscritti non hanno un pannello: usano l'app. Qui non ci arrivano
        // perché il login li rifiuta prima (Login::isUserAllowedToAccessPanel),
        // ma se ci arrivassero è meglio rimandarli fuori che lasciarli su una
        // pagina bianca senza capire cosa sia successo.
        Auth::logout();
        session()->invalidate();
        session()->regenerateToken();

        return redirect()->route('login')->with('status', __(
            'Gli iscritti accedono dall\'app, non da qui.'
        ));
    }

    /**
     * Dove mandarlo dentro il pannello che gli spetta.
     *
     * 🚨 L'indirizzo «richiesto prima» (`url.intended`) si onora **solo se
     * appartiene a quel pannello**. Altrimenti succede questo: uno arriva su
     * `/admin`, viene rimbalzato al login, entra come super admin — e
     * `intended()` lo riporta su `/admin`, dove un super admin non può entrare.
     * Risultato: login riuscito e 403 in faccia, senza capire perché.
     *
     * È esattamente il caso che si è presentato appena acceso il pannello.
     */
    private function destinazione(string $panelId): string
    {
        $home = filament()->getPanel($panelId)->getUrl();

        // ⚠️ L'helper `session()`, non `$request->session()`: la richiesta che
        // Livewire costruisce non ha una sessione legata, e chiederla lì fa
        // fallire con «Session store not set on request» — di nuovo un guasto
        // che si vede solo nel percorso reale.
        $intended = session()->pull('url.intended');

        if (is_string($intended) && str_starts_with($intended, $home)) {
            return $intended;
        }

        return $home;
    }
}
