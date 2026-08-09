<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Responses\RoleAwareLoginResponse;
use App\Support\Tenancy\TenantContext;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * Singleton, non scoped: deve essere UNA sola istanza per richiesta,
         * altrimenti TenantScope e il middleware ResolveTenant leggerebbero
         * oggetti diversi e il filtro non si applicherebbe.
         *
         * Sotto Octane/queue il container viene ripulito tra una richiesta e
         * l'altra, quindi il contesto non trapela al job successivo; i job che
         * girano nello stesso worker devono comunque usare `runAs()`.
         */
        $this->app->singleton(TenantContext::class);

        /**
         * Dopo il login, ognuno al suo pannello.
         *
         * Il form di accesso e' uno solo: e' il ruolo a decidere dove si
         * atterra, non una scelta chiesta all'utente prima di entrare.
         */
        $this->app->bind(LoginResponse::class, RoleAwareLoginResponse::class);
    }

    public function boot(): void
    {
        //
    }
}
