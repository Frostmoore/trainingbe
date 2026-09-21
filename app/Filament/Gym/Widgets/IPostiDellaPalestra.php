<?php

declare(strict_types=1);

namespace App\Filament\Gym\Widgets;

use App\Enums\UserRole;
use App\Filament\Gym\Support\UnInvito;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reporting\IContiDellaPalestra;
use App\Services\Tenancy\InvitiInPalestra;
use App\Support\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\DB;

/**
 * 🎫 **I posti, e i due tasti d'invito** — 23/09/2026, fasi F5.1 e F5.3.
 *
 * 📌 Il committente, per il cruscotto della palestra: *«trainer attivi, slot
 * allievo e trainer (occupati/totali), costo per allievo, prezzo per allievo»*
 * e *«i due tasti d'invito (allievo e trainer) dentro le rispettive card»*.
 *
 * ══ 🚨 PERCHÉ NON È UNO `StatsOverviewWidget` ═══════════════════════════
 *
 * ⛔ Perché una `Stat` **non può contenere un pulsante**. E la richiesta non è
 * «mettiamo anche due bottoni da qualche parte»: è che il gesto stia **dove sta
 * il numero** — si guarda «3 posti su 10» e si invita lì, senza attraversare
 * il menù per arrivare a un elenco e poi a un'azione.
 *
 * ══ ⚠️ SOLO IL GESTORE, E NON È UNA GERARCHIA ═══════════════════════════
 *
 * 🚨 Qui dentro ci sono **i conti della palestra**: quanto paga, quanto fa
 * pagare, quanto le resta. ⛔ Non sono affari di un trainer dipendente, e
 * mostrarglieli vorrebbe dire dirgli il margine del suo datore di lavoro su
 * ogni cliente che segue.
 *
 * 💡 Al trainer resta `GymOverview`, che conta le persone.
 */
class IPostiDellaPalestra extends Widget implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;

    protected string $view = 'filament.gym.widgets.i-posti-della-palestra';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = -1;

    public static function canView(): bool
    {
        return auth()->user()?->isGymAdmin() === true;
    }

    public function invitaIscrittoAction(): Action
    {
        return UnInvito::make(UserRole::Member, 'invitaIscritto');
    }

    public function invitaTrainerAction(): Action
    {
        return UnInvito::make(UserRole::Trainer, 'invitaTrainer');
    }

    /**
     * Tutto quello che il riquadro disegna, già pronto.
     *
     * 💡 Un array solo e non sei metodi: la vista non deve chiamare servizi —
     * ⛔ una vista che interroga il database è una vista che fa una query in
     * più ogni volta che qualcuno la tocca, e non si vede da nessuna parte.
     *
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $palestra = app(TenantContext::class)->get();

        if (! $palestra instanceof Tenant) {
            return ['palestra' => null];
        }

        $inviti = app(InvitiInPalestra::class);
        $conti = app(IContiDellaPalestra::class);

        return [
            'palestra' => $palestra,
            'iscritti' => $inviti->iPosti($palestra, UserRole::Member),
            'trainer' => $inviti->iPosti($palestra, UserRole::Trainer),
            'trainerAttivi' => $this->trainerAttivi($palestra),
            'costo' => $conti->costoPerAllievoCent($palestra),
            'prezzo' => $conti->prezzoPerAllievoCent($palestra),
            'margine' => $conti->marginePerAllievoCent($palestra),
        ];
    }

    /**
     * 🏋️ **Quanti trainer sono davvero operativi.**
     *
     * ⚠️ Non è lo stesso numero dei **posti occupati**: quelli contano anche
     * gli inviti mandati e chi è stato sospeso. 💡 Il committente ha chiesto
     * tutti e due proprio perché la differenza è la domanda: *«ho un posto
     * pagato che non sta lavorando?»*.
     */
    private function trainerAttivi(Tenant $palestra): int
    {
        return User::query()
            ->withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('is_active', true)
            ->whereIn('id', DB::table('model_has_roles as mhr')
                ->join('roles as r', function ($join): void {
                    $join->on('r.id', '=', 'mhr.role_id')->on('r.tenant_id', '=', 'mhr.tenant_id');
                })
                ->where('mhr.tenant_id', $palestra->getKey())
                ->where('r.name', UserRole::Trainer->value)
                ->select('mhr.model_id'))
            ->count();
    }
}
