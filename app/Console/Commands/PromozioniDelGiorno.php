<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\PortafoglioGettoni;
use App\Services\Promozioni\ConsegnaDeiPremi;
use Illuminate\Console\Command;

/**
 * 🗓️ **Il lavoro quotidiano delle promozioni** — 24/09/2026, P2 di
 * `plan_promozioni.md`.
 *
 * Tre giri, in quest'ordine, con `--solo=` per lanciarne uno:
 *
 * | Giro | Cosa fa |
 * |---|---|
 * | `scadenze` | toglie il residuo dei gettoni regalati scaduti (`PortafoglioGettoni::faiScadere`) |
 * | `ricorrenti` | accredita i gettoni del mese (`ConsegnaDeiPremi::accreditaIRicorrenti`) |
 * | `automatiche` | consegna le automatiche con regali a chi ci rientra (`ConsegnaDeiPremi::consegnaLeAutomatiche`) |
 *
 * ⚠️ **Prima le scadenze**: un lotto che scade oggi e un accredito ricorrente
 * di oggi non devono mischiarsi — il nuovo accredito apre un lotto nuovo, e
 * farlo prima renderebbe ambigua la riga «scaduti» del registro.
 */
class PromozioniDelGiorno extends Command
{
    protected $signature = 'promozioni:giro {--solo= : scadenze, ricorrenti o automatiche}';

    protected $description = 'Scadenze dei gettoni regalati, gettoni ricorrenti e promozioni automatiche con regali';

    public function handle(PortafoglioGettoni $portafoglio, ConsegnaDeiPremi $consegna): int
    {
        $solo = $this->option('solo');

        if ($solo === null || $solo === 'scadenze') {
            $this->info('Gettoni scaduti: '.$portafoglio->faiScadere());
        }

        if ($solo === null || $solo === 'ricorrenti') {
            $this->info('Accrediti ricorrenti: '.$consegna->accreditaIRicorrenti());
        }

        if ($solo === null || $solo === 'automatiche') {
            $this->info('Automatiche consegnate: '.$consegna->consegnaLeAutomatiche());
        }

        return self::SUCCESS;
    }
}
