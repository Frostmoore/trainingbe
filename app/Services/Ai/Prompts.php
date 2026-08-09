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
           di alimento e dichiarala in `qty` e `unit`. Non lasciare i grammi vuoti: una
           voce senza grammi non entra in nessun totale ed e' come non averla scritta.

        4. Valori nutrizionali per la quantita' effettiva, non per 100 g. Usa tabelle di
           composizione degli alimenti standard.

        5. CONFIDENZA. Il campo `confidence` va da 0 a 1 e deve essere onesto:
           - 0.9 o piu': la persona ha indicato alimenti e quantita' precise;
           - da 0.6 a 0.9: alimenti chiari, quantita' stimata da te;
           - sotto 0.6: descrizione ambigua, piatto composito non specificato,
             oppure piu' interpretazioni possibili.
           Una confidenza gonfiata e' peggio di una bassa: fa accettare in silenzio una
           stima sbagliata, che si scoprira' settimane dopo quando i totali non tornano.

        6. Se la descrizione non contiene cibo, restituisci `items` vuoto, totali a zero
           e `confidence` 0, con una `note` che spiega il motivo. Non inventare un pasto.

        7. Non aggiungere commenti, consigli o giudizi sull'alimentazione della persona.
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
        5. Restituisci un numero intero di chilocalorie.
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
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
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
                'kcal' => ['type' => 'integer', 'minimum' => 0],
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
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
                'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
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
                            'confidence' => ['type' => 'number', 'minimum' => 0, 'maximum' => 1],
                        ],
                    ],
                ],
            ],
        ];
    }
}
