<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Billing\LaSospensioneAutomatica;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 🤖 **Sospende chi non paga, e riattiva chi ha ripagato** — 27/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * 📌 Il committente: *«per prima cosa ovviamente facciamo che chi non ha pagato
 * si sospende in automatico»*. ⛔ Prima nessuno lo faceva: lo stato di una
 * palestra morosa restava `active` per sempre, e l'avviso «devi pagare» — che
 * guarda quello stato — **non compariva mai**.
 *
 * ══ ⚠️ PERCHE' SI SCRIVE SOLO CON `--scrivi` ══════════════════════════════
 *
 * 🚨 È la convenzione dei comandi di questo progetto che **toccano i clienti**
 * (`RiportaACasa`, `DaiUnaCasaATutti`): di serie guardano ed elencano, e scrivono
 * solo se glielo si chiede. ⛔ Qui pesa più che altrove, perché questo comando
 * **toglie l'accesso**: doverlo scrivere per esteso nel programmatore notturno è
 * un modo di renderlo visibile a chi lo legge.
 *
 * 💡 E senza `--scrivi` è lo strumento per rispondere a *«chi verrebbe sospeso
 * stanotte?»* senza sospendere nessuno.
 */
class SospendiIMorosi extends Command
{
    protected $signature = 'abbonamenti:sospendi-i-morosi {--scrivi : Applica davvero}';

    protected $description = 'Sospende gli spazi con l\'abbonamento finito, e riattiva quelli che hanno ripagato';

    public function handle(LaSospensioneAutomatica $macchina): int
    {
        $scrivi = (bool) $this->option('scrivi');

        $esito = $macchina->giro($scrivi);

        $this->riga('Da sospendere', $esito['sospesi']);
        $this->riga('Da riattivare', $esito['riattivati']);

        if (! $scrivi) {
            $this->newLine();
            $this->comment('Niente è stato cambiato. Per applicare: --scrivi');

            return self::SUCCESS;
        }

        /*
         * 📝 **Si scrive nel registro anche quando non è successo niente?** No.
         * ⚠️ Un giro notturno che logga sempre riempie il registro di righe
         * vuote, e la riga che conta diventa invisibile. 💡 Si logga solo quando
         * uno stato è **cambiato**, perché è l'unica cosa che qualcuno cercherà.
         */
        if ($esito['sospesi'] !== [] || $esito['riattivati'] !== []) {
            Log::info('Sospensione automatica: giro fatto.', [
                'sospesi' => $esito['sospesi'],
                'riattivati' => $esito['riattivati'],
            ]);
        }

        return self::SUCCESS;
    }

    /** @param  list<string>  $spazi */
    private function riga(string $titolo, array $spazi): void
    {
        if ($spazi === []) {
            $this->line("{$titolo}: nessuno.");

            return;
        }

        $this->line("{$titolo}: ".count($spazi));

        foreach ($spazi as $spazio) {
            $this->line('  · '.$spazio);
        }
    }
}
