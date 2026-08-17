<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Food;
use App\Services\Nutrition\Catalogo\AlimentoAmmissibile;
use App\Services\Nutrition\Catalogo\ChiaveAlimento;
use App\Services\Nutrition\Catalogo\GerarchiaFonti;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * L'aggiornamento settimanale da Open Food Facts — 17/08/2026.
 *
 * ── 🚨 Perche' NON riscarica l'esportazione completa ───────────────────────
 *
 * Perche' e' **1,27 GB**, e questo server e' condiviso con altri due siti. Un
 * download di quella taglia una volta a settimana, piu' la scansione di quattro
 * milioni di righe, e' un peso che ricade su `lepatenti.it` e
 * `aeternagrandtour.it` senza che c'entrino niente.
 *
 * ── ⚠️ E perche' NON usa gli export incrementali ──────────────────────────
 *
 * Sembravano la risposta ovvia: Open Food Facts pubblica un delta al giorno e
 * ne tiene tredici. 🚨 **Verificato il 17/08: i delta NON contengono i
 * nutrienti.** Su 6.281 prodotti di un file, quelli con `nutriments` compilato
 * erano **zero**. Sono utili a chi segue le modifiche redazionali, inservibili
 * per noi.
 *
 * 💡 Quello che funziona e' l'**API di ricerca ordinata per data di modifica**:
 * si scorrono le pagine dalla piu' recente e ci si ferma appena si arriva a
 * prodotti gia' visti. In una settimana i prodotti italiani modificati sono
 * qualche migliaio: qualche decina di richieste, non un gigabyte.
 *
 * ── 💡 Il segnaposto puo' sparire senza danno ─────────────────────────────
 *
 * L'ultima data lavorata sta in cache. Se si perde, si riparte da una finestra
 * di sicurezza di otto giorni: si rilavora qualche migliaio di prodotti e non
 * succede niente, perche' la scrittura e' idempotente. ⚠️ Un segnaposto che,
 * perdendosi, obbligasse a una reimportazione completa sarebbe un guasto
 * travestito da ottimizzazione.
 */
class AggiornaAlimentiOpenFoodFacts extends Command
{
    protected $signature = 'alimenti:aggiorna-off
        {--giorni=8 : Finestra di sicurezza quando non c\'e\' un segnaposto}
        {--max-pagine=200 : Tetto di sicurezza sulle richieste}
        {--pausa=12 : Secondi fra una pagina e l\'altra}';

    protected $description = 'Aggiorna il catalogo con i prodotti Open Food Facts modificati di recente';

    private const SEGNAPOSTO = 'off.ultima_modifica_lavorata';

    private const NOTA = 'Fonte: Open Food Facts (openfoodfacts.org) — dati ODbL 1.0, immagini CC BY-SA 3.0';

    private const PER_PAGINA = 100;

    /**
     * 🚨 **Open Food Facts consente 10 ricerche al minuto**, e non e' una
     * raccomandazione: superandole risponde «Page temporarily unavailable» a
     * tutto, compreso quello che stava funzionando.
     *
     * ⚠️ Verificato sul campo il 17/08: la prima versione chiedeva le pagine di
     * fila ed e' stata bloccata alla prima. 💡 Sette secondi fra una pagina e
     * l'altra sarebbero bastati sulla carta, ma sul campo la seconda pagina e'
     * stata rifiutata lo stesso: il limite vero e' piu' stretto di quello
     * documentato, o tiene conto di una finestra piu' lunga. 💡 Dodici secondi
     * fanno cinque richieste al minuto — mezzo limite — e qualche decina di
     * pagine diventa una decina di minuti, di notte, senza che nessuno se ne
     * accorga.
     */
    private const PAUSA_MINIMA = 10;

    /**
     * 🚨 Open Food Facts chiede che i programmi si identifichino con un
     * contatto. Non e' burocrazia: e' come fanno a scrivere a qualcuno invece
     * di bloccare un indirizzo che si comporta male.
     */
    private GerarchiaFonti $gerarchia;

    private const AGENTE = 'TrainingCompanion/1.0 (https://training.riccardoronconi.it)';

    public function handle(ChiaveAlimento $chiavi, AlimentoAmmissibile $filtro, GerarchiaFonti $gerarchia): int
    {
        $this->gerarchia = $gerarchia;

        $daQuando = Cache::get(self::SEGNAPOSTO)
            ?? now()->subDays((int) $this->option('giorni'))->timestamp;

        $this->info('Prodotti modificati dopo il '.date('d/m/Y H:i', (int) $daQuando));

        $piuRecente = (int) $daQuando;
        $pagina = 1;
        $scritti = $scartati = 0;
        $maxPagine = (int) $this->option('max-pagine');

        /*
         * 🚨 **Il segnaposto si sposta solo se si e' arrivati in fondo.**
         *
         * ── Il difetto che questa variabile ha chiuso ──────────────────────
         *
         * L'ordine e' **decrescente**: i prodotti piu' recenti stanno a pagina
         * 1. La prima versione spostava il segnaposto alla data piu' recente
         * vista **anche quando si era fermata a meta'** — e siccome la piu'
         * recente e' sempre a pagina 1, l'esecuzione successiva sarebbe
         * ripartita da li' **saltando per sempre** tutto quello che stava nelle
         * pagine non lette.
         *
         * ⚠️ Nessun errore, nessun log: solo prodotti che non arrivano mai.
         *
         * 💡 Fermandosi senza spostare il segnaposto, la volta dopo si rifa'
         * anche la parte gia' fatta. Non costa niente, perche' la scrittura e'
         * idempotente.
         */
        $arrivatoInFondo = false;

        while ($pagina <= $maxPagine) {
            $prodotti = $this->pagina($pagina);

            if ($prodotti === null) {
                // ⚠️ Si ferma senza spostare il segnaposto: alla prossima
                // esecuzione si ricomincia da dove si era arrivati, invece di
                // saltare i prodotti che non si sono potuti leggere.
                $this->warn("Pagina {$pagina} non leggibile: mi fermo qui.");
                break;
            }

            if ($prodotti === []) {
                // 💡 Pagine finite: si e' visto tutto quello che c'era.
                $arrivatoInFondo = true;
                break;
            }

            $arrivatoAlGiaVisto = false;

            foreach ($prodotti as $p) {
                $modificato = (int) ($p['last_modified_t'] ?? 0);

                // 🚨 L'ordine e' decrescente: il primo prodotto piu' vecchio del
                // segnaposto vuol dire che da qui in giu' e' tutto gia' visto.
                if ($modificato > 0 && $modificato <= $daQuando) {
                    $arrivatoAlGiaVisto = true;
                    break;
                }

                $piuRecente = max($piuRecente, $modificato);

                $this->salva($p, $chiavi, $filtro) ? $scritti++ : $scartati++;
            }

            if ($arrivatoAlGiaVisto) {
                $arrivatoInFondo = true;
                break;
            }

            $this->line("  pagina {$pagina}: scritti {$scritti}, scartati {$scartati}");
            $pagina++;

            // ⚠️ La pausa e' **fra** le pagine, non prima della prima: la prima
            // richiesta non ha nessuno da cui distanziarsi.
            sleep(max(self::PAUSA_MINIMA, (int) $this->option('pausa')));
        }

        if ($pagina > $maxPagine) {
            // 💡 Non e' un errore ma va detto: vuol dire che e' passato troppo
            // tempo dall'ultima esecuzione, e la prossima riprendera' da dove
            // questa e' arrivata.
            $this->warn('Raggiunto il tetto delle pagine: il recupero continuera\' alla prossima esecuzione.');
        }

        if ($arrivatoInFondo) {
            Cache::forever(self::SEGNAPOSTO, $piuRecente);
        } else {
            $this->warn('Interrotto a meta\': il segnaposto resta dov\'era, e la prossima esecuzione rifara\' questo tratto.');
        }

        $esito = "Open Food Facts: {$scritti} aggiornati, {$scartati} scartati dai filtri.";
        $this->info($esito);
        Log::info($esito);

        return self::SUCCESS;
    }

    /**
     * Una pagina di risultati, oppure `null` se non si e' potuta leggere.
     *
     * @return list<array<string, mixed>>|null
     */
    private function pagina(int $numero): ?array
    {
        $risposta = rescue(fn () => Http::withHeaders(['User-Agent' => self::AGENTE])
            ->timeout(60)
            // 🚨 Tre tentativi distanziati di 30 secondi, e non i soliti due da
            // cinque: quando Open Food Facts risponde «temporaneamente non
            // disponibile» e' perche' e' sotto pressione, e riprovare subito
            // aggiunge pressione invece di risolvere.
            ->retry(3, 30000, throw: false)
            ->get('https://world.openfoodfacts.org/api/v2/search', [
                'countries_tags_en' => 'Italy',
                'sort_by' => 'last_modified_t',
                'page_size' => self::PER_PAGINA,
                'page' => $numero,
                // 💡 Si chiedono **solo** i campi che servono: la risposta piena
                // di un prodotto e' un documento con centinaia di chiavi, e su
                // migliaia di prodotti la differenza e' fra megabyte e decine.
                'fields' => 'code,product_name,brands,last_modified_t,image_url,image_small_url,nutriments',
            ]), null, false);

        if ($risposta === null || ! $risposta->successful()) {
            return null;
        }

        /*
         * ⚠️ **Una risposta con codice 200 puo' non essere JSON.**
         *
         * Quando il limite di frequenza scatta, Open Food Facts restituisce una
         * **pagina HTML** di cortesia. 🚨 `json()` su quella torna `null`, e
         * `null ?? []` sarebbe stato letto come «nessun prodotto modificato»:
         * il comando avrebbe spostato il segnaposto e dichiarato successo dopo
         * non aver importato niente.
         */
        $prodotti = $risposta->json('products');

        return is_array($prodotti) ? $prodotti : null;
    }

    /** @param  array<string, mixed>  $p */
    private function salva(array $p, ChiaveAlimento $chiavi, AlimentoAmmissibile $filtro): bool
    {
        $nome = trim((string) ($p['product_name'] ?? ''));

        if (mb_strlen($nome) < 3) {
            return false;
        }

        $n = $p['nutriments'] ?? [];

        $per100 = [
            'kcal_100' => $this->numero($n['energy-kcal_100g'] ?? null),
            'protein_100' => $this->numero($n['proteins_100g'] ?? null),
            'carbs_100' => $this->numero($n['carbohydrates_100g'] ?? null),
            'fat_100' => $this->numero($n['fat_100g'] ?? null),
        ];

        if (! $filtro->ammesso($nome, $per100, ['da_fonte' => true])) {
            return false;
        }

        $marca = $this->primaMarca((string) ($p['brands'] ?? ''));

        $chiave = $chiavi->da($nome, $marca);

        if (! $this->gerarchia->puoScrivere(Food::query()->where('chiave', $chiave)->first(), GerarchiaFonti::RANGO_OFF)) {
            return false;
        }

        Food::query()->updateOrCreate(
            ['chiave' => $chiave],
            [
                'nome' => mb_substr($nome, 0, 120),
                'marca' => $marca,
                ...$per100,
                'basis' => 'g',
                'origine' => Food::ORIGINE_MANUALE,
                'fonte' => 'OFF:'.($p['code'] ?? ''),
                'note' => self::NOTA,
                'codice_a_barre' => mb_substr((string) ($p['code'] ?? ''), 0, 20) ?: null,
                'immagine_url' => $this->url((string) ($p['image_url'] ?? '')),
                'immagine_piccola_url' => $this->url((string) ($p['image_small_url'] ?? '')),
                'pubblico' => true,
                'conferme' => Food::CONFERME_PER_PUBBLICARE,
            ],
        );

        return true;
    }

    private function numero(mixed $valore): ?float
    {
        return is_numeric($valore) ? (float) $valore : null;
    }

    private function primaMarca(string $brands): ?string
    {
        $prima = trim(explode(',', $brands)[0]);

        return $prima === '' ? null : mb_substr($prima, 0, 60);
    }

    private function url(string $valore): ?string
    {
        return str_starts_with($valore, 'https://') ? mb_substr($valore, 0, 255) : null;
    }
}
