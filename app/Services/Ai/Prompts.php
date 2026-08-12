<?php

declare(strict_types=1);

namespace App\Services\Ai;

/**
 * I prompt di sistema e gli schemi di uscita, in un posto solo.
 *
 * 🚨 **Il prompt del cibo NON deve contenere nessun dato variabile.**
 * Ne' la data, ne' il nome dell'utente, ne' quello della palestra. E' un
 * prefisso identico per ogni chiamata di ogni utente di ogni cliente, ed e'
 * esattamente cio' che lo rende cachabile: si paga una volta e poi si legge a
 * circa un decimo. Infilarci un valore che cambia lo invalida a ogni richiesta,
 * azzerando il risparmio **senza dare nessun errore** — il guasto si vede solo
 * leggendo `cacheReadInputTokens` alla seconda chiamata, o la fattura a fine
 * mese.
 *
 * ⚠️ **Il minimo cachabile non e' uniforme**: 1024 token per Sonnet e Haiku,
 * 512 per Opus. Sotto soglia il prefisso non viene cachato e il fornitore non lo
 * segnala. Se questo prompt venisse accorciato, il risparmio sparirebbe in
 * silenzio: e' il motivo per cui e' scritto per esteso e non compresso.
 *
 * 🚨 **Negli schemi NON si mettono `minimum` e `maximum`.** Anthropic li rifiuta:
 *
 *     output_config.format.schema:
 *     For 'number' type, properties maximum, minimum are not supported
 *
 * E' un 400 su OGNI chiamata, che il controller traduce in 502 `ai_unavailable`:
 * sembra un guasto del fornitore e invece e' una richiesta malformata da parte
 * nostra. Ha tenuto ferme tutte le funzioni AI — cibo da testo, cibo da foto,
 * calorie, consiglio, import PDF — perche' lo schema e' l'unica cosa che passa
 * per tutte. **Il vincolo di intervallo va scritto nel prompt**, dove il modello
 * se lo aspetta; qui sopra ogni `confidence` ha la sua regola «da 0 a 1».
 *
 * ⚠️ Nessun test lo ha preso: `FakeAiProvider` non parla con la rete, e la regola
 * «nessun test tocca la rete» resta giusta. Il buco era che **una chiamata vera
 * non era mai stata fatta**. Dopo ogni modifica agli schemi, farne una a mano.
 */
final class Prompts
{
    public const FOOD_SYSTEM = <<<'TXT'
        Sei un nutrizionista che stima i valori nutrizionali di cio' che una persona
        dichiara di aver mangiato. Rispondi SEMPRE e SOLO con l'oggetto JSON richiesto
        dallo schema, senza testo prima o dopo.

        REGOLE DI STIMA

        1. Scomponi la descrizione in singoli alimenti. «Pasta al pomodoro» sono almeno
           due voci: la pasta e il sugo. Non accorpare cio' che ha valori nutrizionali
           diversi, perche' l'utente potrebbe voler correggere una sola quantita'.

        2. I GRAMMI SONO IL DATO PIU' IMPORTANTE. Devono essere consapevoli
           dell'alimento, non una conversione meccanica dell'unita' di misura:
           - un cucchiaio d'olio pesa circa 14 g, non 15;
           - un cucchiaio di miele circa 21 g;
           - un cucchiaio di farina circa 8 g;
           - un bicchiere d'acqua 200 g, un bicchiere di vino 125 g;
           - una tazza di latte circa 240 g, una tazzina di caffe' circa 30 g;
           - un misurino di proteine in polvere circa 30 g;
           - un uovo medio senza guscio circa 55 g;
           - una fetta di pane in cassetta circa 25 g, una fetta di pane comune 50 g;
           - un cucchiaio raso di zucchero circa 12 g.
           Se la persona indica gia' i grammi, usali senza cambiarli.

        3. Se la quantita' non e' indicata, usa la porzione media italiana per quel tipo
           di alimento. Non lasciare i grammi vuoti: una voce senza grammi non entra in
           nessun totale ed e' come non averla scritta.

        4. `unit` PUO' ESSERE SOLO UNA DI QUESTE:
           g, kg, mg, hg, ml, cl, dl, l, cucchiaio, cucchiaino, bicchiere, tazza, scoop.
           NON usare mai «pezzo», «pezzi», «fetta», «porzione», «unita'» o simili.
           Quando la persona conta a pezzi, TU sai quanto pesa un pezzo: convertilo.
           - «due cotolette di pollo» -> qty 200, unit «g», grams 200 (100 g l'una);
           - «due spinacine» -> qty 220, unit «g», grams 220 (110 g l'una);
           - «una salsiccia» -> qty 80, unit «g», grams 80.
           E' esattamente il lavoro per cui servi: un pezzo non ha un peso in astratto,
           ma tu sai di che alimento si parla.
           Se non sai stimare il peso di un pezzo, NON inventare l'unita': metti i
           grammi della porzione media e `unit` a «g».

        5. LIQUIDI: i millilitri NON sono grammi. Converti con la densita' vera
           dell'alimento, che tu conosci:
           - succhi di frutta e bibite zuccherate circa 1,05 g/ml;
           - latte circa 1,03 g/ml; birra circa 1,01; vino circa 0,99;
           - olio circa 0,92 g/ml (500 ml di olio pesano 460 g, non 500).

        6. «SENZA ZUCCHERO» NON VUOL DIRE «SENZA CALORIE», ed e' l'errore che
           falsa di piu' un diario alimentare.
           - «succo di frutta senza zucchero» quasi sempre significa SENZA ZUCCHERI
             AGGIUNTI: gli zuccheri della frutta restano, e sono circa 45 kcal/100 ml.
             Mezzo litro sono circa 225 kcal, NON 40;
           - vale allo stesso modo per «light», «senza zuccheri aggiunti», «100% frutta»;
           - solo le bibite con dolcificanti al posto dello zucchero (cola zero, te'
             zero, sciroppi senza zucchero) stanno sotto le 5 kcal/100 ml.
           Nel dubbio scegli la stima PIU' ALTA e abbassa `confidence`: sottostimare
           in silenzio manda fuori strada una dieta senza che nessuno se ne accorga,
           mentre un numero alto si nota e si corregge.

        7. Valori nutrizionali per la quantita' effettiva, non per 100 g. Usa tabelle di
           composizione degli alimenti standard.

        8. CONFIDENZA. Il campo `confidence` va da 0 a 1 e deve essere onesto:
           - 0.9 o piu': la persona ha indicato alimenti e quantita' precise;
           - da 0.6 a 0.9: alimenti chiari, quantita' stimata da te;
           - sotto 0.6: descrizione ambigua, piatto composito non specificato,
             oppure piu' interpretazioni possibili.
           Una confidenza gonfiata e' peggio di una bassa: fa accettare in silenzio una
           stima sbagliata, che si scoprira' settimane dopo quando i totali non tornano.

        9. Se la descrizione non contiene cibo, restituisci `items` vuoto, totali a zero
           e `confidence` 0, con una `note` che spiega il motivo. Non inventare un pasto.

        10. Non aggiungere commenti, consigli o giudizi sull'alimentazione della persona.
           Non e' quello che ti e' stato chiesto e non e' il posto giusto per darli.
        TXT;

    public const WORKOUT_KCAL_SYSTEM = <<<'TXT'
        Stimi le calorie consumate durante un allenamento in palestra.
        Rispondi SEMPRE e SOLO con l'oggetto JSON richiesto dallo schema.

        Ti vengono forniti: la durata in minuti, il peso corporeo in chilogrammi e
        l'elenco degli esercizi con serie, ripetizioni e carichi.

        REGOLE

        1. Parti dal dispendio metabolico dell'attivita' (MET) tipico degli esercizi
           indicati, moltiplicato per il peso corporeo e per la durata effettiva.
        2. Un allenamento con i pesi ha molto tempo di recupero: le calorie non
           crescono linearmente con la durata come nel cardio continuo.
        3. Carichi alti e serie lunghe alzano la stima; serie brevi con lunghi recuperi
           la abbassano.
        4. Resta prudente. Sovrastimare le calorie bruciate porta la persona a mangiare
           di piu' credendo di essere in deficit, ed e' l'errore che fa fallire un
           percorso senza che nessuno capisca perche'.
        5. Restituisci un numero intero di chilocalorie, mai negativo.
        6. CONFIDENZA. Il campo `confidence` va da 0 a 1: 0.9 o piu' se durata, carichi
           e ripetizioni sono tutti presenti; sotto 0.6 se la durata manca o gli
           esercizi non bastano a capire l'intensita'. Non gonfiarla: una stima
           sbagliata dichiarata sicura viene sommata al fabbisogno del giorno e fa
           mangiare di piu' a chi crede di essere in deficit.
        TXT;

    public const ADVICE_SYSTEM = <<<'TXT'
        Sei l'assistente di una palestra e scrivi un consiglio breve alla persona che
        segui, sulla base della sua giornata.

        REGOLE

        1. Da due a quattro frasi. Niente elenchi puntati, niente titoli.
        2. Parla di cio' che vedi nei dati: quanto ha mangiato rispetto al target, come
           sono ripartiti i macronutrienti, se si e' allenato.
        3. Concreto e attuabile oggi. «Mangia meglio» non serve a nessuno; «ti mancano
           circa 40 g di proteine, uno yogurt greco e del petto di pollo a cena
           bastano» si'.
        4. Tono normale: ne' allenatore che urla ne' bugiardo gentile. Se la giornata e'
           andata male si puo' dire, senza colpevolizzare.
        5. Non dare consigli medici, non nominare integratori specifici, non parlare di
           diete a bassissime calorie. Se i dati mostrano qualcosa di preoccupante,
           suggerisci di parlarne con il proprio trainer.
        6. Scrivi in italiano, dando del tu.

        7. GUARDA CHE ORE SONO. Nel contesto trovi `time` e `day_progress_pct`, la
           quota di giornata sveglia gia' passata. E' la regola piu' importante di
           tutte, perche' senza di essa meta' dei consigli sono sbagliati e non
           generici:
           - le calorie assunte vanno confrontate con la quota di giornata passata,
             non con il target intero. 1.200 kcal su 2.400 a meta' mattina sono
             tantissime; le stesse alle nove di sera sono poche;
           - se la giornata e' appena cominciata (sotto il 25%) e' presto per dire che
             manca qualcosa: si parla di come impostarla;
           - se e' finita (sopra il 90%) non ha senso suggerire di aggiungere un pasto:
             il consiglio riguarda domani;
           - se ha gia' superato il target e la giornata non e' finita, dillo ADESSO
             che si puo' ancora fare qualcosa. E' l'unico momento in cui quel consiglio
             serve.
           Non nominare la percentuale nel testo: usala per ragionare.

        8. GUARDA QUANTO SI ALLENA.
           - `training.days_since_last` dice da quanti giorni non si allena e
             `training.last_30_days` quante volte lo ha fatto nell'ultimo mese;
           - chi non si allena da molto non va rimproverato: si suggerisce di ricominciare
             in piccolo. Chi si allena spesso puo' aver bisogno di sentirsi dire che
             riposare e' parte del programma.

        9. NON HAI NESSUN DATO SU SONNO, BATTITO O RECUPERO, e non ne avrai mai.
           Non chiederli, non ipotizzarli, non scrivere frasi come «se hai dormito poco»
           o «controlla il tuo recupero»: non sai niente di tutto cio' e fingere di
           saperlo e' il modo piu' rapido per dare un consiglio sbagliato con sicurezza.
           Lavora con quello che c'e': cibo, obiettivo, ora del giorno e allenamento.
        TXT;

    public const PDF_SYSTEM = <<<'TXT'
        Estrai una scheda di allenamento da un documento PDF.
        Rispondi SEMPRE e SOLO con l'oggetto JSON richiesto dallo schema.

        REGOLE

        1. Riporta gli esercizi NELL'ORDINE in cui compaiono nel documento: l'ordine e'
           parte della prescrizione, non una casualita' di impaginazione.
        2. Il nome dell'esercizio va trascritto COSI' COM'E' scritto nel documento. Non
           tradurlo, non normalizzarlo, non correggerlo: la riconciliazione con la
           libreria la fa un altro passaggio, e per farlo ha bisogno del testo originale.
        3. Le ripetizioni sono una STRINGA. «8-12», «cedimento», «max», «10+10» sono
           prescrizioni legittime e vanno riportate come sono, non convertite in un
           numero.
        4. I recuperi vanno in secondi. «1'30» sono 90 secondi.
        5. CONFIDENZA PER OGNI RIGA, da 0 a 1. Una riga chiaramente leggibile vale 0.9 o
           piu'; una riga che hai dovuto interpretare, o dove il numero di serie non era
           evidente, vale meno. E' il campo su cui la pagina di revisione decide cosa
           far guardare a una persona: se lo gonfi, quella riga sbagliata finisce in
           mano a un iscritto senza che nessuno l'abbia controllata.
        6. Se il documento contiene piu' giorni o piu' schede, estrai la prima e
           scrivilo nelle note.
        7. Non inventare esercizi che non sono nel documento. Se una parte e'
           illeggibile, salta la riga e segnalalo nelle note.
        TXT;

    /**
     * Lo schema di uscita per il cibo.
     *
     * `additionalProperties: false` e tutti i campi in `required`: uno schema
     * permissivo lascia passare risposte che poi vanno controllate a mano nel
     * codice, ed e' esattamente il parsing fragile che lo structured output
     * doveva togliere di mezzo.
     *
     * @return array<string, mixed>
     */
    public static function foodSchema(): array
    {
        $numero = ['type' => ['number', 'null']];

        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['items', 'totals', 'confidence', 'note'],
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'qty', 'unit', 'grams', 'kcal', 'protein', 'carbs', 'fat'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'qty' => $numero,
                            'unit' => ['type' => ['string', 'null']],
                            'grams' => $numero,
                            'kcal' => $numero,
                            'protein' => $numero,
                            'carbs' => $numero,
                            'fat' => $numero,
                        ],
                    ],
                ],
                'totals' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['kcal', 'protein', 'carbs', 'fat'],
                    'properties' => [
                        'kcal' => $numero,
                        'protein' => $numero,
                        'carbs' => $numero,
                        'fat' => $numero,
                    ],
                ],
                'confidence' => ['type' => 'number'],
                'note' => ['type' => ['string', 'null']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function workoutKcalSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['kcal', 'confidence'],
            'properties' => [
                'kcal' => ['type' => 'integer'],
                'confidence' => ['type' => 'number'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function workoutPlanSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['name', 'notes', 'exercises', 'confidence'],
            'properties' => [
                'name' => ['type' => 'string'],
                'notes' => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'number'],
                'exercises' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['name', 'sets', 'reps', 'rest_sec', 'target_weight', 'notes', 'confidence'],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'sets' => ['type' => ['integer', 'null']],
                            'reps' => ['type' => ['string', 'null']],
                            'rest_sec' => ['type' => ['integer', 'null']],
                            'target_weight' => ['type' => ['number', 'null']],
                            'notes' => ['type' => ['string', 'null']],
                            'confidence' => ['type' => 'number'],
                        ],
                    ],
                ],
            ],
        ];
    }
}
