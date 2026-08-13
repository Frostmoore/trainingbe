<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

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
use App\Services\Tenancy\CreaTenantPersonale;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
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
     * Con il `join_code` si entra in quella palestra. **Senza, da F3, nasce un
     * tenant personale**: e' la stessa scelta della registrazione con email, e
     * deve valere qui perche' questa e' l'**altra** porta d'ingresso. Se ne
     * valesse solo in una, il primo utente gratuito che arriva da Google
     * troverebbe una porta chiusa senza motivo.
     */
    private function primoAccesso(VerifiedSocialUser $identita, SocialLoginRequest $request): JsonResponse
    {
        $this->pretendiIConsensi($request);

        $joinCode = $request->string('join_code')->toString();

        if ($joinCode === '') {
            return $this->primoAccessoSenzaPalestra($identita, $request);
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
                    // 🚨 Falso: questa password non la conosce nessuno, ed e'
                    // apposta. L'app non deve proporgli di cambiarla.
                    'password_is_set' => false,
                    'locale' => $tenant->locale,
                ]);

                /*
                 * 🚨 **Lo sbarramento vale anche di qui — S9.2.**
                 *
                 * ⚠️ **È la porta che si dimentica.** Chi entra con Google non
                 * passa da `RegisterRequest`, quindi mettere la casella solo sul
                 * modulo di registrazione lascerebbe scoperta metà delle
                 * iscrizioni — e nella Parte B, con i *free user*, sarà la
                 * maggioranza.
                 *
                 * La dichiarazione la pretende `SocialLoginRequest` al primo
                 * accesso; qui si conserva il momento in cui è stata data.
                 */
                $utente->forceFill([
                    'age_confirmed_at' => now(),
                    'terms_accepted_at' => now(),
                ])->save();

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

    // ──────────────────── 🆕 F3 — senza palestra ────────────────────

    /**
     * 🚨 **Lo sbarramento 18+ a ogni primo accesso, con palestra o senza.**
     *
     * `SocialLoginRequest` non puo' farlo da solo: la sua regola
     * `exclude_without:join_code` si reggeva sull'equivalenza *«niente codice ⇒
     * non e' un primo accesso»*, che **da F3 non vale piu'** — senza codice
     * nasce un utente gratuito, che e' un'iscrizione a tutti gli effetti.
     *
     * ⚠️ E il modulo non puo' sapere se l'identita' e' nota: lo si scopre solo
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
     * Primo accesso con un fornitore esterno, **senza codice palestra**: nasce
     * un tenant personale.
     *
     * ⚠️ **Qui NON si collega mai a un account esistente per email**, al
     * contrario di `collegaOCrea()`. Il motivo e' che li' il collegamento
     * avviene dentro una palestra, dove `UNIQUE(tenant_id, email)` garantisce
     * che quell'indirizzo sia **una persona sola**. Fuori da una palestra non
     * c'e' niente di equivalente, e collegare per email vorrebbe dire decidere
     * quale dei possibili account personali sia «il suo».
     *
     * 🚨 Chi ha gia' un account personale con quell'indirizzo viene quindi
     * **respinto**, con il messaggio generico di sempre. Entra con email e
     * password — che ha — e da li' potra' collegare l'identita' quando esistera'
     * un modo per farlo.
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

        $giaPresente = User::withoutGlobalScopes()
            ->where('email', $email)
            ->whereHas('tenant', fn (EloquentBuilder $q): EloquentBuilder => $q->personali())
            ->exists();

        if ($giaPresente) {
            Log::warning('Accesso social senza palestra rifiutato: esiste gia un account personale', [
                'provider' => $identita->provider->value,
            ]);

            throw ValidationException::withMessages([
                'id_token' => __('Non è stato possibile completare l\'accesso.'),
            ]);
        }

        $utente = DB::transaction(function () use ($identita, $email): User {
            $utente = ($this->creaTenantPersonale)(
                $identita->name ?? $this->nomeDaEmail($email),
                $email,
                [
                    'username' => $this->usernameLibero($identita),
                    // 🚨 Come in `collegaOCrea()`: una password casuale che
                    // nessuno conosce, non una colonna vuota.
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

    /**
     * ⚠️ **Gemello di `AuthController::resolveActiveTenant()`, e va tenuto tale.**
     *
     * 🚨 `$tenant->ePersonale()` è il rifiuto aggiunto in F1.3: il `join_code` di
     * un tenant personale esiste solo perché la colonna è `unique` e NOT NULL, e
     * chi lo presentasse si registrerebbe **dentro** lo spazio di un'altra
     * persona. Il perché per esteso sta in `AuthController`.
     *
     * 💡 Perché questa porta è **più** esposta dell'altra: qui non c'è una
     * password da indovinare. Chi presenta un token Google valido è già
     * autenticato **come sé stesso**, e il `join_code` è l'unica cosa che decide
     * *dove* finisce. Sono le due sole strade che creano un utente, e il giorno
     * in cui una delle due si dimenticasse questo controllo l'altra non
     * proteggerebbe niente — per questo il test lo verifica su entrambe.
     */
    private function resolveActiveTenant(string $joinCode): Tenant
    {
        $tenant = Tenant::where('join_code', $joinCode)->first();

        if ($tenant === null || $tenant->ePersonale() || ! $tenant->isActive()) {
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
