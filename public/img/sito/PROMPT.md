# I prompt per generare le immagini

Da incollare in ChatGPT, **uno per volta e nella stessa conversazione**: così le immagini successive
ereditano il tono di quelle già fatte, e le sette insieme sembrano lo stesso servizio fotografico
invece di sette immagini prese in giro per il web.

## Prima di cominciare

**Incolla questo blocco all'inizio della conversazione**, da solo. È lo stile comune: senza, ogni
immagine esce con una sua luce e una sua saturazione, e il sito sembra un collage.

> Devo generare una serie di sette fotografie per il sito di un'app di allenamento. Ti darò un
> soggetto per volta. Valgono per **tutte** queste regole, e non vanno ripetute nelle richieste
> successive:
>
> - **fotografia realistica**, non illustrazione, non rendering 3D, non stile «stock» patinato;
> - **luce naturale**, prevalentemente di mattina, ombre morbide, nessun flash diretto;
> - colori **tenui e leggermente desaturati**; il verde-petrolio `#0F766E` compare solo come
>   accento naturale (una maglietta, un dettaglio dell'attrezzatura) e **mai** come dominante;
> - **nessun testo, nessuna scritta, nessun numero, nessun logo, nessun marchio** in nessun punto
>   dell'immagine, nemmeno sui muri o sugli abiti;
> - **nessuno schermo leggibile**: se compare un telefono o un tablet, deve essere spento, di
>   taglio, o con lo schermo non inquadrato;
> - persone dall'aspetto ordinario, corporature varie, **non modelli da rivista**, nessun addome
>   scolpito in primo piano;
> - **spazio vuoto utilizzabile** nella composizione, perché sopra ci va del testo;
> - formato **orizzontale 3:2**.
>
> Confermami che hai capito e aspetta il primo soggetto.

## I sette soggetti

### 1 · `og.jpg` — l'anteprima quando il link viene condiviso

> Interno di una palestra di quartiere vista in campo largo, di prima mattina, ancora vuota. Luce
> che entra da grandi finestre laterali e taglia il pavimento. Rastrelliera di manubri sulla
> destra, panca piana al centro-sinistra, pavimento in gomma scuro. Nessuna persona.
> L'inquadratura deve funzionare anche vista piccola: pochi elementi, contrasto chiaro fra la zona
> illuminata e quella in ombra.

### 2 · `eroe.jpg` — lo sfondo dell'apertura

> Angolo di una sala pesi con la luce del mattino che entra di lato. In primo piano sfocato una
> rastrelliera di manubri; sullo sfondo, fuori fuoco, una persona di spalle che si prepara ad
> allenarsi. La **metà destra dell'inquadratura deve restare visivamente calma** — parete, luce,
> nessun oggetto in evidenza — perché sopra ci va sovrapposto un elemento grafico. Atmosfera
> tranquilla, non «energica».

### 3 · `platea-palestra.jpg` — la scheda «Per le palestre»

> Il bancone d'ingresso di una palestra, ripreso di tre quarti. Dietro il bancone una persona
> dello staff in maglietta scura, vista di lato mentre parla con qualcuno fuori campo. Sul bancone
> un tablet appoggiato **con lo schermo spento**, una pianta, un raccoglitore. Sfondo con la sala
> intravista e sfocata. Nessuna scritta sulle pareti.

### 4 · `platea-trainer.jpg` — la scheda «Per i trainer indipendenti»

> Un personal trainer, in piedi di lato, che corregge la postura di un allievo durante uno stacco
> con bilanciere leggero. Il trainer è **il soggetto principale** ed è visto di profilo; l'allievo
> è parzialmente coperto. Sala piccola, luce laterale, atmosfera di lavoro concentrato e non
> spettacolare. Entrambi vestiti in modo ordinario.

### 5 · `platea-solo.jpg` — la scheda «Per chi si allena da solo»

> Una persona che si allena da sola in casa, di prima mattina: tappetino srotolato sul parquet
> davanti a una finestra, due manubri leggeri appoggiati a terra, una tazza di caffè sul davanzale.
> La persona è vista **di spalle o di tre quarti da dietro**, in una pausa fra due serie. Interno
> normale e vissuto, non un appartamento da catalogo.

### 6 · `privacy.jpg` — la sezione sui dati che restano sul telefono

> Una persona seduta sul bordo di un letto la sera, vista **di lato e in controluce**, che tiene un
> telefono con lo schermo rivolto verso di sé e **non visibile all'obiettivo**. Luce calda e bassa
> di una lampada, il resto della stanza in penombra. Il senso da restituire è la **riservatezza**:
> un momento privato, nessuno che guarda. Tonalità più scure e più calde delle altre sei immagini.

### 7 · `prezzi.jpg` — la fascia della pagina dei prezzi

> Una sala corsi vista **da lontano e dall'alto**, con sei o sette persone che si allenano in modo
> sparso, ciascuna per conto proprio. Nessun volto riconoscibile a quella distanza. Molto spazio
> vuoto in alto e ai lati. L'inquadratura sarà tagliata in una fascia molto larga e bassa, quindi
> l'interesse deve stare nella parte **centrale** dell'altezza.

## Cosa farne

1. Scaricare le immagini e rinominarle **esattamente** come sopra (`og.jpg`, `eroe.jpg`, …).
2. Ridimensionarle alle misure di `LEGGIMI.md` e comprimerle sotto i 300 KB (`og.jpg` sotto i 200).
3. Copiarle in questa cartella. Non c'è niente da rigenerare né da compilare: compaiono da sole.

💡 **Il ritaglio esatto non è critico.** Il contenitore ritaglia da sé al centro (`object-fit:
cover`), quindi un file 3:2 sta bene in tutte e sette le posizioni. Le misure servono soprattutto a
tenere basso il peso.

⚠️ **Se un'immagine esce con del testo dentro, va rigenerata.** I generatori scrivono lettere
storte e parole inventate, e su una parete di palestra si notano più di quanto sembri mentre la si
guarda a schermo intero.
