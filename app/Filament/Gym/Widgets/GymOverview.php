<?php

declare(strict_types=1);

namespace App\Filament\Gym\Widgets;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * I numeri della palestra in cima al pannello.
 *
 * 🚨 **Sono scelti per rispondere a una domanda sola: chi sto per perdere.**
 * Il conteggio degli iscritti totali e' vanita'; «quanti non si allenano da
 * trenta giorni» e «quanti sono senza scheda» sono le due cose su cui una
 * palestra puo' agire domani mattina. Per questo la prima ha il colore
 * dell'allarme quando non e' zero.
 */
class GymOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $iscritti = $this->idIscritti();
        $totale = count($iscritti);

        /*
         * ⛔ **«Non si allenano da 30 giorni» e «Allenamenti questa settimana»
         * non ci sono piu'** — FASE 11.6, 21/08/2026.
         *
         * 📌 *«Niente, nemmeno se si allena»* (16/08). Gli allenamenti stanno
         * sul telefono di chi li fa: non e' che non li mostriamo, il server non
         * ce li ha piu'.
         *
         * ⚠️ **E' una perdita vera, dichiarata**: erano i due numeri con cui una
         * palestra capiva chi stava per abbandonare. 🚨 Lasciarli avrebbe voluto
         * dire mostrare **zero a tutti**, che e' peggio di non mostrarli: una
         * palestra avrebbe creduto che nessuno si allena piu'.
         *
         * 💡 Resta «Senza scheda», che nasce dalle schede — quelle restano.
         */

        /*
         * ══ ⛔ VIA «SENZA SCHEDA PUBBLICATA» — 22/09/2026, F5.4 ════════════
         *
         * 🚨 **Contava una cosa che non esiste più.** Cercava gli iscritti
         * senza una scheda in stato `published` *legata a loro*: ma dopo R2 le
         * schede non hanno destinatari — vivono sui telefoni, e il server tiene
         * solo i **modelli** della palestra.
         *
         * ⛔ Quindi quel numero era **il totale degli iscritti**, sempre, per
         * tutti: un allarme arancione permanente sotto cui non c'era niente da
         * fare. ⚠️ È lo stesso errore che nel 2026-08 aveva già portato a
         * togliere «non si allenano da 30 giorni»: un cruscotto che mostra zero
         * (o tutti) a tutti è peggio di un cruscotto che non lo mostra.
         *
         * 💡 I numeri che lo sostituiscono — posti, costo, guadagno — stanno in
         * `IPostiDellaPalestra` e `IlGiroDiSoldiDellaPalestra` (F5.1, F5.2).
         */
        return [
            Stat::make('Iscritti attivi', (string) $totale)
                ->description('Persone con un account funzionante')
                ->icon('heroicon-m-user-group')
                ->color('primary'),

        ];
    }

    /** @return list<int> */
    private function idIscritti(): array
    {
        $tenantId = app(TenantContext::class)->id();

        if ($tenantId === null) {
            return [];
        }

        $query = User::query()
            ->where('is_active', true)
            ->whereIn('id', DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', UserRole::Member->value)
                ->where('model_has_roles.tenant_id', $tenantId)
                ->select('model_has_roles.model_id'));

        // Il trainer vede i numeri dei **suoi** iscritti: un cruscotto che gli
        // mostra i totali della palestra gli darebbe indirettamente l'elenco che
        // lo scoping di B3.5 gli nega.
        $utente = auth()->user();

        if ($utente instanceof User && $utente->isTrainer() && ! $utente->isGymAdmin()) {
            $query->whereIn('id', $utente->assignedMembers()->pluck('users.id')->all());
        }

        return $query->pluck('id')->all();
    }
}
