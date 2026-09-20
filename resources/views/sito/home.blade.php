{{--
    La home — rifatta il 20/09/2026 (seconda volta in un giorno).

    ── 📌 Cosa aveva detto il committente ─────────────────────────────────────

    *«La home fa schifo ar cazzo, non mi piace per niente l'hero, deve essere
    un'immagine a piena pagina … ci deve essere uno screen della schermata oggi
    dell'app da qualche parte … deve mettere bene in mostra tutte le
    funzionalità dell'app spiegando il piano gratuito e quello su abbonamento in
    un punto specifico della pagina … l'app è gratis ok ma spingiamo comunque
    gli utenti ad abbonarsi, è così che guadagno»*.

    ── 🚨 La regola della home è CAMBIATA, e va detto ─────────────────────────

    Dal 16/08 c'era una regola: **sulla home non si parla di soldi**, con un
    test che faceva fallire qualunque cifra in euro
    (`the_home_does_not_pitch_the_reseller_margin`). ⚠️ Non era una regola sui
    prezzi: era una regola su **chi legge**. La home di quel mattino spiegava a
    chi si allena che una palestra può rivenderglielo addosso e tenersi la
    metà — cioè gli si raccontava il modello di business di cui lui è la merce.

    ✅ **Quella parte resta vietata**, e il test continua a vietarla: nessuna
    rivendita, nessun margine, nessun «posto» qui dentro.
    🆕 **Il prezzo del Plus invece adesso si dice**, in un blocco suo
    (`#gratis-o-plus`), perché chi arriva qui deve poter capire in tre secondi
    cosa è gratis e cosa no. ⛔ Tenerlo nascosto non è pudore: è il sospetto che
    ci sia una fregatura più avanti.
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
                        «Oggi» è la prima cosa che vedi quando apri l'app: quante calorie hai
                        messo insieme e quante ne avevi in programma, l'allenamento di oggi con
                        i suoi esercizi, il peso se l'hai segnato, e lo spunto dell'assistente
                        se lo hai acceso.
                    </p>

                    <ul class="elenco">
                        <li>Le calorie del giorno, confrontate col tuo obiettivo</li>
                        <li>La seduta di oggi, pronta da cominciare</li>
                        <li>Un tocco per segnare un pasto o aprire la scheda</li>
                        <li>Se colleghi Health Connect, ci finiscono anche sonno e passi</li>
                    </ul>
                </div>

                <x-sito.telefono
                    nome="app-oggi"
                    alt="La schermata Oggi: calorie del giorno, allenamento in programma e peso"
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
                vedere quello che hai già scritto.
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
                        Un catalogo con oltre un milione di alimenti, i tuoi piatti ricorrenti
                        fra i preferiti, e le porzioni che ti ricordi una volta sola.
                    </p>
                    <ul class="elenco">
                        <li>Colazione, pranzo, cena e spuntini, con calorie e macro</li>
                        <li>Gli alimenti che ti scrivi tu restano tuoi: non finiscono nel catalogo di nessuno</li>
                        <li>Ricette e piatti composti, salvati una volta e riusati sempre</li>
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
                        <li>Quello che ti ha mandato resta tuo, anche se cambi palestra</li>
                        <li>Nessuno può leggere i messaggi: né noi, né la palestra</li>
                        <li>Hai una scheda su carta o in PDF? La fotografi e l'app la trascrive</li>
                    </ul>
                </div>
            </div>

            {{-- Le funzioni che non meritano una riga intera, ma esistono e vanno dette. --}}
            <div class="griglia" style="margin-top: var(--sp-6);">
                <div class="scheda">
                    <h3>📈 Peso e misure</h3>
                    <p>Li segni quando vuoi e li vedi nel tempo, con le foto dei progressi se ti va. 🚨 Stanno <strong>solo</strong> sul telefono.</p>
                </div>
                <div class="scheda">
                    <h3>😴 Sonno e recupero</h3>
                    <p>Colleghi Health Connect e l'app somma sonno, battito e calorie bruciate alla giornata.</p>
                </div>
                <div class="scheda">
                    <h3>📄 Import da PDF o foto</h3>
                    <p>La scheda che ti hanno dato in palestra diventa una scheda vera, che poi controlli riga per riga.</p>
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

         🚨 **È il «punto specifico della pagina»** chiesto il 20/09. Sta qui, e
         non in fondo, perché è la domanda che una persona si fa **dopo** aver
         visto cosa fa l'app e **prima** di scaricarla. --}}
    <section id="gratis-o-plus">
        <div class="contenitore">
            <p class="occhiello">Gratis o Plus</p>
            <h2>L'app è gratis. Si paga solo l'assistente.</h2>

            <p class="guida">
                Diario, allenamenti, schede, chat, peso e misure non costano niente e non
                scadono. Quello che si paga è l'<strong>intelligenza artificiale</strong>,
                perché costa a ogni domanda: chi non la usa non deve pagarla.
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
                            <td>Diario alimentare, catalogo, ricette</td>
                            <td class="si">✓ tutto</td>
                            <td class="si">✓ tutto</td>
                        </tr>
                        <tr>
                            <td>Allenamenti, schede, storico, cronometro</td>
                            <td class="si">✓ tutto</td>
                            <td class="si">✓ tutto</td>
                        </tr>
                        <tr>
                            <td>Chat cifrata col trainer</td>
                            <td class="si">✓</td>
                            <td class="si">✓</td>
                        </tr>
                        <tr>
                            <td>Peso, misure, foto, copia di sicurezza</td>
                            <td class="si">✓</td>
                            <td class="si">✓</td>
                        </tr>
                        <tr>
                            <td>Scrivi «due uova e una mela» e le calorie le conta lei</td>
                            <td class="no">a gettoni</td>
                            <td class="si">✓ incluso</td>
                        </tr>
                        <tr>
                            <td>Fotografi il piatto e lo riconosce</td>
                            <td class="no">a gettoni</td>
                            <td class="si">✓ incluso</td>
                        </tr>
                        <tr>
                            <td>Lo spunto del giorno sulla tua giornata</td>
                            <td class="no">—</td>
                            <td class="si">✓</td>
                        </tr>
                        <tr>
                            <td>Quante elaborazioni al mese</td>
                            <td class="no">quelle che compri</td>
                            <td class="si">{{ number_format($chiamateMensili, 0, ',', '.') }} incluse</td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div class="griglia-2" style="margin-top: var(--sp-4);">
                <div>
                    <h3>Perché conviene il Plus</h3>
                    <p class="guida">
                        Perché la parte che fa risparmiare tempo è quella: scrivere una frase
                        invece di cercare quattro alimenti, o fotografare il piatto invece di
                        pesarlo. 💡 A {{ $formatta($prezzoSingolo) }} al mese sono
                        <strong>cinque elaborazioni al giorno</strong>, che è più di quante
                        ne fa chi segna tre pasti.
                    </p>
                    <div class="fila-bottoni" style="margin-top: var(--sp-2);">
                        <a href="/prezzi/individuale" class="bottone bottone-pieno">Cosa comprende il Plus</a>
                    </div>
                </div>

                <div class="scheda tenue">
                    <h3 style="margin-top: 0;">E se lo usi ogni tanto?</h3>
                    <p>
                        Ci sono i <strong>gettoni</strong>: si comprano una volta, non scadono,
                        e si consumano solo quando chiedi qualcosa all'assistente. Una stima da
                        testo ne vale uno, una da foto dieci.
                    </p>
                    <p style="margin-bottom: 0;">
                        💡 Se ti allena una palestra o un trainer, l'abbonamento
                        <strong>può regalartelo</strong>: in quel caso non paghi niente tu.
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
                        <li><strong>Sul telefono</strong>: peso, misure, foto, diario, allenamenti, sonno</li>
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
                Regali l'app ai tuoi allievi, scrivi le schede e gliele mandi in chat. La parte
                delicata — peso, diario, messaggi — non passa né da te né da noi, e questo ti
                toglie una responsabilità invece di dartene una.
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
                        Sì, e non c'è un periodo di prova che scade. Si paga solo l'assistente
                        con l'intelligenza artificiale, che è una cosa a parte e si accende
                        quando vuoi.
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
                        la tua roba.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Posso disdire il Plus quando voglio?</strong></summary>
                    <p>
                        Sì, dall'app, senza scrivere a nessuno. Resta attivo fino alla fine del
                        mese già pagato, e quello che hai segnato non sparisce.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Usate i miei dati per la pubblicità?</strong></summary>
                    <p>
                        No. Nel catalogo dell'app una palestra può pagare per comparire più in
                        alto, e questo è tutto: non compriamo né vendiamo profili, e per
                        mostrare quelle schede non serve sapere niente di te.
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
