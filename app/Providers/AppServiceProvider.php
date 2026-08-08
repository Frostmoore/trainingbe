<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Tenancy\TenantContext;
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
    }

    public function boot(): void
    {
        //
    }
}
