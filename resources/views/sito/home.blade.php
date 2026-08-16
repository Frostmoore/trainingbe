{{--
    La home — rifatta il 16/08/2026, sera.

    ── 🚨 Chi legge questa pagina ─────────────────────────────────────────────

    **Una persona che si allena**, non chi compra. È la correzione che ha fatto
    rifare questa pagina: la versione precedente spiegava, nel terzo passo di
    «come funziona», che una palestra può rivendere un posto a 10 € e tenersene
    la metà.

    ⚠️ **È vero, ed è la cosa sbagliata da dire qui.** Chi sta valutando se
    scaricare un'app legge che è un prodotto costruito per essere rivenduto
    addosso a lui, e chiude. Quei numeri esistono ancora e stanno su `/prezzi`,
    che è la pagina dove una palestra li sta cercando apposta.

    💡 **La regola, in una riga**: sulla home non compare nessun prezzo, nessun
    margine e nessun «posto». Ci sono un test che lo verifica
    (`the_home_does_not_pitch_the_reseller_margin`) e un motivo, che è questo.
--}}
@extends('sito.layout')

@section('titolo', 'Training Companion — l\'app per chi si allena')
@section('descrizione', 'Diario alimentare, allenamenti e le schede del tuo trainer, in un\'app sola. Gratis, per sempre, senza periodo di prova.')

@section('contenuto')

    {{-- ═══════════════════ apertura ═══════════════════ --}}
    <section style="padding-top: var(--sp-5);">
        <div class="contenitore griglia-2">
            <div>
                <span class="pillola">In prova con le prime palestre</span>

                {{-- 🚨 L'affermazione è **l'app è gratis**, non «l'AI che ti
                     cambia la vita». È la cosa vera e la più difficile da
                     copiare: gli altri fanno pagare l'ingresso. --}}
                <h1 style="margin-top: var(--sp-2);">Alimentazione e allenamento.<br>In un'app sola, gratis.</h1>

                <p class="guida">
                    Segni cosa mangi, tieni il conto degli allenamenti e ricevi le schede
                    del tuo trainer. Senza abbonamento, senza periodo di prova che scade,
                    senza carta di credito.
                </p>

                <div style="margin-top: var(--sp-3);">
                    <x-sito.scarica :grande="true" />
                </div>

                <p class="nota" style="margin-top: var(--sp-2);">
                    Funziona da sola o con la tua palestra. La scelta è tua e si cambia
                    quando vuoi.
                </p>
            </div>

            {{--
                🚨 **La fotografia sta DIETRO, il telefono è disegnato davanti.**

                L'immagine dà il tono — palestra, luce, movimento — e l'unica
                cosa che **afferma qualcosa sul prodotto** resta scritta in HTML.

                ⚠️ Il contrario — una schermata dell'app dentro una fotografia —
                sarebbe una schermata **inventata**: mostrerebbe un'interfaccia
                che non esiste, e il primo che scarica l'app se ne accorge. È lo
                stesso motivo per cui qui non ci sono loghi di clienti.

                💡 E il telefono disegnato non va tenuto aggiornato con
                un'esportazione grafica: se cambia il testo dello spunto, si
                cambia qui.
            --}}
            <div style="position: relative; display: flex; justify-content: center; align-items: center; min-height: 420px; padding: var(--sp-4) var(--sp-3);">
                <div class="figura-eroe">
                    <x-sito.figura
                        nome="eroe"
                        alt="Una sala pesi con la luce del mattino"
                        :priorita="true"
                    />
                </div>
                <div class="velo-eroe"></div>

                <div aria-hidden="true" style="position: relative; z-index: 2; width: 280px; border: 10px solid var(--testo); border-radius: 36px; padding: var(--sp-2) 12px; background: var(--sfondo); box-shadow: 0 20px 50px rgba(0,0,0,.22);">
                    <div style="height: 5px; width: 70px; background: var(--testo); border-radius: 99px; margin: 0 auto var(--sp-2);"></div>

                    <div style="background: var(--accento-tenue); border-radius: 14px; padding: 14px;">
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <strong style="font-size: 14px; color: var(--accento);">✦ Spunto di oggi</strong>
                        </div>
                        <p style="font-size: 13px; margin: 8px 0 0; line-height: 1.5;">
                            Sei a 1.240 kcal a metà pomeriggio: sei in linea. Stanotte hai
                            dormito poco, quindi oggi potresti tenere il carico più basso.
                        </p>
                        <p style="font-size: 10px; color: var(--testo-tenue); margin: 10px 0 0; line-height: 1.4;">
                            Scritto da un'intelligenza artificiale e può sbagliare. Non è un
                            parere medico.
                        </p>
                    </div>

                    <div style="display: flex; gap: 8px; margin-top: 10px;">
                        <div style="flex: 1; border: 1px solid var(--bordo); border-radius: 12px; padding: 10px;">
                            <div style="font-size: 18px; font-weight: 800;">1.240</div>
                            <div style="font-size: 10px; color: var(--testo-tenue);">di 2.100 kcal</div>
                        </div>
                        <div style="flex: 1; border: 1px solid var(--bordo); border-radius: 12px; padding: 10px;">
                            <div style="font-size: 18px; font-weight: 800;">412</div>
                            <div style="font-size: 10px; color: var(--testo-tenue);">bruciate</div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{--
        ═══════════════════ come funziona ═══════════════════

        🚨 **Tre passi che una persona compie davvero, in ordine.**

        Prima erano «entrano tutti / chi vuole l'AI la accende / la palestra la
        rivende»: tre passi del **modello di business**, non del prodotto. Chi
        li leggeva non sapeva ancora cosa fa l'app, e imparava invece come si
        guadagna sopra di lui.
    --}}
    <section id="come-funziona" class="tenue">
        <div class="contenitore">
            <div class="intestazione-sezione">
                <div class="occhiello">Come funziona</div>
                <h2>Tre passi, e ci vogliono due minuti.</h2>
                <p class="guida">
                    Non c'è niente da configurare prima di cominciare: si scarica, si entra
                    e si scrive il primo pasto.
                </p>
            </div>

            <div class="griglia">
                <div class="scheda">
                    <div class="numero-passo">1</div>
                    <h3>Scarichi ed entri</h3>
                    <p class="nota">
                        Se la tua palestra usa Training Companion, inserisci il suo codice e
                        sei già collegato al tuo trainer. <strong>Se non ce l'hai</strong>,
                        ti registri e basta: l'app funziona lo stesso.
                    </p>
                </div>

                <div class="scheda">
                    <div class="numero-passo">2</div>
                    <h3>Segni cosa mangi e come ti alleni</h3>
                    <p class="nota">
                        Scrivi <em>«pasta al pomodoro e due uova»</em> e l'app calcola
                        calorie e macro. Gli allenamenti li registri esercizio per esercizio,
                        serie per serie, e li ritrovi la volta dopo.
                    </p>
                </div>

                <div class="scheda">
                    <div class="numero-passo">3</div>
                    <h3>Ricevi le schede dal tuo trainer</h3>
                    <p class="nota">
                        Il tuo trainer ti manda la scheda e il piano alimentare direttamente
                        in chat. Li apri, li segui, e <strong>restano tuoi</strong> anche se
                        cambi palestra.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ cosa c'è dentro ═══════════════════ --}}
    <section>
        <div class="contenitore">
            <div class="intestazione-sezione">
                <div class="occhiello">Cosa c'è dentro</div>
                <h2>Tutto quello che serve, niente che non serva.</h2>
            </div>

            <div class="griglia">
                <div class="scheda">
                    <h3>🍽 Diario alimentare</h3>
                    <p class="nota">
                        Calorie, proteine, carboidrati e grassi. Dai preferiti si registra un
                        pasto in due tocchi, e il fabbisogno si calcola da peso, altezza e
                        quanto ti muovi.
                    </p>
                </div>

                <div class="scheda">
                    <h3>🏋 Allenamenti</h3>
                    <p class="nota">
                        Carichi, serie e ripetizioni, con lo storico di ogni esercizio. Vedi
                        subito cosa avevi fatto l'ultima volta, senza cercarlo.
                    </p>
                </div>

                <div class="scheda">
                    <h3>📋 Schede e piani</h3>
                    <p class="nota">
                        Quello che il tuo trainer prepara per te, sul telefono: giorno per
                        giorno, esercizio per esercizio, con le sue note.
                    </p>
                </div>

                <div class="scheda">
                    <h3>💬 Chat col trainer</h3>
                    <p class="nota">
                        Per chiedere se un esercizio si fa così, mandare una foto, cambiare
                        un orario. <strong>Cifrata</strong>: la leggete solo voi due.
                    </p>
                </div>

                <div class="scheda">
                    <h3>📈 Peso e misure</h3>
                    <p class="nota">
                        L'andamento nel tempo, con le foto dei progressi se le vuoi. Restano
                        sul tuo telefono e non le vede nessun altro.
                    </p>
                </div>

                <div class="scheda">
                    <h3>😴 Sonno e recupero</h3>
                    <p class="nota">
                        Se colleghi l'orologio, l'app legge sonno, battito e variabilità — e
                        te li rimette accanto a com'è andato l'allenamento.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{--
        ═══════════════════ l'AI, che è un supplemento ═══════════════════

        ⚠️ **Sezione separata, e dopo le funzioni.** L'AI è la parte che si
        paga: metterla in mezzo alle cose gratuite farebbe sembrare a pagamento
        anche quelle, che è esattamente il malinteso da evitare.
    --}}
    <section class="tenue">
        <div class="contenitore stretto">
            <div class="intestazione-sezione">
                <div class="occhiello">Se ti va</div>
                <h2>L'intelligenza artificiale è un extra.</h2>
                <p class="guida">
                    Tutto quello che hai letto fin qui è gratis e resta gratis. L'AI si
                    accende solo se la vuoi, e senza non manca niente: si scrive il pasto a
                    mano come si è sempre fatto.
                </p>
            </div>

            <div class="griglia">
                <div class="scheda">
                    <h3>Scrivi, e conta lei</h3>
                    <p class="nota">Una frase in italiano al posto di cercare ogni ingrediente in un elenco.</p>
                </div>
                <div class="scheda">
                    <h3>Fotografa il piatto</h3>
                    <p class="nota">Quando non hai voglia di scrivere: una foto, e i macro sono stimati.</p>
                </div>
                <div class="scheda">
                    <h3>Lo spunto del giorno</h3>
                    <p class="nota">Due righe su come sta andando la giornata, costruite sui tuoi numeri.</p>
                </div>
            </div>

            {{-- 🚨 Sta qui e non in fondo a una pagina di condizioni: è la cosa
                 che una persona deve sapere **prima** di dare peso a quello che
                 legge nell'app. --}}
            <div class="scheda" style="margin-top: var(--sp-3); border-color: color-mix(in srgb, var(--accento) 25%, transparent); background: var(--accento-tenue);">
                <h3>⚠️ Quello che l'AI non è</h3>
                <p class="nota">
                    Non è un medico e non è un nutrizionista. Quello che scrive è un'ipotesi
                    costruita su pochi numeri, <strong>può sbagliare</strong>, e non conosce
                    la tua storia clinica. Se riguarda la tua salute, parlane con un medico
                    dello sport. Nell'app c'è scritto sotto ogni spunto.
                </p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ la privacy, che qui è una funzione ═══════════════════ --}}
    <section>
        <div class="contenitore griglia-2">
            <div>
                <div class="occhiello">Quello che non facciamo</div>
                <h2>I dati del tuo corpo non stanno sui nostri server.</h2>
                <p class="guida">
                    Peso, misure, foto dei progressi, sonno e battito vivono
                    <strong>nel tuo telefono</strong>. Non è una promessa scritta in un
                    documento: le tabelle che li contenevano sono state cancellate.
                </p>
                <p class="guida" style="margin-top: var(--sp-2);">
                    I messaggi con il tuo trainer sono cifrati da un telefono all'altro.
                    Noi li consegniamo <strong>senza poterli leggere</strong> — nemmeno
                    volendo, nemmeno la tua palestra.
                </p>
                <p class="guida" style="margin-top: var(--sp-2);">
                    E non vendiamo niente a nessuno: non c'è pubblicità profilata, non c'è
                    un secondo mestiere fatto coi tuoi dati.
                </p>
                <p style="margin-top: var(--sp-3);">
                    <a href="/privacy" class="bottone bottone-vuoto">Leggi l'informativa</a>
                </p>
            </div>

            <div class="griglia" style="grid-template-columns: 1fr;">
                {{-- 💡 Una fotografia riservata, non una di prodotto: qui si
                     sta dicendo «questa roba è tua», e una foto di attrezzi
                     direbbe il contrario. --}}
                <x-sito.figura
                    nome="privacy"
                    alt="Una persona guarda il proprio telefono, di sera, in un momento riservato"
                />
                <div class="scheda tenue">
                    <h3>📱 Resta sul telefono</h3>
                    <p class="nota">Peso e misure · foto dei progressi · sonno, battito e variabilità · le schede che ricevi</p>
                </div>
                <div class="scheda tenue">
                    <h3>🔒 Cifrato da capo a fondo</h3>
                    <p class="nota">I messaggi con il trainer. La chiave non passa da noi, quindi non possiamo leggerli.</p>
                </div>
                <div class="scheda tenue">
                    <h3>☁️ Sta da noi</h3>
                    <p class="nota">Chi sei, a quale palestra appartieni, il diario alimentare e cosa hai comprato.</p>
                </div>
            </div>
        </div>
    </section>

    {{--
        ═══════════════════ per chi ═══════════════════

        ⚠️ Le tre platee restano — servono a chi arriva da fuori a capire se la
        cosa lo riguarda — ma **nessuna delle tre parla di soldi**. Chi vuole i
        numeri clicca, e li trova su `/prezzi`.
    --}}
    <section class="tenue">
        <div class="contenitore">
            <div class="intestazione-sezione">
                <div class="occhiello">Per chi</div>
                <h2>Tre modi di usarla.</h2>
            </div>

            <div class="griglia">
                <div class="scheda">
                    <x-sito.figura
                        nome="platea-solo"
                        alt="Una persona che si allena da sola in casa, di prima mattina"
                    />
                    <h3>Per chi si allena da solo</h3>
                    <p class="nota">
                        Non serve nessuna palestra e non serve nessun codice. Ti registri e
                        cominci, e l'app è tutta lì.
                    </p>
                    <ul class="elenco">
                        <li>Diario, allenamenti e progressi</li>
                        <li>Nessun periodo di prova che scade</li>
                        <li>L'AI a parte, se ti va</li>
                    </ul>
                </div>

                <div class="scheda">
                    <x-sito.figura
                        nome="platea-palestra"
                        alt="La reception di una palestra, con un tablet sul bancone"
                    />
                    <h3>Per le palestre</h3>
                    <p class="nota">
                        L'app con il tuo marchio e i tuoi colori, in mano a tutti gli
                        iscritti. I trainer preparano schede e piani e li mandano in chat.
                    </p>
                    <ul class="elenco">
                        <li>App con il tuo logo</li>
                        <li>Pannello per lo staff</li>
                        <li>Gratis per tutti i tuoi iscritti</li>
                    </ul>
                    <p style="margin-top: var(--sp-2);">
                        <a href="/prezzi" class="bottone bottone-vuoto">Vedi i prezzi</a>
                    </p>
                </div>

                <div class="scheda">
                    <x-sito.figura
                        nome="platea-trainer"
                        alt="Un personal trainer che segue un allievo durante un esercizio"
                    />
                    <h3>Per i trainer indipendenti</h3>
                    <p class="nota">
                        I tuoi allievi entrano con un link tuo: non serve una palestra.
                        Componi dal computer, mandi dal telefono.
                    </p>
                    <ul class="elenco">
                        <li>Schede e piani alimentari</li>
                        <li>Chat cifrata con i tuoi allievi</li>
                        <li>Quello che mandi resta loro</li>
                    </ul>
                    <p style="margin-top: var(--sp-2);">
                        <a href="/prezzi" class="bottone bottone-vuoto">Vedi i prezzi</a>
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ domande ═══════════════════ --}}
    <section id="domande">
        <div class="contenitore stretto">
            <div class="intestazione-sezione">
                <div class="occhiello">Domande frequenti</div>
                <h2>Le cose che ci chiederesti.</h2>
            </div>

            <details>
                <summary>Davvero gratis, o gratis per tre mesi?</summary>
                <p>
                    Gratis. Diario, allenamenti, schede e chat non hanno una scadenza e non
                    chiedono una carta. L'unica cosa che si paga è l'intelligenza
                    artificiale, e solo se la si accende.
                </p>
            </details>

            <details>
                <summary>Serve che la mia palestra la usi?</summary>
                <p>
                    No. Con il codice della palestra sei collegato al tuo trainer e ricevi
                    le sue schede; senza, l'app funziona lo stesso e tieni tutto da solo.
                    Si può anche cominciare da soli e collegarsi dopo.
                </p>
            </details>

            <details>
                <summary>L'AI mi dice cosa mangiare o come allenarmi?</summary>
                <p>
                    No, e ci teniamo a essere chiari: quello che scrive è un'ipotesi
                    costruita su pochi numeri, <strong>non è un parere medico</strong> e non
                    va preso per buono. Se riguarda la tua salute, parlane con un medico
                    dello sport. Nell'app c'è scritto sotto ogni spunto, non in fondo a una
                    pagina di condizioni.
                </p>
            </details>

            <details>
                <summary>La mia palestra vede quanto peso o quanto mi alleno?</summary>
                <p>
                    No. Peso, misure, foto e sonno non arrivano nemmeno a noi: restano sul
                    tuo telefono. E i messaggi con il tuo trainer sono cifrati fra i due
                    telefoni, quindi non li legge nessun altro — noi compresi.
                </p>
            </details>

            <details>
                <summary>Se cambio telefono perdo tutto?</summary>
                <p>
                    No, ma serve il backup: l'app ne fa uno automatico col ripristino di
                    sistema, e in più puoi esportare un file protetto da un codice.
                    ⚠️ Siccome quei dati non ce li abbiamo noi, <strong>se perdi telefono e
                    backup insieme non possiamo recuperarli</strong>. È il prezzo di non
                    tenerli.
                </p>
            </details>

            <details>
                <summary>Se smetto con la palestra, perdo le schede?</summary>
                <p>
                    No. Le schede e i piani che il tuo trainer ti ha mandato
                    <strong>restano tuoi</strong>: sono sul tuo telefono e non li tocca
                    nessuno, nemmeno se il rapporto con la palestra finisce.
                </p>
            </details>
        </div>
    </section>

    {{-- ═══════════════════ chiusura ═══════════════════ --}}
    <section class="tenue" style="text-align: center;">
        <div class="contenitore stretto">
            <h2>Comincia stasera, col primo pasto.</h2>
            <p class="guida" style="margin: 0 auto var(--sp-3);">
                Non serve una palestra, non serve una carta e non c'è niente da disdire.
            </p>
            <div style="display: flex; justify-content: center;">
                <x-sito.scarica :grande="true" />
            </div>
        </div>
    </section>

@endsection
