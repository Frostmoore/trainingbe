<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\PacchettoGettoni;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * 🌱 **Il seme dei pacchetti di gettoni** — 22/09/2026, fase F2.
 *
 * ══ 🚨 PERCHÉ QUESTO SEEDER NON SI COMPORTA COME `PlanSeeder` ═════════════
 *
 * `PlanSeeder` fa `updateOrCreate` e **riscrive** i piani a ogni deploy: lì è
 * giusto, perché il listino dei piani vive nel file.
 *
 * ⛔ Qui no. Dal 22/09 i prezzi dei gettoni si cambiano **dal pannello**, e un
 * seeder che riscrive riporterebbe indietro il prezzo **al primo deploy**, in
 * silenzio: il sito venderebbe a 3,00 € un taglio che il committente aveva
 * alzato a 3,50 €, e nessuno se ne accorgerebbe fino all'estratto conto.
 *
 * 💡 Quindi la regola è: **crea ciò che manca, non tocca ciò che esiste** se
 * qualcuno l'ha modificato a mano. È la stessa difesa di `plans.cucito_a_mano`.
 *
 * ⚠️ `config/listino.php` resta, e resta utile: è il **seme** di
 * un'installazione nuova, ed è versionato — cioè la sola copia dei prezzi che
 * un `git log` sa raccontare.
 */
class PacchettiGettoniSeeder extends Seeder
{
    public function run(): void
    {
        foreach (config('listino.pacchetti') as $ordine => $pacchetto) {
            $codice = self::codiceDi($pacchetto);

            $esistente = PacchettoGettoni::query()->where('codice', $codice)->first();

            /*
             * 🚨 Toccato a mano = non si tocca più. ⛔ Senza questo `continue`,
             * l'intera ragione per cui la tabella esiste verrebbe annullata dal
             * primo deploy.
             */
            if ($esistente !== null && $esistente->modificato_a_mano) {
                continue;
            }

            PacchettoGettoni::query()->updateOrCreate(
                ['codice' => $codice],
                [
                    'nome' => $pacchetto['nome'],
                    'gettoni' => $pacchetto['gettoni'],
                    'prezzo_cent' => $pacchetto['prezzo_cent'],
                    'nota' => $pacchetto['nota'] ?? null,
                    'ordine' => $ordine,

                    /*
                     * ⚠️ `attivo` **non si riscrive** su una riga che c'è già:
                     * se qualcuno ha sospeso un taglio dal pannello senza
                     * toccarne il prezzo, il deploy non deve rimetterlo in
                     * vendita.
                     */
                    ...($esistente === null ? ['attivo' => true] : []),
                ],
            );
        }
    }

    /**
     * Il codice stabile di un taglio.
     *
     * 💡 Si ricava dal **numero di gettoni**, non dal nome: «Prova» un giorno
     * si chiamerà «Assaggio» perché lo decide il marketing, e il codice deve
     * restare quello — ci puntano i `metadata` di Stripe e la storia degli
     * acquisti.
     *
     * @param  array<string, mixed>  $pacchetto
     */
    public static function codiceDi(array $pacchetto): string
    {
        return 'gettoni_'.$pacchetto['gettoni'];
    }

    /** Un codice per un taglio cucito a mano dal pannello. */
    public static function codicePerNuovo(int $gettoni): string
    {
        return 'gettoni_'.$gettoni.'_'.Str::lower(Str::random(4));
    }
}
