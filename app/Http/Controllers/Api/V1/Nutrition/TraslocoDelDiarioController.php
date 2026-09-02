<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Nutrition;

use App\Http\Controllers\Controller;
use App\Models\FoodEntry;
use App\Models\FoodFavorite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Il diario alimentare trasloca sul telefono — Parte I, I3.
 *
 * ══ 📌 PERCHE' ═══════════════════════════════════════════════════════════
 *
 * 📌 Regola R3 del progetto: *«tutto cio' che e' anche lontanamente sensibile
 * resta sul telefono»*. 🚨 Cosa mangia una persona e' dato dell'art. 9, ed era
 * l'ultima tabella grossa di dati personali rimasta qui: peso, sonno,
 * allenamenti e schede se ne sono andati fra S5, D9 e la FASE 11.
 *
 * ══ ⛔ QUANTO E' SEMPLICE, E PERCHE' ══════════════════════════════════════
 *
 * 📌 Il committente, il 02/09/2026: *«Non ci sono utenti, sono solo io, sticazzi
 * de ste cose, meglio farle adesso che dopo»*.
 *
 * 💡 Il piano prevedeva una cerimonia — conferma dei conteggi, dati conservati
 * per chi non aggiorna l'app, una decisione da prendere «con i numeri veri
 * davanti». ⛔ Quei numeri sono **uno**, ed e' il committente. Costruire la
 * cerimonia adesso vorrebbe dire scrivere codice per un problema che non c'e',
 * e doverlo mantenere finche' esiste il progetto.
 *
 * 🚨 **Quello che resta e' il conteggio**, ed e' l'unica cosa che non si taglia:
 * e' la differenza fra *«i dati sono stati spostati»* e *«i dati sono stati
 * persi»*, e costa una riga.
 *
 * ══ 🚨 SOLA LETTURA ══════════════════════════════════════════════════════
 *
 * ⛔ Questo controller **non cancella niente**. Il server si svuota in I4, con
 * una migration, dopo che il trasloco e' stato fatto e verificato sul telefono
 * vero. Un endpoint che consegna e cancella nella stessa richiesta perderebbe
 * tutto il giorno che la risposta non arriva a destinazione.
 */
class TraslocoDelDiarioController extends Controller
{
    /**
     * Tutto il diario di questa persona, in una volta.
     *
     * ══ 💡 PERCHE' SENZA PAGINAZIONE ══════════════════════════════════════
     *
     * Perche' e' un gesto **unico**: si fa una volta e non si ripete. ⚠️ Una
     * paginazione qui vorrebbe dire un ciclo sul telefono, con la possibilita'
     * di fermarsi a meta' — e meta' diario e' peggio di nessun diario, perche'
     * i totali sembrano veri.
     *
     * 🚨 Il tetto c'e' lo stesso ([self::AL_MASSIMO]): non per la memoria, ma
     * perche' una risposta senza limite e' una risposta che un giorno non
     * arriva. Se qualcuno lo supera lo si sapra' da qui, non da un timeout.
     */
    public function pacchetto(Request $request): JsonResponse
    {
        $utente = $request->user();

        $voci = FoodEntry::query()
            ->forUser($utente)
            ->orderBy('eaten_at')
            ->limit(self::AL_MASSIMO)
            ->get();

        $preferiti = FoodFavorite::query()
            ->where('user_id', $utente->getKey())
            ->orderBy('id')
            ->limit(self::AL_MASSIMO)
            ->get();

        return response()->json(['data' => [
            /*
             * 🚨 **I conteggi arrivano dal database, non da `count($voci)`.**
             *
             * ⛔ Contare l'array che si sta mandando risponderebbe sempre
             * «tutte», tetto compreso: il telefono confronterebbe un numero con
             * se stesso e sarebbe sempre d'accordo. 💡 Cosi' invece, se il tetto
             * ha tagliato qualcosa, i due numeri **non tornano** — ed e'
             * esattamente quello che deve succedere.
             */
            'quante_voci' => FoodEntry::query()->forUser($utente)->count(),
            'quanti_preferiti' => FoodFavorite::query()->where('user_id', $utente->getKey())->count(),

            'voci' => $voci->map(fn (FoodEntry $v): array => [
                'id' => $v->id,
                'eaten_at' => $v->eaten_at?->toIso8601String(),
                'created_at' => $v->created_at?->toIso8601String(),
                'meal' => $v->meal->value,
                'description' => $v->description,
                'grams' => $v->grams,
                'qty' => $v->qty,
                'unit' => $v->unit,
                'kcal' => $v->kcal,
                'protein' => $v->protein,
                'carbs' => $v->carbs,
                'fat' => $v->fat,
                'kcal_100' => $v->kcal_100,
                'protein_100' => $v->protein_100,
                'carbs_100' => $v->carbs_100,
                'fat_100' => $v->fat_100,
                'source' => $v->source->value,

                /*
                 * ⚠️ **La risposta grezza del modello viaggia**, ed e' l'unico
                 * campo di cui vale la pena spiegare la presenza: serve a
                 * spiegare un numero che qualcuno contesta — *«non e' stato
                 * specificato se sono panate»* — ed e' il campo che il 12/08 ha
                 * spiegato una stima sbagliata mentre `confidence` diceva 0.85.
                 */
                'ai_raw' => $v->ai_raw === null ? null : json_encode($v->ai_raw),

                'nutrition_plan_id' => $v->nutrition_plan_id,
                'food_id' => $v->food_id,
            ])->all(),

            'preferiti' => $preferiti->map(fn (FoodFavorite $p): array => [
                'id' => $p->id,
                'description' => $p->description,
                'is_meal' => (bool) $p->is_meal,
                'items' => $p->items === null ? null : json_encode($p->items),
                'grams' => $p->grams,
                'qty' => $p->qty,
                'unit' => $p->unit,
                'kcal' => $p->kcal,
                'protein' => $p->protein,
                'carbs' => $p->carbs,
                'fat' => $p->fat,
                'kcal_100' => $p->kcal_100,
                'protein_100' => $p->protein_100,
                'carbs_100' => $p->carbs_100,
                'fat_100' => $p->fat_100,
                'created_at' => $p->created_at?->toIso8601String(),

                /*
                 * 🚨 **Il contatore d'uso, ed e' meta' dell'ordinamento.**
                 *
                 * ⛔ Non e' un aggregato ricalcolabile: e' un numero che il
                 * server incrementa a ogni uso. Contare le voci del diario con
                 * la stessa descrizione darebbe un altro numero — chi ha
                 * scritto «Pollo» a mano dieci volte non ha usato dieci volte
                 * il preferito «Pollo».
                 *
                 * ⚠️ Senza, il telefono avrebbe tutti i preferiti **nell'ordine
                 * sbagliato**, e nessuno guarda un elenco per accorgersi che
                 * manca un criterio. 📌 *«Chi ha venticinque preferiti vuole i
                 * tre che usa ogni giorno in cima»*.
                 */
                'times_used' => $p->times_used,
                'last_used_at' => $p->last_used_at?->toIso8601String(),
            ])->all(),
        ]]);
    }

    /**
     * Il tetto della risposta.
     *
     * ⚠️ **Non e' una paginazione**: e' la soglia oltre la quale questa strada
     * non e' piu' quella giusta, e vogliamo saperlo. 💡 Un anno di diario fitto
     * sta intorno alle 3.000 voci; ventimila vuol dire che qualcosa non torna,
     * o che serve un'altra strada.
     */
    private const AL_MASSIMO = 20000;
}
