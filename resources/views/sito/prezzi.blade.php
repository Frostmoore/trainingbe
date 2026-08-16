@extends('sito.layout')

@section('titolo', 'Prezzi — Training Companion')
@section('descrizione', 'L\'app è gratis. Si paga solo l\'AI, e solo per le persone a cui la accendi.')

@section('contenuto')

    <section style="padding-bottom: var(--sp-4);">
        <div class="contenitore stretto" style="text-align: center;">
            <div class="occhiello">Prezzi</div>
            <h1>Si paga solo quello che si accende.</h1>
            <p class="guida" style="margin: 0 auto;">
                Nessun canone. L'app resta gratis per tutti gli iscritti: paghi solo i posti
                a cui hai acceso l'intelligenza artificiale, e li spegni quando vuoi.
            </p>
        </div>
    </section>

    {{-- ═══════════════════ i tre modi ═══════════════════ --}}
    <section style="padding-top: 0;">
        <div class="contenitore">
            <div class="griglia" style="grid-template-columns: repeat(auto-fit, minmax(290px, 1fr)); gap: var(--sp-3);">

                <div class="scheda scheda-prezzo">
                    <h3>Da solo</h3>
                    <p class="nota" style="min-height: 44px;">Ti registri e cominci. Non serve nessuna palestra.</p>
                    <div class="prezzo">{{ $formatta($prezzoSingolo) }} <small>/mese</small></div>
                    <p class="nota">{{ number_format($gettoniMensili, 0, ',', '.') }} gettoni al mese</p>
                    <ul class="elenco" style="flex: 1;">
                        <li>Diario e allenamenti, gratis per sempre</li>
                        <li>Stima da testo e da foto</li>
                        <li>Spunto del giorno</li>
                        <li class="no">Niente trainer, niente schede da altri</li>
                    </ul>
                    <a href="/#come-funziona" class="bottone bottone-vuoto" style="margin-top: var(--sp-2);">Come funziona</a>
                </div>

                {{-- 🚨 La palestra è la scheda evidenziata perché è **il cliente**:
                     il singolo e il trainer sono le due estremità, la palestra è
                     dove il prodotto guadagna. --}}
                <div class="scheda scheda-prezzo scelta">
                    <span class="etichetta">Per le palestre</span>
                    <h3>A posto</h3>
                    <p class="nota" style="min-height: 44px;">Paghi per gli iscritti a cui accendi l'AI. Minimo cinque.</p>
                    <div class="prezzo">{{ $formatta($primoScaglione) }} <small>/posto al mese</small></div>
                    <p class="nota">Da {{ $formatta($primoScaglione * 5) }} al mese</p>
                    <ul class="elenco" style="flex: 1;">
                        <li>App con il tuo marchio</li>
                        <li>Pannello per lo staff</li>
                        <li>I posti spenti non si pagano</li>
                        <li>Il prezzo scende crescendo</li>
                    </ul>
                    <a href="#scaglioni" class="bottone bottone-pieno" style="margin-top: var(--sp-2);">Vedi gli scaglioni</a>
                </div>

                <div class="scheda scheda-prezzo">
                    <h3>Trainer indipendente</h3>
                    <p class="nota" style="min-height: 44px;">I tuoi allievi entrano con un link tuo. Minimo tre.</p>
                    <div class="prezzo">{{ $formatta($primoScaglione) }} <small>/allievo al mese</small></div>
                    <p class="nota">Da {{ $formatta($primoScaglione * 3) }} al mese</p>
                    <ul class="elenco" style="flex: 1;">
                        <li>Schede e piani alimentari</li>
                        <li>Chat cifrata con i tuoi allievi</li>
                        <li>Gettoni tuoi per comporre</li>
                        <li>Quello che mandi resta loro</li>
                    </ul>
                    <a href="/#come-funziona" class="bottone bottone-vuoto" style="margin-top: var(--sp-2);">Come funziona</a>
                </div>

            </div>
        </div>
    </section>

    {{-- ═══════════════════ gli scaglioni ═══════════════════ --}}
    <section id="scaglioni" class="tenue">
        <div class="contenitore stretto">
            <div class="intestazione-sezione">
                <div class="occhiello">Più cresci, più ti resta</div>
                <h2>Il prezzo per posto scende da solo.</h2>
                <p class="guida">
                    Non c'è niente da contrattare e nessuna soglia da chiedere: gli scaglioni
                    si applicano da soli, mese per mese, su quanti posti hai acceso.
                </p>
            </div>

            <div class="scheda" style="padding: 0; overflow: hidden;">
                <table style="width: 100%; border-collapse: collapse; font-size: 16px;">
                    <thead>
                        <tr style="background: var(--superficie);">
                            <th style="text-align: left; padding: 14px var(--sp-3); border-bottom: 1px solid var(--bordo);">Posti accesi</th>
                            <th style="text-align: right; padding: 14px var(--sp-3); border-bottom: 1px solid var(--bordo);">Prezzo per posto</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($scaglioni as $s)
                            <tr>
                                <td style="padding: 14px var(--sp-3); border-bottom: 1px solid var(--bordo);">{{ $s['etichetta'] }}</td>
                                <td style="padding: 14px var(--sp-3); border-bottom: 1px solid var(--bordo); text-align: right; font-weight: 700;">{{ $formatta($s['prezzo']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

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
                        <div class="nota">Incassi a 10 € l'uno</div>
                        <div style="font-size: 26px; font-weight: 800;">{{ $formatta($esempio['ricavo']) }}</div>
                    </div>
                    <div>
                        <div class="nota">Ti resta</div>
                        <div style="font-size: 26px; font-weight: 800; color: var(--accento);">{{ $formatta($esempio['margine']) }}</div>
                    </div>
                </div>
                <p class="nota" style="margin-top: var(--sp-2);">
                    I 10 € sono un <strong>suggerimento</strong>, non un obbligo: il prezzo ai
                    tuoi iscritti lo decidi tu.
                </p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ i gettoni ═══════════════════ --}}
    <section>
        <div class="contenitore stretto">
            <div class="intestazione-sezione">
                <div class="occhiello">Se finiscono</div>
                <h2>Gettoni in più, quando servono.</h2>
                <p class="guida">
                    Ogni posto ne ha {{ number_format($gettoniMensili, 0, ',', '.') }} al mese, che si azzerano al rinnovo.
                    Chi li finisce può comprarne altri: quelli comprati durano <strong>24 mesi</strong>.
                </p>
            </div>

            <div class="griglia">
                @foreach ($pacchetti as $p)
                    <div class="scheda" style="text-align: center;">
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
                    ⚠️ La foto costa dieci perché a noi costa circa quattro volte una frase, e il
                    resto è il margine con cui il servizio sta in piedi. Preferiamo dirlo che
                    nasconderlo in una nota.
                </p>
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
        </div>
    </section>

    <section style="text-align: center;">
        <div class="contenitore stretto">
            <h2>Cominci da {{ $formatta($primoScaglione * 5) }} al mese.</h2>
            <p class="guida" style="margin: 0 auto var(--sp-3);">
                Cinque posti accesi. Tutti gli altri iscritti usano l'app gratis.
            </p>
            <a href="/admin/login" class="bottone bottone-pieno bottone-grande">Entra nel pannello</a>
        </div>
    </section>

@endsection
