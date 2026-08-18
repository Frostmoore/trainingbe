<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Chat;

use App\Http\Controllers\Controller;
use App\Models\AllegatoCifrato;
use App\Models\Conversation;
use App\Services\Chat\CancelloDellaChat;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Le foto della chat, in transito — N14.
 *
 * ── 🚨 Perche' i byte non stanno DENTRO il messaggio ───────────────────────
 *
 * Una conversazione si carica tutta insieme. Con le foto dentro le buste,
 * aprire una chat con venti foto vorrebbe dire scaricare otto megabyte **ogni
 * volta**, anche solo per rileggere una riga di testo. E un invio interrotto
 * ricomincerebbe da capo invece di riprendere.
 *
 * 💡 Cosi' invece il messaggio porta solo un riferimento e la chiave; i byte
 * viaggiano di qui, una volta sola.
 *
 * ── 🚨 Il server non puo' aprire niente ────────────────────────────────────
 *
 * Riceve byte gia' cifrati e li restituisce identici. La chiave sta dentro il
 * messaggio, che e' una busta `crypto_box` fra le due persone.
 */
class AllegatoCifratoController extends Controller
{
    public function __construct(private readonly CancelloDellaChat $cancello) {}

    /**
     * Deposita una foto cifrata per l'altra persona.
     */
    public function store(Request $request, int $conversation): JsonResponse
    {
        $c = $this->conversazioneDi($request, $conversation);

        if ($c === null) {
            return response()->json(['message' => __('Conversazione non trovata.')], 404);
        }

        /*
         * 🚨 **Lo stesso cancello dei messaggi, e non uno suo.**
         *
         * ⚠️ Una seconda idea di «chi puo' scrivere qui» sarebbe una copia
         * della tabella delle regole destinata a divergere: il giorno che il
         * limite dei tre messaggi cambia, le foto continuerebbero a passare.
         * 💡 Una foto **e'** un messaggio, per tutto cio' che riguarda i
         * permessi.
         */
        $permesso = $this->cancello->puoScrivere($request->user(), $c);

        if (! $permesso->consentito) {
            return response()->json([
                'message' => $permesso->spiegazione,
                'code' => $permesso->codice,
                'permesso' => $permesso->perLApp(),
            ], 403);
        }

        $request->validate([
            'file' => ['required', 'file', 'max:'.(int) (AllegatoCifrato::BYTE_MASSIMI / 1024)],
        ]);

        /*
         * ⚠️ **Nessuna regola sul tipo del file, ed e' voluto.**
         *
         * Arriva un flusso cifrato: da fuori sono byte a caso, e qualunque
         * controllo su estensione o MIME direbbe soltanto che non e' un'immagine
         * — cosa che sappiamo. L'unico limite sensato e' la **misura**.
         */
        $allegato = AllegatoCifrato::deposita(
            $c->id,
            (int) $request->user()->id,
            (string) file_get_contents($request->file('file')->getRealPath()),
        );

        return response()->json([
            'token' => $allegato->token,
            'byte_totali' => $allegato->byte_totali,
            'scade_il' => $allegato->scade_il->toIso8601String(),
        ], 201);
    }

    /**
     * Riprende una foto cifrata **e la cancella** — N14.2.
     *
     * ── 🚨 Si cancella subito dopo averla consegnata ───────────────────────
     *
     * *«restano finche' non le scarica, max 24h»*. Consegnata la foto, il
     * server non ha piu' nessuna ragione di tenerla: e' gia' sul telefono di
     * chi doveva riceverla.
     *
     * ⚠️ **Il rischio accettato, dichiarato**: una connessione che cade fra
     * l'ultimo byte e la scrittura sul telefono perde la foto. 💡 Chi l'ha
     * mandata pero' ce l'ha ancora per ventiquattro ore (§4.10 del piano), e
     * puo' rimandarla. L'alternativa — tenerla finche' qualcuno conferma —
     * vorrebbe dire un protocollo di conferma che nessuno chiude mai, e allegati
     * che restano li' per sempre.
     */
    public function show(Request $request, string $token): StreamedResponse|Response
    {
        $allegato = AllegatoCifrato::query()->where('token', $token)->first();

        if ($allegato === null) {
            return response(['message' => __('Questo allegato non c\'e\' piu\'.')], 404);
        }

        /*
         * 🚨 **La scadenza si controlla qui, non solo nel comando notturno.**
         *
         * ⚠️ Fra una passata di pulizia e l'altra passa un'ora: senza questo
         * controllo, in quella finestra un allegato scaduto verrebbe consegnato
         * lo stesso — e le ventiquattro ore diventerebbero venticinque, o
         * quello che capita.
         */
        if ($allegato->scaduto()) {
            $allegato->butta();

            return response(['message' => __('Questo allegato e\' scaduto.')], 404);
        }

        $c = $this->conversazioneDi($request, $allegato->conversation_id);

        if ($c === null) {
            /*
             * 💡 **404 e non 403**: a chi non fa parte della conversazione non
             * si dice nemmeno che quell'allegato esiste. Un 403 confermerebbe
             * che il token e' valido, che e' un'informazione in piu' di quanto
             * serva.
             */
            return response(['message' => __('Questo allegato non c\'e\' piu\'.')], 404);
        }

        $byte = Storage::disk('local')->get($allegato->percorso());

        if ($byte === null) {
            // Riga senza file: si pulisce e si dice la verita'.
            $allegato->butta();

            return response(['message' => __('Questo allegato non c\'e\' piu\'.')], 404);
        }

        $allegato->butta();

        return response()->stream(
            function () use ($byte): void {
                echo $byte;
            },
            200,
            [
                'Content-Type' => 'application/octet-stream',
                'Content-Length' => (string) strlen($byte),
                // ⚠️ Niente cache: e' consegnato una volta sola, e una copia in
                // un proxy sopravviverebbe alla cancellazione sul server.
                'Cache-Control' => 'no-store, private',
            ],
        );
    }

    /**
     * 🚨 Identica a quella di `ConversationController`: `forUser` **e** la
     * policy. La prima filtra gli elenchi, la seconda copre l'accesso per id —
     * e nega **anche durante un'impersonazione**.
     */
    private function conversazioneDi(Request $request, int $id): ?Conversation
    {
        $conversazione = Conversation::query()
            ->forUser($request->user())
            ->find($id);

        if ($conversazione === null) {
            return null;
        }

        return Gate::allows('view', $conversazione) ? $conversazione : null;
    }
}
