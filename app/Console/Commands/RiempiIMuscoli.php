<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Exercise;
use App\Support\Training\MuscoliDegliEsercizi;
use Illuminate\Console\Command;

/**
 * Riempie i muscoli di tutti gli esercizi — 3b-B.2, 24/08/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«riempi tutti gli esercizi con i gruppi muscolari giusti, anche se al
 * momento sono vuoti o mancano i secondari»*.
 *
 * ── 🚨 Perche' un comando e non una migrazione ────────────────────────────
 *
 * ⛔ Una migrazione gira **una volta sola**. Il catalogo invece cresce: ogni
 * palestra crea i suoi esercizi, e ogni volta che la mappa si allarga bisogna
 * poterla riversare di nuovo. ⚠️ Una seconda migrazione «riempi ancora» sarebbe
 * la confessione che la prima era il posto sbagliato.
 *
 * 💡 Ed e' **idempotente**: rilanciarlo non cambia niente se non c'e' niente da
 * cambiare, e lo dice.
 *
 * ── ⛔ Perche' scrive anche sugli esercizi delle palestre ─────────────────
 *
 * 🚨 `ExerciseMatcher::completa()` si **rifiuta** di toccare gli esercizi che
 * non sono della palestra che sta scrivendo, e ha ragione: la' e' un iscritto
 * che scrive una scheda, e non deve poter cambiare il dato di tutti.
 *
 * ⚠️ Qui e' un'altra cosa. Chi lancia questo comando ha accesso al server, sta
 * agendo come piattaforma e risponde di quello che scrive — che e' esattamente
 * il caso in cui quel confine **non** si applica. E il committente l'ha chiesto
 * per nome: i sette esercizi muti in staging sono di una palestra.
 */
final class RiempiIMuscoli extends Command
{
    protected $signature = 'esercizi:muscoli
        {--forza : sovrascrive anche i muscoli gia\' scritti}
        {--prova : dice cosa farebbe senza scrivere niente}';

    protected $description = 'Riempie primario e secondari degli esercizi dalla mappa (fonte: free-exercise-db)';

    public function handle(): int
    {
        $forza = (bool) $this->option('forza');
        $prova = (bool) $this->option('prova');

        $toccati = 0;
        $gia = 0;
        $sconosciuti = [];

        /*
         * ⚠️ `withoutGlobalScopes` e `chunkById`: il catalogo attraversa tutte
         * le palestre, e caricarlo intero in memoria per una tabella che puo'
         * crescere e' un rischio che non serve correre.
         */
        Exercise::withoutGlobalScopes()
            ->orderBy('id')
            ->chunkById(200, function ($esercizi) use (
                $forza,
                $prova,
                &$toccati,
                &$gia,
                &$sconosciuti,
            ): void {
                foreach ($esercizi as $e) {
                    $muscoli = MuscoliDegliEsercizi::di($e->name);

                    if ($muscoli === null) {
                        $sconosciuti[] = $e->name;

                        continue;
                    }

                    $da = [];

                    /*
                     * 💡 Il primario si tocca **solo** se la mappa ne conosce
                     * uno. ⚠️ `null` nella mappa vuol dire «non lo cambio», non
                     * «non lo so»: quelle righe le abbiamo compilate a mano e
                     * sono giuste.
                     */
                    if ($muscoli['primario'] !== null
                        && ($forza || $e->muscle_group === null)) {
                        $da['muscle_group'] = $muscoli['primario'];
                    }

                    /*
                     * 🚨 `secondary_muscles === null` vuol dire «nessuno l'ha
                     * deciso», e va riempito. ⛔ Un elenco **vuoto** invece e'
                     * una decisione presa — «questo esercizio isola» — e si
                     * tocca solo con `--forza`.
                     */
                    if ($forza || $e->secondary_muscles === null) {
                        $da['secondary_muscles'] = $muscoli['secondari'];
                    }

                    if ($da === []) {
                        $gia++;

                        continue;
                    }

                    $toccati++;

                    if ($prova) {
                        $this->line(sprintf(
                            '  %-46s %s',
                            $e->name,
                            json_encode($da, JSON_UNESCAPED_UNICODE),
                        ));

                        continue;
                    }

                    $e->forceFill($da)->save();
                }
            });

        $this->info(sprintf(
            '%s: %d aggiornati, %d gia\' a posto, %d senza una voce in mappa.',
            $prova ? 'Prova' : 'Fatto',
            $toccati,
            $gia,
            count($sconosciuti),
        ));

        /*
         * 🚨 **Gli sconosciuti si stampano**, non si contano e basta: sono
         * esattamente le righe che restano mute, ed e' l'unico momento in cui
         * qualcuno le vede tutte insieme.
         */
        if ($sconosciuti !== []) {
            $this->warn('Senza una voce in mappa:');

            foreach (array_unique($sconosciuti) as $nome) {
                $this->line('  · '.$nome);
            }
        }

        return self::SUCCESS;
    }
}
