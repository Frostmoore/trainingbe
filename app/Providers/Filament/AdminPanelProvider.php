<?php

declare(strict_types=1);

namespace App\Providers\Filament;

use App\Filament\Auth\Login;
use App\Http\Controllers\DocumentoDellImport;
use App\Http\Middleware\EnsureTenantActive;
use App\Http\Middleware\PretendeLaLiberatoria;
use App\Http\Middleware\ResolveTenant;
use App\Http\Middleware\SpazioDelPannello;
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
use Illuminate\Support\Facades\Route;
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

                // 🆕 E subito dopo, la scelta di chi ha due cappelli — AH.1-quater.8.
                //
                // 🚨 Deve stare DOPO ResolveTenant, che deve poter sovrascrivere,
                // e PRIMA di Authenticate, perché Filament chiama lì
                // `canAccessPanel()` — che adesso guarda i ruoli nello spazio
                // del contesto. Invertirli vorrebbe dire decidere chi entra
                // guardando lo spazio sbagliato.
                SpazioDelPannello::class,

                Authenticate::class,
                EnsureTenantActive::class,

                /*
                 * 🆕 ✍️ **E la liberatoria dell'art. 28** — 20/09/2026.
                 *
                 * 🚨 Ultima della catena, dopo `Authenticate`: serve sapere
                 * **chi** è per sapere cosa deve firmare. ⛔ Finché non firma,
                 * il pannello non si apre — chi vede i dati di altre persone
                 * deve prima accettare per iscritto cosa può farci.
                 */
                PretendeLaLiberatoria::class,
            ])

            /*
             * ══ 🚨 E GLI STESSI MIDDLEWARE ANCHE SULLE RICHIESTE LIVEWIRE ══
             *
             * 📌 Il committente, provando a creare una scheda vuota dal
             * pannello: *«SQLSTATE[23000]: Column 'tenant_id' cannot be null»*.
             *
             * ── ⛔ La causa, che non è nel form ─────────────────────────────
             *
             * Filament applica `middleware()` e `authMiddleware()` alle
             * **pagine**. ⛔ Quando poi si preme «Crea», il salvataggio non è
             * una pagina: è una richiesta di Livewire a `/livewire/update`, e
             * lì Livewire ri-applica **solo** i middleware che qualcuno ha
             * dichiarato *persistenti* (`Livewire::addPersistentMiddleware()`,
             * che Filament chiama con ciò che riceve con `isPersistent: true`).
             *
             * 🚨 Quindi in **ogni** richiesta che salva qualcosa il contesto
             * del tenant era **vuoto**: `BelongsToTenant` non aveva niente da
             * scrivere in `tenant_id`, e il database rifiutava la riga.
             *
             * ── ⚠️ E non era solo la scheda ────────────────────────────────
             *
             * `TenantScope` **a contesto vuoto non filtra**, di proposito (i
             * comandi e i seeder girano senza tenant). ⛔ Con il contesto vuoto
             * su ogni richiesta Livewire, le azioni del pannello giravano
             * **senza il filtro della palestra**: nessuno se n'è accorto perché
             * gli id che arrivano sono quelli della pagina, ma era una porta
             * aperta che nessuno sorvegliava.
             *
             * 💡 **Si dichiarano persistenti solo questi due**, non tutta la
             * catena: `Authenticate` sulle richieste Livewire lo gestisce già
             * Livewire con la firma dello snapshot, e renderlo persistente
             * rischierebbe di respingere i componenti **pubblici** — a partire
             * dal modulo di accesso, che è anche lui un componente Livewire.
             */
            ->persistentMiddleware([
                ResolveTenant::class,
                SpazioDelPannello::class,
            ])

            /*
             * 🆕 📄 **Il documento di un import** — 23/09/2026, F7.2.
             *
             * 🚨 `authenticatedRoutes` e non `routes`: così passa dalla stessa
             * catena di questo pannello — autenticazione, palestra, liberatoria.
             * ⛔ Il documento sta sul disco privato, ed è la scheda di una
             * persona: una rotta fuori da qui sarebbe la porta che F7 ha chiuso.
             */
            ->authenticatedRoutes(function (): void {
                Route::get('/import/{import}/documento', DocumentoDellImport::class)
                    ->whereNumber('import')
                    ->name('documento-import');
            });
    }
}
