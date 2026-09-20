{{--
    La home — riscritta il 20/09/2026.

    ── 🚨 Chi legge questa pagina ─────────────────────────────────────────────

    **Una persona che si allena**, non chi compra. È la regola che ha già fatto
    rifare questa pagina una volta, e resta: sulla home non compare nessun
    prezzo, nessun margine e nessun «posto» da rivendere. Chi vende sta su
    `/professionisti` e su `/prezzi`, che sono le pagine dove le sta cercando.
    C'è un test che lo verifica (`the_home_does_not_pitch_the_reseller_margin`).

    ── 💡 Cosa è cambiato dal 16/08, e perché la pagina è rifatta ─────────────

    Da allora il prodotto ha cambiato la cosa che lo distingue: **i dati del
    corpo non stanno più sui nostri server**. Peso, misure, diario, sonno e
    allenamenti vivono sul telefono di chi li produce (19/09/2026), i messaggi
    sono cifrati da un capo all'altro, e chi vuole può scaricare tutto quello
    che sappiamo di lui con un tocco.

    ⚠️ **Non è una riga di marketing, è una riga di architettura**: se un
    domani qualcuno chiedesse «mandami il peso dei tuoi iscritti», la risposta
    non sarebbe «no», sarebbe «non possiamo». La pagina è costruita intorno a
    questo.
--}}
@extends('sito.layout')

@section('titolo', 'Training Companion — quello che mangi e come ti alleni, sul tuo telefono')
@section('descrizione', 'Diario alimentare, allenamenti e le schede del tuo trainer in un\'app sola, gratis. Peso, misure e diario restano sul tuo telefono: sui nostri server non ci sono.')

@section('contenuto')

    {{-- ═══════════════════ apertura ═══════════════════ --}}
    <section style="padding-top: var(--sp-5);">
        <div class="contenitore griglia-2">
            <div>
                <span class="pillola">In prova con le prime palestre</span>

                {{-- 🚨 L'affermazione è **gratis, e i tuoi dati restano tuoi**:
                     sono le due cose vere e le due più difficili da copiare.
                     Gli altri fanno pagare l'ingresso e tengono tutto. --}}
                <h1 style="margin-top: var(--sp-2);">Quello che mangi e come ti alleni.<br>Sul tuo telefono, gratis.</h1>

                <p class="guida">
                    Segni i pasti, tieni il conto degli allenamenti e ricevi le schede del
                    tuo trainer. Senza abbonamento e senza periodo di prova che scade — e
                    con una differenza che si vede solo leggendo: peso, misure e diario
                    <strong>restano sul tuo telefono</strong>.
                </p>

                <div style="display: flex; gap: var(--sp-2); flex-wrap: wrap; margin-top: var(--sp-3);">
                    <a href="#come-funziona" class="bottone bottone-pieno bottone-grande">Come funziona</a>
                    <a href="/prezzi" class="bottone bottone-vuoto bottone-grande">Quanto costa</a>
                </div>

                {{-- 💡 I pulsanti degli store compaiono da soli il giorno della
                     pubblicazione: finché `config('app_mobile.*')` è vuoto qui
                     si legge «Presto su Google Play», che è vero. --}}
                <div style="margin-top: var(--sp-2);">
                    <x-sito.scarica grande />
                </div>

                <p style="margin-top: var(--sp-2); font-size: 14px; color: var(--testo-tenue);">
                    L'app è gratis. Si paga solo l'assistente con l'intelligenza artificiale,
                    e solo se lo vuoi.
                </p>
            </div>

            {{-- 🚨 `priorita` solo qui: è l'unica immagine che si vede senza
                 scorrere, e caricarla pigra la farebbe comparire dopo. --}}
            <x-sito.figura
                nome="eroe"
                alt="Una persona si allena in palestra con il telefono appoggiato alla panca"
                priorita
            />
        </div>
    </section>

    {{-- ═══════════════════ come funziona ═══════════════════ --}}
    <section id="come-funziona">
        <div class="contenitore">
            <p class="occhiello">Come funziona</p>
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
                        Cerchi l'alimento, o lo scrivi a mano. In palestra apri la scheda e
                        segni le serie: funziona anche <strong>senza rete</strong>, e si
                        rimette in ordine da sé quando torna.
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

    {{-- ═══════════════════ cosa c'è dentro ═══════════════════ --}}
    <section class="alternata">
        <div class="contenitore">
            <p class="occhiello">Cosa c'è dentro</p>
            <h2>Tutto quello che serve, niente che non serva.</h2>

            <div class="griglia" style="margin-top: var(--sp-3);">
                <div class="scheda">
                    <h3>🍽 Diario alimentare</h3>
                    <p>
                        Un catalogo di alimenti da cercare, i tuoi piatti ricorrenti fra i
                        preferiti, e le calorie del giorno confrontate con il tuo obiettivo.
                        💡 Le cose che ti scrivi da solo restano tue: non finiscono nel
                        catalogo di nessun altro.
                    </p>
                </div>

                <div class="scheda">
                    <h3>🏋 Allenamenti</h3>
                    <p>
                        Serie, ripetizioni, carichi e recuperi, con il cronometro fra una
                        serie e l'altra. Se inventi un esercizio tuo, resta tuo: lo vedi
                        solo tu, su questo telefono.
                    </p>
                </div>

                <div class="scheda">
                    <h3>📋 Schede e piani</h3>
                    <p>
                        Quelle di chi ti allena arrivano in chat. Se ne hai una su carta o
                        in PDF, la <strong>fotografi e l'app la trascrive</strong>: poi la
                        controlli riga per riga, con l'originale a portata di pollice.
                    </p>
                </div>

                <div class="scheda">
                    <h3>💬 Chat col trainer</h3>
                    <p>
                        Cifrata da un capo all'altro: la chiave sta sul tuo telefono e su
                        quello di chi ti segue. ⛔ Non la leggiamo noi, non la legge la
                        palestra.
                    </p>
                </div>

                <div class="scheda">
                    <h3>📈 Peso e misure</h3>
                    <p>
                        Li segni quando vuoi e li vedi nel tempo, con le foto dei progressi
                        se ti va. 🚨 <strong>Stanno solo qui</strong>: se vuoi che il tuo
                        trainer li sappia, glieli dici tu.
                    </p>
                </div>

                <div class="scheda">
                    <h3>😴 Sonno e recupero</h3>
                    <p>
                        Se colleghi Health Connect, l'app legge sonno, battito e calorie
                        bruciate e li somma alla giornata. Restano sul telefono anche quelli.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ l'AI ═══════════════════ --}}
    <section id="assistente">
        <div class="contenitore">
            <p class="occhiello">L'assistente</p>
            <h2>L'intelligenza artificiale è un extra, e si vede.</h2>

            <p class="guida">
                L'app funziona per intero senza. L'assistente si accende se lo vuoi, e si
                paga separatamente: costa a ogni domanda, e chi non lo usa non deve pagarlo.
            </p>

            <div class="griglia" style="margin-top: var(--sp-3);">
                <div class="scheda">
                    <h3>Scrivi, e conta lei</h3>
                    <p>«Due uova, pane integrale e una mela»: diventa una riga del diario, con le calorie.</p>
                </div>

                <div class="scheda">
                    <h3>Fotografa il piatto</h3>
                    <p>Una foto al posto di tre ricerche. Quello che torna lo correggi tu prima di salvarlo.</p>
                </div>

                <div class="scheda">
                    <h3>Lo spunto del giorno</h3>
                    <p>Una riga sulla giornata: com'è andata finora, e cosa ha senso fare stasera.</p>
                </div>
            </div>

            <div class="scheda tenue" style="margin-top: var(--sp-3);">
                <h3 style="margin-top: 0;">⚠️ Quello che l'AI non è</h3>
                <p>
                    Non è un medico, non è un dietista e non è il tuo trainer. Dà uno spunto
                    su numeri che le dai tu, e può sbagliare. Per un infortunio, una
                    patologia o una dieta vera serve una persona qualificata: l'app te lo
                    dice, e non fa finta del contrario.
                </p>
                <p style="margin-bottom: 0;">
                    💡 E quando parte, parte con i <strong>numeri</strong> — calorie, serie,
                    obiettivo — non con il tuo nome, la tua email o il nome della tua
                    palestra.
                </p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ privacy ═══════════════════ --}}
    <section class="alternata" id="dati">
        <div class="contenitore">
            <p class="occhiello">I tuoi dati</p>
            <h2>I dati del tuo corpo non stanno sui nostri server.</h2>

            <div class="griglia-2" style="margin-top: var(--sp-3);">
                <p class="guida">
                    Non è una promessa di buone intenzioni: è come è fatta l'app. Dal
                    19&nbsp;settembre 2026 peso, altezza, data di nascita, diario, allenamenti
                    e sonno hanno una sola casa, ed è il tuo telefono.
                </p>

                <x-sito.figura
                    nome="privacy"
                    alt="Un telefono appoggiato, con il diario degli allenamenti aperto"
                />
            </div>

            <div class="griglia" style="margin-top: var(--sp-3);">
                <div class="scheda">
                    <h3>📱 Resta sul telefono</h3>
                    <p>
                        Peso, misure, foto dei progressi, diario, allenamenti, sonno.
                        Nemmeno noi possiamo guardarli: non ce li abbiamo.
                    </p>
                </div>

                <div class="scheda">
                    <h3>🔒 Cifrato da capo a fondo</h3>
                    <p>
                        I messaggi con chi ti allena. Sui nostri server passano buste chiuse:
                        la chiave per aprirle sta sui due telefoni.
                    </p>
                </div>

                <div class="scheda">
                    <h3>☁️ Sta da noi</h3>
                    <p>
                        Il minimo per farla funzionare: il tuo account, chi ti allena, e le
                        buste dei messaggi da recapitare. Server nell'Unione Europea.
                    </p>
                </div>
            </div>

            <div class="scheda" style="margin-top: var(--sp-3);">
                <h3 style="margin-top: 0;">E puoi portarti via tutto, quando vuoi</h3>
                <p>
                    <strong>Profilo → Backup e dati → «Scarica i miei dati»</strong>: un file
                    leggibile con quello che sta sui nostri server <em>e</em> quello che sta
                    sul telefono. Non devi chiederlo a nessuno e non devi aspettare.
                </p>
                <p style="margin-bottom: 0;">
                    E se decidi di andartene, <strong>Profilo → Elimina account</strong>:
                    sparisce tutto, subito. 💡 Restano i messaggi nella conversazione di chi
                    ti seguiva — sono la sua documentazione — ma senza più il tuo nome
                    attaccato, e illeggibili per chiunque altro.
                </p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ per chi ═══════════════════ --}}
    <section id="per-chi">
        <div class="contenitore">
            <p class="occhiello">Per chi</p>
            <h2>Tre modi di usarla.</h2>

            <div class="griglia" style="margin-top: var(--sp-3);">
                <div class="scheda">
                    <x-sito.figura nome="platea-solo" alt="Una persona si allena da sola con il telefono in mano" />
                    <h3>Ti alleni da solo</h3>
                    <p>
                        Scarichi l'app e la usi, gratis. L'assistente lo accendi se ti serve,
                        e lo paghi solo tu.
                    </p>
                </div>

                <div class="scheda">
                    <x-sito.figura nome="platea-trainer" alt="Un trainer segue un allievo durante un esercizio" />
                    <h3>Ti allena un trainer</h3>
                    <p>
                        Ti manda un link, e da lì avete una chat e le schede. Se smettete, le
                        schede che ti ha scritto <strong>restano tue</strong> e la
                        conversazione resta leggibile: semplicemente non vi scrivete più.
                    </p>
                </div>

                <div class="scheda">
                    <x-sito.figura nome="platea-palestra" alt="La sala pesi di una palestra durante un allenamento" />
                    <h3>Vai in palestra</h3>
                    <p>
                        L'app si veste dei suoi colori e i tuoi trainer ti trovano dentro. La
                        palestra vede il tuo nome e chi ti segue: ⛔ non il tuo peso, non il
                        diario, non i messaggi.
                    </p>
                </div>
            </div>

            <p style="margin-top: var(--sp-3);">
                Sei una palestra o un trainer? <a href="/professionisti">C'è una pagina per voi</a>.
            </p>
        </div>
    </section>

    {{-- ═══════════════════ domande ═══════════════════ --}}
    <section class="alternata" id="domande">
        <div class="contenitore">
            <p class="occhiello">Domande frequenti</p>
            <h2>Le cose che ci chiederesti.</h2>

            <div style="margin-top: var(--sp-3); display: grid; gap: var(--sp-2);">
                <details class="scheda">
                    <summary><strong>È davvero gratis?</strong></summary>
                    <p>
                        Sì, e non c'è un periodo di prova che scade. Si paga solo l'assistente
                        con l'intelligenza artificiale, che è una cosa a parte e si accende
                        quando vuoi.
                    </p>
                </details>

                <details class="scheda">
                    <summary><strong>Chi vede il mio peso?</strong></summary>
                    <p>
                        Nessuno. Non sta sui nostri server: sta sul tuo telefono. Se vuoi che
                        il tuo trainer lo sappia, glielo scrivi tu in chat.
                    </p>
                </details>

                <details class="scheda">
                    <summary><strong>Se cambio telefono perdo tutto?</strong></summary>
                    <p>
                        No, se accendi la copia di sicurezza: l'app ne fa una cifrata, che
                        riapri con una password che scegli tu. ⚠️ Se la dimentichi non
                        possiamo recuperarla — è quello che la rende sicura.
                    </p>
                </details>

                <details class="scheda">
                    <summary><strong>Se lascio la palestra, perdo le schede?</strong></summary>
                    <p>
                        No. Le schede che hai ricevuto restano sul tuo telefono, e anche gli
                        esercizi che ti hanno insegnato. Quello che finisce è il rapporto,
                        non la tua roba.
                    </p>
                </details>

                <details class="scheda">
                    <summary><strong>Usate i miei dati per la pubblicità?</strong></summary>
                    <p>
                        No. Nel catalogo una palestra può pagare per comparire più in alto, e
                        questo è tutto: non compriamo né vendiamo profili, e per mostrare
                        quelle schede non serve sapere niente di te.
                    </p>
                </details>

                <details class="scheda">
                    <summary><strong>Posso vedere cosa sapete di me?</strong></summary>
                    <p>
                        Sì, in due tocchi: <strong>Profilo → Backup e dati → «Scarica i miei
                        dati»</strong>. Esce un file leggibile, con la metà che sta da noi e
                        quella che sta sul telefono.
                    </p>
                </details>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ chiusura ═══════════════════ --}}
    <section>
        <div class="contenitore" style="text-align: center;">
            <h2>Comincia stasera, col primo pasto.</h2>
            <p class="guida" style="margin: var(--sp-2) auto 0;">
                Si scarica, si entra con un'email, e da lì è solo questione di segnare quello
                che fai.
            </p>

            <div style="display: flex; gap: var(--sp-2); justify-content: center; flex-wrap: wrap; margin-top: var(--sp-3);">
                <a href="/prezzi" class="bottone bottone-pieno bottone-grande">Guarda i prezzi</a>
                <a href="/professionisti" class="bottone bottone-vuoto bottone-grande">Sono una palestra</a>
            </div>
        </div>
    </section>

@endsection
