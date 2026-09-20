{{--
    La home — terza stesura, 20/09/2026 notte.

    ── 📌 Perché di nuovo ────────────────────────────────────────────────────

    Il committente, leggendo la tabella «gratis o Plus»: *«Certo che li leggiamo
    i passi … E i vantaggi dell'abbonamento sono anche altri, tipo un sacco di
    cards della schermata home sono bloccate senza abbonamento. Adesso ti leggi
    per bene tutte le views del codice, una per una, e tutte le relazioni per
    capire cosa è incluso nel piano gratuito e cosa è dietro il gate
    dell'abbonamento. Poi rifai tutto il sito basandoti su quelle
    informazioni»*.

    ✅ **Fatto**: il gate è stato letto nell'app, file per file, e la tabella
    che ne è uscita sta in `codebase_reference_trainingbe.md` §103.1 — con
    accanto a ogni riga il file che la decide. 🚨 Quella è la fonte: questa
    pagina la racconta, non la reinventa.

    ── ⛔ Gli errori delle due stesure precedenti ────────────────────────────

    | Diceva | Verità |
    |---|---|
    | «sonno e passi» (1ª), poi «i passi non si leggono» (2ª) | I passi **si leggono**, e in «Oggi» c'è una barra a dieci tacche verso l'obiettivo |
    | Il Plus = «assistente automatico e schede» | Sono **cinque card della schermata «Oggi»**, sei valori dell'intestazione, la settimana programmata, i progressi, il colore dell'app, gli allenamenti senza limite |
    | «Allenamenti: tutto» nel gratuito | ⛔ **Un allenamento al giorno**, 3 schede, solo di un giorno |
--}}
@extends('sito.layout')

@section('titolo', 'Training Companion — allenamenti, alimentazione e il tuo trainer in un\'app')
@section('descrizione', 'Diario alimentare, schede di allenamento e la chat col tuo trainer. L\'app è gratis; peso, misure e diario restano sul tuo telefono.')

@section('contenuto')

    {{-- ═══════════════════════ l'apertura, a tutta pagina ═══════════════════════ --}}
    <header class="eroe su-foto">
        <div class="eroe-sfondo">
            {{-- 🚨 `priorita`: è l'unica immagine che si vede senza scorrere, e
                 caricarla pigra la farebbe comparire a pagina già letta. --}}
            <x-sito.figura
                nome="eroe"
                alt="Una persona si allena in palestra con il telefono appoggiato alla panca"
                priorita
            />
        </div>
        <div class="eroe-velo"></div>

        <div class="contenitore">
            <span class="pillola">In prova con le prime palestre</span>

            <h1 style="margin-top: var(--sp-2);">
                Allena il corpo.<br>
                Metti in riga i pasti.<br>
                Vedi dove stai andando.
            </h1>

            <p class="guida">
                Diario alimentare, schede di allenamento e la chat con chi ti segue: in
                un'app sola, gratis. E con una differenza che si vede solo leggendo —
                <strong>peso, misure e diario restano sul tuo telefono</strong>.
            </p>

            <div class="fila-bottoni" style="margin-top: var(--sp-3);">
                <x-sito.scarica grande />
                <a href="#funzioni" class="bottone bottone-vuoto bottone-grande">Guarda cosa fa</a>
            </div>

            <div class="eroe-fatti">
                <span>✅ Gratis, senza periodo di prova che scade</span>
                <span>📶 Funziona anche senza rete</span>
                <span>🔒 I dati del corpo non arrivano a noi</span>
            </div>
        </div>
    </header>

    {{-- ═══════════════════════ la schermata «Oggi» ═══════════════════════ --}}
    <section id="oggi">
        <div class="contenitore">
            <div class="riga-funzione">
                <div>
                    <p class="occhiello">La schermata di apertura</p>
                    <h2>Tutta la giornata in una schermata.</h2>

                    <p class="guida">
                        «Oggi» è la prima cosa che vedi quando apri l'app: quanto hai mangiato e
                        quanto ne avevi in programma, quanto hai bruciato, i passi, il peso,
                        l'allenamento. Sotto, una card per ogni cosa che l'app sa di te.
                    </p>

                    <ul class="elenco">
                        <li>Calorie mangiate e <strong>obiettivo del giorno</strong>, con le bruciate già dentro</li>
                        <li>I <strong>passi</strong> verso il tuo obiettivo, su una barra a dieci tacche</li>
                        <li>Il peso, con il grafico nel tempo</li>
                        <li>Un tasto <strong>+</strong> che aggiunge pasto, peso o allenamento senza cercare dove</li>
                    </ul>

                    <p class="nota" style="margin-top: var(--sp-2);">
                        💡 Cinque card qui dentro — sonno, carico, recupero, composizione,
                        allenamento — <strong>si accendono con l'abbonamento</strong>:
                        <a href="#gratis-o-plus">qui sotto c'è la riga per riga</a>.
                    </p>
                </div>

                <x-sito.telefono
                    nome="app-oggi"
                    alt="La schermata Oggi: calorie del giorno, passi, peso e allenamento"
                />
            </div>
        </div>
    </section>

    {{-- ═══════════════════════ le funzioni, una per riga ═══════════════════════ --}}
    <section class="alternata" id="funzioni">
        <div class="contenitore">
            <p class="occhiello">Cosa fa</p>
            <h2>Quello che serve per allenarsi con la testa.</h2>
            <p class="guida" style="margin-bottom: var(--sp-5);">
                Niente che si spegne dopo trenta giorni, niente che ti obbliga a pagare per
                rivedere quello che hai già scritto.
            </p>

            <div class="riga-funzione rovescia">
                <x-sito.telefono
                    nome="app-diario"
                    alt="Il diario alimentare con i pasti della giornata e le calorie"
                />

                <div>
                    <p class="occhiello">Diario alimentare</p>
                    <h2>Segni cosa mangi, in pochi secondi.</h2>
                    <p class="guida">
                        Un catalogo con oltre un milione di alimenti, le cose che mangi spesso
                        fra i preferiti, e le porzioni che imposti una volta sola.
                        🚨 <strong>Tutto questo è gratis</strong>, senza tetti e senza contatori.
                    </p>
                    <ul class="elenco">
                        <li>Colazione, pranzo, cena e spuntini, con calorie e macro</li>
                        <li>Le sezioni si chiudono: a metà giornata guardi solo quello che ti serve</li>
                        <li>Gli alimenti che ti scrivi tu restano tuoi: non finiscono nell'archivio di nessun altro</li>
                    </ul>
                </div>
            </div>

            <div class="riga-funzione">
                <div>
                    <p class="occhiello">Allenamenti</p>
                    <h2>La scheda in mano, mentre ti alleni.</h2>
                    <p class="guida">
                        Serie, ripetizioni, carichi e recuperi, col cronometro fra una serie e
                        l'altra. ⚠️ Funziona <strong>senza rete</strong>, che è dove si allena
                        mezza Italia: si rimette in ordine da sé quando torna.
                    </p>
                    <ul class="elenco">
                        <li>Segni il peso e le ripetute mentre le fai</li>
                        <li>Lo storico di ogni esercizio, per sapere dove eri la volta prima</li>
                        <li>Esercizi tuoi, se ne inventi: li vedi solo tu</li>
                        <li class="no">Senza abbonamento: <strong>un allenamento al giorno</strong> e le 3 schede più recenti</li>
                    </ul>
                </div>

                <x-sito.telefono
                    nome="app-allenamento"
                    alt="Una seduta in corso: esercizio, serie, carichi e cronometro del recupero"
                />
            </div>

            <div class="riga-funzione rovescia">
                <x-sito.telefono
                    nome="app-chat"
                    alt="La chat col trainer con una scheda appena ricevuta"
                />

                <div>
                    <p class="occhiello">Schede e chat</p>
                    <h2>Il tuo trainer ti scrive qui.</h2>
                    <p class="guida">
                        Schede di allenamento e piani alimentari arrivano in chat, e da lì
                        entrano nell'app. La conversazione è <strong>cifrata da un capo
                        all'altro</strong>: la chiave sta sui due telefoni, non sui nostri
                        server.
                    </p>
                    <ul class="elenco">
                        <li>Con chi ti allena si scrive quanto si vuole, anche senza abbonamento</li>
                        <li>Quello che ti ha mandato resta tuo, anche se cambi palestra</li>
                        <li>Nessuno può leggere i messaggi: né noi, né la palestra</li>
                    </ul>
                </div>
            </div>

            {{-- Le funzioni che non meritano una riga intera, ma esistono e vanno dette. --}}
            <div class="griglia" style="margin-top: var(--sp-6);">
                <div class="scheda">
                    <h3>🚶 Passi e calorie bruciate</h3>
                    <p>
                        Colleghi Health Connect e l'app legge passi, calorie attive, sonno e
                        battito. Le bruciate entrano nell'obiettivo del giorno.
                    </p>
                </div>
                <div class="scheda">
                    <h3>⚖️ Peso e misure</h3>
                    <p>
                        Li segni quando vuoi e li vedi nel tempo, con le foto dei progressi se
                        ti va. 🚨 Stanno <strong>solo</strong> sul telefono.
                    </p>
                </div>
                <div class="scheda">
                    <h3>📄 Import da PDF o foto</h3>
                    <p>
                        La scheda che ti hanno dato in palestra diventa una scheda vera, che poi
                        controlli riga per riga. <span class="nota">La trascrizione la fa
                        l'assistente: costa 50 gettoni.</span>
                    </p>
                </div>
                <div class="scheda">
                    <h3>🎨 I colori della tua palestra</h3>
                    <p>Se ti allena una palestra, l'app si veste dei suoi colori e del suo logo.</p>
                </div>
                <div class="scheda">
                    <h3>💾 Copia di sicurezza</h3>
                    <p>Cifrata, con una password che scegli tu: cambi telefono e ritrovi tutto.</p>
                </div>
                <div class="scheda">
                    <h3>📦 Porti via tutto</h3>
                    <p>«Scarica i miei dati» ti dà un file leggibile con quello che sta da noi <em>e</em> quello che sta sul telefono.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ gratis o Plus — il punto dei soldi ═══════════════════

         🚨 **È il «punto specifico della pagina»** chiesto il 20/09, e la
         tabella viene dal gate letto nell'app (atlante §103.1). ⛔ Ogni riga qui
         dentro ha un file dietro: se non ce l'ha, non si scrive. --}}
    <section id="gratis-o-plus">
        <div class="contenitore">
            <p class="occhiello">Gratis o Plus</p>
            <h2>Cosa è gratis, e cosa fa l'abbonamento.</h2>

            <p class="guida">
                Il diario, i passi, il peso e la chat sono gratis e non scadono. L'abbonamento
                toglie i limiti sugli allenamenti e <strong>apre le letture dei tuoi
                dati</strong>: spunto di oggi, carico e carica, recupero, com'è fatto, e i tuoi
                allenamenti. 💡 I dati restano tuoi comunque — l'abbonamento apre le letture,
                non i numeri.
            </p>

            <div class="confronto" style="margin-top: var(--sp-3);">
                <table>
                    <thead>
                        <tr>
                            <th>Cosa</th>
                            <th>Gratis</th>
                            <th>Plus — {{ $formatta($prezzoSingolo) }}/mese</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td>Diario alimentare, catalogo, preferiti, macro</td>
                            <td class="si">✓ tutto</td>
                            <td class="si">✓ tutto</td>
                        </tr>
                        <tr>
                            <td>Calorie, bruciate e <strong>passi</strong> del giorno</td>
                            <td class="si">✓</td>
                            <td class="si">✓</td>
                        </tr>
                        <tr>
                            <td>Peso, misure, foto, grafici</td>
                            <td class="si">✓</td>
                            <td class="si">✓</td>
                        </tr>
                        <tr>
                            <td>Chat con chi ti allena</td>
                            <td class="si">✓ senza limite</td>
                            <td class="si">✓ senza limite</td>
                        </tr>
                        <tr>
                            <td>Copia di sicurezza, esporta i dati, cancella l'account</td>
                            <td class="si">✓</td>
                            <td class="si">✓</td>
                        </tr>
                        <tr>
                            <td><strong>Allenamenti avviati dall'app</strong></td>
                            <td class="no">uno al giorno</td>
                            <td class="si">quanti vuoi</td>
                        </tr>
                        <tr>
                            <td><strong>Schede utilizzabili</strong></td>
                            <td class="no">le 3 più recenti, solo di un giorno</td>
                            <td class="si">tutte, anche su più giorni</td>
                        </tr>
                        <tr>
                            <td>Settimana programmata</td>
                            <td class="no">—</td>
                            <td class="si">✓</td>
                        </tr>
                        <tr>
                            <td>L'andamento di ogni esercizio, coi primati</td>
                            <td class="no">—</td>
                            <td class="si">✓</td>
                        </tr>
                        <tr>
                            <td>Sonno, recupero, carico e carica, «com'è fatto» il tuo peso</td>
                            <td class="no">card sfumate</td>
                            <td class="si">✓ aperte</td>
                        </tr>
                        <tr>
                            <td>Sonno, HRV, battito e prontezza in cima a «Oggi»</td>
                            <td class="no">—</td>
                            <td class="si">✓</td>
                        </tr>
                        <tr>
                            <td>Il consiglio del giorno, che arriva da solo</td>
                            <td class="no">—</td>
                            <td class="si">✓</td>
                        </tr>
                        <tr>
                            <td>L'analisi della scheda dopo l'allenamento</td>
                            <td class="no">—</td>
                            <td class="si">✓</td>
                        </tr>
                        <tr>
                            <td>Il colore dell'app</td>
                            <td class="no">—</td>
                            <td class="si">✓</td>
                        </tr>
                        <tr>
                            <td>Scrivere a un trainer che non ti segue</td>
                            <td class="no">3 messaggi</td>
                            <td class="si">✓ senza limite</td>
                        </tr>
                        <tr>
                            <td>Foto del piatto, pasto da una frase, trascrizione di un PDF</td>
                            <td class="no">a gettoni</td>
                            <td class="no">a gettoni</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="griglia-2" style="margin-top: var(--sp-4);">
                <div>
                    <h3>L'ultima riga, detta prima</h3>
                    <p class="guida">
                        🚨 <strong>Quello che chiedi tu all'assistente si paga a gettoni in
                        tutti e due i casi</strong>: la foto del piatto, il pasto scritto a
                        parole, la trascrizione di una scheda. Costano a noi nel momento in cui
                        le fai, e preferiamo dirlo qui invece che dopo il pagamento.
                    </p>
                    <p class="guida" style="margin-top: var(--sp-2);">
                        💡 Quello che l'abbonamento accende è l'app che <strong>lavora sui tuoi
                        dati da sola</strong> — il consiglio del giorno, il recupero, il carico,
                        i progressi — e gli allenamenti senza limiti.
                    </p>
                    <div class="fila-bottoni" style="margin-top: var(--sp-3);">
                        <a href="/prezzi/individuale" class="bottone bottone-pieno">Tutto quello che comprende</a>
                    </div>
                </div>

                <div class="scheda tenue">
                    <h3 style="margin-top: 0;">I gettoni, in due righe</h3>
                    <p>
                        Si comprano una volta e <strong>non scadono</strong>. Si consumano solo
                        quando chiedi qualcosa all'assistente: una stima da testo ne vale uno,
                        una da foto dieci, la trascrizione di una scheda cinquanta.
                    </p>
                    <p style="margin-bottom: 0;">
                        💡 Se ti allena una palestra o un trainer, l'abbonamento
                        <strong>può regalartelo</strong>: in quel caso hai il Plus intero senza
                        pagare niente tu.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════ i dati ═══════════════════════ --}}
    <section class="alternata" id="dati">
        <div class="contenitore">
            <p class="occhiello">I tuoi dati</p>
            <h2>I dati del tuo corpo non stanno sui nostri server.</h2>

            <div class="griglia-2" style="margin-top: var(--sp-3);">
                <div>
                    <p class="guida">
                        Non è una promessa di buone intenzioni: è come è fatta l'app. Dal
                        19&nbsp;settembre 2026 peso, altezza, data di nascita, diario,
                        allenamenti e sonno hanno una sola casa, ed è il tuo telefono.
                    </p>

                    <ul class="elenco" style="margin-top: var(--sp-2);">
                        <li><strong>Sul telefono</strong>: peso, misure, foto, diario, allenamenti, sonno, passi</li>
                        <li><strong>Cifrato fra i due telefoni</strong>: i messaggi con chi ti allena</li>
                        <li><strong>Da noi</strong>: il tuo account, chi ti allena, e le buste da recapitare</li>
                        <li>Server nell'Unione Europea, e nessun profilo venduto a nessuno</li>
                    </ul>
                </div>

                <x-sito.figura
                    nome="privacy"
                    alt="Un telefono appoggiato, con il diario degli allenamenti aperto"
                />
            </div>

            <div class="scheda" style="margin-top: var(--sp-3);">
                <h3 style="margin-top: 0;">E puoi portarti via tutto, quando vuoi</h3>
                <p>
                    <strong>Profilo → Backup e dati → «Scarica i miei dati»</strong>: un file
                    leggibile con quello che sta sui nostri server <em>e</em> quello che sta sul
                    telefono. Non devi chiederlo a nessuno e non devi aspettare.
                </p>
                <p style="margin-bottom: 0;">
                    E se decidi di andartene, <strong>Profilo → Elimina account</strong>:
                    sparisce tutto, subito. 💡 Restano i messaggi nella conversazione di chi ti
                    seguiva — sono la sua documentazione — ma senza più il tuo nome attaccato.
                </p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════ come si comincia ═══════════════════════ --}}
    <section id="come-funziona">
        <div class="contenitore">
            <p class="occhiello">Come si comincia</p>
            <h2>Tre passi, e ci vogliono due minuti.</h2>

            <div class="griglia" style="margin-top: var(--sp-3);">
                <div class="scheda">
                    <h3>1. Scarichi ed entri</h3>
                    <p>
                        Basta un'email. Se ti allena una palestra o un trainer, ti mandano un
                        link: lo apri e sei dentro, senza codici da copiare.
                    </p>
                </div>
                <div class="scheda">
                    <h3>2. Segni cosa mangi e come ti alleni</h3>
                    <p>
                        Cerchi l'alimento o lo scrivi a mano. In palestra apri la scheda e segni
                        le serie: funziona anche senza rete.
                    </p>
                </div>
                <div class="scheda">
                    <h3>3. Ricevi le schede di chi ti segue</h3>
                    <p>
                        Il tuo trainer te le manda in chat, cifrate. Restano sul telefono, e
                        restano tue anche il giorno che cambi palestra.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════ per chi allena ═══════════════════════ --}}
    <section class="alternata" id="allenare">
        <div class="contenitore">
            <p class="occhiello">Alleni altre persone?</p>
            <h2>C'è un pannello, e ci sono i piani per lavorarci.</h2>

            <p class="guida">
                Regali l'app ai tuoi allievi — <strong>col Plus intero, non una versione
                ridotta</strong> — scrivi le schede e gliele mandi in chat. La parte delicata
                (peso, diario, messaggi) non passa né da te né da noi, e questo ti toglie una
                responsabilità invece di dartene una.
            </p>

            <div class="griglia-2" style="margin-top: var(--sp-3);">
                <div class="scheda">
                    <h3 style="margin-top: 0;">Sei un trainer</h3>
                    <p>
                        Da {{ $formatta($daTrainer) }} al mese: abboni i tuoi allievi, scrivi
                        schede e piani, e hai una chat con ciascuno.
                    </p>
                    <div class="in-fondo">
                        <a href="/prezzi/trainer" class="bottone bottone-pieno">Piani per i trainer</a>
                    </div>
                </div>

                <div class="scheda">
                    <h3 style="margin-top: 0;">Sei una palestra</h3>
                    <p>
                        Da {{ $formatta($daPalestra) }} al mese: i tuoi istruttori lavorano
                        dentro, l'app si veste dei tuoi colori, e i modelli restano della
                        palestra.
                    </p>
                    <div class="in-fondo">
                        <a href="/prezzi/palestra" class="bottone bottone-pieno">Piani per le palestre</a>
                    </div>
                </div>
            </div>

            <p style="margin-top: var(--sp-3);">
                Prima di scegliere, <a href="/professionisti">leggi come funziona per chi
                allena</a>: c'è anche l'elenco delle cose che non potrai vedere.
            </p>
        </div>
    </section>

    {{-- ═══════════════════════ domande ═══════════════════════ --}}
    <section id="domande">
        <div class="contenitore stretto">
            <p class="occhiello">Domande frequenti</p>
            <h2>Le cose che ci chiederesti.</h2>

            <div style="margin-top: var(--sp-3);">
                <details class="domanda">
                    <summary><strong>È davvero gratis?</strong></summary>
                    <p>
                        Sì, e non c'è un periodo di prova che scade. Diario, passi, peso,
                        allenamenti e chat funzionano senza pagare niente. L'abbonamento toglie
                        i limiti e accende le analisi: <a href="#gratis-o-plus">qui sopra c'è la
                        riga per riga</a>.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Cosa perdo se non mi abbono?</strong></summary>
                    <p>
                        Niente di quello che hai già scritto. Puoi far partire <strong>un
                        allenamento al giorno</strong> e usare le 3 schede più recenti (di un
                        giorno solo); le card di sonno, recupero, carico e composizione le vedi
                        sfumate, e il consiglio del giorno non arriva. 💡 Le schede che ti hanno
                        mandato restano tutte lì: quando ti abboni le ritrovi.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Chi vede il mio peso?</strong></summary>
                    <p>
                        Nessuno. Non sta sui nostri server: sta sul tuo telefono. Se vuoi che il
                        tuo trainer lo sappia, glielo scrivi tu in chat.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Se cambio telefono perdo tutto?</strong></summary>
                    <p>
                        No, se accendi la copia di sicurezza: l'app ne fa una cifrata, che
                        riapri con una password che scegli tu. ⚠️ Se la dimentichi non possiamo
                        recuperarla — è quello che la rende sicura.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Se lascio la palestra, perdo le schede?</strong></summary>
                    <p>
                        No. Le schede che hai ricevuto restano sul tuo telefono, e anche gli
                        esercizi che ti hanno insegnato. Quello che finisce è il rapporto, non
                        la tua roba. ⚠️ Finisce anche l'abbonamento che ti regalava la palestra:
                        torni al gratuito, con tutto quello che avevi scritto al suo posto.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Usate i miei dati per la pubblicità?</strong></summary>
                    <p>
                        No: non compriamo e non vendiamo profili, e dentro l'app non c'è
                        pubblicità di nessun tipo. ⚠️ E non potremmo nemmeno volendo: peso,
                        diario e allenamenti sui nostri server non ci sono.
                    </p>
                </details>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════ chiusura ═══════════════════════ --}}
    <section class="alternata">
        <div class="contenitore" style="text-align: center;">
            <h2>Comincia stasera, col primo pasto.</h2>
            <p class="guida" style="margin: var(--sp-2) auto 0;">
                Si scarica, si entra con un'email, e da lì è solo questione di segnare quello
                che fai.
            </p>

            <div class="fila-bottoni centrata" style="margin-top: var(--sp-3);">
                <x-sito.scarica grande />
                <a href="/prezzi/individuale" class="bottone bottone-vuoto bottone-grande">Gratis o Plus?</a>
            </div>
        </div>
    </section>

@endsection
