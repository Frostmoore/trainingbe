<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Promozioni\Widgets;

use App\Models\Promozione;
use App\Services\Promozioni\NumeriDellaPromozione;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * 📊 **I numeri di una promozione** — 24/09/2026, P7 di `plan_promozioni.md`.
 *
 * 📌 *«quante persone l'hanno usata, quanti gettoni regalati, quanti € scontati,
 * e quanto ha fruttato»*. Il conto sta in `NumeriDellaPromozione`.
 */
class INumeriDellaPromozione extends StatsOverviewWidget
{
    protected static bool $isLazy = false;

    public ?Promozione $record = null;

    protected function getStats(): array
    {
        if ($this->record === null) {
            return [];
        }

        $n = app(NumeriDellaPromozione::class)->di($this->record);
        $euro = static fn (int $cent): string => number_format($cent / 100, 2, ',', '.').' €';

        return [
            Stat::make('Persone', (string) $n['persone'])
                ->description($n['volte'].' volte in tutto'.($n['revocate'] > 0 ? ' · '.$n['revocate'].' revocate' : '')),
            Stat::make('Gettoni regalati', (string) $n['gettoni']),
            Stat::make('Scontato', $euro($n['scontato_cent'])),
            Stat::make('Incassato insieme', $euro($n['incassato_cent']))
                ->description('I pagamenti in cui è stata usata (dal 22/09/2026).'),
        ];
    }
}
