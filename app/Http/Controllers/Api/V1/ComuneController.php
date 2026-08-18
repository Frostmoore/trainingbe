<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Comune;
use App\Services\Scoperta\RicercaComuni;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Il completamento automatico del campo citta' — 18/08/2026. **M1.2.**
 *
 * ── 🚨 Perche' e' pubblica, senza autenticazione ───────────────────────────
 *
 * Perche' i dati **sono gia' pubblici**: l'elenco dei comuni italiani lo pubblica
 * l'ISTAT e lo scarica chiunque in un file da un megabyte. ⚠️ Proteggere con
 * l'autenticazione un dato che si trova alla fonte originale non protegge
 * niente: aggiunge un ostacolo a chi ha diritto di usarlo e nessuno a chi lo
 * vuole comunque.
 *
 * 💡 E serve pubblica: il modulo di iscrizione di una palestra sul sito chiede la
 * citta' **prima** che esista un account a cui autenticarsi.
 *
 * 🚨 Il tetto (`throttle:comuni`) c'e' lo stesso, e per un motivo diverso dai
 * dati: un endpoint pubblico senza limite e' un modo comodo per tenere occupato
 * il server a costo zero.
 */
class ComuneController extends Controller
{
    /**
     * `GET /api/v1/comuni?q=bolo`
     *
     * ⚠️ Sotto i due caratteri risponde con un elenco **vuoto**, non con un
     * errore: chi sta scrivendo `Bo` non ha sbagliato niente, sta scrivendo.
     * Un `422` a meta' parola farebbe lampeggiare un messaggio rosso in un campo
     * che sta funzionando come deve.
     */
    public function index(Request $request, RicercaComuni $ricerca): JsonResponse
    {
        $dati = $request->validate([
            'q' => ['nullable', 'string', 'max:80'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $trovati = $ricerca->cerca(
            (string) ($dati['q'] ?? ''),
            (int) ($dati['limite'] ?? 15),
        );

        return response()->json([
            'data' => $trovati->map(fn (Comune $c): array => $this->scheda($c))->all(),
        ]);
    }

    /**
     * `GET /api/v1/comuni/{comune}` — per rileggere la citta' gia' scelta.
     *
     * 💡 Serve all'app: sul profilo c'e' `comune_id`, e per scrivere «Bologna
     * (BO)» accanto al campo bisogna poterlo risolvere senza cercarlo per nome.
     */
    public function show(Comune $comune): JsonResponse
    {
        return response()->json(['data' => $this->scheda($comune)]);
    }

    /**
     * 🚨 `popolazione` **non** esce.
     *
     * Serve a ordinare la ricerca (`RicercaComuni`), non a essere mostrata: e'
     * un numero vecchio di qualche anno, e pubblicarlo vorrebbe dire farlo
     * sembrare un dato che teniamo aggiornato. ⚠️ Un campo che l'app potrebbe
     * mostrare e' un campo che prima o poi qualcuno mostra.
     *
     * @return array<string, mixed>
     */
    private function scheda(Comune $c): array
    {
        return [
            'id' => $c->id,
            'nome' => $c->nome,
            'nome_altro' => $c->nome_altro,
            'provincia' => $c->provincia,
            'provincia_nome' => $c->provincia_nome,
            'regione' => $c->regione,
            'cap' => $c->cap,
            'esteso' => $c->esteso(),
        ];
    }
}
