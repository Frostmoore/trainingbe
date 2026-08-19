<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Nutrition;

use App\Enums\AiFeature;
use App\Http\Controllers\Controller;
use App\Jobs\TrascriviPianoAlimentare;
use App\Models\ImportazionePiano;
use App\Services\Ai\CancelloDeiGettoni;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * L'importazione di un piano alimentare da PDF — N20.
 *
 * ── 🚨 Il piano e' della persona, e di nessun altro ────────────────────────
 *
 * Ogni rotta qui dentro guarda `user_id`, e a chi non e' il proprietario
 * risponde **404 e non 403**: un 403 confermerebbe che quell'importazione
 * esiste, e su un piano alimentare anche solo l'esistenza e' un'informazione di
 * salute.
 *
 * ⚠️ Non esiste nessuna rotta che permetta a un trainer, a una palestra o a un
 * amministratore di leggere una di queste righe — nemmeno impersonando. Chi
 * volesse aggiungerne una domani legga prima N20.6.
 *
 * ── 🚨 Cosa dichiara chi importa ───────────────────────────────────────────
 *
 * Che il piano l'ha redatto un professionista abilitato e che l'importazione e'
 * sotto la sua responsabilita' (N20.5). Non e' una spunta decorativa: elaborare
 * una dieta e' riservato a medici, biologi nutrizionisti e dietisti, e noi qui
 * **non elaboriamo niente** — ricopiamo un documento che qualcun altro ha gia'
 * firmato.
 *
 * ── ⚠️ E costa 50 gettoni ─────────────────────────────────────────────────
 *
 * Cinquanta e non sette come una foto: e' un PDF multipagina letto per intero,
 * con una risposta lunga. Il cancello si apre **qui**, prima di mettere in coda;
 * i gettoni si scalano **nel job**, e solo se la trascrizione riesce.
 */
class ImportazionePianoController extends Controller
{
    public function __construct(private readonly CancelloDeiGettoni $cancello) {}

    /**
     * Carica il PDF e mette in coda la trascrizione.
     *
     * Risponde `202`: la trascrizione non e' pronta, e fingere che lo sia
     * vorrebbe dire tenere aperta una richiesta HTTP per un minuto.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => [
                'required', 'file', 'mimes:pdf',
                'max:'.(int) (ImportazionePiano::BYTE_MASSIMI / 1024),
            ],

            /*
             * 🚨 **`accepted` e non `boolean`**: deve arrivare vera, non
             * presente. Un `boolean` accetterebbe `false` e lascerebbe passare
             * un'importazione senza dichiarazione — cioe' esattamente il caso
             * che questo campo esiste per impedire.
             */
            'dichiarazione' => ['required', 'accepted'],
        ]);

        $utente = $request->user();

        /*
         * ⚠️ Il cancello **prima** di scrivere il file: aprire l'importazione e
         * poi scoprire che i gettoni non bastano vorrebbe dire un PDF con dentro
         * la dieta di qualcuno depositato sul nostro disco per niente.
         */
        $conGettoni = $this->cancello->apri($utente, AiFeature::NutritionPdfImport);

        $importazione = ImportazionePiano::apri(
            $utente,
            (string) file_get_contents($request->file('file')->getRealPath()),
            (string) $request->file('file')->getClientOriginalName(),
            $conGettoni,
        );

        TrascriviPianoAlimentare::dispatch((int) $importazione->id);

        return response()->json(['data' => $this->perLApp($importazione)], 202);
    }

    /** Lo stato dell'importazione, e la bozza quando c'e'. */
    public function show(Request $request, int $importazione): JsonResponse
    {
        $riga = $this->mia($request, $importazione);

        if ($riga === null) {
            return response()->json(['message' => __('Importazione non trovata.')], 404);
        }

        return response()->json(['data' => $this->perLApp($riga)]);
    }

    /**
     * Il PDF originale — N20.4.
     *
     * ── 🚨 Perche' deve restare consultabile ───────────────────────────────
     *
     * Perche' senza, la revisione riga per riga non e' una revisione: e' la
     * lettura di trenta numeri plausibili senza niente con cui confrontarli.
     * ⚠️ Il rischio di questo import non e' che l'AI fallisca — un fallimento si
     * vede e si rifa'. E' che riesca **a meta'**: «200 g» letti «20 g» non danno
     * nessun errore, danno un piano credibile e sbagliato. L'unica cosa che lo
     * scopre e' l'originale accanto.
     */
    public function pdf(Request $request, int $importazione): StreamedResponse|Response
    {
        $riga = $this->mia($request, $importazione);

        if ($riga === null || $riga->percorsoAssoluto() === null) {
            return response(['message' => __('Importazione non trovata.')], 404);
        }

        return Storage::disk('local')->response(
            $riga->percorso(),
            $riga->nome_file,
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * Chiude l'importazione: il PDF e la bozza se ne vanno.
     *
     * 🚨 **La chiama l'app dopo aver confermato la bozza**, e serve tanto a
     * confermare quanto a scartare — il risultato sul server e' lo stesso,
     * perche' il piano confermato **non resta qui**. Se lo tenessimo avremmo una
     * dieta legata a una persona sui nostri sistemi: un dato dell'art. 9 con un
     * nome sopra. Il telefono se la porta via, e questa riga si cancella.
     */
    public function destroy(Request $request, int $importazione): JsonResponse
    {
        $riga = $this->mia($request, $importazione);

        if ($riga === null) {
            return response()->json(['message' => __('Importazione non trovata.')], 404);
        }

        $riga->butta();

        return response()->json(['data' => ['chiusa' => true]]);
    }

    // ───────────────────────── interni ─────────────────────────

    /**
     * L'importazione, **solo se e' sua**.
     *
     * 🚨 `withoutGlobalScopes()` no: il filtro per palestra resta, e sopra ci si
     * aggiunge `user_id`. Sono due controlli e servono entrambi — il primo tiene
     * fuori le altre palestre, il secondo tiene fuori i compagni di palestra.
     */
    private function mia(Request $request, int $id): ?ImportazionePiano
    {
        $riga = ImportazionePiano::query()
            ->where('id', $id)
            ->where('user_id', (int) $request->user()->id)
            ->first();

        /*
         * 🚨 **La scadenza si controlla qui, non solo nel comando notturno.**
         *
         * ⚠️ Fra una passata di pulizia e l'altra passa un giorno: senza questo
         * controllo, in quella finestra un'importazione scaduta verrebbe ancora
         * consegnata. E finche' il pianificatore non gira davvero (P1), la
         * finestra e' larga quanto tutto il tempo.
         */
        return $riga !== null && ! $riga->scaduta() ? $riga : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function perLApp(ImportazionePiano $riga): array
    {
        return [
            'id' => (int) $riga->id,
            'stato' => $riga->stato,
            'nome_file' => $riga->nome_file,
            'byte_totali' => (int) $riga->byte_totali,
            'bozza' => $riga->bozza,
            'errore' => $riga->errore,
            'scade_il' => $riga->scade_il?->toIso8601String(),

            /*
             * 💡 Quante righe ci sono da controllare, detto **prima**: «34 righe
             * da confrontare con l'originale» e' un'informazione che cambia come
             * qualcuno affronta la revisione. Chi crede che sia questione di due
             * secondi la fa male.
             */
            'righe' => $this->quanteRighe($riga),
        ];
    }

    private function quanteRighe(ImportazionePiano $riga): int
    {
        $totale = 0;

        foreach ((array) ($riga->bozza['giorni'] ?? []) as $giorno) {
            foreach ((array) ($giorno['pasti'] ?? []) as $pasto) {
                $totale += count((array) ($pasto['alimenti'] ?? []));
            }
        }

        return $totale;
    }
}
