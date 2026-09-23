<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\User;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Support\Facades\Auth;

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
     * Il pannello che spetta a questo utente, o `null` se non ne ha uno.
     *
     * 🚨 **Restituisce una stringa, non una risposta**, e il motivo è concreto.
     * Dentro Livewire `redirect()` produce un `Livewire\Redirector`, che non è
     * un `RedirectResponse`, non è un `Response` di Symfony e non ha nemmeno
     * `getTargetUrl()`. Ogni volta che si è provato a maneggiare l'oggetto
     * risposta è saltato fuori un errore diverso, sempre e solo nel percorso
     * reale. Con un URL non c'è niente da maneggiare.
     *
     * È anche il motivo per cui questo metodo è statico e pubblico: lo usa sia
     * la risposta al login, sia `Login::mount()` per chi ha già una sessione.
     * Un solo punto che decide dove va ciascuno.
     */
    public static function urlFor(?User $user): ?string
    {
        if ($user === null) {
            return null;
        }

        if ($user->isSuperAdmin()) {
            return self::destinazione('god');
        }

        /*
         * ══ 🚨 LA DOMANDA VA FATTA IN **TUTTI** GLI SPAZI ══════════════
         *
         * ⚠️ **Questo `if` ha sbagliato la stessa cosa tre volte**, e le prime
         * due sono state curate aggiungendo un predicato. La terza no.
         *
         * | Quando | Chi restava fuori | Perché |
         * |---|---|---|
         * | fino al 16/09 | il **trainer indipendente** | mancava `isFreeTrainer()` |
         * | 18/09 → 24/09 | il **trainer di palestra** | a casa sua è `free_user`, e i ruoli si chiedono lì |
         *
         * 📌 Il committente, il 24/09, provando `trainer@palestra-demo.test`:
         * *«Non mi fa accedere, carica e mi rimette nella stessa pagina»*.
         *
         * ⛔ **Al login il contesto è lo spazio di CASA**, e dalla decisione 2
         * (18/09) la casa di un trainer di palestra è uno spazio **personale**
         * dove il ruolo è `free_user`. Chiesti lì: `isGymAdmin()` no,
         * `isTrainer()` **no** (è `trainer` nella palestra, non a casa),
         * `isFreeTrainer()` no.
         *
         * 🚨 `urlFor()` rispondeva `null`, e `toResponse()` per chi non ha
         * destinazione **lo slogga** e lo rimanda al login. 💡 Da fuori si vede
         * solo *«carica e torna indietro»*: nessun errore, nessun messaggio,
         * niente nei registri. ⛔ **Nessun trainer di palestra poteva entrare nel
         * pannello per sei giorni.**
         *
         * 💡 Il difetto non era il predicato mancante: era **chiedere i ruoli
         * nello spazio di casa** a gente che lavora altrove. `allena()` guarda
         * in **tutti** gli spazi (`ChiSiVede::palestreDoveAllena`), ed è la
         * stessa domanda che fanno le API dell'app in `TrainerController`.
         *
         * 🛡️ Lo tiene fermo `DueCappelliNelPannelloTest`, che prova i tre casi
         * **nel contesto di casa** — provarli dentro la palestra nasconderebbe
         * il difetto, perché lì `isTrainer()` risponde di sì e la domanda
         * sbagliata sembra giusta.
         */
        if ($user->isGymAdmin() || $user->allena()) {
            return self::destinazione('admin');
        }

        return null;
    }

    /**
     * ⚠️ Nessun tipo di ritorno dichiarato, come nel contratto `Responsable`:
     * dentro Livewire il valore restituito da `redirect()` non soddisfa nessun
     * tipo che si possa scrivere qui.
     *
     * @return mixed
     */
    public function toResponse($request)
    {
        /** @var User|null $user */
        $user = Auth::user();

        $destinazione = self::urlFor($user);

        if ($destinazione !== null) {
            return redirect()->to($destinazione);
        }

        if ($user === null) {
            return redirect()->route('login');
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
     * Login riuscito e 403 in faccia, senza capire perché.
     *
     * ⚠️ L'helper `session()`, non `$request->session()`: la richiesta che
     * Livewire costruisce non ha una sessione legata.
     */
    private static function destinazione(string $panelId): string
    {
        $home = filament()->getPanel($panelId)->getUrl();
        $intended = session()->pull('url.intended');

        if (is_string($intended) && str_starts_with($intended, $home)) {
            return $intended;
        }

        return $home;
    }
}
