<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Tenants\Schemas;

use App\Enums\UserRole;
use App\Models\AiUsageLog;
use App\Models\PagamentoIncassato;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Billing\ContoDegliIscritti;
use App\Support\Tenancy\TenantContext;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * 🏢 **La scheda di una palestra** — 22/09/2026, fase F3.
 *
 * ══ 📌 COM'È STATA CHIESTA ════════════════════════════════════════════════
 *
 * > *«Qui ci voglio tutti i dati importanti della palestra: quanti trainer e
 * > chi sono · quanti allievi e chi sono · da quanto è iscritta · quanti soldi
 * > mi ha dato dall'inizio · che abbonamento ha · quanto paga · quando paga ·
 * > quante volte ha pagato in ritardo o ha disdetto · quante schede ha ·
 * > quanti (e quali) esercizi ha · quanto lei e i suoi allievi hanno speso con
 * > l'ai · quanto hanno acquistato token»*
 *
 * ══ 🚨 DUE NUMERI CHE SEMBRANO UGUALI E NON LO SONO ══════════════════════
 *
 * | | |
 * |---|---|
 * | **Spesa AI** | quanto **ci costa** l'intelligenza artificiale di quella palestra: è un costo nostro, in dollari, e non lo paga nessuno |
 * | **Gettoni comprati** | quanto **ci hanno dato** per comprarli: è un ricavo, in euro |
 *
 * ⛔ Metterli vicini senza dirlo sarebbe il modo più rapido di leggere un
 * margine al contrario.
 */
class SchedaDellaPalestra
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('L\'abbonamento')
                ->columns(3)
                ->schema([
                    TextEntry::make('piano')
                        ->label('Piano')
                        ->state(fn (Tenant $r): string => $r->abbonamentoAttivo?->plan?->name ?? 'nessun abbonamento')
                        ->badge()
                        ->color(fn (Tenant $r): string => $r->abbonamentoAttivo === null ? 'danger' : 'success'),

                    TextEntry::make('quanto_paga')
                        ->label('Quanto paga')
                        ->state(fn (Tenant $r): string => self::quantoPaga($r))
                        ->weight('bold'),

                    TextEntry::make('quando_paga')
                        ->label('Quando')
                        ->state(fn (Tenant $r): string => match (true) {
                            $r->abbonamentoAttivo === null => '—',
                            $r->abbonamentoAttivo->ends_at === null => 'non scade',
                            default => $r->abbonamentoAttivo->ends_at->format('d/m/Y')
                                .($r->abbonamentoAttivo->rinnova ? ' (si rinnova)' : ' (non si rinnova)'),
                        }),

                    TextEntry::make('da_quando')
                        ->label('Cliente da')
                        ->state(fn (Tenant $r): string => $r->created_at?->format('d/m/Y').' · '
                            .($r->created_at?->diffForHumans(syntax: true) ?? '')),

                    /*
                     * 💰 **Quello che ci ha dato davvero.** ⚠️ Prima del 22/09
                     * questo numero non esisteva: i pagamenti a canone non li
                     * registrava nessuno, e l'unica risposta era «guarda su
                     * Stripe». Adesso c'è `pagamenti_incassati`.
                     *
                     * 🚨 **Conta da quando il registro esiste**: le righe
                     * precedenti non si possono inventare, e un totale gonfiato
                     * a occhio sarebbe peggio di un totale parziale dichiarato.
                     */
                    TextEntry::make('incassato')
                        ->label('Ci ha dato, dall\'inizio')
                        ->state(fn (Tenant $r): string => self::euro(self::incassato($r)))
                        ->weight('bold')
                        ->helperText('Dal 22/09/2026, da quando il registro degli incassi esiste.'),

                    /*
                     * ⚠️ «In ritardo» = un rinnovo che Stripe ha rifiutato
                     * almeno una volta (`pagamento_fallito_il`). 💡 Non è una
                     * morosità: la carta scade, e succede.
                     */
                    TextEntry::make('problemi')
                        ->label('Ritardi e disdette')
                        ->state(fn (Tenant $r): string => self::problemi($r)),
                ]),

            Section::make('Chi c\'è dentro')
                ->columns(2)
                ->schema([
                    TextEntry::make('trainer')
                        ->label('Trainer')
                        ->state(fn (Tenant $r): string => self::chiSono($r, UserRole::Trainer))
                        ->listWithLineBreaks(),

                    TextEntry::make('iscritti')
                        ->label('Iscritti')
                        ->state(fn (Tenant $r): string => self::chiSono($r, UserRole::Member))
                        ->listWithLineBreaks(),
                ]),

            Section::make('Il lavoro')
                ->columns(2)
                ->schema([
                    TextEntry::make('schede')
                        ->label('Schede')
                        ->state(fn (Tenant $r): string => (string) self::quante($r, 'workout_plans')),

                    /*
                     * 💡 Gli esercizi **suoi**: la libreria di base è globale
                     * (`tenant_id` a `null`) e non è roba sua.
                     */
                    TextEntry::make('esercizi')
                        ->label('Esercizi suoi')
                        ->state(fn (Tenant $r): string => self::esercizi($r)),
                ]),

            Section::make('Intelligenza artificiale e gettoni')
                ->columns(2)
                ->schema([
                    TextEntry::make('spesa_ai')
                        ->label('Quanto ci costa la loro AI')
                        ->state(fn (Tenant $r): string => '$'.number_format(
                            AiUsageLog::millicentsToCurrency(self::spesaAi($r)), 2, ',', '.'
                        ))
                        ->helperText('Dall\'inizio, in dollari: è il listino del modello. ⚠️ È un costo nostro.'),

                    TextEntry::make('gettoni_comprati')
                        ->label('Gettoni comprati da loro')
                        ->state(fn (Tenant $r): string => self::euro(self::gettoniComprati($r)))
                        ->helperText('Lei e i suoi iscritti. ⚠️ Questo invece è un ricavo.'),
                ]),
        ]);
    }

    // ───────────────────────── i conti ─────────────────────────

    private static function contesto(): TenantContext
    {
        return app(TenantContext::class);
    }

    private static function euro(int $cent): string
    {
        return number_format($cent / 100, 2, ',', '.').' €';
    }

    private static function quantoPaga(Tenant $palestra): string
    {
        $piano = $palestra->abbonamentoAttivo?->plan;

        if ($piano === null) {
            return '—';
        }

        $iscritti = app(ContoDegliIscritti::class)->quanti($palestra);

        $euro = self::euro($piano->quantoCostaAlMese($iscritti));

        return $piano->aConsumo() ? $euro.' al mese (a consumo)' : $euro.' al mese';
    }

    private static function incassato(Tenant $palestra): int
    {
        return (int) self::contesto()->runWithoutTenant(
            fn (): int => (int) PagamentoIncassato::query()
                ->where('tenant_id', $palestra->getKey())
                ->sum('importo_cent'),
        );
    }

    private static function gettoniComprati(Tenant $palestra): int
    {
        return (int) self::contesto()->runWithoutTenant(
            fn (): int => (int) PagamentoIncassato::query()
                ->where('tenant_id', $palestra->getKey())
                ->delTipo(PagamentoIncassato::GETTONI)
                ->sum('importo_cent'),
        );
    }

    private static function spesaAi(Tenant $palestra): int
    {
        return (int) AiUsageLog::query()
            ->withoutGlobalScopes([TenantScope::class])
            ->where('tenant_id', $palestra->getKey())
            ->sum('cost_millicents');
    }

    /** Quante righe di questa tabella ha la palestra. */
    private static function quante(Tenant $palestra, string $tabella): int
    {
        return (int) DB::table($tabella)->where('tenant_id', $palestra->getKey())->count();
    }

    private static function esercizi(Tenant $palestra): string
    {
        $quanti = self::quante($palestra, 'exercises');

        if ($quanti === 0) {
            return 'nessuno (usa la libreria di base)';
        }

        $nomi = DB::table('exercises')
            ->where('tenant_id', $palestra->getKey())
            ->orderBy('name')
            ->limit(8)
            ->pluck('name')
            ->all();

        return $quanti.': '.implode(', ', $nomi).($quanti > 8 ? '…' : '');
    }

    /**
     * ⚠️ Si contano dai **ruoli**, non da `users.tenant_id`: un trainer con un
     * portfolio suo lavora nella palestra senza avere lì la propria colonna.
     */
    private static function chiSono(Tenant $palestra, UserRole $ruolo): string
    {
        $nomi = self::contesto()->runWithoutTenant(
            fn (): array => DB::table('model_has_roles as mhr')
                ->join('roles as r', function ($join): void {
                    $join->on('r.id', '=', 'mhr.role_id')->on('r.tenant_id', '=', 'mhr.tenant_id');
                })
                ->join('users as u', 'u.id', '=', 'mhr.model_id')
                ->whereNull('u.deleted_at')
                ->where('u.is_active', true)
                ->where('mhr.tenant_id', $palestra->getKey())
                ->where('r.name', $ruolo->value)
                ->orderBy('u.name')
                ->limit(30)
                ->pluck('u.name')
                ->all(),
        );

        $quanti = app(ContoDegliIscritti::class)->quanti($palestra, $ruolo);

        if ($quanti === 0) {
            return 'nessuno';
        }

        return $quanti.': '.implode(', ', $nomi).($quanti > count($nomi) ? '…' : '');
    }

    private static function problemi(Tenant $palestra): string
    {
        return self::contesto()->runWithoutTenant(function () use ($palestra): string {
            $ritardi = (int) DB::table('plan_subscriptions')
                ->where('tenant_id', $palestra->getKey())
                ->whereNotNull('pagamento_fallito_il')
                ->count();

            /*
             * ⚠️ «Disdetto» = un abbonamento **finito**, non uno cambiato: chi
             * passa a un piano più grande non ha disdetto niente, e contarlo
             * lì dentro darebbe un cliente scontento che non esiste.
             */
            $disdette = (int) DB::table('plan_subscriptions')
                ->where('tenant_id', $palestra->getKey())
                ->whereNotNull('ends_at')
                ->where('ends_at', '<', now())
                ->where('rinnova', false)
                ->count();

            return match (true) {
                $ritardi === 0 && $disdette === 0 => 'mai',
                default => trim(
                    ($ritardi > 0 ? "{$ritardi} pagamenti rifiutati" : '')
                    .($ritardi > 0 && $disdette > 0 ? ' · ' : '')
                    .($disdette > 0 ? "{$disdette} abbonamenti finiti" : '')
                ),
            };
        });
    }
}
