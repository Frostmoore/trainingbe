<?php

declare(strict_types=1);

namespace App\Filament\God\Widgets;

use App\Enums\TenantStatus;
use App\Models\AiUsageLog;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutSession;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\DB;

/**
 * I numeri della piattaforma — B2.5.
 *
 * 🚨 **Il costo AI del mese e' il numero che conta piu' di tutti**, perche' e'
 * l'unica voce che cresce da sola con l'uso e puo' mangiarsi il margine senza
 * che nessuno se ne accorga fino alla fattura. Per questo e' in cifre e non in
 * token: i token non dicono niente a nessuno.
 *
 * 🚨 **Ogni conteggio toglie `TenantScope` esplicitamente.** Il pannello gira
 * senza contesto e lo scope non filtrerebbe comunque, ma dipendere dall'assenza
 * di qualcosa e' fragile: se un giorno qualcuno aggiungesse `ResolveTenant` a
 * questo pannello, il cruscotto mostrerebbe i numeri di **una** palestra come se
 * fossero quelli di tutte — senza nessun errore.
 */
class PlatformOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    protected function getStats(): array
    {
        $attive = Tenant::query()->where('status', TenantStatus::Active->value)->count();
        $totali = Tenant::query()->count();

        $utenti = User::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->whereNotNull('tenant_id')
            ->where('is_active', true)
            ->count();

        $allenamenti = WorkoutSession::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('started_at', '>=', now()->startOfMonth())
            ->count();

        $token = (int) AiUsageLog::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->inMonth()
            ->sum(DB::raw('input_tokens + output_tokens'));

        $costo = (int) AiUsageLog::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->inMonth()
            ->sum('cost_millicents');

        return [
            Stat::make('Palestre attive', (string) $attive)
                ->description($totali > $attive ? ($totali - $attive).' non attive' : 'Tutte attive')
                ->icon('heroicon-m-building-storefront')
                ->color('primary'),

            Stat::make('Utenti attivi', (string) $utenti)
                ->description('Su tutte le palestre')
                ->icon('heroicon-m-user-group')
                ->color('info'),

            Stat::make('Costo AI del mese', '$'.number_format(AiUsageLog::millicentsToCurrency($costo), 2, ',', '.'))
                ->description(number_format($token, 0, ',', '.').' token')
                ->icon('heroicon-m-banknotes')
                ->color($costo > 0 ? 'warning' : 'gray'),

            Stat::make('Allenamenti del mese', number_format($allenamenti, 0, ',', '.'))
                ->description('Sessioni registrate dalle app')
                ->icon('heroicon-m-fire')
                ->color('success'),
        ];
    }
}
