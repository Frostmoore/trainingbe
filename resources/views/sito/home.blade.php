@extends('sito.layout')

@section('titolo', 'Training Companion — l\'app per chi si allena')
@section('descrizione', 'Diario, allenamenti e chat con il tuo trainer: gratis, per sempre. L\'AI è un supplemento che accendi solo se lo vuoi.')

@section('contenuto')

    {{-- ═══════════════════ apertura ═══════════════════ --}}
    <section style="padding-top: var(--sp-5);">
        <div class="contenitore griglia-2">
            <div>
                <span class="pillola">In prova con le prime palestre</span>

                {{-- 🚨 L'affermazione è **l'app è gratis**, non «l'AI che ti
                     cambia la vita». È la cosa vera e la più difficile da
                     copiare: gli altri fanno pagare l'ingresso. --}}
                <h1 style="margin-top: var(--sp-2);">L'app per chi si allena.<br>Gratis, per sempre.</h1>

                <p class="guida">
                    Diario alimentare, schede, allenamenti e la chat cifrata con il tuo
                    trainer. Non costa niente e non ha un periodo di prova che scade.
                </p>

                <p class="guida" style="margin-top: var(--sp-2);">
                    L'intelligenza artificiale è un <strong>supplemento</strong>: la accendi
                    se ti serve, e la paghi solo mentre la usi.
                </p>

                <div style="display: flex; gap: var(--sp-1); flex-wrap: wrap; margin-top: var(--sp-3);">
                    <a href="/prezzi" class="bottone bottone-pieno bottone-grande">Guarda i prezzi</a>
                    <a href="#come-funziona" class="bottone bottone-vuoto bottone-grande">Come funziona</a>
                </div>

                <p class="nota" style="margin-top: var(--sp-2);">
                    Nessuna carta di credito per cominciare. Nessuna, proprio.
                </p>
            </div>

            {{--
                🚨 **La fotografia sta DIETRO, il telefono è disegnato davanti.**

                L'immagine generata dà il tono — palestra, luce, movimento — e
                l'unica cosa che **afferma qualcosa sul prodotto** resta scritta
                in HTML.

                ⚠️ Il contrario — una schermata dell'app dentro una fotografia —
                sarebbe una schermata **inventata**: mostrerebbe un'interfaccia
                che non esiste, e il primo che scarica l'app se ne accorge. È lo
                stesso motivo per cui qui non ci sono loghi di clienti.

                💡 E il telefono disegnato non va tenuto aggiornato con
                un'esportazione grafica: se cambia il testo dello spunto, si
                cambia qui.

                Il velo in mezzo serve a far leggere la scheda: senza, una
                fotografia piena di dettagli se la mangia.
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
                            <span style="margin-left: auto; font-size: 11px; color: var(--accento); border: 1px solid var(--accento); border-radius: 99px; padding: 1px 7px;">1 gettone</span>
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

    {{-- ═══════════════════ come funziona ═══════════════════ --}}
    <section id="come-funziona" class="tenue">
        <div class="contenitore">
            <div class="intestazione-sezione">
                <div class="occhiello">Come funziona</div>
                <h2>Il prodotto è gratis. L'AI è il supplemento.</h2>
                <p class="guida">
                    È il contrario di come funzionano quasi tutte le app di questo tipo, e
                    non è una trovata: è l'unico modo perché una palestra possa metterla in
                    mano a tutti gli iscritti senza chiedere niente a nessuno.
                </p>
            </div>

            <div class="griglia">
                <div class="scheda">
                    <h3>1 · Entrano tutti</h3>
                    <p class="nota">
                        La palestra dà il proprio codice agli iscritti. Loro installano l'app e
                        hanno diario, schede, allenamenti e la chat con il trainer.
                        <strong>Costo: zero.</strong>
                    </p>
                </div>
                <div class="scheda">
                    <h3>2 · Chi vuole l'AI la accende</h3>
                    <p class="nota">
                        Stima delle calorie da una frase o da una foto, e uno spunto sulla
                        giornata. La palestra decide a chi accenderla, uno per uno.
                    </p>
                </div>
                <div class="scheda">
                    <h3>3 · La palestra la rivende</h3>
                    <p class="nota">
                        {{-- 🚨 Anche qui i numeri vengono da `Listino`: era l'unico
                             prezzo rimasto scritto a mano in un template, ed è
                             esattamente il caso che `no_price_is_written_inside_a_view`
                             esiste per impedire. --}}
                        Un posto costa <strong>{{ $formatta($primoScaglione) }}</strong> al mese.
                        Se lo rivende a {{ $formatta($rivenditaSuggerita) }}
                        sull'abbonamento, <strong>{{ $formatta($rivenditaSuggerita - $primoScaglione) }}
                        restano a lei</strong> — e più posti accende, più le resta per ciascuno.
                    </p>
                </div>
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
                    <strong>nel telefono di chi li produce</strong>. Non è una promessa
                    scritta in un documento: le tabelle che li contenevano sono state
                    cancellate.
                </p>
                <p class="guida" style="margin-top: var(--sp-2);">
                    I messaggi con il tuo trainer sono cifrati da un telefono all'altro.
                    Noi li consegniamo <strong>senza poterli leggere</strong> — nemmeno
                    volendo, nemmeno la tua palestra.
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

    {{-- ═══════════════════ per chi ═══════════════════ --}}
    <section class="tenue">
        <div class="contenitore">
            <div class="intestazione-sezione">
                <div class="occhiello">Per chi</div>
                <h2>Tre modi di usarla.</h2>
            </div>

            <div class="griglia">
                <div class="scheda">
                    <x-sito.figura
                        nome="platea-palestra"
                        alt="La reception di una palestra, con un tablet sul bancone"
                    />
                    <h3>Per le palestre</h3>
                    <p class="nota">
                        L'app con il tuo marchio e i tuoi colori. I trainer compongono schede e
                        piani dal pannello o dal telefono, e li mandano in chat.
                    </p>
                    <ul class="elenco">
                        <li>App con il tuo logo</li>
                        <li>Pannello per lo staff</li>
                        <li>Paghi solo i posti AI accesi</li>
                    </ul>
                </div>

                <div class="scheda">
                    <x-sito.figura
                        nome="platea-trainer"
                        alt="Un personal trainer che segue un allievo durante un esercizio"
                    />
                    <h3>Per i trainer indipendenti</h3>
                    <p class="nota">
                        I tuoi allievi entrano con un link tuo: non serve una palestra. Componi
                        dal computer, mandi dal telefono.
                    </p>
                    <ul class="elenco">
                        <li>Schede e piani alimentari</li>
                        <li>Chat cifrata con i tuoi</li>
                        <li>Quello che mandi resta loro</li>
                    </ul>
                </div>

                <div class="scheda">
                    <x-sito.figura
                        nome="platea-solo"
                        alt="Una persona che si allena da sola in casa, di prima mattina"
                    />
                    <h3>Per chi si allena da solo</h3>
                    <p class="nota">
                        Non serve nessuna palestra e non serve nessun codice. Ti registri e
                        cominci.
                    </p>
                    <ul class="elenco">
                        <li>Diario e allenamenti, gratis</li>
                        <li>AI a parte, se la vuoi</li>
                        <li>Nessun periodo di prova che scade</li>
                    </ul>
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
                    chiedono una carta. Si paga solo l'intelligenza artificiale, e solo
                    mentre la si usa.
                </p>
            </details>

            <details>
                <summary>Cos'è un gettone?</summary>
                <p>
                    Una chiamata all'AI. Scrivere cosa hai mangiato ne costa <strong>1</strong>,
                    riconoscerlo da una foto ne costa <strong>10</strong> — perché a noi costa
                    circa quattro volte tanto. Registrare un pasto dal tuo piano, dai preferiti
                    o a mano non costa niente.
                </p>
            </details>

            <details>
                <summary>L'AI mi dice cosa mangiare o come allenarmi?</summary>
                <p>
                    No, e ci teniamo a essere chiari: quello che scrive è un'ipotesi costruita
                    su pochi numeri, <strong>non è un parere medico</strong> e non va preso per
                    buono. Se riguarda la tua salute, parlane con un medico dello sport. Nell'app
                    c'è scritto sotto ogni spunto, non in fondo a una pagina di condizioni.
                </p>
            </details>

            <details>
                <summary>La mia palestra vede quanto peso o quanto mi alleno?</summary>
                <p>
                    No. Peso, misure, foto e sonno non arrivano nemmeno a noi: restano sul tuo
                    telefono. E i messaggi con il tuo trainer sono cifrati fra i due telefoni,
                    quindi non li legge nessun altro — noi compresi.
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
                <summary>Cosa succede se smetto di pagare l'AI?</summary>
                <p>
                    L'app continua a funzionare come prima, senza le funzioni AI. Le schede e i
                    piani che il tuo trainer ti ha mandato <strong>restano tuoi</strong>: sono
                    sul tuo telefono e non li tocca nessuno.
                </p>
            </details>
        </div>
    </section>

    {{-- ═══════════════════ chiusura ═══════════════════ --}}
    <section class="tenue" style="text-align: center;">
        <div class="contenitore stretto">
            <h2>Provala con la tua palestra.</h2>
            <p class="guida" style="margin: 0 auto var(--sp-3);">
                Si comincia da cinque posti. Se non funziona, si spegne — non c'è niente da
                disdire.
            </p>
            <a href="/prezzi" class="bottone bottone-pieno bottone-grande">Guarda i prezzi</a>
        </div>
    </section>

@endsection
