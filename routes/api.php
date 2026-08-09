<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\AuthController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Il prefisso `/api` lo aggiunge bootstrap/app.php: qui si parte da `v1`.
|
| Le rotte protette useranno anche `tenant` e `tenant.active` (B1.6), che oggi
| non esistono ancora: `auth:sanctum` da solo autentica ma NON imposta il
| contesto del tenant, quindi il global scope non filtra. Va bene finché gli
| unici endpoint sono questi, che leggono solo l'utente autenticato — ma nessun
| endpoint che interroghi dati di dominio va aggiunto qui prima di B1.6.
|
*/

Route::prefix('v1')->group(function (): void {

    Route::prefix('auth')->group(function (): void {
        // Pubbliche. I limiti sono definiti in RateLimitServiceProvider e NON
        // sono per solo IP: gli iscritti si collegano dal wi-fi della palestra,
        // quindi un limite per IP farebbe bloccare tutti da chi sbaglia la
        // password. Vedi il commento di quel provider.
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:auth-register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-login');

        // Protette.
        Route::middleware('auth:sanctum')->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('me', [AuthController::class, 'me']);
            Route::get('devices', [AuthController::class, 'devices']);
            Route::delete('devices/{tokenId}', [AuthController::class, 'revokeDevice'])
                ->whereNumber('tokenId');
        });
    });

});
