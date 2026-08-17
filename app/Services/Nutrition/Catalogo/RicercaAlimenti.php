<?php

declare(strict_types=1);

namespace App\Services\Nutrition\Catalogo;

use App\Models\Food;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * I suggerimenti mentre si scrive il nome di un alimento — 17/08/2026.
 *
 * ── L'ordine chiesto dal committente ───────────────────────────────────────
 *
 *     inseriti da me → più selezionati da me → più selezionati in generale →
 *     inseriti da altri
 *
 * 🚨 **I quattro livelli diventano un ordinamento solo**, e non è una
 * semplificazione: «inseriti da me» e «più selezionati da me» sono lo **stesso
 * insieme** letto con due ordini diversi — un alimento che ho inserito è un
 * alimento che ho selezionato almeno una volta. E ordinando per `usi`
 * decrescente, quelli che nessuno ha mai scelto (`usi = 0`) finiscono in fondo
 * da soli, che è precisamente «inseriti da altri».
 *
 * ── ⚠️ Perché prima il prefisso e poi il «contiene» ────────────────────────
 *
 * Su MySQL `LIKE 'pas%'` usa l'indice; `LIKE '%pas%'` **no**, e su
 * sessantamila alimenti scansiona la tabella **a ogni tasto premuto**. Qui si
 * fa prima la ricerca a prefisso, e il «contiene» solo per riempire i posti
 * che restano — così il caso comune (si scrive l'inizio della parola) non paga
 * mai la scansione.
 */
class RicercaAlimenti
{
    /** ⚠️ Sotto i due caratteri qualunque ricerca restituisce mezzo catalogo. */
    public const MINIMO_CARATTERI = 2;

    public function __construct(private readonly ChiaveAlimento $chiavi) {}

    /**
     * @return Collection<int, Food>
     */
    public function cerca(string $testo, User $chi, int $limite = 20): Collection
    {
        $q = $this->chiavi->perCercare($testo);

        if (mb_strlen($q) < self::MINIMO_CARATTERI) {
            return new Collection;
        }

        $perPrefisso = $this->interroga($chi, $limite)
            ->where('chiave', 'like', $this->scappa($q).'%')
            ->get();

        if ($perPrefisso->count() >= $limite) {
            return $perPrefisso;
        }

        /*
         * 💡 Il secondo giro serve a chi cerca «pollo» dentro «Petto di pollo».
         * ⚠️ Esclude quelli già trovati: senza, comparirebbero due volte, e la
         * seconda in una posizione peggiore.
         */
        $restanti = $limite - $perPrefisso->count();

        $perContenuto = $this->interroga($chi, $restanti)
            ->where('chiave', 'like', '%'.$this->scappa($q).'%')
            ->whereNotIn('foods.id', $perPrefisso->modelKeys())
            ->get();

        return $perPrefisso->concat($perContenuto);
    }

    /**
     * La base della ricerca: cosa si può vedere, e in che ordine.
     */
    private function interroga(User $chi, int $limite): Builder
    {
        /*
         * 🚨 Quante volte **questa persona** ha scelto ciascun alimento.
         *
         * ⚠️ È una sotto-interrogazione e non una `JOIN`: con la `JOIN` un
         * alimento mai usato sparirebbe (o servirebbe una `LEFT JOIN` con un
         * `GROUP BY` su tutte le colonne, che è peggio). Qui gli alimenti senza
         * uso valgono zero e restano in elenco.
         *
         * 💡 `withoutGlobalScopes` non serve: il filtro è sul proprio
         * `user_id`, e i propri dati sono nel proprio tenant per definizione.
         */
        $mieSelezioni = '(select count(*) from food_entries'
            .' where food_entries.food_id = foods.id'
            .' and food_entries.user_id = '.(int) $chi->getKey().')';

        return Food::query()
            ->visibiliA($chi)
            ->select('foods.*')
            ->selectRaw($mieSelezioni.' as mie_selezioni')
            // 1. e 2. — i miei, prima quelli che uso di più
            ->orderByRaw($mieSelezioni.' > 0 desc')
            ->orderByRaw($mieSelezioni.' desc')
            // 3. e 4. — i più scelti in generale, poi il resto
            ->orderByDesc('usi')
            ->orderByDesc('conferme')
            ->orderBy('nome')
            ->limit($limite);
    }

    /**
     * ⚠️ `%` e `_` dentro il testo cercato sono **caratteri jolly** per `LIKE`.
     *
     * Chi cerca «50%» senza questa protezione otterrebbe «tutto quello che
     * comincia per 50», e chi cerca «_» il catalogo intero.
     */
    private function scappa(string $testo): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $testo);
    }
}
