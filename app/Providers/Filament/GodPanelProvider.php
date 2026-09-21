<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Http\Controllers\FattureDaScaricare;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Il pannello della piattaforma — solo per il committente.
 *
 * Gira **senza contesto di palestra**: il super admin ha `tenant_id = null` e
 * il global scope non filtra a contesto vuoto, quindi vede tutte le palestre.
 * È il motivo per cui qui NON c'è il middleware `tenant`, che sarebbe
 * controproducente.
 *
 * Chi può entrare è deciso da `User::canAccessPanel()`, che per l'id `god`
 * richiede `isSuperAdmin()` — cioè la colonna, non un ruolo spatie (i ruoli
 * sono limitati alla palestra e qui non ce n'è nessuna).
 *
 * Colore diverso dall'altro pannello **di proposito**: chi amministra entrambi
 * deve capire a colpo d'occhio dove sta agendo, prima di cancellare qualcosa.
 */
class GodPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('god')
            ->path('god')
            ->login(Login::class)
            ->brandName('TrainingCompanion · Piattaforma')
            ->colors([
                'primary' => Color::Rose,
            ])
            ->discoverResources(in: app_path('Filament/God/Resources'), for: 'App\Filament\God\Resources')
            ->discoverPages(in: app_path('Filament/God/Pages'), for: 'App\Filament\God\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/God/Widgets'), for: 'App\Filament\God\Widgets')
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])

            /*
             * 🆕 🧾 **Le fatture da scaricare** — 23/09/2026, F8.
             *
             * ⛔ L'XML **solo qui** (D2): è il documento fiscale, e il cliente
             * non deve poterlo prendere da solo. `authenticatedRoutes` = la
             * catena di questo pannello, cioè solo il super admin.
             */
            ->authenticatedRoutes(function (): void {
                Route::get('/fatture/{fattura}/pdf', [FattureDaScaricare::class, 'pdfPerLaPiattaforma'])
                    ->whereNumber('fattura')
                    ->name('fattura-pdf');

                Route::get('/fatture/{fattura}/xml', [FattureDaScaricare::class, 'xmlPerLaPiattaforma'])
                    ->whereNumber('fattura')
                    ->name('fattura-xml');
            });
    }
}
