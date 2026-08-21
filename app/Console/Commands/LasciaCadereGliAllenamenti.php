<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lascia cadere le tabelle degli allenamenti — FASE 11.6, 21/08/2026.
 *
 * == 🚨 PERCHE' UN COMANDO E NON UNA MIGRAZIONE =============================
 *
 * Una migrazione gira **dentro il deploy**, e questa cancellazione non deve
 * poterlo fare mai:
 *
 * | | Migrazione | Comando |
 * |---|---|---|
 * | Se qualcuno non ha traslocato | il deploy **fallisce a meta'** | non parte, e basta |
 * | Quando succede | quando capita un deploy | quando lo si decide |
 * | Chi la lancia | nessuno | una persona che sa cosa sta facendo |
 *
 * ⚠️ Con `set -e`, una migrazione che si rifiuta di girare interromperebbe il
 * deploy **dopo** `composer install` e **prima** delle cache: il server
 * resterebbe in uno stato peggiore di quello di partenza. 🚨 E lo farebbe per
 * proteggere qualcuno, il che e' il modo peggiore di rompere qualcosa.
 *
 * -- ⛔ Cosa NON fa ---------------------------------------------------------
 *
 * Non parte se **anche un solo** utente non ha confermato il trasloco: quei
 * dati sono l'unica copia esistente dei suoi allenamenti, e per lui la
 * cancellazione e' irreversibile.
 *
 * 💡 Non c'e' nessuna scadenza, ed e' voluto: chi non apre l'app tiene i suoi
 * dati sul server piu' a lungo, e va bene. E' il compromesso di D2 passo 4 in
 * `plan_tutto_sul_telefono.md`.
 */
class LasciaCadereGliAllenamenti extends Command
{
    protected $signature = 'allenamenti:lascia-cadere {--forza : Cancella anche se qualcuno non ha traslocato}';

    protected $description = 'FASE 11.6 — toglie workout_sessions, session_sets e daily_burns, ma solo quando tutti hanno traslocato';

    /** ⚠️ L'ordine conta: le serie prima delle sedute, o restano orfane. */
    private const TABELLE = ['session_sets', 'workout_sessions', 'daily_burns'];

    public function handle(): int
    {
        $mancanti = DB::table('users')->whereNull('workouts_migrated_at')->count();
        $totale = DB::table('users')->count();

        $this->line("Utenti: {$totale}, non ancora traslocati: {$mancanti}");

        foreach (self::TABELLE as $t) {
            if (Schema::hasTable($t)) {
                $this->line("  {$t}: ".DB::table($t)->count().' righe');
            } else {
                $this->line("  {$t}: gia' caduta");
            }
        }

        if ($mancanti > 0 && ! $this->option('forza')) {
            /*
             * ⛔ **Il rifiuto e' il punto di tutto il comando.**
             *
             * 🚨 Meglio non cancellare che cancellare per sbaglio: chi non ha
             * confermato riprova al prossimo avvio dell'app, chi si vede
             * cancellare i dati non ha nessun modo di riaverli.
             */
            $this->error("⛔ {$mancanti} utenti non hanno ancora portato i loro allenamenti sul telefono.");
            $this->line('   Quei dati sono l\'unica copia che hanno. Non si cancella niente.');
            $this->line('   Con --forza si cancella lo stesso: e\' una decisione, non una scorciatoia.');

            return self::FAILURE;
        }

        if ($mancanti > 0) {
            $this->warn("⚠️ --forza: si cancella con {$mancanti} utenti non traslocati. I loro allenamenti spariscono.");
        }

        foreach (self::TABELLE as $t) {
            Schema::dropIfExists($t);
            $this->info("  ✓ {$t}");
        }

        $this->info('Fatto. Gli allenamenti vivono solo sui telefoni di chi li fa.');

        return self::SUCCESS;
    }
}
