<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 🧹 **I registri si tengono finché servono a funzionare** — 20/09/2026.
 *
 * 📌 Il committente, 20/09: *«tempi di conservazione solo finché serve per
 * funzionare»*.
 *
 * ── 🚨 Perché un comando e non una riga nell'informativa ───────────────────
 *
 * Perché un termine di conservazione scritto e non applicato è **peggio** di
 * un termine lungo: dichiara una cosa che non succede. ⛔ Fino a oggi
 * `audit_logs` e `ai_usage_logs` non li potava nessuno: sarebbero cresciuti
 * per sempre, e l'informativa avrebbe detto il falso dal primo giorno.
 *
 * ── 💡 Perché proprio questi due, e perché questi mesi ─────────────────────
 *
 * | Tabella | Quanto | Perché tanto, e perché non di più |
 * |---|---|---|
 * | `audit_logs` | **12 mesi** | È la traccia che risponde a «chi ha fatto cosa»: serve quando una palestra contesta una disiscrizione o si indaga su un accesso. Un anno copre un ciclo intero di stagioni e contratti; oltre, nessuno la guarda più |
 * | `ai_usage_logs` | **13 mesi** | Riga per riga è il **dettaglio** dei consumi: serve a spiegare una fattura contestata. Un anno più un mese copre la fattura più vecchia ancora discutibile |
 *
 * ⚠️ **Gli importi non si toccano.** `addebiti_mensili` e `ai_credit_movements`
 * restano: sono i **totali** su cui si fattura e il saldo dei gettoni, non il
 * dettaglio di chi ha fatto cosa. 🚨 Potare quelli vorrebbe dire perdere il
 * conto dei soldi — e l'obbligo dei dieci anni (art. 2220 c.c.) riguarda loro,
 * non queste righe.
 *
 * 💡 **La cancellazione dell'account non passa di qui** e resta immediata: là
 * si butta tutto, qui si butta il vecchio di chi c'è ancora.
 */
class PotaIRegistri extends Command
{
    protected $signature = 'registri:pota {--dry-run : Dice quante righe butterebbe senza buttarle}';

    protected $description = 'Butta il registro di controllo e i consumi AI più vecchi del dovuto';

    /** Il registro di controllo: un anno. */
    public const MESI_REGISTRO = 12;

    /** Il dettaglio dei consumi AI: un anno più un mese. */
    public const MESI_CONSUMI = 13;

    public function handle(): int
    {
        $prova = (bool) $this->option('dry-run');

        $quante = 0;

        foreach ([
            'audit_logs' => self::MESI_REGISTRO,
            'ai_usage_logs' => self::MESI_CONSUMI,
        ] as $tabella => $mesi) {
            $limite = now()->subMonths($mesi);

            $query = DB::table($tabella)->where('created_at', '<', $limite);

            $trovate = (int) $query->count();
            $quante += $trovate;

            if (! $prova && $trovate > 0) {
                /*
                 * ⚠️ A blocchi: una `DELETE` su mezzo milione di righe tiene il
                 * lock per minuti, e questo gira su un server condiviso.
                 */
                do {
                    $andate = DB::table($tabella)
                        ->where('created_at', '<', $limite)
                        ->limit(1000)
                        ->delete();
                } while ($andate > 0);
            }

            $this->info(($prova ? '[prova] ' : '')."{$tabella}: {$trovate} righe oltre i {$mesi} mesi");
        }

        $this->info(($prova ? '[prova] ' : '').'Totale: '.$quante);

        return self::SUCCESS;
    }
}
