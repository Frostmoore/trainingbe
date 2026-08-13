<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Autenticazione degli iscritti dall'app.
 *
 * Due principi che valgono per tutti i metodi:
 *
 * 1. **Nessuna risposta distingue «non esiste» da «non puoi».** Codice palestra
 *    sbagliato, palestra sospesa, email inesistente e password errata danno
 *    tutti lo stesso errore. Altrimenti l'endpoint diventa un modo per scoprire
 *    quali palestre esistono e chi ci è iscritto.
 * 2. **Il tenant autenticato si legge SEMPRE da `$user->tenant_id`** (ADR-04).
 *    Il `join_code` serve solo a trovare l'utente candidato prima che esista una
 *    sessione: presentare il codice di un'altra palestra non apre nulla, perché
 *    le credenziali non corrisponderanno a nessuno di quella palestra.
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CreaTenantPersonale $creaTenantPersonale,
    ) {}

    /**
     * Iscrizione tramite codice palestra.
     *
     * Crea sempre e solo un `member`: il ruolo non è un dato in ingresso.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $codice = $request->input('join_code');

        // 🆕 F3.2 — nessun codice, nessuna palestra: nasce un tenant personale.
        if ($codice === null) {
            return $this->registraSenzaPalestra($request);
        }

        $tenant = $this->resolveActiveTenant((string) $codice);

        return $this->context->runAs($tenant, function () use ($request, $tenant): JsonResponse {
            // L'unicità è per palestra: qui siamo già nel contesto, quindi il
            // global scope limita la ricerca alla palestra giusta.
            if (User::where('email', $request->string('email'))->exists()) {
                // Stesso messaggio della validazione normale, senza rivelare
                // che l'indirizzo è già iscritto A QUESTA palestra.
                throw ValidationException::withMessages([
                    'email' => __('Non è stato possibile completare la registrazione con questi dati.'),
                ]);
            }

            $user = DB::transaction(function () use ($request, $tenant): User {
                $user = User::create([
                    'tenant_id' => $tenant->id,
                    'name' => $request->string('name')->toString(),
                    'email' => $request->string('email')->toString(),
                    'username' => $request->string('username')->toString(),
                    'password' => $request->string('password')->toString(),
                    'phone' => $request->input('phone'),
                    'locale' => $tenant->locale,
                ]);

                /*
                 * 🚨 **S9.2 — la dichiarazione si registra col suo istante.**
                 *
                 * `RegisterRequest` ha gia' preteso `accepted`, quindi qui non
                 * si controlla di nuovo: si **conserva** il momento in cui e'
                 * stata data. L'art. 7(1) chiede di poterlo dimostrare, e senza
                 * la data non si dimostra niente.
                 *
                 * ⚠️ `forceFill` e non `$fillable`: sono campi che nessuna
                 * richiesta deve poter assegnare in massa. Un `age_confirmed_at`
                 * fillable vorrebbe dire che basta mandarlo in un `PATCH` per
                 * dichiararsi maggiorenne senza mai passare da una casella.
                 */
                $user->forceFill([
                    'age_confirmed_at' => now(),
                    'terms_accepted_at' => now(),
                ])->save();

                $user->assignRole(UserRole::Member->value);

                return $user;
            });

            return $this->tokenResponse($user, $tenant, $request, 201);
        });
    }

    /**
     * Accesso.
     *
     * L'ordine dei controlli è deliberato: si verifica la password **prima** di
     * dire qualsiasi cosa sullo stato dell'account o della palestra, così i
     * messaggi non diventano un oracolo per chi non conosce le credenziali.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $codice = $request->input('join_code');

        // 🆕 F3 — senza codice si entra nel proprio account personale.
        if ($codice === null) {
            return $this->accediSenzaPalestra($request);
        }

        $tenant = $this->resolveActiveTenant((string) $codice);

        return $this->context->runAs($tenant, function () use ($request, $tenant): JsonResponse {
            // Email **o** nome utente. Qui siamo già dentro il contesto della
            // palestra risolta dal `join_code`, quindi il global scope limita
            // la ricerca e non c'è l'ambiguità che ha il login dei pannelli.
            $identificativo = $request->identifier();

            $user = User::where(fn ($q) => $q
                ->where('email', $identificativo)
                ->orWhere('username', $identificativo)
            )->first();

            // `Hash::check` su un hash finto quando l'utente non esiste: senza,
            // la risposta tornerebbe molto più in fretta per le email
            // inesistenti, e i tempi direbbero chi è iscritto e chi no.
            $hash = $user?->password ?? '$2y$12$'.str_repeat('0', 53);

            if (! Hash::check($request->string('password')->toString(), $hash) || $user === null) {
                throw ValidationException::withMessages([
                    'login' => __('Credenziali non valide.'),
                ]);
            }

            if (! $user->is_active) {
                throw ValidationException::withMessages([
                    'login' => __('Questo account non è attivo. Contatta la tua palestra.'),
                ]);
            }

            $user->forceFill(['last_login_at' => now()])->saveQuietly();

            return $this->tokenResponse($user, $tenant, $request);
        });
    }

    /**
     * Revoca il solo token con cui è stata fatta questa richiesta.
     *
     * ⚠️ `currentAccessToken()` **non restituisce sempre un token vero**: con
     * l'autenticazione di sessione (e con `actingAs()` nei test) Sanctum
     * fornisce un `TransientToken`, che non ha un id e non si può cancellare.
     * Darlo per scontato faceva rispondere 500. In quel caso non c'è nulla da
     * revocare, e il logout è comunque riuscito dal punto di vista di chi chiama.
     */
    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();

        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        return response()->json(['message' => __('Sessione chiusa.')]);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user()->load('profile');

        return response()->json([
            'data' => new UserResource($user),
            'branding' => $user->tenant?->branding(),
        ]);
    }

    /**
     * I dispositivi collegati.
     *
     * `is_current` permette all'app di non mostrare all'utente il pulsante per
     * disconnettere sé stesso senza avvisarlo.
     */
    public function devices(Request $request): JsonResponse
    {
        // Vedi la nota in `logout()`: può non esserci un token vero. In quel
        // caso nessun dispositivo risulta «corrente», che è la risposta onesta.
        $token = $request->user()->currentAccessToken();
        $currentId = $token instanceof PersonalAccessToken ? $token->getKey() : null;

        $devices = $request->user()->tokens()
            ->orderByDesc('last_used_at')
            ->get()
            ->map(fn ($t) => [
                'id' => $t->id,
                'name' => $t->name,
                'last_used_at' => $t->last_used_at?->toIso8601String(),
                'created_at' => $t->created_at->toIso8601String(),
                'is_current' => $t->id === $currentId,
            ]);

        return response()->json(['data' => $devices]);
    }

    /**
     * Revoca un dispositivo.
     *
     * Il `delete()` è filtrato sui token dell'utente autenticato: passare l'id
     * del token di un altro non cancella nulla e risponde 404, senza rivelare
     * se quell'id esista.
     */
    public function revokeDevice(Request $request, int $tokenId): JsonResponse
    {
        $deleted = $request->user()->tokens()->whereKey($tokenId)->delete();

        if ($deleted === 0) {
            return response()->json(['message' => __('Dispositivo non trovato.')], 404);
        }

        return response()->json(['message' => __('Dispositivo disconnesso.')]);
    }

    /**
     * Iscrizione con l'invito di un trainer indipendente — F6.2, F6.3.
     *
     * 🚨 **Riusa `RegisterRequest`**, e quindi eredita **tutte** le sue regole:
     * la password confermata, il nome utente nel formato giusto, e soprattutto
     * `age_confirmed` e `terms_accepted` `required|accepted`. È il modo di far
     * valere §2.4 — *ogni porta nuova nasce con lo sbarramento 18+* — senza
     * doverselo ricordare: la porta nuova passa dallo stesso modulo.
     *
     * ⚠️ `join_code` viene **ignorato** anche se presente: chi arriva da un
     * invito entra dal trainer che l'ha invitato, non da una palestra. Un codice
     * accettato qui vorrebbe dire che un invito può essere dirottato altrove.
     */
    public function registerWithInvite(RegisterRequest $request, InvitiDelTrainer $inviti): JsonResponse
    {
        $token = (string) $request->input('token');

        $utente = $inviti->riscatta(
            $token,
            $request->string('name')->toString(),
            $request->string('email')->toString(),
            [
                'username' => $request->string('username')->toString(),
                'password' => $request->string('password')->toString(),
                'phone' => $request->input('phone'),
                'locale' => 'it',
            ],
            $request->boolean('age_confirmed'),
            $request->boolean('terms_accepted'),
        );

        // ⚠️ Dentro il contesto: i ruoli si leggono lì (vedi `registraSenzaPalestra`).
        return $this->context->runAs(
            $utente->tenant,
            fn (): JsonResponse => $this->tokenResponse($utente, $utente->tenant, $request, 201),
        );
    }

    // ──────────────────── 🆕 F3 — senza palestra ────────────────────

    /**
     * Iscrizione **senza** codice palestra: nasce un tenant personale.
     *
     * ── 🚨 L'unicità dell'email, qui, è un'altra cosa ──────────────────────
     *
     * Nel ramo con la palestra l'email è unica **per palestra**, e va bene:
     * `mario@esempio.it` può essere iscritto in due palestre ed essere due
     * persone diverse. Senza palestra quel ragionamento non regge più, perché
     * **ogni account personale ha un tenant tutto suo**: il vincolo
     * `UNIQUE(tenant_id, email)` non impedirebbe **niente**, e la stessa persona
     * potrebbe iscriversi dieci volte creando dieci account.
     *
     * ⚠️ E il guasto sarebbe silenzioso nel modo peggiore: alla seconda
     * iscrizione la persona crederebbe di essere rientrata nel proprio account e
     * lo troverebbe **vuoto**, con dentro il nulla al posto del suo diario.
     *
     * 💡 Quindi qui il controllo è esplicito e **cerca fra i soli tenant
     * personali**: chi ha un account in una palestra può comunque farsene uno
     * personale con lo stesso indirizzo, che è legittimo — sono due cose diverse
     * e il codice palestra dice quale delle due si vuole.
     *
     * 🚨 **Il messaggio d'errore è identico a quello del ramo con la palestra.**
     * Un messaggio diverso — «hai già un account personale» — direbbe a chiunque
     * se un certo indirizzo è iscritto al servizio.
     *
     * ── ⚠️ Il residuo che resta, e perché si accetta ───────────────────────
     *
     * Due registrazioni **nello stesso istante** con lo stesso indirizzo possono
     * passare entrambe: fra il controllo e la scrittura non c'è un lucchetto.
     * La strada per chiuderlo sarebbe una colonna denormalizzata con un indice
     * unico — e andrebbe tenuta allineata a **ogni** cambio di email, per
     * sempre. Un vincolo che può mentire è peggio di un controllo che può
     * correre, e in quel caso il danno è due account con la stessa email, non
     * un accesso indebito. 💡 Il `username` resta unico su tutta la
     * piattaforma, quindi anche nel caso peggiore ciascuno dei due ha un modo
     * non ambiguo per entrare.
     */
    private function registraSenzaPalestra(RegisterRequest $request): JsonResponse
    {
        $email = $request->string('email')->toString();

        if ($this->esisteGiaUnAccountPersonale($email)) {
            throw ValidationException::withMessages([
                'email' => __('Non è stato possibile completare la registrazione con questi dati.'),
            ]);
        }

        $utente = DB::transaction(function () use ($request, $email): User {
            $utente = ($this->creaTenantPersonale)(
                $request->string('name')->toString(),
                $email,
                [
                    'username' => $request->string('username')->toString(),
                    'password' => $request->string('password')->toString(),
                    'phone' => $request->input('phone'),
                    'locale' => 'it',
                ],
            );

            /*
             * 🚨 **Gli stessi due timbri del ramo con la palestra** (S9.2).
             *
             * `RegisterRequest` ha già preteso `accepted`; qui si **conserva** il
             * momento in cui la dichiarazione è stata data, perché l'art. 7(1)
             * chiede di poterlo dimostrare.
             *
             * ⚠️ `forceFill` e non `$fillable`: un `age_confirmed_at` assegnabile
             * in massa vorrebbe dire dichiararsi maggiorenni con un `PATCH`.
             */
            $utente->forceFill([
                'age_confirmed_at' => now(),
                'terms_accepted_at' => now(),
            ])->save();

            return $utente;
        });

        /*
         * ⚠️ **La risposta si costruisce DENTRO il contesto del tenant.**
         *
         * `UserResource` include i ruoli, e spatie gira in modalità teams: fuori
         * dal contesto la relazione `roles` è filtrata su un tenant che non c'è,
         * quindi torna **vuota**. Il ramo con la palestra non ha il problema
         * perché tutto il metodo gira già dentro `runAs()`.
         *
         * 💡 Il difetto era invisibile a occhio — l'utente veniva creato bene, il
         * token era valido, solo `data.roles` arrivava `[]` — e un'app che
         * decide cosa mostrare in base al ruolo si sarebbe comportata come se
         * quella persona non ne avesse nessuno.
         */
        return $this->context->runAs(
            $utente->tenant,
            fn (): JsonResponse => $this->tokenResponse($utente, $utente->tenant, $request, 201),
        );
    }

    /**
     * Accesso **senza** codice palestra: si entra nel proprio account personale.
     *
     * 🚨 **Non era nel piano, ed è stato aggiunto perché senza sarebbe stata una
     * funzione rotta.** `plan_parte_b.md` §5.3 descriveva solo la registrazione;
     * ma `LoginRequest` pretendeva il `join_code`, quindi F3 avrebbe creato
     * persone in grado di iscriversi e **non di rientrare**. Una porta d'ingresso
     * senza serratura di ritorno non è metà funzione: è una funzione che non c'è.
     *
     * ── Come si trova la persona, senza un tenant che la delimiti ──────────
     *
     * `User::findByIdentifier()` non va bene qui: cerca ovunque e, a parità di
     * email fra due palestre, **sceglie**. Qui si vuole il contrario — si guarda
     * fra i **soli account personali**, e se l'indirizzo è ambiguo si rifiuta
     * invece di indovinare.
     *
     * ⚠️ L'ambiguità può nascere solo dal residuo descritto in
     * `registraSenzaPalestra()`. Rifiutare invece di scegliere significa che, in
     * quel caso, la persona non entra con l'email ma entra con il **nome utente**
     * — che è unico su tutta la piattaforma. Sbagliare account sarebbe peggio:
     * troverebbe un diario vuoto e penserebbe di aver perso i propri dati.
     *
     * 💡 **ADR-04 non è violato**, come per il ramo con la palestra: il tenant
     * non arriva dal client: si legge da `$user->tenant_id` dopo aver verificato
     * la password. L'assenza del codice non *indica* un tenant, ne esclude una
     * categoria.
     */
    private function accediSenzaPalestra(LoginRequest $request): JsonResponse
    {
        $identificativo = $request->identifier();

        $candidati = User::withoutGlobalScopes()
            ->whereHas('tenant', fn (Builder $q): Builder => $q->personali())
            ->where(fn (Builder $q): Builder => $q
                ->where('email', $identificativo)
                ->orWhere('username', $identificativo)
            )
            ->get();

        $user = $candidati->count() === 1 ? $candidati->first() : null;

        /*
         * ⚠️ `Hash::check` su un hash finto quando non c'è nessun candidato: la
         * stessa cautela del ramo con la palestra. Senza, la risposta tornerebbe
         * molto più in fretta per gli indirizzi che non esistono, e i **tempi**
         * direbbero chi è iscritto al servizio e chi no.
         */
        $hash = $user?->password ?? '$2y$12$'.str_repeat('0', 53);

        if (! Hash::check($request->string('password')->toString(), $hash) || $user === null) {
            throw ValidationException::withMessages([
                'login' => __('Credenziali non valide.'),
            ]);
        }

        if (! $user->is_active) {
            throw ValidationException::withMessages([
                'login' => __('Questo account non è attivo.'),
            ]);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->context->runAs(
            $user->tenant,
            fn (): JsonResponse => $this->tokenResponse($user, $user->tenant, $request),
        );
    }

    /** C'è già un account **personale** con questo indirizzo? */
    private function esisteGiaUnAccountPersonale(string $email): bool
    {
        return User::withoutGlobalScopes()
            ->where('email', $email)
            ->whereHas('tenant', fn (Builder $q): Builder => $q->personali())
            ->exists();
    }

    // ───────────────────────── privati ─────────────────────────

    /**
     * Trova la palestra dal codice, o fallisce in modo indistinguibile.
     *
     * Codice inesistente e palestra sospesa danno **lo stesso** errore: sono due
     * informazioni che non riguardano chi non è ancora autenticato, e
     * distinguerle renderebbe l'endpoint un modo per enumerare i clienti.
     *
     * Gira fuori dal contesto perché `tenants` è la tabella radice e non è
     * filtrata da nessuno scope.
     *
     * ── 🚨 Il terzo rifiuto: i tenant personali — F1.3 ──────────────────────
     *
     * Dalla Parte B `tenants` non contiene più solo palestre: ogni persona senza
     * palestra ha un tenant suo (`kind = personal`). Quei tenant hanno un
     * `join_code` **perché la colonna è `unique` e NOT NULL**, non perché serva
     * a qualcuno — e un codice che funziona è una porta.
     *
     * ⚠️ Cosa succederebbe senza questa riga, per esteso: chi presentasse il
     * codice di un tenant personale ci si **registrerebbe dentro**, diventando
     * un secondo utente di quello spazio. Da lì `TenantScope` gli mostrerebbe
     * diario, allenamenti e conversazioni di quella persona — non per un difetto
     * dello scoping, ma perché lo scoping avrebbe fatto **esattamente il suo
     * lavoro** su un tenant in cui non doveva entrare.
     *
     * 💡 Il codice è già generato a caso e imprendibile a forza bruta, ma non è
     * su quello che ci si appoggia: un valore casuale è una difesa che dipende
     * da quanto è lungo, questo controllo è una difesa che non dipende da niente.
     * Due difese per la stessa porta, e la seconda regge da sola.
     *
     * 🚨 E il messaggio d'errore resta **identico** agli altri due: dire
     * «questo è un account personale» confermerebbe che quel codice esiste.
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
     * Il payload comune a registrazione e accesso.
     *
     * Include il branding perché l'app possa applicare il tema subito, senza una
     * seconda chiamata.
     */
    private function tokenResponse(User $user, Tenant $tenant, Request $request, int $status = 200): JsonResponse
    {
        $deviceName = $request->input('device_name') ?: 'app';

        // Le abilità sono ancorate al ruolo: un token emesso all'app di un
        // iscritto non deve poter fare più di quanto quell'iscritto possa fare,
        // nemmeno se in futuro gli venisse assegnato un ruolo più alto.
        $token = $user->createToken($deviceName, ['member']);

        return response()->json([
            'token' => $token->plainTextToken,
            'data' => new UserResource($user->load('profile')),
            'branding' => $tenant->branding(),
        ], $status);
    }
}
