<?php

declare(strict_types=1);

namespace App\Filament\Gym\Widgets;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\WorkoutSession;
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

        $inattivi = $totale === 0 ? 0 : User::query()
            ->whereIn('id', $iscritti)
            ->whereDoesntHave('workoutSessions',
                fn ($q) => $q->where('started_at', '>=', now()->subDays(30)))
            ->count();

        $senzaScheda = $totale === 0 ? 0 : User::query()
            ->whereIn('id', $iscritti)
            ->whereDoesntHave('workoutPlans',
                fn ($q) => $q->where('status', PlanStatus::Published->value))
            ->count();

        $allenamentiSettimana = WorkoutSession::query()
            ->where('started_at', '>=', now()->startOfWeek())
            ->count();

        return [
            Stat::make('Iscritti attivi', (string) $totale)
                ->description('Persone con un account funzionante')
                ->icon('heroicon-m-user-group')
                ->color('primary'),

            Stat::make('Non si allenano da 30 giorni', (string) $inattivi)
                ->description($inattivi > 0 ? 'Sono quelli che stai per perdere' : 'Nessuno: va bene cosi\'')
                ->icon('heroicon-m-exclamation-triangle')
                ->color($inattivi > 0 ? 'danger' : 'success'),

            Stat::make('Senza scheda pubblicata', (string) $senzaScheda)
                ->description($senzaScheda > 0 ? 'Aprono l\'app e non trovano niente' : 'Tutti hanno una scheda')
                ->icon('heroicon-m-clipboard-document-list')
                ->color($senzaScheda > 0 ? 'warning' : 'success'),

            Stat::make('Allenamenti questa settimana', (string) $allenamentiSettimana)
                ->description('Sessioni registrate dall\'app')
                ->icon('heroicon-m-fire')
                ->color('info'),
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
