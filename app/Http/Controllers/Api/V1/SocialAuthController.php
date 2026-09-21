<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\SocialLoginRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\SocialIdentity;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Auth\Social\Exceptions\InvalidSocialTokenException;
use App\Services\Auth\Social\SocialTokenVerifier;
use App\Services\Auth\Social\VerifiedSocialUser;
use App\Services\Tenancy\CreaTenantPersonale;
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
 * 3. 🚨 **La palestra non arriva mai dal client.** Il tenant si legge da
 *    `user->tenant_id` (ADR-04); un account nuovo nasce nel suo spazio
 *    personale. ⛔ Da F11 (24/09/2026) non esiste più il codice palestra: in
 *    una palestra si entra solo col link d'invito.
 */
class SocialAuthController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly SocialTokenVerifier $verifier,
        private readonly CreaTenantPersonale $creaTenantPersonale,
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
     * Il caso normale: l'identita' e' gia' collegata a qualcuno. La palestra
     * della persona e' quella scritta sul suo utente.
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
     * Identita' mai vista: o si collega all'account che ha gia' quell'email, o
     * nasce uno spazio personale. Vedi `primoAccessoSenzaPalestra()`.
     */
    private function primoAccesso(VerifiedSocialUser $identita, SocialLoginRequest $request): JsonResponse
    {
        $this->pretendiIConsensi($request);

        return $this->primoAccessoSenzaPalestra($identita, $request);
    }

    // ──────────────────── 🆕 F3 — senza palestra ────────────────────

    /**
     * 🚨 **Lo sbarramento 18+ a ogni primo accesso.**
     *
     * `SocialLoginRequest` non puo' farlo da solo: il modulo non puo' sapere se l'identita' e' nota: lo si scopre solo
     * dopo aver verificato il token. Quindi il controllo sta qui, dove
     * l'informazione c'e'.
     *
     * 💡 Il codice `consents_required` e' riconoscibile dall'app, che deve poter
     * mostrare le due caselle invece di un errore generico su una schermata dove
     * non c'e' niente da correggere.
     */
    private function pretendiIConsensi(SocialLoginRequest $request): void
    {
        $accettato = static fn (mixed $v): bool => in_array($v, [true, 1, '1', 'true', 'on', 'yes'], true);

        if ($accettato($request->input('age_confirmed')) && $accettato($request->input('terms_accepted'))) {
            return;
        }

        abort(response()->json([
            'message' => __('Per iscriverti devi dichiarare di essere maggiorenne e accettare le condizioni.'),
            'code' => 'consents_required',
            'errors' => [
                'age_confirmed' => [__('Devi dichiarare di essere maggiorenne.')],
                'terms_accepted' => [__('Devi accettare le condizioni d\'uso.')],
            ],
        ], 422));
    }

    /**
     * Primo accesso con un fornitore esterno.
     *
     * ══ 🆕 F11 — 24/09/2026: SI COLLEGA ALL'ACCOUNT CHE C'E' GIA' ═══════════
     *
     * ⛔ Fino a F11 il collegamento per email avveniva solo **dentro una
     * palestra**, scelta col codice (dove `UNIQUE(tenant_id, email)` garantiva
     * una persona sola); senza codice chi aveva gia' un account veniva respinto.
     * Tolto il codice, lasciarlo cosi' avrebbe voluto dire che l'iscritto di una
     * palestra che tocca «Accedi con Google» si ritrova **un secondo account,
     * vuoto**, e crede di aver perso i suoi dati.
     *
     * 💡 Adesso vale la stessa regola dell'accesso con la password
     * (`AuthController::accediSenzaPalestra()`): si guarda fra **tutti** gli
     * account con quell'indirizzo, e
     *
     * | Quanti | Cosa succede |
     * |---|---|
     * | 0 | nasce uno spazio personale (201) |
     * | 1 | l'identita' si collega a quell'account (200) — ⚠️ solo con email **verificata**, regola 2 |
     * | 2 o piu' | si rifiuta, col messaggio generico: indovinare quale sia «il suo» vorrebbe dire sbagliare account |
     */
    private function primoAccessoSenzaPalestra(VerifiedSocialUser $identita, SocialLoginRequest $request): JsonResponse
    {
        $email = $identita->email;

        if ($email === null || ! $identita->emailVerified) {
            /*
             * ⚠️ Senza un'email verificata non si crea un account personale.
             * Nel ramo con la palestra un'email finta e' tollerabile — c'e' una
             * palestra che conosce quella persona e un amministratore che puo'
             * sistemare. Qui non c'e' nessuno: resterebbe un account senza un
             * indirizzo vero, quindi senza recupero della password e senza
             * possibilita' di dimostrare che e' suo.
             */
            throw ValidationException::withMessages([
                'id_token' => __('Non è stato possibile completare l\'accesso.'),
            ]);
        }

        $candidati = User::withoutGlobalScopes()->where('email', $email)->get();

        if ($candidati->count() > 1) {
            Log::warning('Accesso social rifiutato: piu account con la stessa email', [
                'provider' => $identita->provider->value,
            ]);

            throw ValidationException::withMessages([
                'id_token' => __('Non è stato possibile completare l\'accesso.'),
            ]);
        }

        if ($candidati->count() === 1) {
            return $this->collegaAllAccountEsistente($candidati->first(), $identita, $request);
        }

        $utente = DB::transaction(function () use ($identita, $email): User {
            $utente = ($this->creaTenantPersonale)(
                $identita->name ?? $this->nomeDaEmail($email),
                $email,
                [
                    'username' => $this->usernameLibero($identita),
                    // 🚨 Una password casuale che nessuno conosce, **non una
                    // colonna vuota**: cosi' l'account non e' accessibile con
                    // una password vuota da nessun percorso. Chi vorra' anche
                    // la password usera' il recupero.
                    'password' => Str::password(32),
                    'password_is_set' => false,
                    'locale' => 'it',
                ],
            );

            $utente->forceFill([
                'age_confirmed_at' => now(),
                'terms_accepted_at' => now(),
            ])->save();

            SocialIdentity::create([
                'user_id' => $utente->getKey(),
                'provider' => $identita->provider->value,
                'provider_user_id' => $identita->providerUserId,
                'email' => $identita->email,
                'name' => $identita->name,
                'last_login_at' => now(),
            ]);

            return $utente;
        });

        return $this->context->runAs(
            $utente->tenant,
            fn (): JsonResponse => $this->tokenResponse($utente, $utente->tenant, $request, 201),
        );
    }

    /**
     * 🆕 F11 — l'identita' si collega all'unico account con quell'email
     * **verificata** (il chiamante l'ha gia' controllato).
     *
     * ⚠️ Gli stessi due controlli di `accediConIdentitaNota()`: un account
     * disattivato non entra, e una palestra sospesa risponde 403
     * `tenant_inactive` — cosi' l'app porta a «palestra sospesa».
     */
    private function collegaAllAccountEsistente(User $utente, VerifiedSocialUser $identita, SocialLoginRequest $request): JsonResponse
    {
        if (! $utente->is_active) {
            throw ValidationException::withMessages([
                'id_token' => __('Non è stato possibile completare l\'accesso.'),
            ]);
        }

        $tenant = Tenant::find($utente->tenant_id);

        if ($tenant === null || ! $tenant->isActive()) {
            return response()->json([
                'message' => __('La tua palestra è sospesa. Contattala per riattivare l\'accesso.'),
                'code' => 'tenant_inactive',
            ], 403);
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

        return $this->context->runAs($tenant, function () use ($utente, $tenant, $request): JsonResponse {
            $utente->forceFill(['last_login_at' => now()])->saveQuietly();

            return $this->tokenResponse($utente, $tenant, $request);
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
            'data' => new UserResource($user),
            'branding' => $tenant->branding(),
        ], $status);
    }
}
