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
use App\Support\Tenancy\TenantContext;
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
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Iscrizione tramite codice palestra.
     *
     * Crea sempre e solo un `member`: il ruolo non è un dato in ingresso.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $tenant = $this->resolveActiveTenant($request->string('join_code')->toString());

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
        $tenant = $this->resolveActiveTenant($request->string('join_code')->toString());

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
