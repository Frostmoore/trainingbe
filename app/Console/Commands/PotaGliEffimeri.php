<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Message;
use Illuminate\Console\Command;

/**
 * Svuota le buste usa e getta scadute — N16.5.
 *
 * ── 🚨 Perché non basta la cancellazione all'apertura ──────────────────────
 *
 * Perché all'apertura si ferma **solo la consegna a chi riceve**: la busta resta
 * sul server per le ventiquattro ore di chi l'ha mandata. E se il destinatario
 * non apre **mai** — telefono spento, ferie, app disinstallata — non si
 * fermerebbe nemmeno quella.
 *
 * ⚠️ **Senza questa passata, un messaggio «una volta sola» resterebbe sul server
 * per sempre**, che è l'esatto contrario di quello che ha chiesto chi l'ha
 * mandato. E nessuno se ne accorgerebbe: dall'app sembrerebbe sparito.
 *
 * ── 💡 Si svuota la busta, non si cancella la riga ─────────────────────────
 *
 * `body` e `nonce` diventano stringhe vuote; id, mittente e data restano. Un
 * messaggio che sparisce dal mezzo di una conversazione sembra un guasto; una
 * traccia che dice «Messaggio effimero» sembra quello che è.
 *
 * ── ⚠️ Il controllo vive anche in lettura ─────────────────────────────────
 *
 * `Message::consegnabileA()` decide **a ogni risposta HTTP**, e questo comando è
 * la seconda metà: senza il controllo in lettura, fra una passata e l'altra un
 * messaggio già visto continuerebbe a essere consegnato; senza il comando, le
 * buste resterebbero scritte sul disco del server. Servono entrambi.
 */
class PotaGliEffimeri extends Command
{
    protected $signature = 'chat:pota-effimeri {--dry-run : Dice cosa svuoterebbe senza svuotarlo}';

    protected $description = 'Svuota le buste dei messaggi usa e getta viste o scadute';

    public function handle(): int
    {
        $prova = (bool) $this->option('dry-run');

        $scadenza = now()->subHours(Message::ORE_PER_CHI_MANDA);

        /*
         * 🚨 **Il criterio e' uno solo: la scadenza di chi ha mandato.**
         *
         * Verrebbe da svuotare anche le buste gia' viste — sono servite, no? No:
         * ⚠️ chi le ha mandate ha ancora diritto di rileggerle fino alle sue
         * ventiquattro ore, e svuotarle prima gli toglierebbe la busta nel
         * momento in cui l'altro apre. E' esattamente il comportamento
         * imprevedibile che i due orologi esistono per evitare.
         *
         * 💡 Che a chi riceve non tornino piu' lo garantisce
         * `Message::consegnabileA()`, che decide **a ogni risposta HTTP**. Questo
         * comando e' la seconda meta': toglie i byte dal disco quando non
         * servono piu' a nessuno.
         */
        $query = Message::query()
            ->where('usa_e_getta', true)
            ->whereNull('svuotato_il')
            ->where('created_at', '<=', $scadenza);

        $quante = 0;

        foreach ($query->cursor() as $messaggio) {
            $quante++;

            if (! $prova) {
                $messaggio->svuota();
            }
        }

        $this->info(($prova ? '[prova] ' : '').'Buste effimere svuotate: '.$quante);

        return self::SUCCESS;
    }
}
