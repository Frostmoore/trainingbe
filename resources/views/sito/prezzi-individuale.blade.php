{{--
    I prezzi di chi si allena — 20/09/2026.

    ── 🚨 La regola che non cambia ────────────────────────────────────────────

    **Nessun numero è scritto qui dentro.** Tutto viene da `config/listino.php`
    attraverso `Listino`: se un prezzo cambia lì, cambia qui, nell'app e su
    Stripe. Un prezzo copiato a mano è un prezzo che un giorno dirà una cosa
    diversa da quella che il sistema incassa, e lo si scopre da un cliente
    arrabbiato invece che da un test.

    ── 💡 Perché la pagina comincia dal gratis ────────────────────────────────

    Perché è vero, ed è la cosa che distingue: l'app **non è una prova di trenta
    giorni**. ⚠️ Cominciare dal prezzo del Plus farebbe cercare la fregatura, e
    chi la cerca la trova anche dove non c'è.
--}}
@extends('sito.layout')

@section('titolo', 'Prezzi per chi si allena — Training Companion')
@section('descrizione', 'L\'app è gratis e non scade. Il Plus costa 8 € al mese e comprende l\'assistente automatico; in alternativa ci sono i gettoni, che non scadono.')

@section('contenuto')

    <section style="padding-bottom: var(--sp-4);">
        <div class="contenitore">
            <span class="pillola">Individuale</span>
            <h1 style="margin-top: var(--sp-2);">L'app è gratis.<br>Si paga solo l'assistente.</h1>

            <div class="griglia-2" style="margin-top: var(--sp-3);">
                <p class="guida">
                    Diario, allenamenti, schede, chat, peso e misure non costano niente e non
                    scadono. L'intelligenza artificiale costa a ogni domanda: o la prendi a
                    canone col Plus, o la paghi a gettoni quando ti serve.
                </p>

                <x-sito.figura
                    nome="platea-solo"
                    alt="Una persona si allena da sola con il telefono in mano"
                />
            </div>
        </div>
    </section>

    <section style="padding-top: 0;">
        <div class="contenitore">
            <div class="griglia-2" style="align-items: stretch;">
                <div class="scheda scheda-prezzo">
                    <h3 style="margin-top: 0;">Gratis</h3>
                    <p class="cifra">0,00 €<small> / per sempre</small></p>
                    <p class="sotto-cifra">nessun periodo di prova, nessuna carta</p>

                    <ul class="elenco">
                        <li>Diario alimentare e catalogo completo</li>
                        <li>Allenamenti, schede, storico e cronometro</li>
                        <li>Chat cifrata con chi ti allena</li>
                        <li>Peso, misure, foto dei progressi</li>
                        <li>Copia di sicurezza cifrata</li>
                        <li class="no">Assistente automatico</li>
                    </ul>

                    <div class="in-fondo">
                        <x-sito.scarica />
                    </div>
                </div>

                <div class="scheda scheda-prezzo scelta">
                    <h3 style="margin-top: 0;">Plus</h3>
                    <p class="cifra">{{ $formatta($prezzoSingolo) }}<small> / mese</small></p>
                    <p class="sotto-cifra">si disdice dall'app, quando vuoi</p>

                    <ul class="elenco">
                        <li><strong>Tutto quello che c'è nel gratis</strong></li>
                        <li>Scrivi cosa hai mangiato e le calorie le conta lei</li>
                        <li>Fotografi il piatto e lo riconosce</li>
                        <li>Lo spunto del giorno sulla tua giornata</li>
                        <li>{{ number_format($chiamateMensili, 0, ',', '.') }} elaborazioni al mese — cinque al giorno</li>
                    </ul>

                    <div class="in-fondo">
                        <p class="nota" style="margin: 0 0 var(--sp-1);">
                            🚨 Il Plus si attiva <strong>dentro l'app</strong>: Profilo → Abbonamento.
                        </p>
                        <x-sito.scarica />
                    </div>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════ i gettoni ═══════════════════════ --}}
    <section class="alternata" id="gettoni">
        <div class="contenitore">
            <p class="occhiello">L'alternativa al canone</p>
            <h2>I gettoni: si comprano una volta e restano lì.</h2>

            <p class="guida">
                Vanno bene se l'assistente lo accendi ogni tanto. ⚠️ Non scadono, e non c'è un
                abbonamento nascosto dietro: quando finiscono, l'app continua a funzionare come
                prima.
            </p>

            <div class="griglia-pacchetti" style="margin-top: var(--sp-3);">
                @foreach ($pacchetti as $p)
                    <div class="scheda scheda-prezzo @if ($loop->last) scelta @endif">
                        <h3 style="margin-top: 0;">{{ $p['nome'] }}</h3>
                        <p class="cifra">{{ $formatta($p['prezzo']) }}</p>
                        <p class="sotto-cifra">{{ number_format($p['gettoni'], 0, ',', '.') }} gettoni</p>
                        <p style="margin: 0;">{{ $p['nota'] }}</p>
                        <p class="in-fondo nota" style="margin: 0;">
                            {{ number_format($p['centPerGettone'], 1, ',', '.') }} cent a gettone
                        </p>
                    </div>
                @endforeach
            </div>

            <p class="nota" style="margin-top: var(--sp-3);">
                Quanto costa una cosa? Il conto lo trovi nell'app <strong>prima</strong> di
                premere: una stima da testo vale un gettone, una da foto dieci, la trascrizione
                di una scheda cinquanta.
            </p>
        </div>
    </section>

    {{-- ═══════════════════════ domande ═══════════════════════ --}}
    <section id="domande-prezzi">
        <div class="contenitore stretto">
            <p class="occhiello">Domande</p>
            <h2>Sui soldi, in chiaro.</h2>

            <div style="margin-top: var(--sp-3);">
                <details class="domanda">
                    <summary><strong>Se non pago, cosa perdo?</strong></summary>
                    <p>
                        Niente di quello che hai già. Senza Plus e senza gettoni l'assistente
                        non risponde: tutto il resto dell'app funziona uguale, compresi i dati
                        che hai messo dentro fino a ieri.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Conviene il Plus o i gettoni?</strong></summary>
                    <p>
                        Il Plus, se usi l'assistente quasi ogni giorno: comprende
                        {{ number_format($chiamateMensili, 0, ',', '.') }} elaborazioni al mese.
                        I gettoni, se lo accendi ogni tanto — non scadono, quindi non si butta
                        via niente.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Me lo può pagare la mia palestra?</strong></summary>
                    <p>
                        Sì. Palestre e trainer comprano dei <strong>posti</strong> e li regalano
                        a chi vogliono: chi lo riceve ha il Plus senza pagare niente. Se poi
                        lascia la palestra, l'abbonamento regalato finisce — ma i suoi dati
                        restano suoi.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>I gettoni scadono?</strong></summary>
                    <p>No. Restano finché li usi, anche se nel frattempo attivi o disdici il Plus.</p>
                </details>

                <details class="domanda">
                    <summary><strong>Come si paga, e chi vede la mia carta?</strong></summary>
                    <p>
                        Con carta, attraverso Stripe. I dati della carta li scrivi da loro: noi
                        non li vediamo e non li conserviamo.
                    </p>
                </details>
            </div>
        </div>
    </section>

    <section class="alternata">
        <div class="contenitore" style="text-align: center;">
            <h2>Provala. È gratis, e resta gratis.</h2>
            <div class="fila-bottoni centrata" style="margin-top: var(--sp-3);">
                <x-sito.scarica grande />
                <a href="/" class="bottone bottone-vuoto bottone-grande">Guarda cosa fa</a>
            </div>
        </div>
    </section>

@endsection
