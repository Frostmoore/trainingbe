<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Si sta creando un esercizio nuovo senza dire che muscoli allena — 3b-A.3.5.
 *
 * ══ 🚨 PERCHE' BLOCCA, INVECE DI LASCIAR PASSARE ══════════════════════════
 *
 * 📌 *«Tutti gli esercizi devono indicare il muscolo o il gruppo muscolare che
 * allenano (anche piu' di uno)»*.
 *
 * ⛔ **Se si puo' lasciare vuoto, fra sei mesi meta' catalogo e' vuoto.** Non e'
 * una previsione pessimista: al 24/08/2026, prima di questa guardia, in staging
 * c'erano gia' **sette** esercizi nati muti — tutti `is_custom`, tutti creati da
 * `ExerciseMatcher` mentre qualcuno salvava una scheda. Nessuno li aveva
 * decisi, e nessuno se ne era accorto.
 *
 * 🚨 E il danno non si vede dove nasce: si vede **mesi dopo**, su una figura del
 * corpo che resta grigia e su un grafico a stella che ha delle punte in meno.
 * A quel punto nessuno collega la zona spenta al momento in cui l'esercizio e'
 * stato scritto.
 *
 * ── ⚠️ Vale SOLO alla creazione ───────────────────────────────────────────
 *
 * 💡 Un esercizio che **esiste gia'** non chiede niente: i suoi muscoli il
 * server li sa. La guardia scatta nell'unico momento in cui la risposta serve
 * davvero, cioe' quando una riga nuova sta per entrare nella libreria.
 *
 * ── ⛔ E pretende una risposta anche quando la risposta e' «nessuno» ───────
 *
 * 🚨 Servono **tutti e due** i campi: il primario, e i secondari **anche
 * vuoti**. `[]` dice «questo esercizio isola davvero» ed e' una decisione
 * legittima; `NULL` dice «non ci ho pensato». Accettare `NULL` per i secondari
 * vorrebbe dire richiudere dalla finestra la porta appena chiusa.
 */
final class MuscoliNonDecisiException extends RuntimeException
{
    public function __construct(public readonly string $esercizio)
    {
        parent::__construct(sprintf(
            '«%s» e\' un esercizio nuovo e nessuno ha detto che muscoli allena.',
            $esercizio,
        ));
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'error' => 'muscoli_non_decisi',
            'message' => __(
                '«:esercizio» non e\' in libreria. Dimmi che muscoli allena: quello principale, e quelli che aiutano — anche «nessuno», se isola.',
                ['esercizio' => $this->esercizio],
            ),
            'meta' => ['exercise' => $this->esercizio],
        ], 422);
    }
}
