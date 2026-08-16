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
                    <span>a gettoni · da {{ $formatta($pacchetti[0]['prezzo']) }}</span>
                </a>
                <a href="#trainer">
                    <strong>Sei un trainer</strong>
                    <span>ad allievo · da {{ $formatta($listino->costoMensile($minimoTrainer)) }} al mese</span>
                </a>
                <a href="#palestre">
                    <strong>Sei una palestra</strong>
                    <span>a iscritto · da {{ $formatta($listino->costoMensile($minimoPalestra)) }} al mese</span>
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
                        <h2>Paghi a gettoni, e li compri quando servono.</h2>
                        <p class="guida" style="margin-left: 0;">
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
                            ⚠️ La foto costa dieci perché a noi costa circa quattro volte una
                            frase, e il resto è il margine con cui il servizio sta in piedi.
                            Preferiamo dirlo che nasconderlo in una nota.
                        </p>
                    </div>

                    <div class="scheda" style="margin-top: var(--sp-3);">
                        <h3>Oppure a mese</h3>
                        <p class="nota">
                            Se l'AI la usi tutti i giorni c'è l'abbonamento:
                            <strong>{{ $formatta($prezzoSingolo) }} al mese</strong> con
                            {{ number_format($gettoniMensili, 0, ',', '.') }} gettoni —
                            <strong>{{ (int) round($gettoniMensili / 30) }} richieste al giorno</strong>,
                            con dentro qualche foto.
                            ⚠️ Quelli del mese <strong>si azzerano</strong> al rinnovo; quelli
                            comprati no, restano 24 mesi. Si usano prima i primi.
                        </p>
                    </div>
                </div>

                {{-- ═════════ 2 · i trainer indipendenti ═════════ --}}
                <div class="anta" id="trainer">
                    <div class="intestazione-sezione" style="text-align: left;">
                        <div class="occhiello">Sei un trainer indipendente</div>
                        <h2>Paghi per gli allievi a cui accendi l'AI.</h2>
                        <p class="guida" style="margin-left: 0;">
                            Non serve una palestra: i tuoi allievi entrano con un link tuo.
                            Minimo {{ $minimoTrainer }} allievi, e i posti spenti non si pagano.
                        </p>
                    </div>

                    <div class="scheda" style="padding: 0; overflow-x: auto;">
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
                                <div style="font-size: 24px; font-weight: 800;">{{ $formatta($esempioTrainer['costo']) }}</div>
                            </div>
                            <div>
                                <div class="nota">Se lo rivendi a 10 €</div>
                                <div style="font-size: 24px; font-weight: 800;">{{ $formatta($esempioTrainer['ricavo']) }}</div>
                            </div>
                            <div>
                                <div class="nota">Ti resta</div>
                                <div style="font-size: 24px; font-weight: 800; color: var(--accento);">{{ $formatta($esempioTrainer['margine']) }}</div>
                            </div>
                        </div>
                    </div>

                    <ul class="elenco" style="margin-top: var(--sp-3);">
                        <li>Schede e piani alimentari, dal computer o dal telefono</li>
                        <li>Chat cifrata con i tuoi allievi</li>
                        <li>Quello che mandi resta loro, anche se smettete</li>
                    </ul>

                    <p style="margin-top: var(--sp-3);">
                        <a href="/admin/login" class="bottone bottone-pieno">Entra nel pannello</a>
                    </p>
                </div>

                {{-- ═════════ 3 · le palestre ═════════ --}}
                <div class="anta" id="palestre">
                    <div class="intestazione-sezione" style="text-align: left;">
                        <div class="occhiello">Sei una palestra</div>
                        <h2>Paghi per gli iscritti a cui accendi l'AI.</h2>
                        <p class="guida" style="margin-left: 0;">
                            Tutti gli altri usano l'app gratis, con il tuo marchio.
                            Minimo {{ $minimoPalestra }} posti, e il conteggio del mese guarda
                            il <strong>massimo raggiunto</strong>.
                        </p>
                    </div>

                    <div class="scheda" style="padding: 0; overflow-x: auto;">
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
                        🚨 Gli scaglioni sono <strong>progressivi</strong>, come le aliquote: i
                        primi {{ $scaglioni[0]['etichetta'] }} costano
                        {{ $formatta($scaglioni[0]['prezzo']) }} anche a chi ne ha trecento.
                        Non c'è nessuna soglia che fa saltare la fattura.
                    </p>

                    {{--
                        💡 **L'esempio è il pezzo che vende**, e non è un vezzo:
                        una palestra non compra «4,99 a posto», compra «incasso
                        600 e ne pago 264». Il numero che conta è la differenza.
                    --}}
                    <div class="scheda" style="margin-top: var(--sp-3); background: var(--accento-tenue); border-color: color-mix(in srgb, var(--accento) 25%, transparent);">
                        <h3>Un esempio con {{ $esempio['posti'] }} iscritti</h3>
                        <div class="griglia" style="grid-template-columns: repeat(3, 1fr); gap: var(--sp-2); margin-top: var(--sp-2);">
                            <div>
                                <div class="nota">Paghi a noi</div>
                                <div style="font-size: 24px; font-weight: 800;">{{ $formatta($esempio['costo']) }}</div>
                            </div>
                            <div>
                                <div class="nota">Se lo rivendi a 10 €</div>
                                <div style="font-size: 24px; font-weight: 800;">{{ $formatta($esempio['ricavo']) }}</div>
                            </div>
                            <div>
                                <div class="nota">Ti resta</div>
                                <div style="font-size: 24px; font-weight: 800; color: var(--accento);">{{ $formatta($esempio['margine']) }}</div>
                            </div>
                        </div>
                        <p class="nota" style="margin-top: var(--sp-2);">
                            I 10 € sono un <strong>suggerimento</strong>, non un obbligo: quanto
                            e se addebitare qualcosa ai tuoi iscritti lo decidi tu, e ne
                            rispondi tu.
                        </p>
                    </div>

                    <p style="margin-top: var(--sp-3);">
                        <a href="/admin/login" class="bottone bottone-pieno">Entra nel pannello</a>
                    </p>
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

            <details>
                <summary>Quanto durano {{ number_format($gettoniMensili, 0, ',', '.') }} gettoni?</summary>
                <p>
                    Circa <strong>{{ (int) round($gettoniMensili / 30) }} richieste al giorno</strong>
                    per un mese, con dentro qualche foto. Scrivere un pasto ne costa 1,
                    riconoscerlo da una foto ne costa 10; registrarlo dal tuo piano, dai
                    preferiti o a mano non ne costa nessuno.
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
