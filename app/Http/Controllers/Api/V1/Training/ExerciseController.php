<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Training;

use App\Enums\MuscleGroup;
use App\Http\Controllers\Controller;
use App\Models\Exercise;
use App\Services\Training\ExerciseMatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * La libreria esercizi vista dall'app — B4.5.
 *
 * Restituisce i globali **piu'** quelli della palestra: e' `TenantOrGlobalScope`
 * a farlo, senza che questo controller debba saperne niente. L'iscritto la usa
 * per cercare un esercizio quando registra una serie fuori scheda.
 */
class ExerciseController extends Controller
{
    public function __construct(private readonly ExerciseMatcher $matcher) {}

    public function index(Request $request): JsonResponse
    {
        $query = Exercise::query()->ordered();

        if (($termine = trim((string) $request->query('q'))) !== '') {
            $query->search($termine);
        }

        if (($gruppo = $request->query('muscle_group')) !== null) {
            $query->where('muscle_group', $gruppo);
        }

        /*
         * ══ 🚨 IL TETTO DEVE STARE SOPRA IL CATALOGO INTERO — 3b-L ════════
         *
         * ⛔ Era **200**, e con 3b-L gli esercizi della piattaforma sono
         * diventati **314**: l'app ne avrebbe ricevuti 200 e basta, senza
         * nessun errore. 🚨 Il danno non sarebbe stato «mancano dei
         * risultati»: `catalogoEserciziProvider` e' la fonte da cui l'app
         * ricava **illustrazione, muscoli e MET** di ogni esercizio a partire
         * dal suo id. Centoquattordici esercizi sarebbero rimasti senza
         * figura e con la stella dei muscoli vuota, e chi guardava avrebbe
         * concluso che l'importazione non ha funzionato.
         *
         * ⚠️ **Un tetto ci vuole lo stesso**: senza, una palestra con un
         * catalogo enorme si porterebbe giu' tutto a ogni avvio. Ma deve
         * stare largo sopra il caso vero, non appena sotto.
         *
         * ⏳ **Debito dichiarato**: la risposta giusta e' la paginazione. Fino
         * ad allora l'app si accorge del troncamento — se riceve esattamente
         * quanti ne ha chiesti, lo scrive nel log invece di far finta di
         * niente.
         */
        $esercizi = $query->limit(min(1000, (int) $request->integer('limit', 100)))->get();

        $mio = $request->user()?->tenant_id;

        return response()->json([
            'data' => $esercizi->map(fn (Exercise $e): array => [
                'id' => $e->id,
                'name' => $e->name,
                'muscle_group' => $e->muscle_group?->value,
                'secondary_muscles' => $e->secondary_muscles ?? [],
                'equipment' => $e->equipment,
                // Serve all'app per distinguere «esercizio della piattaforma» da
                // «aggiunto dalla mia palestra», che e' l'unica differenza
                // percepibile fra i due.
                'is_global' => $e->isGlobal(),

                /*
                 * 🆕 **Da dove viene questo esercizio** — 3b-N, 28/08/2026.
                 *
                 * 📌 Serve alla pagina «Esercizi»: *«mettiamo anche una pagina
                 * Esercizi»*. ⛔ `is_global` non basta — dice «della
                 * piattaforma o no», e il «no» adesso comprende due cose molto
                 * diverse: quelli che ho scritto **io** e quelli che vedo
                 * perche' me li passa un trainer (3b-M).
                 *
                 * 🚨 **Lo calcola il server**, che e' l'unico a sapere di che
                 * tenant e' la riga. ⛔ L'app non ha il `tenant_id` e non deve
                 * averlo: sarebbe un numero interno esposto per far fare a lei
                 * un confronto che qui costa niente.
                 *
                 * 💡 «condivisa» e non «del trainer»: dopo 3b-M puo' arrivare
                 * anche da una palestra da cui si e' usciti, e chiamarla «del
                 * trainer» sarebbe una bugia in un caso su due.
                 */
                'origine' => match (true) {
                    $e->tenant_id === null => 'piattaforma',
                    $e->tenant_id === $mio => 'mia',
                    default => 'condivisa',
                },
                // C23 — l'illustrazione, se la palestra o la piattaforma l'ha
                // caricata. `null` quando non c'e': l'app disegna un segnaposto
                // invece di una miniatura rotta.
                'image_url' => $e->imageUrl(),

                /*
                 * ⚖️ 🆕 Chi ha fatto il disegno — 3b-L, 28/08/2026.
                 *
                 * 🚨 **Viaggia con l'immagine e da nessun'altra parte.** Un
                 * credito che l'app tenesse per conto suo — una costante, una
                 * riga scritta nel widget — resterebbe li' anche il giorno che
                 * una palestra sostituisce il disegno con una foto sua, e
                 * attribuirebbe a Bryl Lim una fotografia che non ha scattato.
                 *
                 * 💡 `null` vuol dire «non e' dovuto niente», e l'app allora
                 * non scrive nessuna riga.
                 */
                'image_credit' => $e->imageCredit(),

                /*
                 * 🆕 **Il MET viaggia con l'esercizio** — FASE 11.2, 21/08/2026.
                 *
                 * 🚨 Da quando gli allenamenti stanno sul telefono, il calcolo
                 * delle calorie (`MET x kg x ore`) gira **li'**. Ma il catalogo
                 * degli esercizi resta sul server — e' roba condivisa, non e' di
                 * nessuno (`plan_tutto_sul_telefono.md` §2.2) — quindi il MET
                 * deve arrivare insieme all'esercizio, o l'app dovrebbe chiedere
                 * il catalogo ogni volta che ricalcola.
                 *
                 * ⚠️ 🆕 **`null` per 175 esercizi su 314** — era 1 su 121 prima
                 * di 3b-L. Gli esercizi importati da workout-guide non portano
                 * con se' un MET, e non se ne inventa uno: l'app usa il ripiego
                 * generico, esattamente come faceva `metOf()` qui.
                 *
                 * 💡 L'eccezione sono gli **allungamenti**, che il MET ce
                 * l'hanno (2.3): li' il ripiego generico da 5.0 non sarebbe
                 * stato «impreciso», sarebbe stato **il doppio del vero**.
                 */
                'met' => $e->met,
            ])->all(),
        ]);
    }

    /**
     * Un esercizio che la libreria non ha — C2.3.
     *
     * 🚨 **Nasce della PALESTRA, non della persona** (decisione D3). Dentro una
     * palestra il vocabolario deve essere comune: se ogni iscritto si creasse i
     * propri, lo storico di due persone che fanno la stessa cosa non sarebbe
     * confrontabile e il trainer si troverebbe venti nomi diversi per la panca
     * piana. `created_by` resta come traccia di chi l'ha introdotto.
     *
     * ⚠️ **Risponde 200 se esiste già, 201 solo se l'ha creato davvero.** Non è
     * un dettaglio di stile: `ExerciseMatcher` riconosce che «panca piana
     * bilanciere» è la «panca piana» che la palestra ha già, e restituisce
     * quella. Creare un doppione a ogni richiesta è esattamente la degenerazione
     * che `slug_normalized` esiste per impedire.
     */
    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'muscle_group' => ['nullable', Rule::enum(MuscleGroup::class)],

            /*
             * 🆕 I muscoli secondari — 3b-A.3.4/A.3.5.
             *
             * ⚠️ **`nullable` qui e obbligatori piu' sotto**, e non e' una
             * contraddizione: se il nome corrisponde a un esercizio che esiste
             * gia', i muscoli non servono — il server li sa. 🚨 Servono solo
             * quando la riga **sta per nascere**, e chi lo sa e' il matcher,
             * non la validazione: la risposta e' 422 `muscoli_non_decisi`.
             */
            'secondary_muscles' => ['nullable', 'array', 'max:6'],
            'secondary_muscles.*' => [Rule::enum(MuscleGroup::class)],

            'equipment' => ['nullable', 'string', 'max:64'],
        ]);

        $utente = $request->user();

        $esistenti = Exercise::query()->count();

        /*
         * 🚨 **I muscoli entrano dal matcher, non dopo** — 3b-A.3.5.
         *
         * ⛔ Prima si chiamava `match()` a mani vuote e si scriveva dopo con un
         * `forceFill`. Due scritture per lo stesso fatto, e una guardia che non
         * poteva esistere: quando il matcher creava, non sapeva niente.
         */
        $esercizio = $this->matcher->match(
            $dati['name'],
            $utente->tenant_id,
            $utente,
            isset($dati['muscle_group']) ? MuscleGroup::from((string) $dati['muscle_group']) : null,
            array_key_exists('secondary_muscles', $dati) && is_array($dati['secondary_muscles'])
                ? array_values(array_map(strval(...), $dati['secondary_muscles']))
                : null,
        );

        $creato = Exercise::query()->count() > $esistenti;

        // L'attrezzo si scrive solo su ciò che è appena nato: cambiarlo su un
        // esercizio della piattaforma perché un iscritto ha mandato un valore
        // diverso lo cambierebbe per tutta la palestra.
        if ($creato && ($dati['equipment'] ?? null) !== null) {
            $esercizio->forceFill(['equipment' => $dati['equipment']])->save();
        }

        return response()->json([
            'data' => [
                'id' => $esercizio->id,
                'name' => $esercizio->name,
                'muscle_group' => $esercizio->muscle_group?->value,
                'secondary_muscles' => $esercizio->secondary_muscles ?? [],
                'equipment' => $esercizio->equipment,
                'is_global' => $esercizio->isGlobal(),
                // Dice all'app se ha creato qualcosa o se ha ritrovato ciò che
                // c'era: senza, non può spiegare perché il nome che ha scritto
                // è tornato indietro diverso.
                'created' => $creato,
            ],
        ], $creato ? 201 : 200);
    }
}
