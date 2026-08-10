<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\SocialProvider;
use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SocialLoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\SocialIdentity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Social\Exceptions\InvalidSocialTokenException;
use App\Services\Auth\Social\SocialTokenVerifier;
use App\Services\Auth\Social\VerifiedSocialUser;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Accesso con Google o Apple — C17.
 *
 * ⏸️ **Il codice c'e' ed e' provato; mancano le credenziali.** Perche' funzioni
 * davvero servono un client id per Google (uno per Android, uno per iOS) e un
 * bundle id per Apple, in `services.social.*.client_ids`. Finche' non ci sono,
 * `configured()` e' falso, l'endpoint risponde 501 e **l'app non mostra i
 * pulsanti**: meglio un pulsante che non c'e' di uno che da' sempre errore.
 *
 * ─────────────────────────────────────────────────────────────────────────
 * Le tre regole che rendono sicuro questo endpoint
 * ─────────────────────────────────────────────────────────────────────────
 *
 * 1. 🚨 **L'identita' e' `(provider, sub)`, mai l'email.** Il `sub` e' stabile
 *    per sempre; l'email cambia, e con «Nascondi la mia email» di Apple cambia
 *    da sola. Cercare per email vorrebbe dire perdere l'account di qualcuno.
 *
 * 2. 🚨 **Si collega a un utente esistente SOLO con email verificata.** Se
 *    bastasse un'email dichiarata, chiunque riesca a farsi emettere un token con
 *    l'indirizzo di un altro entrerebbe nel suo account. Apple verifica sempre;
 *    Google lo dichiara in `email_verified`.
 *
 * 3. 🚨 **La palestra non arriva mai dal token.** Il `join_code` serve solo a
 *    creare un account nuovo; per un'identita' gia' nota il tenant si legge da
 *    `user->tenant_id` (ADR-04). Altrimenti presentare il codice di un'altra
 *    palestra sposterebbe una persona di palestra al primo accesso.
 */
class SocialAuthController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SocialTokenVerifier $verifier,
    ) {}

    public function store(SocialLoginRequest $request): JsonResponse
    {
        $provider = $request->fornitore();

        if (! $this->verifier->configured($provider)) {
            // 501 e non 422: non e' l'utente ad aver sbagliato qualcosa, e' il
            // server a non avere ancora questa funzione. Dirlo con un errore di
            // validazione manderebbe a cercare l'errore nei dati inseriti.
            return response()->json([
                'message' => __('L\'accesso con :provider non è ancora disponibile.', [
                    'provider' => $provider->label(),
                ]),
                'code' => 'social_not_configured',
            ], 501);
        }

        try {
            $identita = $this->verifier->verify($provider, $request->string('id_token')->toString());
        } catch (InvalidSocialTokenException $e) {
            // 🚨 Il motivo vero va nei log, non nella risposta: «firma non
            // valida» e «token scaduto» sono informazioni utili a chi sta
            // provando a entrare. All'app va la stessa frase generica di una
            // password sbagliata.
            Log::warning('Token social rifiutato', [
                'provider' => $provider->value,
                'motivo' => $e->getMessage(),
            ]);

            throw ValidationException::withMessages([
                'id_token' => __('Non è stato possibile completare l\'accesso.'),
            ]);
        }

        $esistente = SocialIdentity::where('provider', $provider->value)
            ->where('provider_user_id', $identita->providerUserId)
            ->first();

        return $esistente !== null
            ? $this->accediConIdentitaNota($esistente, $request)
            : $this->primoAccesso($identita, $request);
    }

    /**
     * Il caso normale: l'identita' e' gia' collegata a qualcuno.
     *
     * ⚠️ Qui il `join_code` **si ignora deliberatamente**, anche se l'app lo
     * manda: la palestra della persona e' quella scritta sul suo utente, e
     * lasciare che un codice la cambiasse sarebbe un modo per entrare in una
     * palestra diversa dalla propria.
     */
    private function accediConIdentitaNota(SocialIdentity $identita, SocialLoginRequest $request): JsonResponse
    {
        $utente = $identita->user;

        if ($utente === null || ! $utente->is_active) {
            throw ValidationException::withMessages([
                'id_token' => __('Non è stato possibile completare l\'accesso.'),
            ]);
        }

        $tenant = Tenant::find($utente->tenant_id);

        if ($tenant === null || ! $tenant->isActive()) {
            // La stessa risposta del middleware `tenant.active`, cosi' l'app
            // porta alla schermata «palestra sospesa» invece che al login —
            // dove riproverebbe all'infinito con credenziali giuste.
            return response()->json([
                'message' => __('La tua palestra è sospesa. Contattala per riattivare l\'accesso.'),
                'code' => 'tenant_inactive',
            ], 403);
        }

        $identita->forceFill(['last_login_at' => now()])->save();

        return $this->context->runAs($tenant, function () use ($utente, $tenant, $request): JsonResponse {
            $utente->forceFill(['last_login_at' => now()])->saveQuietly();

            return $this->tokenResponse($utente, $tenant, $request);
        });
    }

    /**
     * Identita' mai vista: o si collega a un account esistente, o se ne crea uno.
     *
     * In entrambi i casi serve il `join_code`, perche' senza non si sa di quale
     * palestra si parli — e l'email da sola non basta: la stessa email puo'
     * essere iscritta a due palestre diverse, ed e' una situazione prevista.
     */
    private function primoAccesso(VerifiedSocialUser $identita, SocialLoginRequest $request): JsonResponse
    {
        $joinCode = $request->string('join_code')->toString();

        if ($joinCode === '') {
            // 422 con un codice riconoscibile: l'app deve poter capire che
            // serve il codice palestra e chiederlo, invece di mostrare un
            // errore generico su una schermata senza campi.
            return response()->json([
                'message' => __('Per il primo accesso serve il codice della tua palestra.'),
                'code' => 'join_code_required',
                'errors' => ['join_code' => [__('Per il primo accesso serve il codice della tua palestra.')]],
            ], 422);
        }

        $tenant = $this->resolveActiveTenant($joinCode);

        return $this->context->runAs($tenant, function () use ($identita, $tenant, $request): JsonResponse {
            $utente = $this->collegaOCrea($identita, $tenant);

            return $this->tokenResponse($utente, $tenant, $request, $utente->wasRecentlyCreated ? 201 : 200);
        });
    }

    /**
     * Collega l'identita' a un utente della palestra, creandolo se non c'e'.
     *
     * 🚨 Il collegamento per email avviene **solo con email verificata**: vedi
     * la regola 2 in testa alla classe. Con un'email non verificata si crea un
     * account nuovo, che nel peggiore dei casi e' un doppione da unire a mano —
     * mentre l'alternativa, nel peggiore dei casi, e' l'accesso all'account di
     * qualcun altro.
     */
    private function collegaOCrea(VerifiedSocialUser $identita, Tenant $tenant): User
    {
        return DB::transaction(function () use ($identita, $tenant): User {
            $utente = null;

            if ($identita->email !== null && $identita->emailVerified) {
                $utente = User::where('email', $identita->email)->first();
            }

            if ($utente !== null && ! $utente->is_active) {
                throw ValidationException::withMessages([
                    'id_token' => __('Non è stato possibile completare l\'accesso.'),
                ]);
            }

            /*
             * 🚨 **Email NON verificata che pero' esiste gia' in questa
             * palestra: si rifiuta.**
             *
             * E' il caso dell'attacco. Le tre alternative erano tutte peggiori:
             * collegare l'account vorrebbe dire regalarlo a chiunque si faccia
             * emettere un token con l'email di un altro; crearne uno secondo
             * violerebbe `UNIQUE(tenant_id, email)` — e infatti prima di questa
             * riga il server rispondeva **500**; crearne uno con un'email
             * segnaposto lascerebbe due account per la stessa persona, di cui
             * uno inutilizzabile.
             *
             * ⚠️ Il prezzo lo paga un caso legittimo raro — un account Google
             * Workspace senza email confermata — che resta fuori dall'accesso
             * social. Quella persona entra con email e password, che ha gia'.
             *
             * Il messaggio e' quello generico di sempre: dire «questo indirizzo
             * e' gia' iscritto» trasformerebbe l'endpoint in un modo per
             * scoprire chi frequenta quale palestra.
             */
            if ($utente === null && $identita->email !== null
                && User::where('email', $identita->email)->exists()) {
                Log::warning('Accesso social rifiutato: email non verificata su un account esistente', [
                    'provider' => $identita->provider->value,
                    'tenant_id' => $tenant->id,
                ]);

                throw ValidationException::withMessages([
                    'id_token' => __('Non è stato possibile completare l\'accesso.'),
                ]);
            }

            if ($utente === null) {
                $utente = User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $identita->name ?? $this->nomeDaEmail($identita->email),
                    'email' => $identita->email ?? $this->emailSegnaposto($identita),
                    'username' => $this->usernameLibero($identita),
                    // 🚨 Una password casuale che nessuno conosce, **non una
                    // colonna vuota**: cosi' l'account non e' accessibile con
                    // una password vuota da nessun percorso, nemmeno futuro. Chi
                    // vorra' anche la password usera' il recupero.
                    'password' => Str::password(32),
                    'locale' => $tenant->locale,
                ]);

                $utente->assignRole(UserRole::Member->value);
            }

            SocialIdentity::create([
                'user_id' => $utente->getKey(),
                'provider' => $identita->provider->value,
                'provider_user_id' => $identita->providerUserId,
                'email' => $identita->email,
                // Apple lo manda **solo** adesso: se non si salva qui, e' perso.
                'name' => $identita->name,
                'last_login_at' => now(),
            ]);

            return $utente;
        });
    }

    // ───────────────────────── privati ─────────────────────────

    private function nomeDaEmail(?string $email): string
    {
        if ($email === null) {
            return 'Iscritto';
        }

        $locale = Str::before($email, '@');

        return Str::title(str_replace(['.', '_', '-'], ' ', $locale));
    }

    /**
     * Un indirizzo segnaposto quando il fornitore non ne da' nessuno.
     *
     * ⚠️ La colonna e' NOT NULL e unica per palestra. Il dominio `.invalid` e'
     * riservato dallo standard (RFC 2606) proprio a questo: non esiste e non
     * potra' mai esistere, quindi nessuno rischia di scriverci davvero.
     */
    private function emailSegnaposto(VerifiedSocialUser $identita): string
    {
        return $identita->provider->value.'-'.substr(sha1($identita->providerUserId), 0, 16).'@social.invalid';
    }

    /**
     * Un nome utente libero, derivato dall'email.
     *
     * ⚠️ `username` e' unico **su tutta la piattaforma**, non per palestra: due
     * `mario.rossi` in due palestre diverse non possono coesistere, e con la
     * registrazione normale e' l'utente a risolvere il conflitto. Qui non c'e'
     * nessuno a cui chiedere, quindi si aggiunge un suffisso finche' non passa.
     */
    private function usernameLibero(VerifiedSocialUser $identita): string
    {
        $base = Str::of($identita->email ?? $identita->providerUserId)
            ->before('@')
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9._-]+/', '.')
            ->trim('.-_')
            ->limit(24, '')
            ->toString();

        if (strlen($base) < 3) {
            $base = $identita->provider->value.'.'.substr(sha1($identita->providerUserId), 0, 6);
        }

        $candidato = $base;
        $n = 1;

        // `withoutGlobalScopes`: l'unicita' e' di piattaforma, quindi la
        // verifica non deve fermarsi alla palestra corrente — altrimenti si
        // proporrebbe un nome gia' preso altrove e sarebbe il database a
        // rifiutarlo, con un 500 invece di un account.
        while (User::withoutGlobalScopes()->where('username', $candidato)->exists()) {
            $candidato = $base.'.'.(++$n);
        }

        return $candidato;
    }

    private function resolveActiveTenant(string $joinCode): Tenant
    {
        $tenant = Tenant::where('join_code', $joinCode)->first();

        if ($tenant === null || ! $tenant->isActive()) {
            throw ValidationException::withMessages([
                'join_code' => __('Codice palestra non valido.'),
            ]);
        }

        return $tenant;
    }

    /**
     * La stessa forma di `AuthController`: `{token, data, branding}`.
     *
     * 🚨 **Non e' un inviluppo `{data: …}`**, e il client lo sa: srotolarla
     * farebbe sparire il token. Vedi la nota in `ApiClient::_unwrap()` lato app.
     */
    private function tokenResponse(User $user, Tenant $tenant, SocialLoginRequest $request, int $status = 200): JsonResponse
    {
        $token = $user->createToken($request->input('device_name') ?: 'app', ['member']);

        return response()->json([
            'token' => $token->plainTextToken,
            'data' => new UserResource($user->load('profile')),
            'branding' => $tenant->branding(),
        ], $status);
    }
}
