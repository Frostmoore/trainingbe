<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Nutrition;

use App\Enums\AiFeature;
use App\Http\Controllers\Controller;
use App\Jobs\TrascriviIlDocumento;
use App\Models\ImportazioneDaDocumento;
use App\Services\Ai\CancelloDeiGettoni;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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
class ImportazioneDaDocumentoController extends Controller
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
            /*
             * ══ 🆕 UNA LISTA, E NON SOLO PDF — K1, 03/09/2026 ═══════════════
             *
             * 📌 *«L'import di pdf per le schede e per i piani alimentari deve
             * funzionare anche con le immagini»*.
             *
             * 🚨 **Fino a cinque**, perche' una scheda su carta sono spesso due
             * o tre pagine fotografate: accettarne una sola vorrebbe dire che chi
             * ne fotografa una **perde il resto senza accorgersene**.
             *
             * ⚠️ **`file` singolo non si accetta piu'.** Tenerlo per compatibilita'
             * vorrebbe dire due strade che fanno la stessa cosa, e quella meno
             * percorsa e' quella che si rompe in silenzio. 💡 L'app si aggiorna
             * insieme al server: e' l'unico client che esiste.
             */
            'file' => ['required', 'array', 'min:1', 'max:'.ImportazioneDaDocumento::AL_MASSIMO],

            /*
             * ⛔ **`heic` non c'e'**, e non e' una dimenticanza: Anthropic non lo
             * accetta, e lasciarlo passare darebbe un rifiuto del fornitore che
             * a chi guarda arriva come *«l'AI non e' disponibile»*. 💡 Il telefono
             * lo converte prima di caricare.
             */
            'file.*' => [
                'required', 'file', 'mimes:pdf,jpg,jpeg,png,webp',
                'max:'.(int) (ImportazioneDaDocumento::BYTE_MASSIMI / 1024),
            ],

            /*
             * 🚨 **`accepted` e non `boolean`**: deve arrivare vera, non
             * presente. Un `boolean` accetterebbe `false` e lascerebbe passare
             * un'importazione senza dichiarazione — cioe' esattamente il caso
             * che questo campo esiste per impedire.
             */
            'dichiarazione' => ['required', 'accepted'],

            /*
             * ══ 🔴 IL CONSENSO A MANDARE QUESTO DOCUMENTO — K1-ter ══════════
             *
             * 📌 Il committente: *«si deve richiedere il consenso specifico a
             * mandare quei dati all'AI»*.
             *
             * ⚠️ **E' diverso da `ai_consent_at`**, che il middleware `ai.consent`
             * ha gia' preteso prima di arrivare qui: quello dice *«puoi usare
             * l'AI»*, questo dice *«puoi mandare **questo file**»*. 🚨 Si chiede
             * ogni volta, perche' il file e' diverso ogni volta.
             *
             * ⛔ **`accepted` e non `boolean`**, come `dichiarazione`: deve
             * arrivare **vera**, non presente. Un `boolean` accetterebbe `false`
             * e lascerebbe passare un caricamento senza consenso — cioe'
             * esattamente il caso che questo campo esiste per impedire.
             */
            'consenso_documento' => ['required', 'accepted'],

            /*
             * 🆕 **Cosa si sta importando** — K2, 03/09/2026.
             *
             * ⚠️ `sometimes` e non `required`: quando manca vale `piano`, che e'
             * cio' che questa rotta ha sempre fatto. 💡 Un `required` avrebbe
             * rotto l'app installata per un campo che ha un valore ovvio.
             *
             * 🚨 Decide **tre cose**: quale funzione AI paga, quale prompt
             * legge il documento, e con quale schema esce la bozza. Sbagliarlo
             * non da' un errore: da' una bozza vuota, letta con lo schema di
             * un'altra cosa.
             */
            'genere' => [
                'sometimes',
                Rule::in([
                    ImportazioneDaDocumento::GENERE_PIANO,
                    ImportazioneDaDocumento::GENERE_SCHEDA,
                ]),
            ],
        ]);

        $utente = $request->user();

        /*
         * ⚠️ Il cancello **prima** di scrivere il file: aprire l'importazione e
         * poi scoprire che i gettoni non bastano vorrebbe dire un PDF con dentro
         * la dieta di qualcuno depositato sul nostro disco per niente.
         */
        $genere = (string) $request->input('genere', ImportazioneDaDocumento::GENERE_PIANO);

        /*
         * 💡 **Costano uguale**, e non e' un caso: un prezzo diverso spingerebbe
         * verso l'oggetto sbagliato per il motivo sbagliato. ⚠️ Ma sono due voci
         * distinte in contabilita', e vanno tenute tali.
         */
        $funzione = $genere === ImportazioneDaDocumento::GENERE_SCHEDA
            ? AiFeature::PdfImport
            : AiFeature::NutritionPdfImport;

        $conGettoni = $this->cancello->apri($utente, $funzione);

        /*
         * ⚠️ **L'ordine e' quello in cui arrivano**, e va conservato: per delle
         * pagine fotografate e' l'informazione principale — la seconda letta per
         * prima da' una scheda che comincia da meta'.
         */
        $files = [];

        foreach ($request->file('file') as $caricato) {
            $files[] = [
                'byte' => (string) file_get_contents($caricato->getRealPath()),
                'nome' => (string) $caricato->getClientOriginalName(),

                /*
                 * 🚨 **Il tipo si legge dal FILE, non da quello che dichiara chi
                 * carica.** `getClientMimeType()` viene dal client e si puo'
                 * scrivere a mano; `getMimeType()` guarda i byte. 💡 Da questo
                 * dipende se il documento parte come `document` o come `image`,
                 * e sbagliarlo e' una richiesta rifiutata dal fornitore.
                 */
                'mime' => (string) $caricato->getMimeType(),
            ];
        }

        $importazione = ImportazioneDaDocumento::apri($utente, $files, $conGettoni, $genere);

        TrascriviIlDocumento::dispatch((int) $importazione->id);

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

    /*
    | ══ ⛔ LA ROTTA DEL DOCUMENTO NON ESISTE PIU' — K1-bis, 03/09/2026 ═══════
    |
    | Serviva a riconsegnare all'app il PDF che aveva appena caricato, perche' la
    | revisione riga per riga senza l'originale accanto **non e' una revisione**:
    | e' la lettura di trenta numeri plausibili senza niente con cui confrontarli.
    |
    | 🚨 Quella ragione **resta vera**, e infatti l'originale c'e' ancora — ma
    | non qui. 💡 Il telefono se ne fa una copia **quando sceglie il file**, e da
    | li' lo riapre: farselo riconsegnare dal server era un giro inutile che
    | costava una copia di un documento sanitario sul nostro disco per sette
    | giorni.
    |
    | ⚠️ E dal 03/09 quel documento sul server **non c'e' comunque piu'**: se ne
    | va appena il job ha finito, riuscito o fallito (`buttaIDocumenti()`).
    */

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
    private function mia(Request $request, int $id): ?ImportazioneDaDocumento
    {
        $riga = ImportazioneDaDocumento::query()
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
    private function perLApp(ImportazioneDaDocumento $riga): array
    {
        return [
            'id' => (int) $riga->id,
            'stato' => $riga->stato,
            'nome_file' => $riga->nome_file,
            'byte_totali' => (int) $riga->byte_totali,

            /*
             * 💡 `pdf` o `immagini` — serve all'app per sapere **quale
             * avvertenza** mostrare in revisione: 📌 *«l'analisi delle immagini
             * e' generalmente meno accurata di quella dei PDF»*.
             *
             * ⚠️ Non si deduce dal nome del file: un `.pdf` puo' essere
             * qualunque cosa, e il tipo qui e' stato deciso guardando i byte.
             */
            'tipo' => $riga->tipo,
            'genere' => $riga->genere,
            'quanti_documenti' => count($riga->documenti ?? []),
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

    private function quanteRighe(ImportazioneDaDocumento $riga): int
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
