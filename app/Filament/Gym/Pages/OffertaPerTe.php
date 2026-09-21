<?php

declare(strict_types=1);

namespace App\Filament\Gym\Pages;

use App\Enums\SuCosaVale;
use App\Models\Promozione;
use App\Services\Billing\LePromozioni;
use App\Services\Promozioni\ConsegnaDeiPremi;
use App\Services\Promozioni\LeOfferteDellaPersona;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * 🎁 **La pagina che spiega un'offerta** — 22/09/2026, fase F2.
 *
 * 📌 Il committente, sul link delle promozioni: *«il link deve portare a una
 * pagina che spiega l'offerta, ma **nel pannello non sul sito**»*.
 *
 * ══ ⚠️ COSA VUOL DIRE «NEL PANNELLO» ═════════════════════════════════════
 *
 * Che chi apre il link **è già cliente**: ha un account, e l'offerta la si può
 * già verificare su di lui. 🚨 Una promozione per chi non è ancora cliente non
 * avrebbe dove atterrare — non ha un pannello — ed è una conseguenza scritta
 * nel piano, non una dimenticanza.
 *
 * ── 🚨 Il codice dice QUALE offerta, non A CHI spetta ─────────────────────
 *
 * ⛔ Senza il controllo sui bersagli un codice inoltrato varrebbe per chiunque,
 * e una promozione «per gli allievi della palestra Tal dei Tali» finirebbe su
 * mezza piattaforma. 💡 Il controllo lo fa `LePromozioni::conIlCodice()`, che
 * è lo stesso che decide lo sconto al pagamento: una regola sola, due usi.
 */
class OffertaPerTe extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedGift;

    protected static ?string $title = 'La tua offerta';

    protected string $view = 'filament.gym.pages.offerta-per-te';

    /**
     * ⛔ **Non sta nel menù**: ci si arriva dal link, e un menù con dentro
     * «offerta» anche a chi non ne ha nessuna è una promessa non mantenuta.
     */
    protected static bool $shouldRegisterNavigation = false;

    public static function getSlug(?Panel $panel = null): string
    {
        return 'offerta/{codice?}';
    }

    public ?string $codice = null;

    public function mount(?string $codice = null): void
    {
        $this->codice = $codice;
    }

    /** L'offerta del link, se questa persona ce l'ha davvero. */
    public function offerta(): ?Promozione
    {
        if (blank($this->codice)) {
            return null;
        }

        $utente = auth()->user();

        if ($utente === null) {
            return null;
        }

        /*
         * 💡 Senza dire su cosa: la pagina spiega l'offerta, non un acquisto in
         * particolare. 🆕 P1 — e un'offerta di soli regali non sconta niente,
         * quindi chiederla «sui gettoni» o «sul canone» non la troverebbe.
         */
        return app(LePromozioni::class)->conIlCodice($this->codice, $utente);
    }

    /**
     * Perché non vale — o `null` se vale.
     *
     * ⚠️ **Tre motivi diversi, tre messaggi diversi**: «scaduta», «esaurita» e
     * «non è per te» portano a tre telefonate diverse, e un «non valida» unico
     * le farebbe arrivare tutte come reclami uguali.
     */
    public function motivo(): ?string
    {
        $utente = auth()->user();

        if ($utente === null || blank($this->codice)) {
            return 'Questo indirizzo non porta a nessuna offerta.';
        }

        // 🆕 P8 — la stessa regola dell'app: `LeOfferteDellaPersona::perche()`.
        return app(LeOfferteDellaPersona::class)->perche((string) $this->codice, $utente);
    }

    /**
     * 🆕 🎁 **«Riscatta»** — P2 di `plan_promozioni.md`.
     *
     * Per le offerte con un link che **regalano** qualcosa (gettoni, mesi):
     * chi apre il link li prende da qui. Gli sconti invece si usano pagando,
     * e per quelli il tasto non c'è.
     */
    public function riscattaAction(): Action
    {
        return Action::make('riscatta')
            ->label('Riscatta')
            ->icon('heroicon-m-gift')
            ->color('primary')
            ->visible(function (): bool {
                $offerta = $this->offerta();
                $utente = auth()->user();

                return $offerta !== null
                    && $utente !== null
                    && app(ConsegnaDeiPremi::class)->percheNo($offerta, $utente) === null;
            })
            ->action(function (): void {
                $offerta = $this->offerta();
                $utente = auth()->user();

                $fatta = $offerta !== null && $utente !== null
                    ? app(ConsegnaDeiPremi::class)->riscatta($offerta, $utente)
                    : null;

                if ($fatta === null) {
                    Notification::make()
                        ->title('Non è stato possibile riscattarla')
                        ->body($offerta !== null && $utente !== null
                            ? app(ConsegnaDeiPremi::class)->percheNo($offerta, $utente)
                            : null)
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title('Fatto!')
                    ->body($fatta->gettoni > 0 ? "Hai ricevuto {$fatta->gettoni} gettoni." : 'L\'offerta è tua.')
                    ->success()
                    ->send();
            });
    }

    /** Le altre offerte che questa persona ha già, senza fare niente. */
    public function altreAutomatiche(): Collection
    {
        $utente = auth()->user();

        if ($utente === null) {
            return collect();
        }

        /*
         * 🆕 P8 — le automatiche di sempre, **più** quelle che la promozione dice
         * di mostrare nel pannello (`visibile_in`) e quelle già sue in sospeso.
         */
        return collect(SuCosaVale::cases())
            ->flatMap(fn (SuCosaVale $cosa) => app(LePromozioni::class)->automaticheDi($utente, $cosa))
            ->merge(app(LeOfferteDellaPersona::class)->per($utente, 'pannello'))
            ->unique('id')
            ->values();
    }
}
