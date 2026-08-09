<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Ai\AiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\Nutrition\DiaryController;
use App\Http\Controllers\Api\V1\Nutrition\FoodFavoriteController;
use App\Http\Controllers\Api\V1\Training\BodyMetricController;
use App\Http\Controllers\Api\V1\Training\ExerciseController;
use App\Http\Controllers\Api\V1\Training\PhotoController;
use App\Http\Controllers\Api\V1\Training\WorkoutPlanController;
use App\Http\Controllers\Api\V1\Training\WorkoutSessionController;
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

        // ── Allenamento (B4.5) ───────────────────────────────────────────
        Route::get('workout-plans', [WorkoutPlanController::class, 'index']);
        Route::get('workout-plans/{plan}', [WorkoutPlanController::class, 'show'])->whereNumber('plan');

        Route::get('workout-sessions', [WorkoutSessionController::class, 'index']);
        Route::post('workout-sessions', [WorkoutSessionController::class, 'store']);
        Route::get('workout-sessions/{session}', [WorkoutSessionController::class, 'show'])->whereNumber('session');
        Route::post('workout-sessions/{session}/sets', [WorkoutSessionController::class, 'storeSet'])->whereNumber('session');
        Route::post('workout-sessions/{session}/finish', [WorkoutSessionController::class, 'finish'])->whereNumber('session');
        Route::patch('workout-sessions/{session}/kcal', [WorkoutSessionController::class, 'updateKcal'])->whereNumber('session');
        Route::delete('workout-sessions/{session}', [WorkoutSessionController::class, 'destroy'])->whereNumber('session');

        Route::get('exercises', [ExerciseController::class, 'index']);

        Route::get('body-metrics', [BodyMetricController::class, 'index']);
        Route::post('body-metrics', [BodyMetricController::class, 'store']);

        // Le foto NON si servono da un URL pubblico: `file` controlla di chi
        // sono prima di consegnarle. Vedi PhotoController.
        Route::get('photos', [PhotoController::class, 'index']);
        Route::post('photos', [PhotoController::class, 'store']);
        Route::get('photos/{photo}/file', [PhotoController::class, 'file'])->whereNumber('photo');
        Route::delete('photos/{photo}', [PhotoController::class, 'destroy'])->whereNumber('photo');

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
        Route::middleware('throttle:ai')->group(function (): void {
            Route::post('ai/food/text', [AiController::class, 'foodFromText']);
            Route::post('ai/food/photo', [AiController::class, 'foodFromPhoto']);
            Route::get('ai/advice', [AiController::class, 'advice']);
        });

        Route::get('ai/usage', [AiController::class, 'usage']);
    });

});
