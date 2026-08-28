<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Exercise;
use App\Support\Tenancy\TenantContext;
use App\Support\Training\IllustrazioniDegliEsercizi;
use Illuminate\Console\Command;

/**
 * Mette il disegno su ogni esercizio della piattaforma — 3b-L, 28/08/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«Scarica i svg e mettili su tutti gli esercizi»*.
 *
 * ── 🚨 Perche' un comando e non un seeder ─────────────────────────────────
 *
 * ⛔ Un seeder gira su un database vuoto. Qui i file pesano **7,8 MB** e vanno
 * copiati nello storage: farlo dentro `db:seed` vorrebbe dire ricopiarli a
 * ogni `migrate:fresh` di ogni sviluppatore, e sui test — dove non servono
 * **mai** — allungherebbe ogni corsa di qualche secondo per niente.
 *
 * 💡 E' **idempotente**: rilanciarlo non rifa' il lavoro gia' fatto, perche'
 * ogni immagine si porta dietro lo slug da cui viene.
 *
 * ── 🚨 `preservingOriginal()` NON E' UN DETTAGLIO ─────────────────────────
 *
 * ⛔ Senza, `addMedia()` **sposta** il file invece di copiarlo: la prima
 * esecuzione svuoterebbe `database/data/illustrazioni/`, cioe' cancellerebbe
 * dal repository i file appena committati. ⚠️ E il secondo lancio non
 * troverebbe piu' niente, senza spiegare perche'.
 *
 * ── ⚠️ Non sovrascrive una foto messa da una persona ──────────────────────
 *
 * 🚨 La collezione e' `singleFile()`: caricare la nostra illustrazione butta
 * via quello che c'era. Se un gestore ha caricato **la foto della macchina di
 * quella palestra**, quella vale piu' di un disegno generico — e' l'ordine di
 * precedenza che l'app applica gia' in `FotoDellEsercizio`. 💡 Quindi qui si
 * salta, a meno di `--forza`.
 */
final class AttaccaLeIllustrazioni extends Command
{
    protected $signature = 'esercizi:illustrazioni
        {--forza : sostituisce anche le immagini caricate a mano}
        {--prova : dice cosa farebbe senza scrivere niente}';

    protected $description = "Attacca l'illustrazione del catalogo a ogni esercizio della piattaforma";

    public function handle(TenantContext $contesto): int
    {
        $cartella = database_path('data/illustrazioni');
        $indice = database_path('data/illustrazioni.jsonl');

        if (! is_file($indice)) {
            $this->error("Manca $indice. Vedi database/data/LEGGIMI.md.");

            return self::FAILURE;
        }

        /*
         * ⚠️ Il credito si legge dal file **accanto ai disegni**, non da una
         * costante nel codice: se un giorno un disegno viene da un'altra
         * fonte, la riga giusta e' quella del suo file.
         */
        $crediti = [];

        foreach (file($indice, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $riga) {
            $r = json_decode($riga, true, flags: JSON_THROW_ON_ERROR);
            $crediti[$r['s']] = ['credito' => $r['c'], 'fonte' => $r['u']];
        }

        $forza = (bool) $this->option('forza');
        $prova = (bool) $this->option('prova');

        $messe = 0;
        $saltate = 0;
        $assenti = [];
        $senzaFile = [];

        $contesto->runWithoutTenant(function () use (
            $cartella, $crediti, $forza, $prova,
            &$messe, &$saltate, &$assenti, &$senzaFile
        ): void {
            foreach (IllustrazioniDegliEsercizi::tutte() as $nome => $slug) {
                $file = "$cartella/$slug.png";

                if (! is_file($file)) {
                    $senzaFile[] = "$nome ($slug)";

                    continue;
                }

                $esercizio = Exercise::withoutGlobalScopes()
                    ->whereNull('tenant_id')
                    ->where('slug_normalized', Exercise::normalize($nome))
                    ->first();

                if ($esercizio === null) {
                    $assenti[] = $nome;

                    continue;
                }

                $gia = $esercizio->getFirstMedia(Exercise::COLLECTION_IMMAGINE);

                if ($gia !== null && ! $forza) {
                    $origine = $gia->getCustomProperty('origine');

                    /*
                     * 🚨 **Tre casi diversi, non due.**
                     *
                     * 1. Stessa origine: e' gia' la nostra, e' quella giusta,
                     *    non c'e' niente da fare.
                     * 2. Origine **assente**: l'ha caricata una persona dal
                     *    pannello. ⛔ Non si tocca: la foto della macchina di
                     *    quella palestra vale piu' di un disegno generico.
                     * 3. Origine **diversa**: ce l'abbiamo messa noi e poi la
                     *    mappa e' cambiata. 💡 Va sostituita, altrimenti un
                     *    disegno sbagliato resterebbe li' per sempre e il
                     *    comando direbbe «tutto a posto».
                     */
                    if ($origine === $slug || $origine === null) {
                        $saltate++;

                        continue;
                    }
                }

                if ($prova) {
                    $messe++;

                    continue;
                }

                $esercizio
                    ->addMedia($file)
                    ->preservingOriginal()
                    ->usingFileName("$slug.png")
                    ->withCustomProperties([
                        'origine' => $slug,
                        'credito' => $crediti[$slug]['credito'] ?? null,
                        'fonte' => $crediti[$slug]['fonte'] ?? null,
                    ])
                    ->toMediaCollection(Exercise::COLLECTION_IMMAGINE);

                $messe++;
            }
        });

        $this->info(($prova ? '[prova] ' : '')."illustrazioni messe: $messe");

        if ($saltate > 0) {
            $this->line("gia' a posto o caricate a mano: $saltate (--forza per sostituirle)");
        }

        if ($assenti !== []) {
            $this->warn('esercizi che non esistono in tabella: '.count($assenti));
            $this->line('  '.implode("\n  ", array_slice($assenti, 0, 15)));
            $this->line('  → manca il seeder? `php artisan db:seed --class=ExerciseLibrarySeeder`');
        }

        if ($senzaFile !== []) {
            $this->error('disegni citati dalla mappa e non presenti: '.count($senzaFile));
            $this->line('  '.implode("\n  ", $senzaFile));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
