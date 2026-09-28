<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\LoginRequest;
use App\Http\Requests\Api\V1\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use App\Jobs\MandaLaMail;
use App\Mail\BenvenutoMail;
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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;
use Throwable;

/**
 * Autenticazione degli iscritti dall'app.
 *
 * Due principi che valgono per tutti i metodi:
 *
 * 1. **Nessuna risposta distingue «non esiste» da «non puoi».** Email
 *    inesistente e password errata danno lo stesso errore. Altrimenti
 *    l'endpoint diventa un modo per scoprire chi è iscritto.
 * 2. **Il tenant autenticato si legge SEMPRE da `$user->tenant_id`** (ADR-04),
 *    dopo aver verificato la password.
 *
 * ⛔ **Niente più codice palestra** — F11, 24/09/2026. 📌 *«è da rimuovere da
 * tutto, non esiste più»*. Chi si iscrive nasce nel suo spazio personale; in una
 * palestra si entra solo col **link d'invito** (`registerWithInvite`, o
 * `inviti-palestra/{token}/accetta` per chi ha già un account).
 */
class AuthController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly CreaTenantPersonale $creaTenantPersonale,
    ) {}

    /**
     * Iscrizione: nasce **sempre** uno spazio personale.
     *
     * ⛔ Il ramo «con il codice palestra» non c'è più (F11): un `join_code` lo
     * respinge già `RegisterRequest`, con un messaggio che dice dove si entra.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        return $this->registraSenzaPalestra($request);
    }

    /**
     * Accesso, con email o nome utente.
     *
     * ⛔ Il ramo «con il codice palestra» non c'è più (F11): un `join_code`
     * mandato da un'app vecchia **non si legge**, e si entra lo stesso. La
     * ricerca, l'ambiguità e il controllo sulla palestra sospesa sono in
     * `accediSenzaPalestra()`.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        return $this->accediSenzaPalestra($request);
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
        $user = $request->user();

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
     * personale con lo stesso indirizzo, che è legittimo — sono due cose
     * diverse. ⚠️ All'accesso, con l'email ripetuta, si entra col nome utente.
     *
     * 🚨 **Il messaggio d'errore è quello generico di sempre.**
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

        $this->benvenuto($utente, BenvenutoMail::PERSONA);

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
     * Accesso **senza** codice palestra.
     *
     * ══ 🆕 NON PIÙ «SOLO GLI ACCOUNT PERSONALI» — 14/09/2026 ════════════════
     *
     * ⛔ **Questo metodo cercava fra i soli tenant personali, e chiudeva fuori
     * chiunque fosse entrato in una palestra dall'app.**
     *
     * 🚨 Trovato provando l'app sull'emulatore: si entra in Palestra Demo da
     * *Profilo › Entra in una palestra*, che sposta l'account nel tenant della
     * palestra; al rientro successivo `POST /auth/login` rispondeva
     * **«Credenziali non valide»** con la password giusta. Con il `join_code`
     * funzionava — ma da 3b-J.1 **la schermata di accesso il codice non lo
     * chiede più**, quindi per quella persona non esisteva nessuna strada.
     *
     * ⚠️ **L'assunto era vero quando è stato scritto**: allora in palestra si
     * entrava solo iscrivendosi con il codice, quindi chi stava in un tenant
     * palestra il codice ce l'aveva per forza. `Entra in una palestra` ha tolto
     * quel vincolo, e nessuno è tornato qui. 💡 È il difetto di sempre: una
     * condizione giusta che diventa falsa altrove, senza che niente si rompa.
     *
     * 🚨 **La regola sull'ambiguità non cambia**: si guarda fra tutti gli
     * account con quell'identificativo e, se non ce n'è **esattamente uno**, si
     * rifiuta invece di indovinare. Il prezzo è quello già messo in conto qui
     * sotto — con un'email ripetuta si entra con il **nome utente**, che è unico
     * su tutta la piattaforma — e resta molto più piccolo di un'app in cui chi
     * entra in palestra non rientra più.
     *
     * ══ ⚖️ E IL CONTROLLO CHE IL FILTRO PROTEGGEVA RESTA ═════════════════════
     *
     * 🚨 **Il filtro sui tenant personali non era solo un assunto: teneva in
     * piedi una regola.** `RegistrazioneLiberaTest` la scrive per esteso — senza
     * codice non si devono trovare gli utenti delle palestre, *«altrimenti
     * sarebbe un modo per accedere a un account di palestra saltando il codice —
     * e con esso il controllo che la palestra sia attiva»*.
     *
     * 💡 Quel controllo adesso si fa qui, esplicitamente, e la risposta è la
     * stessa dell'accesso con Google (`SocialAuthController`) e del middleware
     * `tenant.active`: **403 con `code: tenant_inactive`**, che porta l'app alla
     * schermata «palestra sospesa».
     *
     * ⚖️ Ed è più onesto di prima: chi è in una palestra sospesa leggeva
     * «Credenziali non valide» e riprovava all'infinito una password giusta.
     *
     * ⚖️ **ADR-04 regge ancora**: il tenant non arriva dal client, si legge da
     * `$user->tenant_id` dopo aver verificato la password.
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

        /*
         * ⛔ **Qui c'era `->whereHas('tenant', fn ($q) => $q->personali())`**, e
         * teneva fuori chi era entrato in una palestra dall'app: vedi la nota
         * in cima al metodo. Chi tocca questa query si ricordi che il filtro
         * per categoria di tenant **non può tornare**: l'unica cosa che
         * delimita la ricerca è l'identificativo, e a decidere è l'unicità.
         */
        $candidati = User::withoutGlobalScopes()
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

        $tenant = $user->tenant;

        /*
         * 🚨 **Il controllo che il vecchio filtro proteggeva.** Vedi la nota in
         * cima al metodo: senza codice si arriva anche agli account di palestra,
         * quindi la palestra va guardata **qui**.
         *
         * ⚠️ 403 con `code: tenant_inactive`, non un errore di credenziali: è la
         * stessa risposta del middleware `tenant.active` e dell'accesso con
         * Google, ed è quella che porta l'app a «palestra sospesa» invece che a
         * riprovare una password che è giusta.
         *
         * 💡 `$tenant === null` non dovrebbe succedere — `users.tenant_id` è
         * obbligatorio — ma se succede il posto giusto dove fermarsi è questo,
         * non due righe più in là dentro `runAs(null)`.
         */
        /*
         * 🆕 🔑 **Una palestra sospesa NON chiude la porta a nessuno** —
         * decisione del committente, 26/09/2026 (`memory/decisioni.md`).
         *
         * 📌 *«non ha senso che mi dica che la palestra è sospesa e non mi
         * faccia accedere, al massimo non mi dà i privilegi dell'abbonamento
         * ma non devo PERDERE L'ACCESSO AL MIO ACCOUNT se la palestra non ha
         * pagato»*. E ancora: *«il mio account è e sarà sempre mio: se il mio
         * abbonamento lo paga qualcun altro lo ringrazio, ma non mi può
         * togliere l'accesso»*.
         *
         * 🚨 **Qui c'era un 403 `tenant_inactive`**, e chiudeva fuori una
         * persona dai **propri** dati per un inadempimento commerciale di un
         * terzo. ⚖️ Indifendibile anche sul piano giuridico: allenamenti e
         * diario sono dati personali dell'iscritto, e accesso e portabilità
         * (artt. 15 e 20 GDPR) non dipendono da chi paga il servizio.
         *
         * 💡 Ora lo stato della palestra non decide più **se** si entra, ma
         * **cosa funziona**: `tenant.active` è rimasto solo sulle rotte che la
         * palestra finanzia (`ai`, `trainer`, `gym`, `nutrition-plans`,
         * `importazioni`). Tutto il resto è della persona e resta suo.
         *
         * ⚠️ Il controllo su `$tenant === null` **resta**, e non è la stessa
         * cosa: quello non è «la palestra non paga», è «la palestra non
         * esiste». Proseguire con un contesto vuoto vorrebbe dire un global
         * scope che non filtra, cioè una persona che vede i dati di tutti.
         */
        /*
         * 🆕 🏠 **SENZA SPAZIO NON SI RIFIUTA: SE NE DÀ UNO** — 63.2, 27/09/2026.
         *
         * ══ ⛔ QUI C'ERA UN 409 `tenant_missing`, E CHIUDEVA FUORI ═══════════
         *
         * 🚨 È **il guasto del 27/09**: il super admin aveva `tenant_id = null` e
         * non entrava nell'app. ⛔ Allora l'ho «risolto» dandogli uno spazio **a
         * mano**, sul database. 💡 Cioè ho sistemato **quella persona**, non il
         * difetto: chiunque si fosse trovato senza spazio — per un dato sporco, una
         * migrazione a metà, un ripristino parziale — sarebbe rimasto fuori.
         *
         * 🔑 L'ha trovato `SiEntraSempreTest`, che rompe una cosa alla volta e
         * pretende che l'accesso regga. ⚠️ **La matrice dei 25 casi non lo vedeva**:
         * nessuno dei venticinque aveva `tenant_id` nullo.
         *
         * ══ ⚖️ PERCHE' NON SI PROSEGUE SEMPLICEMENTE SENZA CONTESTO ═════════
         *
         * ⛔ Perché la vecchia nota aveva ragione su una cosa: con un contesto vuoto
         * il `TenantScope` **non filtra**, e quella persona vedrebbe i dati di
         * tutti. 🚨 Non è un dettaglio: è il difetto peggiore che questo progetto
         * possa avere.
         *
         * 💡 Quindi non si sceglie fra «fuori» e «dentro senza filtro»: si **crea la
         * casa**, che è la decisione F2 del progetto — *ogni persona ha uno spazio
         * suo*. ⚠️ E si usa la stessa macchina del comando `abbonamenti:casa-a-tutti`
         * (`CreaTenantPersonale::perChiEsisteGia()`): ⛔ una seconda strada per
         * creare uno spazio vorrebbe dire due spazi fatti in modo diverso.
         *
         * ⚠️ **Succede una volta sola per persona**: al giro dopo lo spazio c'è.
         */
        if ($tenant === null) {
            $tenant = app(CreaTenantPersonale::class)->dagliUnaCasa($user);

            Log::warning('Accesso: utente senza spazio, gliene è stato creato uno.', [
                'utente' => $user->getKey(),
                'spazio' => $tenant->getKey(),
            ]);

            $user->refresh();
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->context->runAs(
            $tenant,
            fn (): JsonResponse => $this->tokenResponse($user, $tenant, $request),
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
            'data' => new UserResource($user),
            'branding' => $tenant->branding(),
        ], $status);
    }

    /**
     * 🆕 👋 **Il benvenuto** — 22/09/2026.
     *
     * 🚨 Fuori dalla transazione e dentro un `try`: l'account e' gia' creato e
     * la persona sta aspettando la risposta. ⛔ Far fallire una registrazione
     * riuscita perche' la posta e' giu' sarebbe il peggiore dei baratti.
     *
     * ⚠️ Non si manda a chi arriva da un invito: quella persona ha gia'
     * ricevuto la mail dell'invito, e due mail di fila per lo stesso gesto
     * sembrano un errore.
     */
    private function benvenuto(User $utente, string $tipo): void
    {
        try {
            MandaLaMail::dispatch($utente->email, new BenvenutoMail($utente, $tipo));
        } catch (Throwable $e) {
            Log::error('Benvenuto: mail non partita.', [
                'utente' => $utente->getKey(),
                'errore' => $e->getMessage(),
            ]);
        }
    }
}
