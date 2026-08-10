<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\ResponseFactory;
use App\Http\Responses\RoleAwareLoginResponse;
use App\Services\Ai\AiManager;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use App\Support\Impersonation\Impersonator;
use App\Support\Tenancy\TenantContext;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
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
         * La verifica dei token social — C17.
         *
         * Legato al contratto e non alla classe concreta perche' i test lo
         * sostituiscono con un doppio: un test che deve produrre un token
         * firmato da Google per provare la **logica di collegamento** starebbe
         * provando la crittografia invece del proprio codice. La verifica vera
         * ha un test suo, che parte da una chiave generata al volo.
         */
        $this->app->bind(
            \App\Services\Auth\Social\SocialTokenVerifier::class,
            \App\Services\Auth\Social\JwksSocialTokenVerifier::class,
        );

        /**
         * 🚨 Singleton, e non e' un'ottimizzazione.
         *
         * `AiManager` tiene il registro dei fornitori (`extend()`) e le istanze
         * gia' costruite. Con un binding normale ogni iniezione ne creerebbe uno
         * nuovo: un fornitore registrato in un punto — un test, un comando, un
         * driver alternativo per una palestra — non esisterebbe per chi lo
         * risolve altrove, **senza nessun errore**. Se ne accorgerebbe solo chi
         * nota che una sostituzione non ha avuto effetto.
         *
         * E' anche cio' che evita di ricostruire il client HTTP del fornitore a
         * ogni chiamata.
         */
        $this->app->singleton(AiManager::class);

        /**
         * I numeri con la virgola non devono diventare interi quando sono tondi.
         *
         * Il perche' — un crash in Dart che compare solo sui valori tondi — sta
         * per esteso in `App\Http\ResponseFactory`. Va sostituita la fabbrica e
         * non aggiunto un middleware: dopo la codifica l'informazione e' gia'
         * persa.
         */
        $this->app->singleton(ResponseFactoryContract::class, ResponseFactory::class);

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
        $this->registraBannerImpersonazione();
    }

    /**
     * La barra rossa in cima ai pannelli quando la sessione e' impersonata.
     *
     * Registrata qui e non in un singolo pannello perche' deve comparire in
     * **tutti**: si impersona dal pannello di piattaforma ma si finisce in
     * quello della palestra, ed e' proprio li' che serve ricordarselo.
     *
     * Il render hook e' `PANELS_TOPBAR_BEFORE` e non `BODY_START`: quest'ultimo
     * finisce anche nelle pagine di login, dove non c'e' nessuna sessione da
     * segnalare.
     */
    private function registraBannerImpersonazione(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_BEFORE,
            function (): string {
                $impersonator = app(Impersonator::class);

                if (! $impersonator->isImpersonating()) {
                    return '';
                }

                $target = auth()->user();

                return view('impersonation.banner', [
                    'impersonating' => true,
                    'target' => $target?->name ?? '—',
                    'tenant' => $target?->tenant?->name,
                    'original' => $impersonator->originalUser()?->name ?? '—',
                ])->render();
            },
        );
    }
}
