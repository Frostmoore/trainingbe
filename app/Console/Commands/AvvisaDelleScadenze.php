<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\LAvvisoDellaScadenza;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * ⏳ **Avvisa chi sta per scadere** — voce 62, 27/09/2026.
 *
 * ══ ⚠️ ANCHE QUESTO SCRIVE SOLO CON `--scrivi` ════════════════════════════
 *
 * 🚨 È la convenzione dei comandi che **toccano i clienti** (`SospendiIMorosi`,
 * `RiportaACasa`, `DaiUnaCasaATutti`): di serie guardano ed elencano.
 *
 * 💡 Qui non toglie niente a nessuno, ma manda **notifiche vere a telefoni
 * veri**: ⛔ una prova fatta per sbaglio con `--scrivi` non si ritira, e
 * `scadenza_avvisata_il` resta segnata — cioè l'avviso vero, quello utile, non
 * partirebbe più.
 *
 * ⚖️ Senza `--scrivi` è lo strumento per rispondere a *«chi verrebbe avvisato
 * stanotte?»* senza avvisare nessuno.
 */
class AvvisaDelleScadenze extends Command
{
    protected $signature = 'abbonamenti:avvisa-delle-scadenze {--scrivi : Manda davvero le notifiche}';

    protected $description = 'Avvisa chi ha un abbonamento che scade fra pochi giorni e non si rinnova da sé';

    public function handle(LAvvisoDellaScadenza $avviso): int
    {
        $scrivi = (bool) $this->option('scrivi');

        $fatti = $avviso->giro($scrivi);

        $this->line(sprintf(
            'Abbonamenti in scadenza entro %d giorni, non rinnovabili e non ancora avvisati: %d',
            LAvvisoDellaScadenza::GIORNI_PRIMA,
            count($fatti),
        ));

        if ($fatti !== []) {
            $this->line('  id: '.implode(', ', $fatti));
        }

        if (! $scrivi) {
            $this->newLine();
            $this->comment('Nessuna notifica è partita. Per mandarle: --scrivi');

            return self::SUCCESS;
        }

        /*
         * 📝 Si scrive nel registro **solo se è partito qualcosa**: ⚠️ un giro
         * notturno che logga sempre riempie il registro di righe vuote, e la riga
         * che conta diventa invisibile. 💡 È la stessa scelta di `SospendiIMorosi`.
         */
        if ($fatti !== []) {
            Log::info('Avvisi di scadenza mandati.', ['abbonamenti' => $fatti]);
        }

        return self::SUCCESS;
    }
}
