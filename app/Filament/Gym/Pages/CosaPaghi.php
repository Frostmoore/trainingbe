<?php

declare(strict_types=1);

namespace App\Filament\Gym\Pages;

use App\Models\AddebitoMensile;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\AddebitoDelMese;
use App\Services\Billing\ContoDegliIscritti;
use App\Services\Billing\Stripe\Cassa;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * 💶 **Cosa paghi** — il piano a consumo, visto da dentro. 3b-AH.6.9
 * (17/09/2026).
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * 🚨 Perche' la prima telefonata su una fattura a consumo e' sempre la stessa:
 * *«ma io ne ho 40, perche' pago per 100?»*. ⛔ Una cifra che non torna con
 * nessun numero che il cliente ha sotto gli occhi non e' un problema di
 * contabilita': e' un problema di fiducia, e arriva **dopo** che i soldi sono
 * partiti.
 *
 * 💡 Qui la stessa cifra si vede **prima**: quanti iscritti hai adesso, quanto
 * sarebbe il mese se finisse oggi, e la storia di quelli gia' emessi.
 *
 * ══ ⚠️ CHI LA VEDE ════════════════════════════════════════════════════════
 *
 * Solo il **proprietario** — `Cassa::loPuoGestire()`, la stessa domanda che
 * governa la disdetta. ⛔ Un trainer della palestra che legge quanto costa il
 * mese sta leggendo quanti iscritti ha il suo datore di lavoro, e non e' una
 * cosa che gli abbia dato nessuno.
 *
 * ⚠️ E **solo se il piano e' a consumo**: su un canone questa pagina direbbe
 * cose che non c'entrano, e il prezzo sta gia' in fattura.
 */
class CosaPaghi extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Cosa paghi';

    protected static ?string $title = 'Cosa paghi';

    protected static ?int $navigationSort = 95;

    protected string $view = 'filament.gym.pages.cosa-paghi';

    /**
     * 🚨 `canAccess()` e non solo `shouldRegisterNavigation()`: nascondere la
     * voce di menu non chiude la pagina, e l'indirizzo si digita.
     */
    public static function canAccess(): bool
    {
        $utente = auth()->user();

        if (! $utente instanceof User || ! Cassa::loPuoGestire($utente)) {
            return false;
        }

        return self::pianoCorrente()?->aConsumo() === true;
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    /** La palestra in cui si sta lavorando adesso. */
    public static function palestra(): ?Tenant
    {
        return app(TenantContext::class)->get();
    }

    public static function pianoCorrente(): ?Plan
    {
        $palestra = self::palestra();

        if ($palestra === null) {
            return null;
        }

        return app(TenantContext::class)->runWithoutTenant(
            fn (): ?Plan => PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $palestra->getKey())
                ->attivi()
                ->with('plan')
                ->orderByDesc('starts_at')
                ->first()?->plan,
        );
    }

    /**
     * Quanti iscritti ci sono **adesso**.
     *
     * 💡 Lo stesso servizio che usa la fattura: se questo numero e quello
     * addebitato divergessero, questa pagina peggiorerebbe il problema che deve
     * risolvere.
     */
    public function iscrittiOra(): int
    {
        $palestra = self::palestra();

        return $palestra === null ? 0 : app(ContoDegliIscritti::class)->quanti($palestra);
    }

    /**
     * Quanto sarebbe il mese **se finisse adesso**.
     *
     * ⚠️ Si dice «se finisse adesso» e non «questo mese»: gli iscritti possono
     * ancora cambiare, e una cifra data per definitiva che poi cambia e' peggio
     * di nessuna cifra.
     */
    public function stimaDelMese(): ?int
    {
        return self::pianoCorrente()?->costoDelMese($this->iscrittiOra());
    }

    /** Il minimo del piano, se sotto quella soglia ci si sta. */
    public function minimo(): ?int
    {
        $piano = self::pianoCorrente();

        if ($piano === null || ($piano->minimo_iscritti ?? 0) <= $this->iscrittiOra()) {
            return null;
        }

        return $piano->minimo_iscritti;
    }

    public function periodoCorrente(): string
    {
        return AddebitoDelMese::periodoDi();
    }

    /**
     * Gli addebiti gia' scritti, dal piu' recente.
     *
     * @return Collection<int, AddebitoMensile>
     */
    public function storico(): Collection
    {
        $palestra = self::palestra();

        if ($palestra === null) {
            return collect();
        }

        return AddebitoMensile::query()
            ->with('plan')
            ->orderByDesc('periodo')
            ->limit(24)
            ->get();
    }
}
