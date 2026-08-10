<?php

declare(strict_types=1);

namespace App\Filament\God\Widgets;

use App\Models\AiUsageLog;
use App\Models\Scopes\TenantScope;
use App\Models\Tenant;
use App\Services\Ai\Quota\TenantAiQuota;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Le cinque palestre che consumano di piu' — B2.5.
 *
 * 🚨 **Serve a una decisione commerciale, non alla curiosita'.** Un cliente che
 * consuma dieci volte gli altri o sta usando il prodotto benissimo — e allora e'
 * il momento di proporgli il piano superiore — oppure ha qualcosa che gira a
 * vuoto. In entrambi i casi bisogna saperlo prima della fattura, non dopo.
 *
 * La colonna «% del tetto» e' quella che dice quale delle due cose sia: al 95%
 * di un tetto basso e' un cliente da far crescere; al 300% di token con un tetto
 * illimitato e' qualcosa da guardare.
 */
class TopTenantsByAiCost extends TableWidget
{
    protected static ?string $heading = 'Consumo AI del mese per palestra';

    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 2;

    public function table(Table $table): Table
    {
        return $table
            ->query(
                Tenant::query()
                    ->select('tenants.*')
                    ->selectSub(
                        AiUsageLog::query()
                            ->withoutGlobalScopes([TenantScope::class])
                            ->selectRaw('COALESCE(SUM(cost_millicents), 0)')
                            ->whereColumn('ai_usage_logs.tenant_id', 'tenants.id')
                            ->inMonth(),
                        'ai_cost',
                    )
                    ->selectSub(
                        AiUsageLog::query()
                            ->withoutGlobalScopes([TenantScope::class])
                            ->selectRaw('COALESCE(SUM(input_tokens + output_tokens), 0)')
                            ->whereColumn('ai_usage_logs.tenant_id', 'tenants.id')
                            ->inMonth(),
                        'ai_tokens',
                    )
                    ->orderByDesc('ai_cost')
                    ->limit(5),
            )
            ->columns([
                TextColumn::make('name')->label('Palestra')->weight('bold'),

                TextColumn::make('plan')->label('Piano')->badge()->color('gray'),

                TextColumn::make('ai_tokens')
                    ->label('Token')
                    ->formatStateUsing(fn ($state): string => number_format((int) $state, 0, ',', '.'))
                    ->alignEnd(),

                TextColumn::make('ai_cost')
                    ->label('Costo')
                    ->formatStateUsing(fn ($state): string => '$'
                        .number_format(AiUsageLog::millicentsToCurrency((int) $state), 2, ',', '.'))
                    ->alignEnd(),

                /*
                 * ⚠️ **Non c'e' piu' una «% del tetto» di palestra** — C20.
                 *
                 * La quota e' passata a essere di ciascun iscritto: una
                 * percentuale di palestra sarebbe una frazione di un tetto che
                 * non esiste, e chi la guardasse crederebbe di avere un margine
                 * che nessuno gli garantisce.
                 *
                 * Quello che serve sapere da qui e' **quanto da' a ognuno**:
                 * moltiplicato per gli iscritti, e' il costo massimo del mese —
                 * un numero che si puo' calcolare prima di venderlo.
                 */
                TextColumn::make('per_iscritto')
                    ->label('Token per iscritto')
                    ->getStateUsing(function (Tenant $r): string {
                        $tetto = $r->tokensPerMember();

                        return $tetto === null
                            ? 'illimitato'
                            : number_format($tetto, 0, ',', '.');
                    })
                    ->badge()
                    ->color(fn (Tenant $r): string => $r->tokensPerMember() === null ? 'warning' : 'gray')
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateHeading('Nessun consumo AI questo mese');
    }
}
