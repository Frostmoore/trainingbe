<?php

declare(strict_types=1);

namespace App\Services\Scoperta;

use App\Models\Comune;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Il selettore di citta' — 18/08/2026. **M1.2.** 🆕 Riscritto il 23/09/2026 per
 * il mondo (fase F6.1).
 *
 * ── 🚨 L'ordinamento e' tutto il servizio ──────────────────────────────────
 *
 * Trovare i comuni che cominciano per «mil» e' banale. Il punto e' **quale
 * mettere per primo**: in ordine alfabetico verrebbero `Milanesi`, `Milena`,
 * `Milazzo`, e `Milano` compare quarto. ⚠️ Un selettore di citta' in cui il
 * capoluogo non e' fra i primi e' un selettore che le persone smettono di usare
 * dopo due tentativi.
 *
 * ══ 🆕 🌍 COSA È CAMBIATO DIVENTANDO MONDIALI ═════════════════════════════
 *
 * La vecchia versione diceva, a ragione: *«qui le righe sono ottomila, e non
 * cresceranno mai»*. ⛔ **Sono diventate 232.000**, e il `LIKE '%x%'` che su
 * ottomila righe costava meno di un millisecondo è diventato **tre secondi a
 * ogni tasto** — misurato, non stimato.
 *
 * E con i numeri sono arrivati tre difetti di qualità, tutti visti sul
 * database vero:
 *
 * | Si scriveva | Usciva per primo | Mancava |
 * |---|---|---|
 * | `bolo` | *Bolo*, un villaggio delle Filippine | **Bologna** |
 * | `emilia` | *Emiliano Zapata* ×20, Messico | **Reggio nell'Emilia** |
 * | `munch` | *Münchenstein* (Svizzera) | **Monaco di Baviera** — che GeoNames chiama `Munich` |
 *
 * ══ ✅ COME FUNZIONA ADESSO: CINQUE GRADINI ═══════════════════════════════
 *
 * | Gradino | Cosa | Perché lì |
 * |---|---|---|
 * | **0** | il nome **esatto**, nel paese di chi cerca | «roma» → Roma, non Roma (Texas) |
 * | **1** | il nome **esatto**, altrove — **solo città grandi** | «mosca» → Mosca prima di *Moscazzano*; «paris» → Parigi |
 * | **2** | **inizia con**, nel paese di chi cerca | «bolo» → Bologna |
 * | **3** | **contiene**, nel paese di chi cerca | «emilia» → Reggio nell'Emilia |
 * | **4** | **inizia con**, altrove | «spring» → Springfield |
 *
 * 🚨 **Il gradino 1 vale solo sopra i 100.000 abitanti**, ed è la regola che
 * aggiusta «bolo»: un villaggio filippino che si chiama *esattamente* così non
 * deve passare davanti a Bologna. ⚠️ Una città grande con quel nome esatto,
 * invece, sì: chi scrive per intero «parigi» vuole Parigi, non un comune
 * italiano che per caso comincia così.
 *
 * ⚠️ **Il «contiene» si cerca solo nel paese di chi cerca** — per l'Italia sono
 * di nuovo ottomila righe, e costa quello che costava. ⛔ Sul mondo sarebbe la
 * scansione da tre secondi.
 *
 * 💡 A parità di gradino: **il paese di chi cerca prima, poi la più grande**.
 *
 * ── 🗣️ E si cerca su tre nomi ──────────────────────────────────────────────
 *
 * `chiave` (GeoNames, `munich`), `chiave_altro` (italiano, `monaco di
 * baviera`), `chiave_locale` (del posto, `munchen`). ⚠️ Tre query separate e
 * **non** un `OR`: con l'`OR` MySQL rinuncia agli indici (misurato: 36 ms con
 * uno solo, 128 con due in `OR`).
 */
class RicercaComuni
{
    /** ⚠️ Sotto i due caratteri non si cerca: qualunque risposta sarebbe rumore. */
    public const MINIMO_CARATTERI = 2;

    /**
     * 🌍 Il paese di chi cerca, quando non lo dice.
     *
     * 💡 L'Italia, perché oggi i clienti sono italiani. ⚠️ Il giorno che l'app
     * sarà davvero internazionale lo manderà lei (`?paese=DE`), e questa
     * costante resta il ripiego.
     */
    public const PAESE_PREDEFINITO = Comune::ITALIA;

    /**
     * 🏙️ Sotto questa popolazione un nome esatto **straniero** non salta in cima.
     *
     * 🚨 È la soglia che separa Parigi da *Bolo* (Filippine): la prima si cerca
     * per nome intero, il secondo capita per caso.
     */
    public const GRANDE_CITTA = 100_000;

    /**
     * 🔢 Quante righe per ogni gradino si leggono dal database.
     *
     * ⚠️ Non il limite finale: serve margine, perché i gradini si fondono e si
     * riordinano in PHP. ⛔ Leggere tutto quello che comincia con «sa» vorrebbe
     * dire diecimila righe per mostrarne quindici.
     */
    private const PER_GRADINO = 150;

    /** 🗣️ I tre nomi di una città: GeoNames, italiano, locale. */
    private const COLONNE = ['chiave', 'chiave_altro', 'chiave_locale'];

    /**
     * 🔤 Da quante lettere si cercano anche i **paesi piccoli** del resto del
     * mondo.
     *
     * ⚠️ Sotto, «sa» o «ma» pescherebbero decine di migliaia di villaggi da
     * ordinare. Da tre lettere in su i candidati crollano, e chi cerca un
     * villaggio straniero ne scrive comunque di più.
     */
    private const LETTERE_PER_I_PICCOLI = 3;

    /**
     * 🔤 Da quante lettere un nome **esatto** straniero passa davanti ai
     * comuni di casa. Vedi `gradino()`: sotto, «san» mandava in cima il Mali.
     */
    private const LETTERE_PER_UN_NOME_INTERO = 4;

    public function __construct(private readonly ChiaveComune $chiavi) {}

    /**
     * I comuni che corrispondono al testo, i migliori per primi.
     *
     * @param  string|null  $paese  🆕 il paese di chi cerca (ISO 3166-1), che
     *                              viene prima a parità di gradino
     * @return Collection<int, Comune>
     */
    public function cerca(string $testo, int $limite = 15, ?string $paese = null): Collection
    {
        $q = $this->chiavi->perCercare($testo);

        if (mb_strlen($q) < self::MINIMO_CARATTERI) {
            return new Collection;
        }

        $paese = strtoupper($paese ?? self::PAESE_PREDEFINITO);

        /*
         * ⚠️ I caratteri speciali di `LIKE` vanno neutralizzati: chi digita `%`
         * in un campo di ricerca non sta chiedendo «tutti i comuni».
         * 💡 `ChiaveComune` toglie già tutto ciò che non è lettera o cifra: sta
         * qui lo stesso perché questa classe non deve dipendere da un dettaglio
         * dell'altra per essere corretta.
         */
        $like = addcslashes($q, '%_\\');

        /** @var array<int, Comune> $trovati */
        $trovati = [];

        $aggiungi = function (Builder $query) use (&$trovati): void {
            foreach ($query
                ->orderByRaw('COALESCE(popolazione, 0) DESC')
                ->limit(self::PER_GRADINO)
                ->get() as $c) {
                $trovati[$c->id] = $c;
            }
        };

        /*
         * 1️⃣ **Inizia con, a casa di chi cerca — per intero.**
         *
         * 💡 L'indice `(paese, attivo, chiave)` restringe prima al paese: per
         * l'Italia sono ottomila righe, e nessuno deve faticare a trovare un
         * paese di trecento abitanti a casa sua.
         */
        foreach (self::COLONNE as $colonna) {
            $aggiungi($this->base()->where('paese', $paese)->where($colonna, 'like', "{$like}%"));
        }

        /*
         * 2️⃣ **Inizia con, nel mondo — solo le città grandi.**
         *
         * 🚨 È il giro che deve reggere 232.000 righe. ⛔ Senza `rilevante`,
         * «sa» comincia settemila nomi che il database deve ordinare tutti per
         * popolazione: 220-680 ms a ogni tasto. Con, sono qualche centinaio.
         * Vedi la migrazione `le_citta_grandi_si_cercano_prima`.
         *
         * ⚠️ Tre query e non un `OR`: ognuna usa il proprio indice (misurato:
         * 36 ms con uno, 128 con due in `OR`).
         */
        foreach (self::COLONNE as $colonna) {
            $aggiungi($this->base()
                ->where('rilevante', true)
                ->where('paese', '!=', $paese)
                ->where($colonna, 'like', "{$like}%"));
        }

        /*
         * 3️⃣ **Inizia con, nel mondo — anche i paesi piccoli**, ma solo se
         * serve.
         *
         * 💡 Parte quando i primi due giri non riempiono la lista, e quando
         * chi scrive ha già messo **almeno tre lettere**: a quel punto i
         * candidati sono pochi, e il giro costa poco. ⛔ Su «sa» sarebbe il
         * giro da settemila righe che il secondo esiste per evitare.
         */
        if (count($trovati) < $limite && mb_strlen($q) >= self::LETTERE_PER_I_PICCOLI) {
            foreach (self::COLONNE as $colonna) {
                $aggiungi($this->base()
                    ->where('rilevante', false)
                    ->where('paese', '!=', $paese)
                    ->where($colonna, 'like', "{$like}%"));
            }
        }

        /*
         * 2️⃣ **Contiene**, ma **solo nel paese di chi cerca**.
         *
         * ⚠️ È il gradino che trova «Reggio nell'Emilia» scrivendo «emilia».
         * ⛔ Sul mondo intero sarebbe la scansione da tre secondi; dentro un
         * paese solo l'indice `(paese, attivo, chiave)` restringe prima, e per
         * l'Italia sono di nuovo ottomila righe.
         */
        foreach ($this->base()
            ->where('paese', $paese)
            ->where(fn (Builder $w) => $w
                ->where('chiave', 'like', "%{$like}%")
                ->orWhere('chiave_altro', 'like', "%{$like}%")
                ->orWhere('chiave_locale', 'like', "%{$like}%"))
            ->orderByRaw('COALESCE(popolazione, 0) DESC')
            ->limit(self::PER_GRADINO)
            ->get() as $c) {
            $trovati[$c->id] = $c;
        }

        return (new Collection(array_values($trovati)))
            ->sort(fn (Comune $a, Comune $b): int => [
                $this->gradino($a, $q, $paese),
                $a->paese === $paese ? 0 : 1,
                -((int) $a->popolazione),
                $a->nome,
            ] <=> [
                $this->gradino($b, $q, $paese),
                $b->paese === $paese ? 0 : 1,
                -((int) $b->popolazione),
                $b->nome,
            ])
            ->take($limite)
            ->values();
    }

    /**
     * Su quale gradino sta questa città, per questo testo. Vedi la tabella in
     * testa alla classe.
     */
    private function gradino(Comune $c, string $q, string $paese): int
    {
        $chiavi = array_filter([$c->chiave, $c->chiave_altro, $c->chiave_locale]);
        $qui = $c->paese === $paese;

        $esatto = in_array($q, $chiavi, true);

        if ($esatto && $qui) {
            return 0;
        }

        /*
         * 🚨 **E solo da quattro lettere in su.** ⛔ Misurato: con «san» saliva
         * in cima *San*, una città del Mali da 200.000 abitanti — nome esatto,
         * città grande, tutto in regola. Ma chi scrive «san» sta scrivendo
         * **l'inizio** di San Severo o di Sanremo, non il nome intero di una
         * città africana.
         *
         * 💡 Un nome intero lo si scrive quando è abbastanza lungo da essere un
         * nome: «mosca», «parigi», «nizza». I nomi di città di tre lettere che
         * un italiano cerca per nome intero sono una manciata, e restano
         * trovabili al gradino 4.
         */
        if ($esatto && mb_strlen($q) >= self::LETTERE_PER_UN_NOME_INTERO
            && (int) $c->popolazione >= self::GRANDE_CITTA) {
            return 1;
        }

        $iniziaCon = false;

        foreach ($chiavi as $k) {
            if (str_starts_with((string) $k, $q)) {
                $iniziaCon = true;

                break;
            }
        }

        return match (true) {
            $iniziaCon && $qui => 2,
            $qui => 3,
            default => 4,
        };
    }

    /** @return Builder<Comune> */
    private function base(): Builder
    {
        return Comune::query()->attivi();
    }
}
