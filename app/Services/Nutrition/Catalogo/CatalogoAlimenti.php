<?php

declare(strict_types=1);

namespace App\Services\Nutrition\Catalogo;

use App\Models\Food;
use App\Models\FoodEntry;
use App\Models\User;
use Illuminate\Database\QueryException;

/**
 * Quello che una persona scrive entra nel catalogo — 17/08/2026.
 *
 * ── 🚨 Perche' una voce di diario non diventa subito un alimento pubblico ──
 *
 * Perche' il nome e' **testo libero**, e prima o poi qualcuno scrivera' «cena
 * da mia sorella dopo l'operazione». ⚠️ In un catalogo pubblico immediato
 * quella riga finirebbe nel suggerimento di sconosciuti, ed e' il tipo di fuga
 * che non si ritira.
 *
 * 💡 Un alimento diventa pubblico quando lo inserisce **una seconda persona**,
 * indipendentemente. «Petto di pollo» ci arriva in un giorno perche' lo
 * scrivono in tanti; un'etichetta privata non ci arriva mai. La stessa regola
 * fa da filtro di qualita' senza costare niente.
 *
 * ── ⚠️ Persone diverse, non volte diverse ─────────────────────────────────
 *
 * Chi mangia lo yogurt ogni mattina conta **uno**. Contare le volte
 * trasformerebbe la soglia in un modo per pubblicarsi da soli quello che si
 * vuole, scrivendolo due giorni di fila.
 */
class CatalogoAlimenti
{
    public function __construct(
        private readonly ChiaveAlimento $chiavi,
        private readonly AlimentoAmmissibile $filtro,
        private readonly GerarchiaFonti $gerarchia,
    ) {}

    /**
     * Registra quello che una persona ha mangiato, e restituisce l'alimento
     * del catalogo a cui corrisponde — oppure `null` se non ci entra.
     *
     * 💡 Torna `null` **senza lamentarsi**: la stragrande maggioranza delle
     * voci di diario non e' materiale da catalogo (macro incompleti, pasti
     * composti, descrizioni vaghe), e non e' un errore. E' il caso normale.
     *
     * @param  array{kcal_100: ?float, protein_100: ?float, carbs_100: ?float, fat_100: ?float}  $per100
     */
    public function registra(
        string $nome,
        ?string $marca,
        array $per100,
        string $origine,
        User $chi,
    ): ?Food {
        $nome = trim($nome);

        if (! $this->filtro->ammesso($nome, $per100)) {
            return null;
        }

        $chiave = $this->chiavi->da($nome, $marca);

        $alimento = Food::query()->where('chiave', $chiave)->first();

        if ($alimento === null) {
            return $this->crea($chiave, $nome, $marca, $per100, $origine);
        }

        $this->aggiorna($alimento, $per100, $origine);
        $this->contaLUso($alimento, $chi);

        return $alimento;
    }

    /**
     * @param  array<string, float|null>  $per100
     */
    private function crea(string $chiave, string $nome, ?string $marca, array $per100, string $origine): ?Food
    {
        try {
            return Food::query()->create([
                'chiave' => $chiave,
                'nome' => mb_substr($nome, 0, 120),
                'marca' => $marca !== null ? mb_substr($marca, 0, 60) : null,
                ...$per100,
                'basis' => 'g',
                'origine' => $origine,
                // 🚨 Nasce **privato**, con una conferma sola: quella di chi
                // l'ha appena scritto.
                'conferme' => 1,
                'usi' => 1,
                'pubblico' => false,
            ]);
        } catch (QueryException $e) {
            /*
             * ⚠️ **Due richieste insieme passano entrambe dal controllo di
             * esistenza.**
             *
             * Chi registra due pasti in fretta, o l'app che riprova una
             * chiamata andata in timeout, arriva qui due volte con la stessa
             * chiave. 💡 L'unicita' sulla colonna fa fallire la seconda, e
             * questo `catch` la trasforma in «prendi quella che c'e' gia'» —
             * che e' esattamente cio' che si voleva.
             */
            if ($e->getCode() !== '23000') {
                throw $e;
            }

            return Food::query()->where('chiave', $chiave)->first();
        }
    }

    /**
     * 🚨 I valori si toccano **solo** se la gerarchia lo consente.
     *
     * Un utente non sovrascrive mai una riga CREA o Open Food Facts, e fra due
     * utenti vince chi c'era prima — con l'unica eccezione del manuale che
     * promuove una voce creata dall'AI.
     *
     * @param  array<string, float|null>  $per100
     */
    private function aggiorna(Food $alimento, array $per100, string $origine): void
    {
        if (! $this->gerarchia->puoScrivere($alimento, GerarchiaFonti::RANGO_UTENTE, $origine)) {
            return;
        }

        $alimento->fill([...$per100, 'origine' => $origine])->save();
    }

    /**
     * Conta l'uso, e pubblica l'alimento quando lo ha scritto una seconda
     * persona.
     *
     * ── ⚠️ Perche' si guarda `food_entries` e non una tabella apposta ──────
     *
     * Perche' una tabella «chi ha confermato cosa» sarebbe **un secondo posto
     * in cui e' scritto chi mangia cosa** — la categoria di dati che la Parte I
     * sta togliendo dal server. Il legame esiste gia' in `food_entries`, e
     * `EXISTS` sull'indice `(user_id, food_id)` risponde alla stessa domanda
     * senza aggiungere niente.
     *
     * 🎯 Il giorno in cui il diario si sposta sul telefono, questo conteggio se
     * ne va con lui invece di restare qui a fare da archivio residuo.
     */
    private function contaLUso(Food $alimento, User $chi): void
    {
        /*
         * 🚨 `withoutGlobalScopes` perche' un alimento e' **globale**: due
         * persone di palestre diverse che scrivono «Petto di pollo» sono due
         * conferme, e il filtro per tenant ne farebbe vedere una sola.
         *
         * ⚠️ Non e' una fuga: la condizione e' sul **proprio** `user_id`, e
         * quello che si legge e' se quella persona l'ha gia' usato.
         *
         * 💡 La voce di diario appena creata non ha ancora `food_id` — glielo
         * scrive l'osservatore **dopo** questa chiamata — quindi non si conta
         * da sola.
         */
        $giaSuo = FoodEntry::query()
            ->withoutGlobalScopes()
            ->where('user_id', $chi->getKey())
            ->where('food_id', $alimento->getKey())
            ->exists();

        $alimento->increment('usi');

        if ($giaSuo) {
            return;
        }

        $alimento->increment('conferme');
        $alimento->refresh();

        if (! $alimento->pubblico && $alimento->conferme >= Food::CONFERME_PER_PUBBLICARE) {
            $alimento->forceFill(['pubblico' => true])->save();
        }
    }
}
