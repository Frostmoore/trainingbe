<?php

declare(strict_types=1);

namespace App\Filament\Gym\Widgets;

use App\Enums\UserRole;
use App\Filament\Gym\Support\UnInvito;
use App\Filament\Gym\Support\UnInvitoAllievo;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Reporting\IContiDellaPalestra;
use App\Services\Tenancy\InvitiDelTrainer;
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

    /**
     * ══ 🆕 ANCHE IL TRAINER INDIPENDENTE, A CASA SUA — F10.7, 24/09/2026 ═════
     *
     * 📌 Il committente: *«su quella di giulia ci devono essere le stesse cards
     * che riguardano i guadagni e le spese che avrebbe una palestra (di base è
     * solo una palestra con un solo trainer)»*.
     *
     * 💡 `comandaQui()` e non `isGymAdmin()`: la domanda vera non è «amministri
     * una palestra», è **«questo spazio è tuo?»**. ⛔ Per un trainer dipendente
     * la risposta resta no, e resta no per lo stesso motivo di prima: qui dentro
     * c'è il margine del suo datore di lavoro.
     */
    public static function canView(): bool
    {
        return auth()->user()?->comandaQui() === true;
    }

    /**
     * 🚨 **Due inviti diversi, perché sono due cose diverse.**
     *
     * ⛔ `InvitiInPalestra::invita()` **rifiuta** uno spazio personale — *«Solo
     * una palestra può invitare»* — ed è giusto così: un trainer indipendente
     * non fa entrare nessuno «dentro se stesso», si prende un allievo. 💡 La
     * sua strada è `InvitiDelTrainer`, cioè `UnInvitoAllievo`.
     *
     * ⚠️ È lo stesso difetto che il committente ha trovato il 24/09 nella
     * pagina degli iscritti: *«Se clicco su "Invita un iscritto" mi dice che
     * solo la palestra può invitare»*. Qui il tasto è **dentro la card dei
     * posti**, e sarebbe stato il secondo posto da cui usciva lo stesso errore.
     */
    public function invitaIscrittoAction(): Action
    {
        return $this->spazioPersonale()
            ? UnInvitoAllievo::make('invitaIscritto')
            : UnInvito::make(UserRole::Member, 'invitaIscritto');
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

        $conti = app(IContiDellaPalestra::class);

        $comune = [
            'palestra' => $palestra,
            'costo' => $conti->costoPerAllievoCent($palestra),
            'prezzo' => $conti->prezzoPerAllievoCent($palestra),
            'margine' => $conti->marginePerAllievoCent($palestra),
        ];

        /*
         * ══ 🆕 🏋️ LO SPAZIO DI UN TRAINER NON HA DIPENDENTI — F10.7 ═════════
         *
         * ⛔ **Niente card dei trainer**, e non è una semplificazione: un
         * trainer indipendente non può assumere nessuno (`InvitiInPalestra`
         * rifiuta gli inviti da uno spazio personale), e `iPosti(…, Trainer)`
         * gli direbbe «0 su 0 posti» — un limite che non esiste, su una cosa
         * che non può fare.
         *
         * 💡 I suoi posti sono gli **allievi**, e li conta `InvitiDelTrainer`:
         * è lo stesso conto che decide se può mandare un altro invito, quindi
         * il numero scritto qui e il rifiuto che riceve là non possono
         * discordare.
         */
        if ($palestra->ePersonale()) {
            $trainer = $palestra->proprietario;

            return $comune + [
                'iscritti' => $trainer === null
                    ? ['occupati' => 0, 'totali' => 0, 'rimasti' => 0]
                    : app(InvitiDelTrainer::class)->iPosti($trainer),
                'trainer' => null,
                'trainerAttivi' => null,
            ];
        }

        $inviti = app(InvitiInPalestra::class);

        return $comune + [
            'iscritti' => $inviti->iPosti($palestra, UserRole::Member),
            'trainer' => $inviti->iPosti($palestra, UserRole::Trainer),
            'trainerAttivi' => $this->trainerAttivi($palestra),
        ];
    }

    /** Lo spazio che si sta guardando è quello personale di un trainer? */
    private function spazioPersonale(): bool
    {
        return app(TenantContext::class)->get()?->ePersonale() === true;
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
