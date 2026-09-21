<?php

declare(strict_types=1);

namespace App\Filament\God\Widgets;

use App\Models\AiUsageLog;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Billing\ContoDegliIscritti;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

/**
 * 🏢 **Le palestre a colpo d'occhio** — B2.5, rifatta il 21/09/2026 (fase F1).
 *
 * ══ 📌 COS'ERA, E PERCHÉ NON ANDAVA ══════════════════════════════════════
 *
 * Era «le cinque palestre che consumano di più», con dentro **token**, un
 * **«Costo»** senza unità e un **«Token per iscritto»**. Il committente:
 *
 * > *«**Token per iscritto** è falso. Gli abbonati hanno semplicemente 150
 * > chiamate gratuite, ma non sono token, non chiamarli così … A questo punto
 * > mettici la data di rinnovo dell'abbonamento o il piano dell'abbonamento col
 * > costo tra parentesi … **Costo** non so cosa sia, quindi o lo rendi chiaro o
 * > lo togli. Il senso è che in questa tabella ci devono essere i dati
 * > importanti che posso vedere velocemente senza entrare su palestre o su
 * > utenti.»*
 *
 * ⛔ Aveva ragione su tutte e tre:
 *
 * | Colonna vecchia | Cosa c'era che non andava |
 * |---|---|
 * | «Token per iscritto» | ⛔ **Non erano token**: era il tetto di **chiamate** mensili, e vale 150 per tutti — una colonna che scrive sempre lo stesso numero |
 * | «Token» | 💡 Il consumo in token è un dettaglio tecnico: quello che decide è la **cifra** |
 * | «Costo» | ⚠️ Era il costo dell'AI del mese **in dollari**, e non lo diceva |
 * | «Piano» | 🚨 Leggeva `tenants.plan`: una colonna **legacy** che non c'entra con l'abbonamento vero |
 *
 * ══ 💡 COSA C'È ADESSO ════════════════════════════════════════════════════
 *
 * Le cose per cui si apre questa pagina: **chi è**, **cosa paga**, **quando
 * scade**, **quanto mi costa in AI**. 🚨 L'ordinamento resta sul costo AI: è
 * l'unica voce che cresce da sola con l'uso, e la si guarda per prima.
 */
class TopTenantsByAiCost extends TableWidget
{
    protected static ?string $heading = 'Le palestre, a colpo d\'occhio';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    /**
     * 🧮 Gli iscritti di ogni palestra, chiesti una volta sola.
     *
     * ⚠️ Serve a dire quanto paga chi è **a consumo**, e lì il numero dipende
     * dagli iscritti. 💡 È lo stesso conteggio che finisce in fattura.
     *
     * @var array<int, int>|null
     */
    private static ?array $iscritti = null;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Tenant::query()
                    /*
                     * 🚨 **Solo le palestre** — F1.4: `tenants` contiene anche
                     * un tenant per ogni persona senza palestra, e qui si
                     * decide su clienti, non su righe di servizio.
                     */
                    ->palestre()
                    ->with('abbonamentoAttivo.plan')
                    ->select('tenants.*')
                    ->selectSub(
                        AiUsageLog::query()
                            ->withoutGlobalScopes([TenantScope::class])
                            ->selectRaw('COALESCE(SUM(cost_millicents), 0)')
                            ->whereColumn('ai_usage_logs.tenant_id', 'tenants.id')
                            ->inMonth(),
                        'ai_cost',
                    )
                    ->orderByDesc('ai_cost')
                    ->limit(10),
            )
            ->columns([
                TextColumn::make('name')
                    ->label('Palestra')
                    ->weight('bold')
                    ->description(fn (Tenant $r): string => $r->slug),

                /*
                 * 📌 *«il piano dell'abbonamento col costo tra parentesi»*.
                 *
                 * ⚠️ Il costo si legge con `Plan::quantoCostaAlMese()`: un
                 * piano a consumo ha `price_cents = 0`, e mostrarlo così
                 * direbbe «gratis» sul piano più caro del listino.
                 */
                TextColumn::make('abbonamentoAttivo.plan.name')
                    ->label('Piano')
                    ->badge()
                    ->color(fn (Tenant $r): string => $r->abbonamentoAttivo === null ? 'danger' : 'success')
                    ->placeholder('nessun abbonamento')
                    ->description(fn (Tenant $r): ?string => self::quantoPaga($r)),

                /*
                 * 📌 *«la data di rinnovo dell'abbonamento»*.
                 *
                 * ⚠️ `ends_at = null` vuol dire **non scade**: è la convenzione
                 * delle palestre, e scriverci «scaduto» sarebbe il contrario.
                 */
                TextColumn::make('abbonamentoAttivo.ends_at')
                    ->label('Rinnovo')
                    ->dateTime('d/m/Y')
                    ->placeholder('non scade')
                    ->description(fn (Tenant $r): ?string => match (true) {
                        $r->abbonamentoAttivo === null => null,
                        (bool) $r->abbonamentoAttivo->rinnova => 'automatico',
                        default => 'non si rinnova',
                    })
                    ->color(fn (Tenant $r): string => $r->abbonamentoAttivo?->rinnova ? 'gray' : 'warning'),

                TextColumn::make('iscritti')
                    ->label('Iscritti')
                    ->state(fn (Tenant $r): int => self::iscrittiDi($r))
                    ->badge()
                    ->color('gray')
                    ->alignEnd(),

                /*
                 * 🚨 **In dollari, e adesso lo dice.** Il listino dei modelli è
                 * in dollari: convertirlo qui vorrebbe dire scrivere un cambio
                 * a mano in un cruscotto, e sbagliarlo il giorno che si muove.
                 */
                TextColumn::make('ai_cost')
                    ->label('Costo AI del mese')
                    ->formatStateUsing(fn ($state): string => '$'
                        .number_format(AiUsageLog::millicentsToCurrency((int) $state), 2, ',', '.'))
                    ->description('quanto ci costa la loro AI')
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nessuna palestra');
    }

    private static function iscrittiDi(Tenant $palestra): int
    {
        self::$iscritti ??= app(ContoDegliIscritti::class)->perOgniPalestra();

        return self::$iscritti[$palestra->getKey()] ?? 0;
    }

    /** Quanto paga questo mese, già scritto in euro. */
    private static function quantoPaga(Tenant $palestra): ?string
    {
        $piano = $palestra->abbonamentoAttivo?->plan;

        if ($piano === null) {
            return null;
        }

        $euro = number_format($piano->quantoCostaAlMese(self::iscrittiDi($palestra)) / 100, 2, ',', '.').' €';

        return $piano->aConsumo() ? $euro.' (a consumo)' : $euro;
    }
}
