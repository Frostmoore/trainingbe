<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\ResolveTenant;
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
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Il pannello della palestra — amministratori e trainer.
 *
 * 🚨 **`ResolveTenant` ed `EnsureTenantActive` stanno in `authMiddleware`, non
 * in `middleware`.** Devono girare **dopo** l'autenticazione: `ResolveTenant`
 * legge il tenant dall'utente autenticato, e prima del login non c'è nessun
 * utente da cui leggerlo. Metterli nella catena generale non darebbe errore —
 * darebbe un contesto vuoto, cioè **nessun filtro**: ogni amministratore
 * vedrebbe gli iscritti di tutte le palestre.
 *
 * `EnsureTenantActive` chiude il pannello quando l'abbonamento scade, anche a
 * sessione già aperta.
 */
class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login(Login::class)
            ->brandName('TrainingCompanion')
            ->colors([
                'primary' => Color::Teal,
            ])
            ->discoverResources(in: app_path('Filament/Admin/Resources'), for: 'App\Filament\Admin\Resources')
            ->discoverPages(in: app_path('Filament/Admin/Pages'), for: 'App\Filament\Admin\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Admin/Widgets'), for: 'App\Filament\Admin\Widgets')
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
                // 🚨 ResolveTenant PRIMA di Authenticate, e non è un dettaglio.
                //
                // Filament chiama `canAccessPanel()` dentro il suo `Authenticate`.
                // Quel metodo controlla i ruoli, che in modalità teams sono
                // limitati alla palestra corrente: col contesto ancora vuoto
                // `hasRole('gym_admin')` non trova niente e l'amministratore
                // viene respinto dal proprio pannello con un 403.
                //
                // Metterlo prima funziona perché `$request->user()` è già
                // risolvibile dalla sessione (StartSession sta nella catena
                // generale): Authenticate serve a **rifiutare** chi non è
                // autenticato, non a renderlo disponibile.
                ResolveTenant::class,
                Authenticate::class,
                EnsureTenantActive::class,
            ]);
    }
}
