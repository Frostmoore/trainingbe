<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Account\ConsentController;
use App\Http\Controllers\Api\V1\Account\RecoveryKeyController;
use App\Http\Controllers\Api\V1\Account\TimezoneController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Ai\AiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\Chat\ChatKeyController;
use App\Http\Controllers\Api\V1\Chat\ConversationController;
use App\Http\Controllers\Api\V1\Chat\DeviceTokenController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\Nutrition\DiaryController;
use App\Http\Controllers\Api\V1\Nutrition\FoodFavoriteController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use App\Http\Controllers\Api\V1\Training\CalendarController;
use App\Http\Controllers\Api\V1\Training\ExerciseController;
use App\Http\Controllers\Api\V1\Training\SeriesController;
use App\Http\Controllers\Api\V1\Training\WorkoutPlanController;
use App\Http\Controllers\Api\V1\Training\WorkoutSessionController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Il prefisso `/api` lo aggiunge bootstrap/app.php: qui si parte da `v1`.
|
| Ordine dei middleware: auth:sanctum -> tenant -> tenant.active
| `tenant` ha bisogno dell'utente autenticato; `tenant.active` del contesto che
| `tenant` imposta. Invertirli non darebbe errore: darebbe **nessun filtro**.
|
*/

Route::prefix('v1')->group(function (): void {

    // ── Pubblica, senza autenticazione ───────────────────────────────────
    // L'app la chiama PRIMA del login per vestirsi dei colori della palestra.
    // Throttle stretto: senza credenziali da indovinare, è il modo più comodo
    // per provare codici palestra a tappeto.
    Route::get('branding/lookup', [BrandingController::class, 'lookup'])
        ->middleware('throttle:branding-lookup');

    Route::prefix('auth')->group(function (): void {

        // ── Pubbliche ────────────────────────────────────────────────────
        // I limiti sono in RateLimitServiceProvider e NON sono per solo IP:
        // gli iscritti si collegano dal wi-fi della palestra, dove decine di
        // persone condividono un indirizzo.
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:auth-register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-login');

        /*
         * Accesso con Google o Apple — C17.
         *
         * ⚠️ Sotto `auth-login` e non `auth-register`, anche quando crea un
         * account: dal punto di vista di chi la usa e' un **accesso**, e la
         * distinzione fra prima volta e volte successive la fa il server, non
         * l'utente. Metterla sul limite della registrazione bloccherebbe al
         * terzo tentativo chi sta solo riprovando ad entrare.
         *
         * ⏸️ Risponde **501** finche' `services.social.*.client_ids` e' vuoto.
         */
        Route::post('social', [SocialAuthController::class, 'store'])
            ->middleware('throttle:auth-login');

        // ── Autenticate, ma raggiungibili anche a palestra SOSPESA ───────
        //
        // Perché senza `tenant.active`: se l'abbonamento della palestra scade,
        // l'iscritto deve comunque potersene andare e mettere in sicurezza il
        // proprio account. Chiudere anche queste significherebbe intrappolare
        // una persona in una sessione che non può né usare né terminare, per
        // una decisione commerciale che non la riguarda.
        Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('devices', [AuthController::class, 'devices']);
            Route::delete('devices/{tokenId}', [AuthController::class, 'revokeDevice'])
                ->whereNumber('tokenId');
        });

        // ── Autenticate e riservate alle palestre ATTIVE ─────────────────
        //
        // `me` è l'endpoint con cui l'app si avvia: rispondendo 403 con
        // `code: tenant_inactive` le dice subito di mostrare la schermata di
        // servizio sospeso, invece di lasciarla proseguire e fallire più avanti
        // in modo confuso.
        Route::middleware(['auth:sanctum', 'tenant', 'tenant.active'])->group(function (): void {
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    /*
    | ── Dominio (B4, B5, B6) ──────────────────────────────────────────────
    |
    | TUTTI con la catena completa ['auth:sanctum','tenant','tenant.active']:
    | sono dati della palestra, e senza `tenant` il global scope non filtra —
    | l'iscritto vedrebbe le schede di tutti.
    |
    | 🚨 Non aggiungere endpoint di dominio senza quella catena.
    */
    Route::middleware(['auth:sanctum', 'tenant', 'tenant.active'])->group(function (): void {

        // ── Profilo (C1) ─────────────────────────────────────────────────
        //
        // Nessun id: si legge e si scrive sempre e solo il proprio profilo.
        // È una PATCH perché l'app salva un campo alla volta mentre la persona
        // compila: una PUT costringerebbe a rimandare tutto ogni volta, e il
        // primo campo dimenticato azzererebbe gli altri.
        // ── La schermata principale (D4) ─────────────────────────────────
        //
        // Una chiamata sola per calorie, allenamenti, peso, sonno e parametri:
        // con sei richieste separate basta che una sia lenta perché la
        // schermata compaia a pezzi. Il consiglio del giorno resta a parte
        // (`/ai/advice`) perché è l'unico che può costare una chiamata AI.
        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::get('profile', [ProfileController::class, 'show']);
        Route::patch('profile', [ProfileController::class, 'update']);

        // ── Eliminazione dell'account (C6) ───────────────────────────────
        //
        // 🚨 Apple la pretende per ogni app con registrazione: dev'essere
        // raggiungibile dall'app, senza scrivere a nessuno. `preview` esiste
        // perché un'azione irreversibile va spiegata dove si compie, e la
        // spiegazione deve venire dal server: scritta solo nell'app, diventa
        // falsa il giorno che il server cambia politica.
        Route::get('account/deletion-preview', [AccountController::class, 'preview']);
        Route::delete('account', [AccountController::class, 'destroy']);

        // G8 — email e password si cambiano dall'app. Entrambe chiedono la
        // password attuale: un telefono lasciato sbloccato non deve bastare a
        // spostare l'account su un indirizzo altrui.
        Route::patch('account/email', [AccountController::class, 'updateEmail']);
        Route::patch('account/password', [AccountController::class, 'updatePassword']);

        // ── Allenamento (B4.5) ───────────────────────────────────────────
        Route::get('workout-plans', [WorkoutPlanController::class, 'index']);

        // 🚨 S7 — i modelli della palestra, per chi allena.
        //
        // L'assegnazione è uscita dal pannello: il trainer manda la scheda
        // **dalla chat, dall'app**, perché è l'unico posto in cui esistono le
        // chiavi per cifrarla. I modelli però li scrive nel pannello, e da lì
        // l'app deve poterli leggere.
        //
        // ⚠️ **Prima di `{plan}`**: `whereNumber` protegge già la rotta con
        // l'id, ma l'ordine rende esplicito che `templates` non è un id.
        Route::get('workout-plans/templates', [WorkoutPlanController::class, 'templates']);
        Route::get('workout-plans/{plan}', [WorkoutPlanController::class, 'show'])->whereNumber('plan');

        // C2 — l'iscritto si scrive le proprie schede (decisione D1). Quelle del
        // trainer restano in sola lettura: la distinzione è in WorkoutPlanPolicy,
        // e la risposta di `index` la riporta nel campo `editable`.
        Route::post('workout-plans', [WorkoutPlanController::class, 'store']);
        Route::put('workout-plans/{plan}', [WorkoutPlanController::class, 'update'])->whereNumber('plan');
        Route::delete('workout-plans/{plan}', [WorkoutPlanController::class, 'destroy'])->whereNumber('plan');

        Route::get('workout-sessions', [WorkoutSessionController::class, 'index']);
        Route::post('workout-sessions', [WorkoutSessionController::class, 'store']);
        Route::get('workout-sessions/{session}', [WorkoutSessionController::class, 'show'])->whereNumber('session');
        Route::post('workout-sessions/{session}/sets', [WorkoutSessionController::class, 'storeSet'])->whereNumber('session');
        Route::post('workout-sessions/{session}/finish', [WorkoutSessionController::class, 'finish'])->whereNumber('session');
        Route::patch('workout-sessions/{session}/kcal', [WorkoutSessionController::class, 'updateKcal'])->whereNumber('session');
        Route::delete('workout-sessions/{session}', [WorkoutSessionController::class, 'destroy'])->whereNumber('session');

        Route::get('exercises', [ExerciseController::class, 'index']);

        // C2.3 — l'esercizio che la libreria non ha. Nasce **della palestra**
        // (D3): il vocabolario dev'essere comune, o lo storico di due iscritti
        // non è confrontabile. 200 se esisteva già, 201 solo se è nato adesso.
        Route::post('exercises', [ExerciseController::class, 'store']);

        /*
        | ⚠️ `body-metrics` non esiste piu' — S5.4.
        |
        | Peso e misure sono dati del corpo e restano sul telefono di chi li
        | produce (decisione D9-bis). L'app li tiene in `ArchivioSalute` e il
        | server non li vede mai.
        */

        // C3 — le serie per i grafici. Un endpoint solo, stessa forma di
        // risposta per entrambe le metriche: l'app ha un parser unico.
        Route::get('series', [SeriesController::class, 'index']);

        // C4 — il calendario. Mese e settimana restituiscono la stessa forma di
        // celle: l'app disegna due layout, non due modelli di dati.
        Route::get('calendar', [CalendarController::class, 'month']);
        Route::get('calendar/week', [CalendarController::class, 'week']);
        Route::get('calendar/{date}', [CalendarController::class, 'day'])
            ->where('date', '\d{4}-\d{2}-\d{2}');

        /*
        | ⚠️ Le rotte `photos` non esistono piu' — S5.4.
        |
        | Le foto dei progressi sono file sul telefono di chi le scatta
        | (decisione D9-bis). Non c'e' piu' niente da caricare, da servire o da
        | autenticare — ed e' sparito con loro anche il difetto G12, che nasceva
        | proprio dal dover autenticare a mano il download di un'immagine.
        */

        // ── Nutrizione (B5.5) ────────────────────────────────────────────
        Route::get('diary', [DiaryController::class, 'index']);
        Route::get('targets', [DiaryController::class, 'targets']);
        Route::get('nutrition-plan', [DiaryController::class, 'plan']);

        Route::post('food-entries', [DiaryController::class, 'store']);
        Route::patch('food-entries/{entry}', [DiaryController::class, 'update'])->whereNumber('entry');
        Route::delete('food-entries/{entry}', [DiaryController::class, 'destroy'])->whereNumber('entry');
        Route::post('food-entries/{entry}/favorite', [FoodFavoriteController::class, 'fromEntry'])->whereNumber('entry');

        Route::get('food-favorites', [FoodFavoriteController::class, 'index']);
        Route::post('food-favorites/meal', [FoodFavoriteController::class, 'storeMeal']);
        Route::post('food-favorites/{favorite}/add', [FoodFavoriteController::class, 'add'])->whereNumber('favorite');
        Route::delete('food-favorites/{favorite}', [FoodFavoriteController::class, 'destroy'])->whereNumber('favorite');

        Route::post('daily-burn', [DiaryController::class, 'storeBurn']);

        // ── AI (B6.6) ────────────────────────────────────────────────────
        //
        // 🚨 Doppio limite, e servono entrambi: `throttle:ai` protegge dal
        // singolo utente che martella, la quota di palestra (dentro il
        // controller) protegge il conto di fine mese. Il primo senza il secondo
        // lascerebbe cento iscritti educati bruciare il budget in un giorno.
        // 🚨 `ai.consent` prima di `throttle`: senza il consenso esplicito
        // (art. 9(2)(a)) niente esce verso Anthropic. È un middleware e non un
        // `if` nel controller perché valga anche sulle rotte AI che non esistono
        // ancora — la prossima partirebbe scoperta, e funzionerebbe benissimo.
        Route::middleware(['ai.consent', 'throttle:ai'])->group(function (): void {
            Route::post('ai/food/text', [AiController::class, 'foodFromText']);
            Route::post('ai/food/photo', [AiController::class, 'foodFromPhoto']);
            Route::get('ai/advice', [AiController::class, 'advice']);
        });

        Route::get('ai/usage', [AiController::class, 'usage']);

        // ── Chat (B8.4) ──────────────────────────────────────────────────
        //
        // Il contratto regge anche il polling: `messages?after=` restituisce
        // solo il nuovo. L'app ricade su polling a 15s quando il socket non si
        // apre, e su rete mobile capita.
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::post('conversations', [ConversationController::class, 'open']);
        Route::get('conversations/contacts', [ConversationController::class, 'contacts']);
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])->whereNumber('conversation');
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'store'])->whereNumber('conversation');
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])->whereNumber('conversation');

        // ── Le chiavi della chat (S6.3) ──────────────────────────────────
        //
        // 🚨 La chiave dell'altra persona si prende **attraverso la
        // conversazione**, non per id utente. Le chiavi sono pubbliche, ma
        // «chi ha una chiave in questo sistema» e' gia' un'informazione: dice
        // chi e' iscritto e chi ha aperto l'app. Una rubrica aperta la
        // regalerebbe.
        Route::put('chat-key', [ChatKeyController::class, 'store']);
        Route::get('conversations/{conversation}/key', [ChatKeyController::class, 'show'])->whereNumber('conversation');

        // ── Il pacchetto incartato della chiave maestra (S6.2) ────────────
        //
        // 🚨 Il server conserva byte che non sa leggere. La password di
        // recupero **non passa mai di qui**: la derivazione avviene sul
        // telefono, e se la password toccasse il server anche per un istante
        // l'intero schema non servirebbe a niente.
        Route::get('account/recovery-key', [RecoveryKeyController::class, 'show']);
        Route::put('account/recovery-key', [RecoveryKeyController::class, 'store']);

        // ── I consensi facoltativi (S9.1) ────────────────────────────────
        //
        // 🚨 Si danno **dopo** l'iscrizione, uno per uno. Chiederli al momento
        // della registrazione li trasformerebbe in condizioni per usare il
        // servizio, e un consenso che serve per iscriversi non è «liberamente
        // dato» (art. 7(4)) — cioè non è consenso.
        //
        // 🚨 Revocare costa esattamente quanto concedere: stessa chiamata,
        // `false` invece di `true` (art. 7(3)).
        Route::get('account/consents', [ConsentController::class, 'show']);
        Route::patch('account/consents', [ConsentController::class, 'update']);

        // ── Il fuso orario della persona (A3) ─────────────────────────────
        //
        // 🚨 Il server non può indovinarlo: l'IP dice dov'è la rete, l'offset
        // non distingue Roma d'estate da Helsinki d'inverno. Lo sa solo il
        // telefono, e lo manda a ogni avvio.
        //
        // `PUT` perché è idempotente: è lo stato del dispositivo, non una
        // modifica a qualcosa.
        Route::put('account/timezone', [TimezoneController::class, 'update']);

        Route::post('device-tokens', [DeviceTokenController::class, 'store']);
        Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);

        /*
        | ── Sonno e parametri vitali: NON CI SONO PIU' ────────────────────
        |
        | 🚨 Qui vivevano `GET health/sleep` e `POST health/ingest-token`.
        | Cancellate in S1.1 di `plan_security_and_retention.md`.
        |
        | Sonno, HRV e battito **restano sul telefono di chi li produce**
        | (decisione D9): il server non li riceve, non li conserva e non li
        | manda a nessun modello. Non e' una funzione persa, e' una funzione
        | traslocata nell'app — `lib/src/features/health/`.
        |
        | ⚠️ Chi volesse riaprirle deve prima leggere §C11 e §C12 di
        | `todo-2026-08-11.md`: il motivo per cui non ci sono non e' tecnico.
        */

        /*
        | Autorizzazione dei canali privati per l'APP (B8.2).
        |
        | 🚨 Serve una rotta a parte. Quella di serie — `POST /broadcasting/auth`
        | — sta nel gruppo `web`, cioe' sessione con cookie e protezione CSRF:
        | un client che si autentica con un **token bearer** non la supera, e la
        | chat via socket non si aprirebbe mai. Non darebbe un errore chiaro,
        | darebbe un 419 che sembra un problema di rete.
        |
        | Il gruppo qui e' gia' `auth:sanctum`, quindi l'utente c'e'; le callback
        | in `routes/channels.php` sono le stesse, e restano l'unico controllo
        | vero su chi ascolta cosa.
        */
        Route::post('broadcasting/auth', fn (Request $request) => Broadcast::auth($request));
    });

    /*
    | ── L'ingest dall'orologio non esiste piu' ────────────────────────────
    |
    | Qui c'era `POST health/ingest`, **l'unico endpoint scrivente fuori da
    | `auth:sanctum`**: si autenticava con `users.health_ingest_token` perche'
    | chi mandava i dati era un'automazione senza sessione.
    |
    | 🚨 Cancellato in S1.1 insieme alla colonna del token. Ed e' un guadagno
    | doppio: oltre a togliere i dati sanitari dal server, **toglie l'unica
    | superficie scrivente non autenticata da Sanctum** che il progetto avesse.
    */

});
