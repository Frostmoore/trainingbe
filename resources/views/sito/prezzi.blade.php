{{--
    La pagina dei prezzi — divisa in tre il 16/08/2026, sera.

    ── 🚨 Perché in tre sezioni e non in tre schede affiancate ────────────────

    Perché le tre platee **non comprano la stessa cosa**: una palestra e un
    trainer pagano a numero di persone, chi si allena da solo paga a numero di
    gettoni. Tre schede una accanto all'altra costringono a leggere tutto per
    capire quale riga riguarda chi legge, e a confrontare grandezze diverse
    come se fossero la stessa.

    ⚠️ Qui invece si sceglie in cima **chi si è**, e si legge una sezione sola.
    Le altre due restano sotto per chi vuole confrontarle davvero.

    💡 **L'esempio con i margini sta qui e non sulla home**: questa è la pagina
    dove una palestra li sta cercando apposta. Sulla home sarebbe la cosa che
    fa chiudere la scheda a chi voleva solo scaricare un'app.
--}}
@extends('sito.layout')

@section('titolo', 'Prezzi — Training Companion')
@section('descrizione', 'L\'app è gratis. Si paga solo l\'AI: a persona per palestre e trainer, a gettoni per chi si allena da solo.')

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

    {{-- 🚨 La scelta in cima: tre porte, e chi legge ne apre una sola. --}}
    <section style="padding-top: 0; padding-bottom: var(--sp-3);">
        <div class="contenitore">
            <div class="griglia" style="gap: var(--sp-2);">
                <a href="#palestre" class="scheda porta">
                    <div class="occhiello">Sei una palestra</div>
                    <h3>Paghi a iscritto</h3>
                    <p class="nota">Da {{ $formatta($listino->costoMensile($minimoPalestra)) }} al mese, minimo {{ $minimoPalestra }} posti</p>
                </a>
                <a href="#trainer" class="scheda porta">
                    <div class="occhiello">Sei un trainer</div>
                    <h3>Paghi ad allievo</h3>
                    <p class="nota">Da {{ $formatta($listino->costoMensile($minimoTrainer)) }} al mese, minimo {{ $minimoTrainer }} allievi</p>
                </a>
                <a href="#solo" class="scheda porta">
                    <div class="occhiello">Ti alleni da solo</div>
                    <h3>Paghi a gettoni</h3>
                    <p class="nota">Da {{ $formatta($pacchetti[0]['prezzo']) }}, e non scadono per 24 mesi</p>
                </a>
            </div>
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

    {{-- ═══════════════════ 1 · le palestre ═══════════════════ --}}
    <section id="palestre" class="tenue">
        <div class="contenitore stretto">
            <div class="intestazione-sezione">
                <div class="occhiello">Sei una palestra</div>
                <h2>Paghi per gli iscritti a cui accendi l'AI.</h2>
                <p class="guida">
                    Tutti gli altri usano l'app gratis, con il tuo marchio. I posti spenti non
                    si pagano, e il conteggio del mese guarda il <strong>massimo raggiunto</strong>.
                </p>
            </div>

            <div class="scheda" style="padding: 0; overflow: hidden;">
                <table class="tabella-prezzi">
                    <thead>
                        <tr>
                            <th>Iscritti con l'AI accesa</th>
                            <th style="text-align: right;">Prezzo per persona</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($scaglioni as $s)
                            <tr>
                                <td>{{ $s['etichetta'] }}</td>
                                <td style="text-align: right; font-weight: 700;">{{ $formatta($s['prezzo']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <p class="nota" style="margin-top: var(--sp-2);">
                🚨 Gli scaglioni sono <strong>progressivi</strong>, come le aliquote: i primi
                {{ $scaglioni[0]['etichetta'] }} costano {{ $formatta($scaglioni[0]['prezzo']) }} anche
                a chi ne ha trecento. Non c'è nessuna soglia che fa saltare la fattura.
                Minimo {{ $minimoPalestra }} posti.
            </p>

            {{--
                💡 **L'esempio è il pezzo che vende**, e non è un vezzo: una
                palestra non compra «4,99 a posto», compra «incasso 600 e ne
                pago 264». Il numero che conta è la differenza.
            --}}
            <div class="scheda" style="margin-top: var(--sp-3); background: var(--accento-tenue); border-color: color-mix(in srgb, var(--accento) 25%, transparent);">
                <h3>Un esempio con {{ $esempio['posti'] }} iscritti</h3>
                <div class="griglia" style="grid-template-columns: repeat(3, 1fr); gap: var(--sp-2); margin-top: var(--sp-2);">
                    <div>
                        <div class="nota">Paghi a noi</div>
                        <div style="font-size: 26px; font-weight: 800;">{{ $formatta($esempio['costo']) }}</div>
                    </div>
                    <div>
                        <div class="nota">Se rivendi a 10 € l'uno</div>
                        <div style="font-size: 26px; font-weight: 800;">{{ $formatta($esempio['ricavo']) }}</div>
                    </div>
                    <div>
                        <div class="nota">Ti resta</div>
                        <div style="font-size: 26px; font-weight: 800; color: var(--accento);">{{ $formatta($esempio['margine']) }}</div>
                    </div>
                </div>
                <p class="nota" style="margin-top: var(--sp-2);">
                    I 10 € sono un <strong>suggerimento</strong>, non un obbligo: quanto e se
                    addebitare qualcosa ai tuoi iscritti lo decidi tu, e ne rispondi tu.
                </p>
            </div>

            <p style="margin-top: var(--sp-3);">
                <a href="/admin/login" class="bottone bottone-pieno">Entra nel pannello</a>
            </p>
        </div>
    </section>

    {{-- ═══════════════════ 2 · i trainer ═══════════════════ --}}
    <section id="trainer">
        <div class="contenitore stretto">
            <div class="intestazione-sezione">
                <div class="occhiello">Sei un trainer indipendente</div>
                <h2>Paghi per gli allievi a cui accendi l'AI.</h2>
                <p class="guida">
                    Non serve una palestra: i tuoi allievi entrano con un link tuo. Stesso
                    listino, minimo {{ $minimoTrainer }} allievi invece di {{ $minimoPalestra }}.
                </p>
            </div>

            <div class="scheda" style="padding: 0; overflow: hidden;">
                <table class="tabella-prezzi">
                    <thead>
                        <tr>
                            <th>Allievi con l'AI accesa</th>
                            <th style="text-align: right;">Prezzo per allievo</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($scaglioni as $s)
                            <tr>
                                <td>{{ $s['etichetta'] }}</td>
                                <td style="text-align: right; font-weight: 700;">{{ $formatta($s['prezzo']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="scheda" style="margin-top: var(--sp-3); background: var(--accento-tenue); border-color: color-mix(in srgb, var(--accento) 25%, transparent);">
                <h3>Un esempio con {{ $esempioTrainer['posti'] }} allievi</h3>
                <div class="griglia" style="grid-template-columns: repeat(3, 1fr); gap: var(--sp-2); margin-top: var(--sp-2);">
                    <div>
                        <div class="nota">Paghi a noi</div>
                        <div style="font-size: 26px; font-weight: 800;">{{ $formatta($esempioTrainer['costo']) }}</div>
                    </div>
                    <div>
                        <div class="nota">Se rivendi a 10 € l'uno</div>
                        <div style="font-size: 26px; font-weight: 800;">{{ $formatta($esempioTrainer['ricavo']) }}</div>
                    </div>
                    <div>
                        <div class="nota">Ti resta</div>
                        <div style="font-size: 26px; font-weight: 800; color: var(--accento);">{{ $formatta($esempioTrainer['margine']) }}</div>
                    </div>
                </div>
            </div>

            <ul class="elenco" style="margin-top: var(--sp-3);">
                <li>Schede e piani alimentari, dal computer o dal telefono</li>
                <li>Chat cifrata con i tuoi allievi</li>
                <li>Quello che mandi resta loro, anche se smettete</li>
                <li>I posti spenti non si pagano</li>
            </ul>

            <p style="margin-top: var(--sp-3);">
                <a href="/admin/login" class="bottone bottone-pieno">Entra nel pannello</a>
            </p>
        </div>
    </section>

    {{--
        ═══════════════════ 3 · chi si allena da solo ═══════════════════

        🚨 **Qui l'unità di misura cambia**, ed è il motivo per cui questa
        sezione non poteva stare nella stessa tabella delle altre due: una
        palestra compra **persone**, una persona compra **gettoni**. Metterli
        nella stessa riga farebbe confrontare due grandezze diverse.
    --}}
    <section id="solo" class="tenue">
        <div class="contenitore stretto">
            <div class="intestazione-sezione">
                <div class="occhiello">Vuoi allenarti da solo</div>
                <h2>Paghi a gettoni, e li compri quando servono.</h2>
                <p class="guida">
                    Diario, allenamenti e progressi sono <strong>gratis</strong> e non
                    scadono. I gettoni servono solo per l'intelligenza artificiale: si
                    comprano una volta e durano <strong>24 mesi</strong>.
                </p>
            </div>

            <div class="griglia">
                @foreach ($pacchetti as $indice => $p)
                    <div class="scheda scheda-prezzo{{ $indice === 1 ? ' scelta' : '' }}" style="text-align: center;">
                        @if ($indice === 1)
                            <span class="etichetta">Il più scelto</span>
                        @endif
                        <h3>{{ number_format($p['gettoni'], 0, ',', '.') }} gettoni</h3>
                        <div class="prezzo" style="font-size: 30px;">{{ $formatta($p['prezzo']) }}</div>
                        <p class="nota">{{ $p['nota'] }}</p>
                        <p class="nota">
                            {{ number_format((int) round($p['gettoni'] / 30), 0, ',', '.') }} richieste al giorno
                            per un mese
                        </p>
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
                    ⚠️ La foto costa dieci perché a noi costa circa quattro volte una frase, e il
                    resto è il margine con cui il servizio sta in piedi. Preferiamo dirlo che
                    nasconderlo in una nota.
                </p>
            </div>

            <div class="scheda" style="margin-top: var(--sp-3);">
                <h3>Oppure a mese</h3>
                <p class="nota">
                    Se l'AI la usi tutti i giorni conviene l'abbonamento:
                    <strong>{{ $formatta($prezzoSingolo) }} al mese</strong> con
                    {{ number_format($gettoniMensili, 0, ',', '.') }} gettoni che si rinnovano.
                    ⚠️ Quelli del mese <strong>si azzerano</strong> al rinnovo; quelli comprati
                    no, restano 24 mesi. Si usano prima i primi.
                </p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ domande sui prezzi ═══════════════════ --}}
    <section>
        <div class="contenitore stretto">
            <div class="intestazione-sezione">
                <div class="occhiello">Domande</div>
                <h2>Sui prezzi, in particolare.</h2>
            </div>

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
