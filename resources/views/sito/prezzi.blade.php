{{--
    La pagina dei prezzi — a carosello dal 16/08/2026, sera.

    ── 🚨 Perché a carosello e non tre sezioni impilate ───────────────────────

    Perché le tre platee **non comprano la stessa cosa**: palestre e trainer
    pagano a numero di persone, chi si allena da solo paga a numero di gettoni.
    Impilate, chi legge le attraversa tutte e tre e confronta grandezze diverse
    come se fossero la stessa; affiancate in un carosello ne vede **una per
    volta**, e le altre due sono lì solo se le cerca.

    ⚠️ **Si parte da chi si allena da solo**, non dalla palestra. È la platea
    più larga e la sola che arriva qui senza sapere già cosa vuole comprare —
    la palestra ci arriva sapendolo, e una linguetta le basta.

    💡 **L'esempio con i margini sta in questa pagina e non sulla home**: qui una
    palestra lo sta cercando apposta. Sulla home sarebbe la cosa che fa chiudere
    la scheda a chi voleva solo scaricare un'app.
--}}
@extends('sito.layout')

@section('titolo', 'Prezzi — Training Companion')
@section('descrizione', 'L\'app è gratis. Si paga solo l\'AI: a gettoni per chi si allena da solo, a persona per trainer e palestre.')

@section('contenuto')

    <section style="padding-bottom: var(--sp-3);">
        <div class="contenitore stretto" style="text-align: center;">
            <div class="occhiello">Prezzi</div>
            <h1>Si paga solo quello che si accende.</h1>
            <p class="guida" style="margin: 0 auto;">
                L'app resta gratis per tutti: diario, allenamenti, schede e chat non hanno
                un costo né una scadenza. Si paga soltanto l'intelligenza artificiale.
            </p>
        </div>
    </section>

    <section style="padding-top: 0; padding-bottom: var(--sp-4);">
        <div class="contenitore">
            <x-sito.figura
                nome="prezzi"
                alt="Un gruppo che si allena in sala, visto da lontano"
            />
        </div>
    </section>

    <section style="padding-top: 0;">
        <div class="contenitore">

            {{-- 🚨 Le linguette sono link veri: funzionano anche senza
                 JavaScript, e premerle sposta il carosello perché il browser
                 scorre l'antenato scorrevole più vicino. --}}
            <div class="linguette">
                <a href="#solo">
                    <strong>Ti alleni da solo</strong>
                    <span>da {{ $formatta($prezzoSingolo) }} al mese</span>
                </a>
                <a href="#trainer">
                    <strong>Sei un trainer</strong>
                    <span>da {{ $formatta($listino->migliorPrezzoPerPosto('trainer')) }} ad allievo</span>
                </a>
                <a href="#palestre">
                    <strong>Sei una palestra</strong>
                    <span>da {{ $formatta($listino->migliorPrezzoPerPosto('palestre')) }} a iscritto</span>
                </a>
            </div>

            <div class="carosello">

                {{-- ═════════ 1 · chi si allena da solo ═════════

                     🚨 **Qui l'unità di misura è diversa dalle altre due**, ed è
                     il motivo per cui questa non poteva essere una riga nella
                     stessa tabella: una palestra compra **persone**, una persona
                     compra **gettoni**.
                --}}
                <div class="anta" id="solo">
                    <div class="intestazione-sezione" style="text-align: left;">
                        <div class="occhiello">Ti alleni da solo</div>
                        <h2>Diario e allenamenti gratis. L'AI, se la vuoi.</h2>
                    </div>

                    {{--
                        🚨 **L'abbonamento sta per primo e occupa tutta la larghezza.**

                        Prima era una nota in fondo, e il pacchetto da 500 gettoni
                        era la cosa evidenziata: esattamente al contrario di come
                        conviene leggerla. ⚠️ L'abbonamento è la cosa che rende il
                        servizio sostenibile; un pacchetto una tantum è quello che
                        compri quando finisci.
                    --}}
                    <div class="offerta-principale">
                        <div>
                            <span class="etichetta">Consigliato</span>
                            <h3 style="margin-top: var(--sp-1);">Abbonamento mensile</h3>
                            <p class="nota" style="margin-top: 4px;">
                                {{ number_format($gettoniMensili, 0, ',', '.') }} richieste al mese, che
                                si rinnovano. Niente credito da controllare, niente ricariche da
                                ricordarsi.
                            </p>
                            <ul class="elenco" style="margin-top: var(--sp-2);">
                                <li>Scrivi cosa hai mangiato e conta l'AI</li>
                                <li>Riconoscimento dalla foto del piatto</li>
                                <li>Lo spunto del giorno</li>
                                <li>Si disdice quando vuoi</li>
                            </ul>
                        </div>

                        <div class="offerta-prezzo">
                            <div class="prezzo-grande">{{ $formatta($prezzoSingolo) }}</div>
                            <div class="nota">al mese, IVA inclusa</div>
                            <a href="/registrati" class="bottone bottone-pieno bottone-grande"
                               style="margin-top: var(--sp-2);">Iscriviti ora</a>
                        </div>
                    </div>

                    <h3 style="margin-top: var(--sp-4);">Oppure a consumo, senza abbonarti</h3>
                    <p class="nota">
                        Gettoni che compri una volta e ti durano <strong>24 mesi</strong>. Vanno
                        bene se l'AI la accendi ogni tanto.
                    </p>

                    <div class="griglia" style="margin-top: var(--sp-2);">
                        @foreach ($pacchetti as $p)
                            <div class="scheda scheda-prezzo" style="text-align: center;">
                                <h3>{{ number_format($p['gettoni'], 0, ',', '.') }} gettoni</h3>
                                <div class="prezzo">{{ $formatta($p['prezzo']) }}</div>
                                <p class="nota">{{ $p['nota'] }}</p>
                            </div>
                        @endforeach
                    </div>

                    <div class="scheda tenue" style="margin-top: var(--sp-3);">
                        <h3>Quanto dura un gettone</h3>
                        <ul class="elenco">
                            <li><strong>1 gettone</strong> — scrivere cosa hai mangiato, o uno spunto del giorno</li>
                            <li><strong>10 gettoni</strong> — riconoscere un pasto da una foto</li>
                            <li class="no"><strong>gratis</strong> — dal tuo piano, dai preferiti, a mano, e tutti gli allenamenti</li>
                        </ul>
                        <p class="nota" style="margin-top: var(--sp-2);">
                            ⚠️ La foto costa dieci perché a noi costa circa quattro volte una frase,
                            e il resto è il margine con cui il servizio sta in piedi. Preferiamo
                            dirlo che nasconderlo in una nota.
                        </p>
                    </div>
                </div>

                {{-- ═════════ 2 · i trainer indipendenti ═════════ --}}
                <div class="anta" id="trainer">
                    <x-sito.listino-posti
                        occhiello="Sei un trainer indipendente"
                        titolo="Paghi per gli allievi a cui accendi l'AI."
                        sottotitolo="Non serve una palestra: i tuoi allievi entrano con un link tuo."
                        unita="allievi"
                        unitaSingolare="allievo"
                        :pacchetti="$listino->pacchettiPosti('trainer')"
                        :aConsumo="$listino->aConsumo('trainer')"
                        :minimo="$listino->minimo('trainer')"
                        :formatta="$formatta"
                    />

                    <ul class="elenco" style="margin-top: var(--sp-3);">
                        <li>Schede e piani alimentari, dal computer o dal telefono</li>
                        <li>Chat cifrata con i tuoi allievi</li>
                        <li>Quello che mandi resta loro, anche se smettete</li>
                    </ul>
                </div>

                {{-- ═════════ 3 · le palestre ═════════ --}}
                <div class="anta" id="palestre">
                    <x-sito.listino-posti
                        occhiello="Sei una palestra"
                        titolo="Paghi per gli iscritti a cui accendi l'AI."
                        sottotitolo="Tutti gli altri usano l'app gratis, con il tuo marchio."
                        unita="iscritti"
                        unitaSingolare="iscritto"
                        :pacchetti="$listino->pacchettiPosti('palestre')"
                        :aConsumo="$listino->aConsumo('palestre')"
                        :minimo="$listino->minimo('palestre')"
                        :formatta="$formatta"
                    />

                    {{--
                        💡 **L'esempio è il pezzo che vende**, e non è un vezzo: una
                        palestra non compra «4,40 a posto», compra «incasso 250 e ne
                        pago 110». Il numero che conta è la differenza.
                    --}}
                    <div class="scheda evidenziata" style="margin-top: var(--sp-3);">
                        <h3>Un esempio con {{ $esempio['posti'] }} iscritti</h3>
                        <div class="conti">
                            <div>
                                <div class="nota">Paghi a noi</div>
                                <strong>{{ $formatta($esempio['costo']) }}</strong>
                            </div>
                            <div>
                                <div class="nota">Se lo rivendi a 10 €</div>
                                <strong>{{ $formatta($esempio['ricavo']) }}</strong>
                            </div>
                            <div>
                                <div class="nota">Ti resta</div>
                                <strong class="risalta">{{ $formatta($esempio['margine']) }}</strong>
                            </div>
                        </div>
                        <p class="nota" style="margin-top: var(--sp-2);">
                            I 10 € sono un <strong>suggerimento</strong>, non un obbligo: quanto e
                            se addebitare qualcosa ai tuoi iscritti lo decidi tu, e ne rispondi tu.
                        </p>
                    </div>
                </div>

            </div>
        </div>
    </section>

    {{-- ═══════════════════ domande sui prezzi ═══════════════════ --}}
    <section class="tenue">
        <div class="contenitore stretto">
            <div class="intestazione-sezione">
                <div class="occhiello">Domande</div>
                <h2>Sui prezzi, in particolare.</h2>
            </div>

            {{--
                📣 **La pubblicità nel catalogo** — M5.7, 18/08/2026.

                🚨 Sta fra le domande e non fra i piani, ed è voluto: non è un
                piano, è un servizio in più che si accende quando serve. Metterla
                accanto ai pacchetti l'avrebbe fatta sembrare un costo obbligato
                per stare nel catalogo, che è il contrario di come funziona —
                nel catalogo ci si sta gratis.
            --}}
            <details>
                <summary>Posso farmi trovare per primo nel catalogo?</summary>
                <p>
                    Sì, e si paga <strong>solo per le persone che ti vedono davvero</strong>:
                    <strong>{{ number_format($costoVisualizzazione, 2, ',', '.') }} €</strong> a persona
                    raggiunta — che sono {{ number_format($costoVisualizzazione * 1000, 0, ',', '.') }} €
                    ogni mille. Non c'è nessun canone: la accendi e la spegni quando vuoi.
                </p>
                <p>
                    <strong>Una persona vale una volta al giorno</strong>, anche se apre il catalogo
                    dieci volte: paghi le persone raggiunte, non le schermate. E scegli un
                    <strong>tetto di spesa mensile</strong> — minimo
                    {{ number_format($budgetMinimo, 0, ',', '.') }} € — oltre il quale la campagna si
                    spegne da sola e riparte il mese dopo.
                </p>
                <p>
                    I risultati a pagamento portano l'etichetta <strong>«Sponsorizzato»</strong>:
                    chi cerca ha diritto di sapere cosa sta guardando.
                </p>
            </details>

            <details>
                <summary>Quanto dura un pacchetto di gettoni?</summary>
                <p>
                    Dipende da come li spendi: scrivere un pasto ne costa <strong>1</strong>,
                    riconoscerlo da una foto ne costa <strong>10</strong>, e registrarlo dal
                    tuo piano, dai preferiti o a mano non ne costa nessuno. Chi scrive quasi
                    sempre e fotografa ogni tanto se la cava con poche decine a settimana.
                </p>
            </details>

            {{--
                📌 **Il numero si dice.**

                Fino al 17/08 questa risposta parlava di «uso quotidiano» senza
                dare la cifra, per non invitare al confronto con i pacchetti.
                ⚠️ Ma il limite sta comunque nelle condizioni d'uso, e un numero
                che c'è ma non si dice si legge come qualcosa da nascondere.
            --}}
            <details>
                <summary>Quante richieste ho con l'abbonamento?</summary>
                <p>
                    <strong>{{ number_format($gettoniMensili, 0, ',', '.') }} al mese</strong>, che si
                    rinnovano e non si accumulano: sono circa
                    <strong>{{ (int) round($gettoniMensili / 30) }} al giorno</strong>, con dentro
                    qualche foto. Scrivere un pasto ne costa 1, riconoscerlo da una foto ne costa
                    10; registrarlo dal tuo piano, dai preferiti o a mano non ne costa nessuno.
                </p>
            </details>

            <details>
                <summary>E se le finisco prima della fine del mese?</summary>
                <p>
                    L'app continua a funzionare come sempre: diario, allenamenti, schede e chat
                    non c'entrano niente con l'AI. Se vuoi rimetterla in moto subito compri un
                    pacchetto di gettoni, che dura 24 mesi e non si azzera al rinnovo.
                </p>
            </details>

            <details>
                <summary>Cosa succede se supero i posti del mio pacchetto?</summary>
                <p>
                    Niente si spegne da solo. Ti avvisiamo e puoi passare al pacchetto sopra
                    quando vuoi: la differenza si paga dal mese successivo, non all'indietro.
                </p>
            </details>

            <details>
                <summary>Se spengo un posto a metà mese, cosa pago?</summary>
                <p>
                    Si contano i posti <strong>al massimo raggiunto nel mese</strong>. È la regola
                    più semplice da verificare, e impedisce sia a noi di gonfiare il conto sia di
                    accendere cento posti per un pomeriggio senza pagarli.
                </p>
            </details>

            <details>
                <summary>Devo firmare per un anno?</summary>
                <p>
                    No. Si paga mese per mese e si spegne quando si vuole: non c'è niente da
                    disdire perché non c'è nessun impegno.
                </p>
            </details>

            <details>
                <summary>I prezzi sono con l'IVA?</summary>
                <p>
                    Per palestre e trainer con partita IVA sono <strong>al netto</strong>. Per chi
                    si iscrive da solo sono <strong>IVA inclusa</strong>.
                </p>
            </details>

            <details>
                <summary>Cosa succede ai gettoni comprati se smetto?</summary>
                <p>
                    Restano validi 24 mesi dall'ultima ricarica, e ogni ricarica sposta in avanti
                    la scadenza di tutto il saldo. Prima che scadano ti avvisiamo: un credito
                    pagato non deve sparire in silenzio.
                </p>
            </details>

            <details>
                <summary>E se una richiesta non riesce?</summary>
                <p>
                    Non si paga. Se il fornitore del modello non risponde, il gettone non viene
                    scalato.
                </p>
            </details>
        </div>
    </section>

@endsection
