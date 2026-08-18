<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AllegatoCifrato;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Butta gli allegati della chat scaduti — N14.3.
 *
 * ── 🚨 Perche' non basta la cancellazione allo scarico ─────────────────────
 *
 * Un allegato viene cancellato appena il destinatario lo scarica. Ma se non lo
 * scarica **mai** — telefono spento, ferie, app disinstallata — quella riga e
 * quel file resterebbero li' per sempre, e crescerebbero.
 *
 * *«restano finche' non le scarica, max 24h»* — il committente, 18/08/2026.
 *
 * ── ⚠️ Anche i file orfani ────────────────────────────────────────────────
 *
 * `AllegatoCifrato::deposita()` scrive **prima il file e poi la riga**, di
 * proposito: nell'ordine inverso esisterebbe un istante in cui la riga promette
 * un file che non c'e'. Il prezzo e' che un guasto fra le due operazioni lascia
 * un file senza riga — e quello non lo cancellerebbe nessuno. Lo raccoglie
 * questa seconda passata.
 */
class PotaGliAllegati extends Command
{
    protected $signature = 'chat:pota-allegati {--dry-run : Dice cosa butterebbe senza buttarlo}';

    protected $description = 'Butta gli allegati cifrati della chat scaduti, e i file rimasti orfani';

    public function handle(): int
    {
        $prova = (bool) $this->option('dry-run');

        $scaduti = AllegatoCifrato::query()
            ->where('scade_il', '<=', now())
            ->get();

        foreach ($scaduti as $allegato) {
            if (! $prova) {
                $allegato->butta();
            }
        }

        $this->info(($prova ? '[prova] ' : '').'Allegati scaduti: '.$scaduti->count());

        $orfani = $this->orfani($prova);

        $this->info(($prova ? '[prova] ' : '').'File orfani: '.$orfani);

        return self::SUCCESS;
    }

    /**
     * I file sul disco che non hanno piu' una riga.
     *
     * 💡 Si confronta con **tutti** i token, non solo con quelli scaduti: un
     * file il cui token non esiste in tabella e' orfano per definizione, e non
     * c'e' nessun altro modo di accorgersene.
     */
    private function orfani(bool $prova): int
    {
        $disco = Storage::disk('local');

        if (! $disco->exists(AllegatoCifrato::CARTELLA)) {
            return 0;
        }

        $vivi = AllegatoCifrato::query()->pluck('token')->flip();
        $buttati = 0;

        foreach ($disco->files(AllegatoCifrato::CARTELLA) as $percorso) {
            $nome = basename($percorso);

            if ($vivi->has($nome)) {
                continue;
            }

            /*
             * ⚠️ **Solo i file abbastanza vecchi.**
             *
             * Fra la scrittura del file e la creazione della riga passa un
             * istante: se il comando girasse proprio li' in mezzo, butterebbe
             * un allegato appena depositato e valido. Un'ora di margine rende
             * quella corsa impossibile.
             */
            if ($disco->lastModified($percorso) > now()->subHour()->getTimestamp()) {
                continue;
            }

            if (! $prova) {
                $disco->delete($percorso);
            }

            $buttati++;
        }

        return $buttati;
    }
}
