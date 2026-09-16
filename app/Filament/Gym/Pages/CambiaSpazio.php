<?php

declare(strict_types=1);

namespace App\Filament\Gym\Pages;

use App\Http\Middleware\SpazioDelPannello;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\ChiSiVede;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * 🆕 **Cambiare spazio** — 3b-AH.1-quater.8, 16/09/2026.
 *
 * ══ 📌 IL CASO ════════════════════════════════════════════════════════════
 *
 * Una persona può avere **due cappelli**: un giro di allievi suo e il ruolo di
 * `trainer` dentro una palestra. ⛔ Il pannello mostrava **sempre e solo** il
 * suo spazio personale, quindi della palestra dove lavora non vedeva niente.
 *
 * 💡 Qui si sceglie in quale dei due si sta lavorando. La scelta vive in
 * sessione e `SpazioDelPannello` la **ricontrolla a ogni richiesta**: il
 * pannello non si fida di questa pagina, e non deve.
 *
 * ══ ⚠️ PERCHE' SI VEDE SOLO A CHI SERVE ═══════════════════════════════════
 *
 * Con **uno** spazio solo la voce non compare: sarebbe una scelta fra una cosa
 * sola, cioè un menù che non fa niente in un pannello dove ogni voce in più è
 * una cosa in più da capire. 🚨 E quasi tutti hanno un solo spazio.
 *
 * ══ 💡 COME SI CAPISCE DOVE SI E' ═════════════════════════════════════════
 *
 * Dal **nome in alto a sinistra**: `brandName` legge il tenant del contesto a
 * ogni richiesta, quindi cambia da solo insieme allo spazio. ⚠️ Non serve un
 * secondo indicatore — ne servirebbe uno che può disallinearsi.
 */
class CambiaSpazio extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?string $navigationLabel = 'Cambia spazio';

    protected static ?string $title = 'Dove stai lavorando';

    protected static ?int $navigationSort = 99;

    protected string $view = 'filament.gym.pages.cambia-spazio';

    /**
     * ⚠️ **Solo a chi ha davvero più di uno spazio.**
     *
     * 🚨 `canAccess()` e non solo `shouldRegisterNavigation()`: nascondere la
     * voce di menù non chiude la pagina, e l'indirizzo si digita.
     */
    public static function canAccess(): bool
    {
        return self::spazi()->count() > 1;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /**
     * Gli spazi di questa persona, con il nome.
     *
     * @return Collection<int, Tenant>
     */
    public static function spazi(): Collection
    {
        $utente = auth()->user();

        if (! $utente instanceof User) {
            return collect();
        }

        $id = app(ChiSiVede::class)->spaziDi($utente);

        if ($id->isEmpty()) {
            return collect();
        }

        /*
         * ⚠️ `withoutGlobalScopes()`: il `TenantScope` su `tenants` filtra sul
         * contesto corrente, e qui servono **tutti** gli spazi — compreso quello
         * in cui non sto in questo momento, che è poi il punto della pagina.
         */
        return Tenant::withoutGlobalScopes()
            ->whereIn('id', $id->all())
            ->orderBy('name')
            ->get();
    }

    /** Lo spazio in cui si sta lavorando adesso. */
    public function spazioCorrente(): ?int
    {
        return app(TenantContext::class)->get()?->getKey();
    }

    /**
     * 🚨 **Scrive in sessione e ricarica.**
     *
     * ⚠️ Il controllo vero non è qui: `SpazioDelPannello` ricontrolla la scelta
     * a **ogni** richiesta contro `ChiSiVede::spaziDi()`. 💡 Quello qui sotto
     * serve a dare un messaggio onesto invece di un cambio che non avviene.
     */
    public function scegli(int $spazio): void
    {
        $utente = auth()->user();

        if (! $utente instanceof User || ! app(ChiSiVede::class)->spaziDi($utente)->contains($spazio)) {
            Notification::make()
                ->title('Non lavori in questo spazio')
                ->danger()
                ->send();

            return;
        }

        session()->put(SpazioDelPannello::CHIAVE, $spazio);

        /*
         * 💡 **Si torna alla pagina iniziale, non si resta qui.** Ogni elenco
         * già disegnato appartiene allo spazio di prima: lasciarli sotto gli
         * occhi mentre l'intestazione dice un altro nome è il modo più rapido
         * di far credere che il cambio non abbia funzionato.
         */
        $this->redirect(filament()->getPanel('admin')->getUrl());
    }
}
