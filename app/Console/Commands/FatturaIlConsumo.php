<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\AddebitoDelMese;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * 💶 Il giro mensile del piano a consumo — 3b-AH.6.9 (17/09/2026).
 *
 * ══ ⚠️ PERCHE' FATTURA IL MESE **APPENA CHIUSO** ══════════════════════════
 *
 * Perche' a consumo si paga **quello che si e' consumato**, e finche' il mese
 * e' aperto quel numero cambia ancora. ⛔ Fatturare il primo del mese il mese
 * che comincia vorrebbe dire chiedere in anticipo soldi per iscritti che
 * potrebbero non arrivare mai — e sarebbe un canone, cioe' l'altro piano.
 *
 * 💡 Quindi gira il **primo del mese** e conta il mese **precedente**.
 *
 * ══ 🚨 E PERCHE' SI PUO' CHIAMARE A MANO SENZA PAURA ══════════════════════
 *
 * `unique(tenant_id, periodo)` su `addebiti_mensili`: un secondo giro trova la
 * riga gia' scritta e non ne crea un'altra. ⛔ Senza quel vincolo, «lo rilancio
 * che non sono sicuro sia partito» sarebbe il modo piu' facile di fatturare due
 * volte lo stesso mese.
 */
class FatturaIlConsumo extends Command
{
    protected $signature = 'fattura:il-consumo
        {--periodo= : Il mese da fatturare, YYYY-MM. Di serie: quello appena chiuso.}
        {--senza-incasso : Calcola e scrive gli addebiti, ma non manda niente a Stripe.}';

    protected $description = 'Conta gli iscritti e fattura il mese alle palestre sul piano a consumo';

    public function handle(AddebitoDelMese $addebiti): int
    {
        $periodo = (string) ($this->option('periodo')
            ?: AddebitoDelMese::periodoDi(Carbon::now()->subMonthNoOverflow()));

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $periodo) !== 1) {
            /*
             * ⛔ **Si rifiuta invece di aggiustare.** Un `2026-9` accettato e
             * normalizzato in `2026-09` sembrerebbe gentile, ma il vincolo di
             * unicita' e' su una stringa: il giorno che qualcuno scrive la
             * variante non normalizzata da un'altra parte, lo stesso mese viene
             * fatturato due volte senza violare niente.
             */
            $this->error("«{$periodo}» non e' un periodo: si scrive YYYY-MM.");

            return self::FAILURE;
        }

        $incassa = ! (bool) $this->option('senza-incasso');

        $esito = $addebiti->giroDelMese($periodo, $incassa);

        $this->info("Periodo {$periodo}: {$esito['calcolati']} addebiti calcolati.");

        if ($incassa) {
            $this->line("  incassati: {$esito['incassati']}");
            $this->line("  senza cliente Stripe: {$esito['senza_cliente']}");

            if ($esito['falliti'] > 0) {
                $this->warn("  falliti: {$esito['falliti']} — guarda `errore` sulla riga prima di riprovare.");
            }
        }

        Log::info('Giro di fatturazione a consumo.', ['periodo' => $periodo] + $esito);

        /*
         * ⚠️ **Un fallimento non fa fallire il comando.** Le righe fallite sono
         * scritte e si vedono; un exit code diverso da zero farebbe riprovare
         * l'intero giro allo scheduler, ricalcolando niente e ritentando tutto.
         */
        return self::SUCCESS;
    }
}
