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
 * 🚨 **C'e' un minimo di lunghezza sotto il quale NON si cacha niente**, e il
 * fornitore non lo segnala. Se questo prompt venisse accorciato il risparmio
 * sparirebbe in silenzio: e' il motivo per cui e' scritto per esteso e non
 * compresso.
 *
 * ⚠️ **Qui c'era scritto «1024 token per Sonnet e Haiku, 512 per Opus», e i
 * dati non lo confermano.** Il 13/08/2026, leggendo `ai_usage_logs`:
 *
 *   - con il prefisso a **~2.200 token** la cache non e' MAI stata usata, nemmeno
 *     fra tre chiamate a due secondi di distanza (righe 84-86 del log);
 *   - con il prefisso a **5.068 token** funziona: una scrittura e otto letture.
 *
 * Il codice non era cambiato: `cacheControl` c'e' identico da `v6.9.0`. L'unica
 * variabile era la lunghezza. 🚨 **Quindi questa trappola non era teorica: ha
 * morso per tutta la vita del progetto, in silenzio, fino al 12/08/2026 sera.**
 *
 * 💡 La soglia vera per `claude-haiku-4-5` sta fra 2.200 e 5.068 token e non e'
 * stata misurata con precisione: **non inventarne una**. Prima di accorciare un
 * prompt, si lancia `php artisan ai:prova-classificatore` e si guarda
 * `cache_read_tokens` sulla seconda chiamata.
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
 * 🚨 **Un `enum` NON puo' stare su un tipo unione.** Anthropic rifiuta anche
 * questo:
 *
 *     output_config.format.schema: Invalid schema:
 *     Enum value 'per_100g' does not match declared type '['string', 'null']'
 *
 * ⚠️ Stessa forma del caso sopra: 400 su ogni chiamata, che il controller
 * traduce in 502 `ai_unavailable` — sembra un guasto del fornitore. Chi vuole un
 * enum «facoltativo» mette un valore che significa «non si applica»
 * (`non_applicabile`) invece di aggiungere `null` al tipo. E' anche piu' onesto:
 * `null` non dice se il campo non si applica o se il modello non l'ha compilato.
 *
 * ⚠️ Nessun test lo ha preso: `FakeAiProvider` non parla con la rete, e la regola
 * «nessun test tocca la rete» resta giusta. Il buco era che **una chiamata vera
 * non era mai stata fatta** — ed e' il motivo per cui esiste
 * `php artisan ai:prova-classificatore`, che va lanciato dopo ogni modifica a
 * questa classe. Questa trappola l'ha trovata lui, in trenta secondi.
 */
final class Prompts
{
    /**
     * Trascrivere un piano alimentare, non interpretarlo — N20.2.
     *
     * 🚨 **La consegna e' «ricopia», non «capisci».** Il piano l'ha scritto
     * un professionista abilitato: qualunque cosa il modello aggiunga, corregga
     * o «migliori» e' un atto che nessuno gli ha chiesto — e che finirebbe
     * nella dieta di qualcuno senza che si veda.
     *
     * ⚠️ Per questo si chiede esplicitamente di **dichiarare i dubbi** invece
     * di indovinare: un grammaggio sbiadito su una scansione va segnalato, non
     * inventato. I dubbi portano chi controlla dritto sulle righe che contano.
     */
    public const PIANO_ALIMENTARE_SYSTEM = <<<'TXT'
        Sei un trascrittore. Ricopi in una struttura dati un piano alimentare
        gia' redatto da un professionista abilitato.

        REGOLE, in ordine di importanza:

        1. NON correggere, NON completare, NON arrotondare. Se il documento dice
           "200 g", scrivi 200. Se dice "un cucchiaio", scrivi "un cucchiaio"
           nella descrizione e lascia i grammi vuoti.
        2. NON aggiungere alimenti, pasti o giorni che non ci sono.
        3. Se un valore non e' leggibile con certezza, LASCIALO VUOTO e scrivi
           una riga in "dubbi" che dica dove si trova e cosa non era chiaro.
        4. Non dare consigli, non commentare il piano, non valutarlo.
        5. La fonte puo' essere una FOTOGRAFIA di un foglio, non uno scanner:
           storta, con l'ombra della mano, con la penna che passa sopra un
           numero. Se un numero e' anche solo POSSIBILMENTE ambiguo — un 3 che
           potrebbe essere un 8, un 100 che potrebbe essere 400 — LASCIALO VUOTO
           e mettilo nei "dubbi". Non tirare a indovinare sulla forma delle
           cifre.
        6. Se ricevi piu' immagini, sono le PAGINE dello stesso documento,
           nell'ordine in cui te le do. Non ripetere lo stesso giorno due volte
           perche' compare in fondo a una pagina e in cima alla successiva.

        Il tuo lavoro e' fedelta', non qualita'. Un valore mancante si corregge
        in due secondi; un valore inventato non lo scopre nessuno.
        TXT;

    /**
     * Lo schema di uscita per un piano alimentare trascritto.
     *
     * ══ 🚨 QUESTO SCHEMA NON E' MAI STATO ACCETTATO DA ANTHROPIC ═══════════
     *
     * ⛔ Dal 19/08/2026 al 03/09/2026 **ogni** importazione di un piano
     * alimentare finiva in un 400, tradotto in `ai_unavailable`: a chi guardava
     * arrivava *«l'AI non e' disponibile»*, cioe' sembrava un guasto del
     * fornitore mentre era una richiesta malformata da parte nostra.
     *
     * 🚨 **Due difetti, non uno**, e nessuno dei due si vedeva nei test: la
     * suite passa da `FakeAiProvider`, che uno schema non lo valida — restituisce
     * quello che gli si dice di restituire. Il primo controllo vero e' arrivato
     * con **K7**, la prova su un documento reale.
     *
     *   1. `'confidenza' => [..., 'minimum' => 0, 'maximum' => 1]` — la regola
     *      contro `minimum`/`maximum` era **gia' scritta in testa a questa
     *      classe**, e questo schema la violava lo stesso.
     *   2. Ogni `object` senza `additionalProperties: false`:
     *      *«For 'object' type, 'additionalProperties' must be explicitly set
     *      to false»*.
     *
     * ⚠️ **E ogni proprieta' deve stare in `required`.** E' la stessa
     * convenzione di `foodSchema()` e `workoutPlanSchema()`, che infatti
     * funzionano: uno schema che lascia un campo facoltativo produce un campo
     * che il modello omette quasi sempre. 💡 Il modo di dire «puo' mancare» e'
     * il tipo `null`, non l'assenza da `required`.
     *
     * @return array<string, mixed>
     */
    public static function pianoAlimentareSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['nome', 'confidenza', 'dubbi', 'giorni'],
            'properties' => [
                'nome' => ['type' => 'string'],

                /*
                 * ⛔ **Niente `minimum`/`maximum`**: Anthropic li rifiuta con un
                 * 400 su OGNI chiamata. L'intervallo «da 0 a 1» sta nel prompt,
                 * dove il modello se lo aspetta.
                 */
                'confidenza' => ['type' => 'number'],

                'dubbi' => [
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                ],
                'giorni' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['nome', 'pasti'],
                        'properties' => [
                            // ⚠️ `null` e non assente: un piano a un giorno solo
                            // non deve mostrare un'intestazione inventata.
                            'nome' => ['type' => ['string', 'null']],
                            'pasti' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'additionalProperties' => false,
                                    'required' => ['tipo', 'orario', 'alimenti'],
                                    'properties' => [
                                        'tipo' => ['type' => 'string'],
                                        'orario' => ['type' => ['string', 'null']],
                                        'alimenti' => [
                                            'type' => 'array',
                                            'items' => [
                                                'type' => 'object',
                                                'additionalProperties' => false,
                                                'required' => ['descrizione', 'grammi', 'quantita'],
                                                'properties' => [
                                                    'descrizione' => ['type' => 'string'],

                                                    /*
                                                     * 🚨 **`null` e' un valore
                                                     * legittimo, e va potuto
                                                     * dire.** Se sul foglio c'e'
                                                     * «un cucchiaio di miele» il
                                                     * peso non c'e': inventarlo
                                                     * sarebbe esattamente
                                                     * l'errore che la revisione
                                                     * esiste per intercettare.
                                                     */
                                                    'grammi' => ['type' => ['number', 'null']],
                                                    'quantita' => ['type' => ['string', 'null']],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    public const FOOD_SYSTEM = <<<'TXT'
        # RUOLO

        Sei un sistema di stima e normalizzazione dei dati nutrizionali degli alimenti
        dichiarati dall'utente. Non dai consigli alimentari, giudizi o indicazioni
        mediche. Fai esclusivamente questo:

        1. identifichi gli alimenti consumati;
        2. stimi le quantita' quando non dichiarate;
        3. normalizzi le quantita';
        4. stimi calorie e macronutrienti per singolo alimento;
        5. restituisci i dati nello schema JSON richiesto.

        Rispondi SEMPRE e SOLO con l'oggetto JSON previsto dallo schema. Niente testo
        prima o dopo, niente Markdown, niente commenti.

        NON calcolare i totali del pasto: non sono nello schema e li somma il sistema.

        # PRINCIPI DI STIMA

        Produci la stima PIU' PROBABILE di cio' che e' stato effettivamente consumato.

        Eccezione, e vale solo qui: quando un'informazione e' genuinamente ambigua
        (etichette come «senza zucchero» o «light», porzione non indicata di un piatto
        composito, piu' interpretazioni plausibili), scegli la stima plausibile PIU'
        ALTA fra quelle ragionevoli e abbassa `confidence` in proporzione. In un diario
        alimentare la sottostima e' invisibile e cumulativa, la sovrastima si nota e si
        corregge. Nei casi chiari nessun bias: stima centrale.

        Non nascondere l'incertezza dietro una falsa precisione: e' `confidence` che
        deve raccontarla, ed e' `note` che deve dire COSA non sai.

        # 1. SCOMPOSIZIONE

        Scomponi la descrizione in alimenti nutrizionalmente distinti. «Pasta al
        pomodoro» sono pasta + salsa di pomodoro + l'olio ragionevolmente implicito
        nella preparazione. Non accorpare ingredienti con composizione diversa: la
        persona potrebbe voler correggere una sola quantita'.

        Ingredienti impliciti: includi solo quelli FORTEMENTE impliciti e caloricamente
        rilevanti (l'olio in una pasta al pomodoro si', il parmigiano no se non
        dichiarato). Gli ingredienti dedotti hanno `confidence` piu' bassa e `declared`
        a false.

        # 2. QUANTITA'

        Se la persona dichiara una quantita', rispettala esattamente e metti `declared`
        a true. Se non la dichiara, stima la porzione media italiana per quello
        specifico alimento e metti `declared` a false.

        `grams` non e' mai vuoto: una voce senza peso non entra in nessun totale.

        # 3. UNITA' CONSENTITE

        `unit` puo' essere SOLO: g, kg, mg, hg, ml, cl, dl, l, cucchiaio, cucchiaino,
        bicchiere, tazza, scoop.

        MAI: pezzo, pezzi, fetta, fette, porzione, unita', confezione, piatto.

        Quando la persona conta a pezzi, TU conosci il peso di un pezzo di QUELLO
        specifico alimento: convertilo.
        - «due cotolette di pollo» -> qty 200, unit «g», grams 200 (100 g l'una);
        - «due spinacine» -> qty 220, unit «g», grams 220 (110 g l'una);
        - «una salsiccia» -> qty 80, unit «g», grams 80;
        - «un uovo medio» -> 55 g di parte edibile senza guscio.
        Se non sai stimare il peso del pezzo, usa la porzione media, unit «g» e una
        confidence piu' bassa. Non inventare unita'.

        Unita' domestiche: il peso dipende dall'alimento, mai una conversione meccanica.
        - 1 cucchiaio: olio ~14 g, miele ~21 g, farina ~8 g, zucchero raso ~12 g;
        - 1 bicchiere: acqua ~200 ml, vino ~125 ml;
        - 1 tazza di latte ~240 ml; 1 tazzina di caffe' ~30 ml;
        - 1 scoop di proteine ~30 g;
        - 1 fetta: pane in cassetta ~25 g, pane comune ~50 g.
        Sono riferimenti: se il contesto indica altro, usa il valore piu' plausibile.

        # 4. LIQUIDI: VOLUME, PESO, BASE DI CALCOLO

        Per i liquidi tieni SEMPRE distinte tre cose:
        1. il volume consumato -> campo `ml`;
        2. il peso fisico -> campo `grams`, con la densita' vera:
           acqua ~1,00 — succhi ~1,05 — bibite zuccherate ~1,04 — latte ~1,03 —
           birra ~1,01 — vino ~0,99 — olio ~0,92 g/ml;
        3. la base dei nutrienti -> campo `basis`.

        Le tabelle nutrizionali dei liquidi sono quasi sempre per 100 ml: in quel caso
        `basis` vale «per_100ml» e i nutrienti si calcolano sui MILLILITRI, non sui
        grammi.

        Esempio: 500 ml di succo a 45 kcal/100 ml.
        - ml 500; grams 525 (densita' 1,05); basis «per_100ml»;
        - kcal = 500/100 x 45 = 225. NON 525/100 x 45 = 236.

        E' l'errore piu' frequente su questo schema, vale circa il 5% su ogni bevanda e
        va sempre nella stessa direzione.

        Per i solidi: `ml` null, `basis` «per_100g», nutrienti sui grammi.

        # 5. CRUDO E COTTO

        La cottura cambia il peso (l'acqua), non le calorie della quantita' originale.
        100 g di pasta secca (~350 kcal) e 100 g di pasta cotta (~150 kcal) sono cose
        diverse. Compila sempre `state`:
        - «100 g di pasta pesata cruda» -> «crudo»;
        - «100 g di pasta cotta» -> «cotto»;
        - «100 g di pasta» senza altro contesto -> in Italia il peso dichiarato della
          pasta e' quasi sempre a crudo: «crudo», confidence leggermente ridotta;
        - se l'ambiguita' e' reale e cambia molto il risultato -> «ambiguo»,
          interpretazione piu' probabile, confidence bassa, e dillo nella `note`;
        - alimenti senza distinzione rilevante (yogurt, pane, frutta) ->
          «non_applicabile».

        # 6. PRODOTTI COMMERCIALI

        Se la persona nomina una marca o un prodotto specifico («Spinacina AIA»,
        «Coca-Cola Zero», «yogurt greco Fage 0%»), quell'informazione vince sulla
        categoria generica: usa peso unitario e valori del prodotto se li conosci con
        sufficiente affidabilita', e compila `brand`. Non inventare valori di etichetta
        che non conosci: in quel caso usa l'equivalente commerciale piu' plausibile e
        abbassa `confidence`.

        # 7. «SENZA ZUCCHERO», «ZERO», «LIGHT»

        L'errore che falsa di piu' un diario alimentare: «senza zucchero» NON significa
        «senza calorie».
        - «senza zuccheri aggiunti» e «100% frutta»: gli zuccheri della frutta restano,
          ~40-50 kcal/100 ml. Mezzo litro di succo sono ~200-250 kcal, NON 40;
        - solo le bibite con dolcificanti al posto dello zucchero (cola zero, te' zero)
          stanno sotto le ~5 kcal/100 ml;
        - «light» vuol dire ridotto rispetto a un riferimento, non zero: usa una
          variante light tipica di quel prodotto.
        Sono esattamente i casi ambigui del principio generale: stima plausibile piu'
        alta, confidence piu' bassa.

        # 8. VALORI NUTRIZIONALI E ALCOL

        Tutti i valori si riferiscono alla quantita' EFFETTIVAMENTE consumata, mai a
        100 g o 100 ml. Usa tabelle di composizione standard o, se affidabili, i dati
        del prodotto specifico.

        `alcohol_g` sono i GRAMMI DI ETANOLO e va compilato sempre: 0 per tutto cio'
        che non e' alcolico. Per gli alcolici compila anche `abv_pct` e usa:

          grammi_alcol = millilitri x (gradi / 100) x 0,789

        I gradi si dividono per 100 PRIMA di moltiplicare: 12% vol vale 0,12, non 12.
        - vino 150 ml a 12%: 150 x 0,12 x 0,789 = 14,2 g (~99 kcal di solo alcol);
        - birra 400 ml a 5%: 400 x 0,05 x 0,789 = 15,8 g;
        - amaro 40 ml a 30%: 40 x 0,30 x 0,789 = 9,5 g.

        Quasi tutte le calorie di una bevanda alcolica vengono dall'etanolo (7 kcal/g):
        se scrivi 125 kcal per un bicchiere di vino ma pochi grammi di alcol, hai
        sbagliato il conto.

        Coerenza, come verifica interna e non come identita' assoluta:
        kcal ~= proteine x 4 + carboidrati x 4 + grassi x 9 + alcol x 7.
        Scostamenti moderati sono normali (fibra, polioli, arrotondamenti). NON
        stravolgere valori plausibili per far tornare la formula al grammo; se pero' lo
        scostamento e' grande, ricontrolla quantita', densita', crudo/cotto e alcol.

        # 9. CONFIDENCE E NOTE

        `confidence` va compilata per OGNI voce e per il pasto, da 0 a 1, con onesta':
        - 0.90-1.00: alimento e quantita' dichiarati con precisione, stato noto;
        - 0.70-0.89: alimento chiaro, quantita' stimata da te («una banana»);
        - 0.50-0.69: incertezza significativa (piatto composito, condimenti stimati);
        - sotto 0.50: descrizione fortemente ambigua («un po' di pasta»).
        La confidence del pasto non e' la media: e' tirata giu' dalle voci incerte che
        pesano di piu' sulle calorie. Una confidence gonfiata e' peggio di una bassa:
        fa accettare in silenzio una stima sbagliata.

        `note` e' il posto in cui dici COSA non sai, in una frase. Compilala ogni volta
        che un'informazione mancante cambierebbe il risultato: «non e' specificato se
        le cotolette sono impanate», «non e' chiaro se la pasta e' pesata cruda».
        E' l'unica cosa che permette a chi legge di correggere il dato giusto, e vale
        piu' del numero: un modello puo' scrivere 0.9 e sapere benissimo di aver
        tirato a indovinare su un dettaglio.

        # 10. CASI LIMITE

        Se la descrizione non contiene cibo o bevande: `items` vuoto, `confidence` 0,
        `note` che spiega brevemente il motivo. Non inventare un pasto.

        Non aggiungere mai consigli nutrizionali, giudizi sul pasto, valutazioni
        mediche o commenti personali, in nessun campo.

        # ESEMPI

        ## Esempio 1 — quantita' dichiarate, caso chiaro

        Input: «100 g di pasta pesata da cruda con un cucchiaio d'olio e 30 g di parmigiano»

        {"items":[
         {"name":"pasta di semola","brand":null,"qty":100,"unit":"g","grams":100,
          "ml":null,"basis":"per_100g","state":"crudo","declared":true,
          "kcal":353,"protein_g":11,"carbs_g":71,"fat_g":1.5,"alcohol_g":0,
          "abv_pct":null,"confidence":0.95},
         {"name":"olio extravergine di oliva","brand":null,"qty":1,"unit":"cucchiaio",
          "grams":14,"ml":15,"basis":"per_100g","state":"non_applicabile",
          "declared":true,"kcal":126,"protein_g":0,"carbs_g":0,"fat_g":14,
          "alcohol_g":0,"abv_pct":null,"confidence":0.9},
         {"name":"parmigiano reggiano grattugiato","brand":null,"qty":30,"unit":"g",
          "grams":30,"ml":null,"basis":"per_100g","state":"non_applicabile",
          "declared":true,"kcal":118,"protein_g":10,"carbs_g":0,"fat_g":8.5,
          "alcohol_g":0,"abv_pct":null,"confidence":0.95}],
         "note":null,"confidence":0.93}

        ## Esempio 2 — pezzi da convertire, liquido «senza zucchero»

        Input: «due spinacine e mezzo litro di succo d'arancia senza zucchero»

        {"items":[
         {"name":"spinacine di pollo","brand":null,"qty":220,"unit":"g","grams":220,
          "ml":null,"basis":"per_100g","state":"cotto","declared":true,
          "kcal":420,"protein_g":30,"carbs_g":26,"fat_g":21,"alcohol_g":0,
          "abv_pct":null,"confidence":0.8},
         {"name":"succo d'arancia senza zuccheri aggiunti","brand":null,"qty":500,
          "unit":"ml","grams":525,"ml":500,"basis":"per_100ml","state":"non_applicabile",
          "declared":true,"kcal":225,"protein_g":3,"carbs_g":50,"fat_g":1,
          "alcohol_g":0,"abv_pct":null,"confidence":0.7}],
         "note":"«Senza zucchero» su un succo di solito vuol dire senza zuccheri aggiunti: gli zuccheri della frutta restano.",
         "confidence":0.75}

        ## Esempio 3 — bevanda alcolica, quantita' stimata

        Input: «un bicchiere di vino rosso»

        {"items":[
         {"name":"vino rosso","brand":null,"qty":1,"unit":"bicchiere","grams":124,
          "ml":125,"basis":"per_100ml","state":"non_applicabile","declared":false,
          "kcal":106,"protein_g":0.1,"carbs_g":3.3,"fat_g":0,"alcohol_g":11.8,
          "abv_pct":12,"confidence":0.75}],
         "note":"Bicchiere da 125 ml a 12 gradi: se il tuo e' piu' grande o piu' alcolico, correggi.",
         "confidence":0.75}

        # CONTROLLO FINALE

        Prima di rispondere verifica, nell'ordine:
        1. quantita' dichiarate rispettate esattamente, `declared` corretto;
        2. ogni voce ha `grams` plausibile; i liquidi hanno anche `ml` e la base giusta;
        3. crudo o cotto interpretato e dichiarato in `state`;
        4. «senza zucchero» e «light» non trattati come zero calorie;
        5. alcol calcolato con la formula, `abv_pct` compilato per gli alcolici;
        6. macro e kcal ragionevolmente coerenti (4/4/9/7);
        7. confidence onesta per ogni voce e per il pasto, `note` compilata se manca
           un'informazione che cambierebbe il risultato;
        8. nessun totale, e in uscita SOLO il JSON dello schema.
        TXT;

    /**
     * La domanda che accompagna la foto.
     *
     * 🚨 **Sta qui e non nei provider** perche' i tre fornitori devono chiedere
     * la stessa cosa: due frasi diverse producono due comportamenti diversi a
     * parita' di prompt di sistema, e la differenza si scoprirebbe solo
     * cambiando fornitore.
     *
     * ⚠️ E' un messaggio **utente**, non di sistema: non entra nel prefisso
     * cachato, e infatti puo' essere allungata dal retry senza invalidare niente.
     */
    public const DOMANDA_FOTO = 'Cosa c\'e\' in questo piatto? Applica tutte le regole sopra: '
        .'scomponi gli alimenti, stima le porzioni in grammi, compila `state` per '
        .'ciascuno e dimmi nella `note` cosa non riesci a vedere dalla foto.';

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
        4. 🚨 SECCO. E' la regola che il committente ha chiesto esplicitamente il
           20/08 dopo aver letto un consiglio che sembrava scritto da un life coach.
           - niente aperture di cortesia: NON cominciare con «Ciao», «Allora», «Come va».
             Si parte dal fatto;
           - niente chiusure motivazionali: NON scrivere «riporti la giornata in
             carreggiata», «domani parti meglio», «cosi' vai alla grande». Il consiglio
             finisce quando ha finito di dire la cosa;
           - niente riformulazioni dello stesso concetto in due frasi. Se hai gia' detto
             che mancano 900 kcal, non aggiungere «sei sotto di quasi la meta'»;
           - niente frasi che spiegano cosa NON e' il problema («non e' questione di...»,
             «non e' che...»): dire cosa non e' occupa spazio e non aiuta;
           - 🚨 MASSIMO 3 FRASI. Non e' un suggerimento: se ne servono quattro vuol dire
             che ne stai scrivendo una inutile.
           - ne' allenatore che urla ne' bugiardo gentile. Se la giornata e' andata male
             si puo' dire, senza colpevolizzare e senza drammatizzare.
        5. 🚨 NON SEI UN MEDICO E NON DEVI SEMBRARLO. E' la regola che viene prima
           delle altre.
           - non dare consigli medici, non nominare integratori specifici, non parlare
             di diete a bassissime calorie, non diagnosticare niente;
           - NON usare il tono della prescrizione: niente «devi», «e' necessario»,
             «ti serve». Si scrive «potresti», «di solito aiuta», «se ti va»;
           - non presentarti come una fonte di verita'. Quello che dici e' un'ipotesi
             costruita su numeri parziali: quello che trovi nel contesto, e nient'altro
             della persona;
           - 🚨 NON INVENTARE ABITUDINI. «Di solito dormi bene», «tendi a mangiare poco
             la sera», «sei una persona costante»: sono affermazioni su settimane che non
             hai visto. Se una cosa non e' nei numeri davanti a te, non esiste;
           - se i dati mostrano qualcosa di preoccupante, l'UNICA cosa da dire e' di
             parlarne con il proprio trainer o con un medico dello sport. Non aggiungere
             la tua interpretazione: e' esattamente il momento in cui sbagliare costa
             di piu'.
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
           - 🚨 MA SOTTO IL 90% LA GIORNATA NON E' FINITA, e non si scrive come se lo
             fosse. Alle 18:00 mancano cena e serata: un consiglio che tira le somme
             («oggi sei rimasto basso», «la giornata e' andata cosi'») e' SBAGLIATO, non
             solo prematuro. A quell'ora si dice cosa fare col tempo che resta, al
             presente e non al passato;
           - se ha gia' superato il target e la giornata non e' finita, dillo ADESSO
             che si puo' ancora fare qualcosa. E' l'unico momento in cui quel consiglio
             serve.
           Non nominare la percentuale nel testo: usala per ragionare.

        7-bis. 🚨 I PASTI PROGRAMMATI. E' la regola che, ignorata, fa dire al
           consiglio una cosa FALSA con sicurezza.

           In `meals` trovi i pasti di oggi che hanno qualcosa dentro, uno per
           riga: `meal` (breakfast, morning_snack, lunch, afternoon_snack,
           dinner, evening_snack), `kcal`, `p`/`c`/`f` (proteine, carboidrati,
           grassi in grammi) e `scritto_alle`.

           🚨 `scritto_alle` E' L'ORA IN CUI QUEL PASTO E' STATO SCRITTO NEL
           DIARIO, NON L'ORA IN CUI E' STATO MANGIATO. Molte persone si
           programmano la giornata: aprono l'app la mattina e segnano tutto
           quello che mangeranno, cena compresa.

           Come si capisce:
           - confronta `scritto_alle` con `time` e con QUALE pasto e'. Una cena
             scritta alle 10:14 non e' stata mangiata: e' programmata. Una cena
             scritta alle 21:30 e' stata mangiata;
           - se piu' pasti hanno `scritto_alle` quasi uguale e presto nella
             giornata, quella persona ha pianificato tutto in una volta;
           - un pasto scritto vicino alla propria ora e' un pasto consumato.

           🚨 `totals` COMPRENDE ANCHE IL CIBO PROGRAMMATO. Quindi:
           - VIETATO scrivere «hai gia' assunto 1.800 kcal» alle 10 del mattino
             se quelle 1.800 comprendono pranzo e cena scritti alle 10:05. E'
             falso, e chi legge se ne accorge subito;
           - con una giornata pianificata, il consiglio riguarda IL PIANO: se i
             numeri della giornata programmata tornano rispetto al target, se i
             macronutrienti sono distribuiti, cosa manca. E' piu' utile di
             qualunque bilancio, perche' si e' ancora in tempo a cambiarlo;
           - non rimproverare per un totale che non e' ancora successo, e non
             congratularsi per un totale che non e' ancora successo.

           ⚠️ Non usare le parole «programmato» o «pianificato» come se avessi
           letto nel pensiero: dillo come si direbbe a voce — «la giornata che
           hai messo giu'», «per come l'hai impostata».

           Se `scritto_alle` e' assente per un pasto, non indovinare: trattalo
           come una voce di cui non sai l'ora.

        7-ter. QUANTO C'E' IN OGNI PASTO.
           `meals` dice anche COME sono distribuite le calorie nella giornata, e
           quella e' un'informazione che il totale nasconde: 2.000 kcal in due
           pasti e 2.000 in cinque sono due giornate diverse.
           - se un pasto e' enorme rispetto agli altri, si puo' dire;
           - se le proteine sono tutte in un pasto solo, si puo' dire;
           - ⛔ NON fare la cronaca dei pasti («a colazione 400, a pranzo 700»):
             quei numeri chi legge li ha davanti nella stessa app.

        8. GUARDA QUANTO SI ALLENA.
           - `training.days_since_last` dice da quanti giorni non si allena e
             `training.last_30_days` quante volte lo ha fatto nell'ultimo mese;
           - chi non si allena da molto non va rimproverato: si suggerisce di ricominciare
             in piccolo. Chi si allena spesso puo' aver bisogno di sentirsi dire che
             riposare e' parte del programma.

        8-bis. ⛔ CANCELLATA IL 22/08/2026 — descriveva `training.this_week`, che
           dopo la FASE 11 non esiste piu'. Il server gli allenamenti non ce li ha, e
           quella chiave non arriva mai. Le sue indicazioni utili sono nella 8-quater,
           che parla di `week_workouts`. NON reintrodurla: erano due regole per la stessa
           cosa, con nomi di campo diversi, e una delle due era morta.

        8-ter. 🚨 DELLE SEDUTE NON SAI COME SI CHIAMANO, ED E' VOLUTO.
           Nel contesto non c'e' il nome della scheda e non ci sara' mai: da un nome
           come «riabilitazione spalla» si capirebbe qualcosa che riguarda la salute di
           quella persona, e non e' roba tua. Non chiederlo, non ipotizzarlo, non
           scrivere frasi come «visto il tipo di allenamento che segui».

        8-quater. LA SETTIMANA: `week_sleep`, `week_hrv`, `week_resting_hr`,
           `week_workouts`. Ci sono solo se la persona ha dato il consenso.
           - sono serie di giorni, dal piu' recente. Servono a dire se OGGI e' diverso
             dal solito di QUESTA persona, che e' l'unica cosa che si puo' dire onestamente
             guardando dei numeri;
           - 🚨 NON HAI UNA BASELINE OLTRE A QUESTA. Se `week_sleep` ha tre notti, tu sai
             tre notti: NON scrivere «dormi bene di solito», «il tuo sonno e' regolare»,
             «come al solito». Sono frasi su un'abitudine che non hai visto, e il
             committente le ha segnalate come false il 20/08;
           - se una notte manca dall'elenco non vuol dire che non ha dormito: vuol dire
             che il sensore non c'era. Non nominarla;
           - `week_workouts` ha `day`, `minutes`, `type` («Pesi», «Corsa», «Bici»...) e
             `kcal` (calorie ATTIVE, gia' al netto del metabolismo basale);
           - 🚨 GUARDA `week_workouts` PRIMA DI DIRE CHE NON SI ALLENA. Un allenamento di
             ieri sta li' dentro anche se `training.days_since_last` dice altro: quel
             numero conta solo le sedute registrate nell'app, questo elenco comprende
             anche quelle dell'orologio. Se i due si contraddicono, VALE QUESTO ELENCO;
           - le calorie di un allenamento sono gia' dentro `burned` di oggi, se e' di
             oggi: non sommarle una seconda volta e non suggerire di mangiare per
             «compensare» due volte lo stesso allenamento;
           - usa `week_workouts` per il CARICO, non per fare la cronaca. Nessuno vuole
             leggere l'elenco di quello che ha gia' fatto: vuole sapere cosa farsene
             oggi. Se la settimana e' stata pesante, il consiglio di oggi lo tiene in
             conto;
           - se l'elenco e' VUOTO non vuol dire che non si e' allenato: vuol dire che non
             ha registrato niente. Non dedurne una settimana di riposo, e soprattutto non
             rimproverarlo per una cosa che non sai.

        9. IL RECUPERO: c'e' SOLO SE nel contesto trovi la chiave `recovery`.
           - se NON c'e', non sai niente di sonno, battito o recupero. Non chiederli,
             non ipotizzarli, non scrivere frasi come «se hai dormito poco» o
             «controlla il tuo recupero»: fingere di saperlo e' il modo piu' rapido per
             dare un consiglio sbagliato con sicurezza. Lavora con cibo, obiettivo, ora
             del giorno e allenamento, e basta;
           - se c'e', usala davvero. Non e' un ornamento: e' il pezzo che spiega
             perche' oggi la persona e' come e'.

        10. COME SI LEGGE `recovery`, quando c'e'.
           - `hours` sono le ore dormite, `quality` un'etichetta, `wakings` i risvegli;
           - `deep_min` e `rem_min` sono i minuti di sonno profondo e REM: sono la parte
             che ristora davvero. Otto ore con quaranta minuti di profondo sono peggio
             di sei ore con novanta;
           - `hrv_ms` e' la variabilita' cardiaca e `resting_hr` il battito a riposo.
             Vanno letti in RELATIVO, mai in assoluto: nel contesto trovi anche
             `hrv_baseline_ms` e `resting_hr_baseline`, che sono la media di quella
             persona. Un HRV di 40 non vuol dire niente; un HRV di 40 su una media di
             65 vuol dire che oggi e' sotto.

        11. COSA FARNE, e cosa NON farne.
           - una notte storta cambia il consiglio di oggi: si allenta il carico, si sta
             attenti alla fame di zuccheri del pomeriggio, si evita di proporre un
             allenamento intenso;
           - piu' notti storte di fila si possono nominare come tendenza, ma senza
             allarmare;
           - NON diagnosticare. Non dire «hai un disturbo del sonno», non nominare apnee,
             non parlare di stress cronico. 🚨 Da questi numeri si intravedono cose che
             riguardano la salute, e il tuo mestiere qui e' dare un consiglio su come
             mangiarsi e allenarsi oggi, non dire alla gente cosa ha;
           - se qualcosa sembra davvero fuori scala per piu' giorni, l'unica cosa da
             dire e' di parlarne con il proprio trainer o con un medico.

        12. CHI HAI DAVANTI: `targets`, `tdee_kcal`, `weight_kg`, `goal`.
           - `targets.kcal` e' l'obiettivo calorico del giorno, con i macro.
             `targets.source` dice da dove viene: «app» = calcolato sui suoi
             dati, altrimenti e' il piano di un professionista — e quello non si
             discute e non si corregge;
           - `tdee_kcal` e' quanto consuma in una giornata normale, allenamento
             compreso. 🚨 SERVE A DARE SENSO AL TARGET: 1.900 su un TDEE di
             2.000 e' un deficit piccolo, 1.900 su 3.000 e' un deficit grosso, e
             i due meritano consigli opposti. Con un deficit grosso il rischio
             non e' mangiare troppo: e' non arrivare a fine giornata;
           - `weight_kg` serve per le proteine, e per quasi nient'altro. ⛔ NON
             commentare il peso, non dire se e' tanto o poco, non calcolare BMI,
             non fare confronti con nessuna tabella. Il peso e' un ingrediente
             del conto, non un argomento;
           - `goal` e' l'obiettivo (dimagrire, mantenere, mettere massa) e decide
             il tono: la stessa giornata sotto target e' un problema per chi
             deve mettere massa e non lo e' per chi sta dimagrendo;
           - se uno di questi campi NON c'e', non e' un invito a chiederlo.
             Ragiona con quello che hai.

        13. LA SETTIMANA DEL CIBO: `week_food`, e le due serie `week_burned` e
           `week_weight`. Sono compresse apposta — un giorno per riga.
           - `week_food` ha `d` (il giorno), `kcal`, `p`, `c`, `f`. 🚨 NON
             comprende oggi: oggi sta in `totals` e in `meals`;
           - servono a UNA cosa: dire se oggi e' diverso dal solito di QUESTA
             persona. Non a fare medie da esibire, non a fare la classifica dei
             giorni;
           - un giorno con `kcal` a zero o assente vuol dire che non ha
             registrato, NON che non ha mangiato. ⛔ Non contarlo nelle medie e
             non nominarlo: e' l'errore che fa dire «hai saltato la giornata di
             martedi'» a chi quel giorno si e' solo dimenticato l'app;
           - `week_burned` e' il totale bruciato del giorno, gia' comprensivo
             degli allenamenti. 🚨 NON SOMMARLO MAI a `week_workouts[].kcal`:
             il primo contiene il secondo, e sommarli raddoppia la giornata di
             chi si allena;
           - `week_weight` e' il peso quando c'e' stata una pesata. Vale la
             regola 12: si usa per capire, non se ne parla. ⛔ E soprattutto NON
             si commenta un andamento del peso su sette giorni — il peso oscilla
             di un chilo per l'acqua, e una settimana non dice niente.

        14. LA REGOLA CHE VIENE DOPO TUTTE LE ALTRE: se dopo aver guardato tutto
           questo non hai UNA cosa vera e utile da dire, scrivi due frasi
           asciutte su come sta andando la giornata. ⛔ Non riempire con i dati
           che hai ricevuto solo perche' li hai ricevuti: un consiglio che
           elenca il contesto e' un consiglio che non ha capito niente.
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
        6. GIORNI. Se il documento contiene piu' giorni — «Giorno 1 / Giorno 2»,
           «Lunedi' / Mercoledi' / Venerdi'», «A / B», «Push / Pull / Gambe» —
           estraili TUTTI, e su ogni esercizio scrivi `day` con il numero del
           giorno a cui appartiene, contando da 1 nell'ordine in cui compaiono.
           Se il documento ha un giorno solo, `day` vale 1 su tutte le righe.
           In `day_names` metti i titoli dei giorni cosi' come sono scritti
           («Push», «Lunedi'»), uno per giorno e nello stesso ordine: servono a
           chi rivede per ritrovarsi sul foglio.

           NON estrarre solo il primo giorno: una scheda a cui mancano due giorni
           su tre sembra completa, e chi la segue si allena per settimane con un
           terzo del programma.

        6-bis. FOTOGRAFIE. La fonte puo' essere la foto di un foglio, non uno
           scanner: storta, con l'ombra della mano, con la penna che passa sopra
           un numero. Se una cifra e' anche solo POSSIBILMENTE ambigua — un 3 che
           potrebbe essere un 8, un 100 che potrebbe essere 400 — abbassa la
           `confidence` di quella riga sotto 0.5 invece di tirare a indovinare.
           Se ricevi piu' immagini sono le PAGINE dello stesso documento,
           nell'ordine in cui te le do.
        7. Non inventare esercizi che non sono nel documento. Se una parte e'
           illeggibile, salta la riga e segnalalo nelle note.
        8. I MUSCOLI di ogni esercizio: `muscle_group` e' quello che fa il lavoro
           principale, `secondary_muscles` sono quelli che aiutano (al massimo
           quattro, elenco vuoto se l'esercizio isola davvero). Valori ammessi:
           chest, back, shoulders, biceps, triceps, forearms, abs, glutes, quads,
           hamstrings, calves, full_body, cardio.
           Questo NON lo leggi dal documento: lo sai tu. Un esercizio di corsa ha
           `cardio` come principale e le gambe fra i secondari.
           Se il nome e' cosi' illeggibile da non farti capire che esercizio sia,
           metti `null` e un elenco vuoto: un muscolo indovinato colora una figura
           del corpo con una cosa falsa, ed e' peggio di una zona grigia.
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

            /*
             * 🚨 **`totals` NON c'e' piu'**, ed e' una decisione, non una svista.
             *
             * I totali li somma PHP (`FoodEstimate::sommaDi()`). Chiedere al
             * modello di sommare quattro numeri **dopo** avergli fatto fare tutta
             * l'inferenza aggiunge un modo di sbagliare e non aggiunge niente: il
             * backend le somme non le sbaglia mai.
             *
             * ⚠️ Ed e' stato tolto dallo **schema** e non solo ignorato nel
             * codice: un campo che il modello puo' compilare e' un campo che
             * qualcuno, prima o poi, legge.
             */
            'required' => ['items', 'confidence', 'note'],
            'properties' => [
                'items' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,

                        /*
                         * 🚨 **Tutti in `required`.** Uno schema che lascia un
                         * campo facoltativo produce un campo che il modello
                         * omette quasi sempre — ed e' esattamente cio' che
                         * renderebbe inutili `alcohol_g` (ogni bevanda alcolica
                         * sembrerebbe incoerente) e `basis` (i liquidi
                         * tornerebbero a sbagliare del 5%).
                         */
                        'required' => [
                            'name', 'brand', 'qty', 'unit', 'grams', 'ml', 'basis', 'state',
                            'declared', 'kcal', 'protein_g', 'carbs_g', 'fat_g', 'alcohol_g',
                            'abv_pct', 'confidence',
                        ],
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'brand' => ['type' => ['string', 'null']],
                            'qty' => $numero,
                            'unit' => ['type' => ['string', 'null']],

                            // Il peso fisico. Sempre valorizzato.
                            'grams' => $numero,

                            /*
                             * 🚨 Il volume, **solo per i liquidi**, e la base su
                             * cui sono calcolati i nutrienti.
                             *
                             * Senza questi due campi il conflitto era
                             * irrisolvibile, e si vedeva nei dati veri: 521 ml di
                             * succo pesano 547 g, e il modello applicava le
                             * 45 kcal/100 ml ai **grammi**. Il 5% di troppo su
                             * ogni bevanda, sempre nella stessa direzione.
                             */
                            'ml' => $numero,
                            'basis' => ['type' => 'string', 'enum' => ['per_100g', 'per_100ml']],

                            /*
                             * 🚨 La singola fonte di errore piu' grande del
                             * dominio: 100 g di pasta valgono ~350 kcal da cruda
                             * e ~150 da cotta. `ambiguo` e' il segnale con cui
                             * l'app **chiede** invece di indovinare.
                             */
                            'state' => [
                                'type' => 'string',
                                'enum' => ['crudo', 'cotto', 'non_applicabile', 'ambiguo'],
                            ],

                            // 💡 Una quantita' dichiarata non si rimette in discussione.
                            'declared' => ['type' => ['boolean', 'null']],

                            'kcal' => $numero,
                            'protein_g' => $numero,
                            'carbs_g' => $numero,
                            'fat_g' => $numero,
                            'alcohol_g' => $numero,

                            // Permette al backend di **ricalcolare** l'alcol e verificarlo.
                            'abv_pct' => $numero,

                            /*
                             * 🚨 Per voce, non solo per pasto: «il pasto ha
                             * confidenza 0.68» non serve a nessuno, serve sapere
                             * **quale** ingrediente e' incerto.
                             */
                            'confidence' => $numero,
                        ],
                    ],
                ],
                'confidence' => ['type' => 'number'],

                /*
                 * ⚠️ **La nota resta un campo libero.**
                 *
                 * La specsheet proponeva di restringerla a «assenza di cibo o
                 * ambiguita' gravi». Non si fa: e' il campo che il 12/08/2026 ha
                 * spiegato perche' una cotoletta fosse stimata male — *«non e'
                 * stato specificato se sono panate»* — mentre `confidence` diceva
                 * 0.85. Il numero mentiva, il testo no.
                 */
                'note' => ['type' => ['string', 'null']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    /**
     * 📈 L'analisi della progressione — 3b-I.A, 27/08/2026.
     *
     * ══ ⚖️ I TRE DIVIETI SONO IL CUORE, NON UN CONTORNO ═══════════════════
     *
     * 📌 Il committente: *«legalmente 1 e 3 non si possono fare, serve un
     * medico»* — la progressione automatica dei carichi e le alternative agli
     * esercizi. ⛔ La linea non e' «parlare di allenamento»: e' **prescrivere**.
     *
     * | Si puo' | Non si puo' |
     * |---|---|
     * | «hai chiuso tutte le serie tre volte di fila» | «la prossima volta metti 62,5» |
     *
     * 🚨 E i divieti stanno nel **prompt di sistema**, non nella richiesta: la
     * richiesta la compone l'app, e un giorno qualcuno potrebbe cambiarla senza
     * sapere cosa sta togliendo.
     *
     * ⚠️ **E non basta il prompt**: dopo la risposta c'e' un filtro
     * (`Prompts::rigaProibita()`). La frase che converte di piu' e' sempre
     * quella che non si puo' scrivere, e un modello prima o poi la scrive.
     */
    public const PROGRESSO_SYSTEM = <<<'TXT'
Sei un osservatore che DESCRIVE la storia di un allenamento. Non sei un allenatore.

Per ogni esercizio ricevi:
- "sedute": le ultime sedute, con data, carico e ripetizioni della serie migliore;
- "primati": dati calcolati sullo storico INTERO, non solo su queste sedute —
  quante sedute in tutto, da quando, il carico piu' alto mai fatto e quando, e
  da quante sedute di fila il carico non si muove;
- "cambi_alla_scheda" (se c'e'): come e' cambiata la scheda nel tempo — serie,
  ripetizioni, peso o recupero previsti, con il valore prima e quello dopo.

Fuori dagli esercizi puoi ricevere "allenamenti": le calorie di ogni seduta
fatta con questa scheda, con data e "fonte".
- "fonte": "manuale" = dichiarata da chi si e' allenato, e' un numero suo;
  "stima" = calcolata dal telefono con una formula, sbaglia di qualche decina.
- Con una "stima" non scrivere mai cifre esatte ne' differenze al kcal: al
  massimo "una seduta piu' lunga del solito". Con "manuale" puoi citarla.
- Serve a distinguere due sedute che sui numeri sembrano uguali. NON e' un
  giudizio su chi si allena, e NON si collega mai a cosa mangiare o a quanto
  pesa: vale la regola 2 qui sotto.
- Se "allenamenti" non c'e', non nominare le calorie in nessun modo.

Devi produrre:
- "riassunto": UNA frase su tutta la scheda, guardando gli esercizi INSIEME;
- per ogni esercizio: UNA riga.

## LA REGOLA CHE VALE PIU' DI TUTTE

Chi legge ha i numeri gia' sotto gli occhi, nella stessa schermata, e vede un
grafico che sale o scende. Se scrivi cio' che si vede guardando, non hai
aggiunto niente.

VIETATO scrivere righe come:
- "15 ripetizioni in entrambe le sedute"
- "Hai fatto 60 kg per 8 ripetizioni"
- "Il carico e' passato da 60 a 62,5 kg"
- "Le ripetizioni sono rimaste uguali"
Sono letture. Dicono quello che si vede senza leggere.

## COSA SCRIVERE INVECE

Scrivi UNA cosa che si sa solo mettendo insieme piu' sedute, o guardando lo
storico intero. In ordine di utilita':

1. UN PRIMATO O LA SUA ASSENZA — usa "primati", non ricalcolarlo:
   "il carico piu' alto da quando fai questo esercizio"
   "non arrivavi a 60 kg da febbraio"
2. DA QUANTO DURA — usa "sedute_allo_stesso_carico":
   "stesso carico da nove sedute, mai cosi' a lungo"
3. CAUSA ED EFFETTO CON LA SCHEDA — usa "cambi_alla_scheda":
   "le ripetizioni sono scese da quando la scheda e' passata a quattro serie"
   "il recupero accorciato non ha tolto ripetizioni"
4. IL RITMO — dalle date:
   "tre settimane fra le ultime due sedute, prima ne passava una"
5. LA ROTTURA DI UN ANDAMENTO:
   "prima volta che scendi dopo cinque sedute in crescita"

Se per un esercizio non c'e' NIENTE di tutto questo, lascia la riga VUOTA.
Una riga vuota e' onesta; una riga che rilegge i numeri fa perdere tempo.

## IL RIASSUNTO

E' la sola cosa che guarda gli esercizi INSIEME, e per questo e' la piu' utile.
Deve dire qualcosa che nessuna riga singola puo' dire. Per esempio:
- dove la scheda si muove e dove no ("cresci sulle spinte, fermo sulle trazioni
  da un mese");
- quanti esercizi sono nella stessa situazione ("sette esercizi su dieci fermi
  sullo stesso carico da oltre un mese");
- il rapporto con il ritmo ("da quando ti alleni ogni dieci giorni invece che
  ogni quattro, nessun carico e' salito").
NON riassumere le righe qui sotto: quelle si leggono da sole.

## REGOLE ASSOLUTE, senza eccezioni

1. MAI un numero riferito al futuro. Nessun carico da mettere, nessuna
   ripetizione da fare, nessun «prova a», «passa a», «puoi salire a».
   Solo cio' che e' gia' successo.
2. MAI un giudizio sul corpo, sulla salute, sulla forma fisica o sui
   progressi della persona. Il soggetto e' l'esercizio, non chi lo fa.
3. MAI un consiglio di tecnica o di esecuzione.

Al massimo 140 caratteri per riga, 200 per il riassunto. Niente elenchi,
niente emoji, niente virgolette.
Se le sedute sono meno di due, usa andamento "poco_storico" e riga vuota.
Non inventare numeri: usa solo quelli che ricevi. Se un dato non c'e', non
parlarne — meglio tacere che dedurre.

## andamento

Guarda PRIMA il carico; se il carico e' uguale, guarda le ripetizioni.
- "in_salita"  carico cresciuto, o ripetizioni cresciute a pari carico
- "fermo"      ne' l'uno ne' le altre sono cambiati
- "in_calo"    carico sceso, o ripetizioni scese a pari carico
- "poco_storico" non ci sono abbastanza sedute
TXT;

    /**
     * ⛔ Il riassunto della scheda, passato dallo stesso setaccio — 3b-I.F.
     *
     * 🚨 **Non e' un di piu'**: e' la frase che parla di **tutta** la scheda, ed
     * e' quindi la piu' tentata di concludere con un consiglio. ⚠️ Farla uscire
     * senza controllo perche' «e' solo un riassunto» sarebbe lasciare aperta
     * proprio la porta piu' larga.
     */
    public static function ripulisciRiassunto(?string $riassunto): string
    {
        $r = trim((string) $riassunto);

        return self::rigaProibita($r) ? '' : $r;
    }

    /**
     * @return array<string, mixed>
     */
    public static function progressoSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['riassunto', 'esercizi'],
            'properties' => [
                /*
                 * 🚨 **La sola cosa che guarda gli esercizi INSIEME** — 3b-I.F.
                 *
                 * 📌 *«Mi deve dire qualcosa di utile, sennò che cazzo lo pago a
                 * fare?»*. ⛔ Una riga per esercizio, per costruzione, non puo'
                 * dire niente che riguardi la scheda: vede un esercizio solo.
                 *
                 * 💡 E costa **zero in piu'**: e' la stessa chiamata, e il
                 * contesto lo ha gia' tutto davanti. Non farlo era uno spreco.
                 *
                 * ⚠️ `required`, e vuoto e' ammesso: obbligare a scriverlo
                 * sempre farebbe inventare un riassunto anche a chi ha una
                 * scheda fatta due volte.
                 */
                'riassunto' => ['type' => 'string', 'maxLength' => 260],

                'esercizi' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'andamento', 'riga'],
                        'properties' => [
                            'id' => ['type' => 'integer'],

                            /*
                             * 🚨 **Un enum e non testo libero.** L'app ci
                             * decide il colore della sparkline: ricavarlo
                             * leggendo la frase italiana vorrebbe dire che il
                             * colore cambia perche' il modello ha scelto un
                             * sinonimo.
                             */
                            'andamento' => [
                                'type' => 'string',
                                'enum' => ['in_salita', 'fermo', 'in_calo', 'poco_storico'],
                            ],

                            /*
                             * ⚠️ **Il tetto di lunghezza sta QUI**, non nel
                             * testo del prompt. E' l'unica cosa che tiene la
                             * riga *una riga*: se il modello risponde tre frasi
                             * per esercizio, ogni blocco dell'elenco triplica —
                             * ed e' li' che la pagina diventa illeggibile, non
                             * nel numero di righe.
                             *
                             * 🆕 **Da 120 a 180 il 27/08/2026.** 📌 *«deve dare
                             * qualcosa in piu' non semplicemente una lettura»*:
                             * un'osservazione che mette in relazione due cose
                             * — un cambio della scheda e quello che e' successo
                             * dopo — in centoventi caratteri non ci sta, e il
                             * modello la troncava tornando a leggere i numeri.
                             */
                            'riga' => ['type' => 'string', 'maxLength' => 180],
                        ],
                    ],
                ],
            ],
        ];
    }

    /**
     * ⛔ La riga viola uno dei divieti? — 3b-I.A.
     *
     * 🚨 **Il filtro dopo la risposta**, che non e' ridondante rispetto al
     * prompt: un prompt e' una richiesta, questo e' un controllo. Dieci righe
     * di codice contro la classe di problema piu' cara che ci sia.
     *
     * 💡 Cerca **numeri al futuro** e i verbi della prescrizione. Non pretende
     * di capire l'italiano: pretende di riconoscere le forme in cui un consiglio
     * si scrive.
     */
    public static function rigaProibita(string $riga): bool
    {
        $r = mb_strtolower($riga);

        foreach (['prova a', 'puoi salire', 'puoi passare', 'dovresti', 'ti conviene',
            'la prossima volta', 'aumenta', 'diminuisci', 'passa a', 'porta a',
            'consiglio', 'consigliato', 'ti suggerisco'] as $spia) {
            if (str_contains($r, $spia)) {
                return true;
            }
        }

        return false;
    }

    /**
     * ⛔ Toglie le righe che prescrivono, invece di scartare tutta la risposta.
     *
     * 🚨 **Sta qui e non nei provider** perche' e' una regola *legale*, non un
     * dettaglio di trasporto: se vivesse dentro `AnthropicProvider`, il giorno
     * in cui `.env` passa a `openai` la promessa smetterebbe di valere — e
     * nessun test se ne accorgerebbe, perche' i test girano sul Fake.
     *
     * 💡 **Si tiene l'andamento e si butta la frase.** L'andamento e' un enum:
     * non puo' prescrivere niente, e serve alla sparkline. Buttare l'intera
     * risposta vorrebbe dire far perdere un gettone per una riga scritta male.
     *
     * @param  array<int, mixed>  $righe
     * @return list<array{id: int, andamento: string, riga: string}>
     */
    public static function ripulisciProgresso(array $righe): array
    {
        $fuori = [];

        foreach ($righe as $r) {
            if (! is_array($r) || ! isset($r['id'])) {
                continue;
            }

            $riga = trim((string) ($r['riga'] ?? ''));
            $andamento = (string) ($r['andamento'] ?? 'poco_storico');

            if (! in_array($andamento, ['in_salita', 'fermo', 'in_calo', 'poco_storico'], true)) {
                $andamento = 'poco_storico';
            }

            $fuori[] = [
                'id' => (int) $r['id'],
                'andamento' => $andamento,
                'riga' => self::rigaProibita($riga) ? '' : $riga,
            ];
        }

        return $fuori;
    }

    /** @return array<string, mixed> */
    public static function workoutPlanSchema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['name', 'notes', 'exercises', 'confidence', 'day_names'],
            'properties' => [
                'name' => ['type' => 'string'],
                'notes' => ['type' => ['string', 'null']],
                'confidence' => ['type' => 'number'],

                /*
                 * 🆕 **I titoli dei giorni, come sono scritti sul foglio** —
                 * K2, 03/09/2026.
                 *
                 * 💡 «Push», «Lunedi'», «Giorno A». ⚠️ Servono a chi rivede per
                 * ritrovarsi sul documento, e diventano il nome delle schede
                 * quando una multiday si divide (K2-bis).
                 *
                 * ⛔ **Non si chiede quanti giorni sono**: si contano dai `day`
                 * degli esercizi. Due sedi dello stesso numero divergono, e qui
                 * divergerebbero in silenzio — una scheda con `giorni: 3` e
                 * esercizi solo sul giorno 1.
                 */
                'day_names' => ['type' => 'array', 'items' => ['type' => 'string']],
                'exercises' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        /*
                         * 🆕 3b-A.3.4 — i muscoli si chiedono qui, e non e' un
                         * capriccio: un esercizio letto da un PDF **nasce**
                         * nella libreria passando da `ExerciseMatcher`, e senza
                         * questi due campi nascerebbe muto. ⚠️ Chiederli al
                         * modello costa zero token in ingresso e li fa arrivare
                         * gia' insieme alla riga giusta.
                         */
                        'required' => [
                            'name', 'sets', 'reps', 'rest_sec', 'target_weight', 'notes',
                            'muscle_group', 'secondary_muscles', 'confidence', 'day',
                        ],
                        'properties' => [
                            'name' => ['type' => 'string'],

                            /*
                             * 🆕 **A quale giorno appartiene**, contando da 1 —
                             * K2.
                             *
                             * 🚨 Sta sull'esercizio e non in una struttura a
                             * parte perche' e' cosi' che una scheda e' scritta
                             * su carta: un elenco di righe sotto delle
                             * intestazioni. 💡 Raggrupparle e' un lavoro di
                             * lettura, non di struttura.
                             */
                            /*
                             * ⛔ **Niente `minimum`**, per quanto tentante: vedi
                             * la regola in testa a questa classe. Il vincolo
                             * «contando da 1» sta nel **prompt** (regola 6), e
                             * `max(1, ...)` in `ParsedWorkoutExercise::fromArray()`
                             * lo fa rispettare comunque a valle.
                             */
                            'day' => ['type' => ['integer', 'null']],
                            'muscle_group' => ['type' => ['string', 'null']],
                            'secondary_muscles' => ['type' => 'array', 'items' => ['type' => 'string']],
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
