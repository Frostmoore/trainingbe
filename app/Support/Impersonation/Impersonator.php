<?php

declare(strict_types=1);

namespace App\Support\Impersonation;

use App\Enums\AuditAction;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use BadMethodCallException;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Entrare nei panni di un altro utente, e uscirne.
 *
 * 🚨 **Perche' e' la funzione piu' delicata dei pannelli.** Un'impersonazione e'
 * un accesso completo ai dati di un cliente che, nei log applicativi, e'
 * indistinguibile da un accesso legittimo di quel cliente. Il supporto e' la
 * ragione numero uno per cui servira' — «non riesco a vedere la mia scheda» si
 * risolve in trenta secondi guardando quello che vede lui — ma senza traccia
 * diventa una porta di servizio permanente e invisibile.
 *
 * Percio' qui ci sono tre cose, non una:
 *  1. **il controllo**, ripetuto anche se il pannello e' gia' chiuso agli altri;
 *  2. **la traccia**, scritta PRIMA di cambiare identita' — l'attore va
 *     registrato finche' e' ancora lui, dopo il `login()` `auth()->user()` e'
 *     gia' la vittima e il registro direbbe che Mario ha impersonato Mario;
 *  3. **l'uscita**, sempre disponibile e anch'essa tracciata.
 *
 * ⚠️ **La sessione va rattoppata a mano dopo il cambio utente.** Il middleware
 * `AuthenticateSession` confronta l'hash della password in sessione con quello
 * dell'utente autenticato: dopo aver sostituito l'utente i due non coincidono
 * piu' e alla richiesta successiva la sessione verrebbe svuotata con un
 * `AuthenticationException`. Non e' un difetto del middleware — sta facendo
 * esattamente il suo lavoro, cioe' accorgersi che l'utente della sessione e'
 * cambiato.
 */
class Impersonator
{
    /** La chiave in sessione che dice «questa sessione non e' di chi sembra». */
    public const SESSION_KEY = 'impersonator_id';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    // ───────────────────────── interrogazioni ─────────────────────────

    public function isImpersonating(): bool
    {
        return session()->has(self::SESSION_KEY);
    }

    /** Chi ha iniziato l'impersonazione, se e' in corso. */
    public function originalUser(): ?User
    {
        $id = session()->get(self::SESSION_KEY);

        if ($id === null) {
            return null;
        }

        return User::withoutGlobalScopes()->find($id);
    }

    /**
     * L'attore puo' impersonare il bersaglio?
     *
     * Le condizioni sono cinque e ognuna ha gia' un modo noto di essere
     * aggirata se manca:
     *  - solo il super admin, perche' e' l'unico ruolo che sta fuori dalle
     *    palestre e non ha un cliente da cui essere pagato per farlo;
     *  - mai un altro super admin, altrimenti l'audit diventa contestabile
     *    («non ero io, era lui nei miei panni»);
     *  - mai se stessi, che non serve a niente e sporca il registro;
     *  - mai un utente disattivato, perche' l'impersonazione non deve essere il
     *    modo per far tornare vivo un account chiuso;
     *  - **solo chi ha un pannello in cui entrare.**
     *
     * L'ultima merita una riga in piu'. Un iscritto non entra in nessun
     * pannello: usa solo l'app. Impersonarlo qui produrrebbe una sessione web
     * valida che sbatte su un 403 alla prima pagina — l'impressione sarebbe che
     * l'impersonazione sia rotta, mentre e' il bersaglio a non avere una
     * destinazione. Per guardare i dati di un iscritto la strada e' il pannello
     * della sua palestra (B3.4), dove quei dati si vedono per mestiere.
     */
    public function can(User $actor, User $target): bool
    {
        return $actor->isSuperAdmin()
            && ! $target->isSuperAdmin()
            && $actor->getKey() !== $target->getKey()
            && (bool) $target->is_active
            && ($target->isGymAdmin() || $target->isTrainer());
    }

    // ───────────────────────── azioni ─────────────────────────

    /**
     * @throws RuntimeException se le condizioni di `can()` non sono soddisfatte
     */
    public function start(User $target, ?User $actor = null): void
    {
        $actor ??= $this->requireAuthenticated();

        if (! $this->can($actor, $target)) {
            throw new RuntimeException('Impersonazione non consentita.');
        }

        // 🚨 PRIMA il registro, poi il cambio di identita'.
        $this->audit->log(
            AuditAction::ImpersonationStarted,
            $target,
            [
                'target_email' => $target->email,
                'target_username' => $target->username,
                'tenant' => $target->tenant?->name,
            ],
            actor: $actor,
            tenant: $target->tenant_id,
        );

        $this->guard()->login($target);

        session()->put(self::SESSION_KEY, $actor->getKey());
        $this->syncPasswordHash($target);
    }

    /**
     * Torna all'utente di partenza.
     *
     * Se la sessione non e' impersonata non fa niente e non lancia: la rotta di
     * uscita e' pubblica per gli autenticati, e chi ci arriva per sbaglio deve
     * trovare una pagina, non un errore.
     */
    public function stop(): ?User
    {
        $original = $this->originalUser();

        if ($original === null) {
            session()->forget(self::SESSION_KEY);

            return null;
        }

        $impersonato = auth()->user();

        $this->audit->log(
            AuditAction::ImpersonationStopped,
            $impersonato instanceof User ? $impersonato : null,
            $impersonato instanceof User ? ['target_email' => $impersonato->email] : [],
            actor: $original,
            tenant: $impersonato instanceof User ? $impersonato->tenant_id : null,
        );

        session()->forget(self::SESSION_KEY);

        $this->guard()->login($original);
        $this->syncPasswordHash($original);

        return $original;
    }

    // ───────────────────────── interni ─────────────────────────

    private function requireAuthenticated(): User
    {
        $user = auth()->user();

        if (! $user instanceof User) {
            throw new RuntimeException('Nessun utente autenticato.');
        }

        return $user;
    }

    private function guard(): StatefulGuard
    {
        $guard = Auth::guard(config('filament.auth.guard') ?? 'web');

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('Il guard di sessione non e\' utilizzabile per l\'impersonazione.');
        }

        return $guard;
    }

    /**
     * Riallinea l'hash in sessione all'utente adesso autenticato.
     *
     * Senza, `AuthenticateSession` alla richiesta successiva vede due hash
     * diversi, conclude — correttamente — che la sessione e' stata dirottata, e
     * la svuota. Il risultato sarebbe che l'impersonazione «funziona» per una
     * richiesta sola e poi sbatte fuori tutti.
     */
    private function syncPasswordHash(User $user): void
    {
        $hash = $user->getAuthPassword();

        try {
            $hash = $this->guard()->hashPasswordForCookie($hash);
        } catch (BadMethodCallException) {
            // Guard senza supporto: resta l'hash grezzo, che il middleware
            // accetta ancora come formato precedente.
        }

        session()->put('password_hash_'.Auth::getDefaultDriver(), $hash);
    }
}
