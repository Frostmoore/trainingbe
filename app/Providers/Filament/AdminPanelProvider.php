<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\ResolveTenant;
use App\Support\Tenancy\PanelBranding;
use App\Support\Tenancy\TenantContext;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages\Dashboard;
use Filament\Panel;
use Filament\PanelProvider;
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
            // 🚨 Nome e colori si risolvono a **ogni richiesta** (closure), non
            // alla registrazione del pannello. `panel()` gira una volta sola,
            // quando non c'e' ancora nessun utente autenticato: leggere il
            // tenant li' applicherebbe il marchio della prima palestra che apre
            // il pannello a tutte le altre.
            ->brandName(fn (): string => app(TenantContext::class)->get()?->name ?? 'TrainingCompanion')
            ->brandLogo(fn (): ?string => ($logo = app(TenantContext::class)->get()?->logo_path) !== null
                ? url($logo)
                : null)
            ->colors(fn (): array => PanelBranding::colorsFor(app(TenantContext::class)->get()))
            ->discoverResources(in: app_path('Filament/Gym/Resources'), for: 'App\Filament\Gym\Resources')
            ->discoverPages(in: app_path('Filament/Gym/Pages'), for: 'App\Filament\Gym\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Gym/Widgets'), for: 'App\Filament\Gym\Widgets')
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
