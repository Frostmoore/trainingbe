{{--
    Il listino di chi allena — una vista sola per trainer e palestre, 20/09/2026.

    ── 🚨 Perché una vista e non due ──────────────────────────────────────────

    Perché è **la stessa pagina con altri numeri**: piani a posti, un piano su
    misura, l'esempio della rivendita, la pubblicità nel catalogo. ⚠️ Due viste
    copiate divergono al primo ritocco, e la divergenza si vede solo mettendole
    una accanto all'altra — cioè mai, perché stanno a due indirizzi diversi.

    💡 Quello che cambia davvero è il **vocabolario** (allievi/iscritti) e il
    fatto che una palestra ha anche il tetto dei trainer. Sta tutto nelle tre
    righe in cima alla vista.

    ── 🚨 Trappola pagata scrivendo questo file ───────────────────────────────

    Questo commento diceva «le tre righe di (chiocciola)php qui sotto», con la
    direttiva scritta per esteso. ⛔ **Blade compila le direttive PRIMA di
    togliere i commenti**: quella parola è diventata un tag PHP aperto, e si è
    portata via tutta la testa del file — la pagina rispondeva 500 con
    «Undefined variable $unitaUno». ⚠️ Vale per ogni direttiva e per ogni
    componente scritti dentro a un commento Blade.

    🚨 **Nessun numero scritto nel markup**: tutto da `Listino`.
--}}
@extends('sito.layout')

@php
    $diPalestra = $platea === 'palestre';
    $unita = $diPalestra ? 'iscritti' : 'allievi';
    $unitaUno = $diPalestra ? 'iscritto' : 'allievo';
@endphp

@section('titolo', $diPalestra ? 'Prezzi per le palestre — Training Companion' : 'Prezzi per i trainer — Training Companion')
@section('descrizione', $diPalestra
    ? 'Piani per palestre da 60 € al mese: posti iscritto e posti trainer, con l\'app vestita dei tuoi colori.'
    : 'Piani per trainer indipendenti da 30 € al mese: abboni i tuoi allievi, scrivi schede e piani, e hai una chat con ciascuno.')

@section('contenuto')

    <section style="padding-bottom: var(--sp-4);">
        <div class="contenitore">
            <span class="pillola">{{ $diPalestra ? 'Palestra' : 'Trainer' }}</span>

            <h1 style="margin-top: var(--sp-2);">
                @if ($diPalestra)
                    Posti per gli iscritti,<br>posti per i tuoi trainer.
                @else
                    Paghi i posti,<br>non le funzioni.
                @endif
            </h1>

            <p class="guida">
                Un <strong>posto</strong> è un abbonamento completo regalato a
                un{{ $diPalestra ? ' tuo iscritto' : ' tuo allievo' }}: lui non paga niente e
                ha l'assistente automatico, come se lo pagasse da sé
                ({{ $formatta($prezzoSingolo) }} al mese).
                @if ($diPalestra)
                    🚨 Ogni piano dice anche quanti <strong>trainer</strong> possono lavorare:
                    valgono tutti e due i tetti.
                @endif
            </p>

            <p class="nota" style="margin-top: var(--sp-2);">
                Tutti i prezzi sono quelli finali: niente da aggiungere alla cassa.
            </p>

            <div style="margin-top: var(--sp-4);">
                <x-sito.figura
                    :nome="$diPalestra ? 'platea-palestra' : 'platea-trainer'"
                    :alt="$diPalestra
                        ? 'La sala pesi di una palestra durante un allenamento'
                        : 'Un trainer segue un allievo durante un esercizio'"
                />
            </div>
        </div>
    </section>

    {{-- ═══════════════════════ i piani ═══════════════════════ --}}
    <section style="padding-top: 0;" id="piani">
        <div class="contenitore">
            <div class="griglia-pacchetti">
                @foreach ($piani as $piano)
                    <div class="scheda scheda-prezzo @if ($loop->index === 1) scelta @endif">
                        <h3 style="margin-top: 0;">{{ $piano['nome'] }}</h3>
                        <p class="cifra">{{ $formatta($piano['prezzo']) }}<small> / mese</small></p>
                        <p class="sotto-cifra">{{ $formatta($piano['perSlot']) }} a {{ $unitaUno }} · {{ $piano['nota'] }}</p>

                        <ul class="elenco">
                            <li><strong>{{ $piano['slot'] }} {{ $unita }}</strong> abbonati</li>
                            @if ($piano['trainer'] !== null)
                                <li><strong>{{ $piano['trainer'] }} trainer</strong> al lavoro</li>
                            @endif
                            <li>Schede e piani illimitati</li>
                            <li>Chat cifrata con ognuno</li>
                        </ul>

                        <div class="in-fondo">
                            <a href="/iscriviti/{{ $piano['codice'] }}" class="bottone bottone-pieno" style="width: 100%;">
                                Abbonati
                            </a>
                        </div>
                    </div>
                @endforeach

                {{-- Il su misura non si compra con un tocco: si concorda. --}}
                <div class="scheda scheda-prezzo">
                    <h3 style="margin-top: 0;">{{ $suMisura['nome'] }}</h3>
                    <p class="cifra">{{ $formatta($suMisura['perSlot']) }}<small> / {{ $unitaUno }}</small></p>
                    <p class="sotto-cifra">più {{ $formatta($suMisura['canone']) }} al mese</p>

                    <ul class="elenco">
                        <li><strong>Da {{ $suMisura['minimo'] }} {{ $unita }}</strong> in su, senza tetto</li>
                        @if ($suMisura['trainerIllimitati'])
                            <li><strong>Trainer illimitati</strong></li>
                        @endif
                        <li>Si paga quello che si usa, col minimo garantito</li>
                        <li>Fattura mensile a mese chiuso</li>
                    </ul>

                    <div class="in-fondo">
                        <a href="mailto:info@smp-digital.it?subject=Piano%20su%20misura%20{{ $diPalestra ? 'palestra' : 'trainer' }}"
                           class="bottone bottone-vuoto" style="width: 100%;">Scrivici</a>
                    </div>
                </div>
            </div>

            <div class="scheda tenue" style="margin-top: var(--sp-3);">
                <h3 style="margin-top: 0;">💡 Dove conviene cambiare</h3>
                <p style="margin-bottom: 0;">
                    Con {{ $suMisura['minimo'] }} {{ $unita }} il su misura costa
                    <strong>{{ $formatta($costoAlMinimo) }}</strong> al mese, contro i
                    {{ $formatta($piuGrande['prezzo']) }} del {{ $piuGrande['nome'] }}. Da lì in
                    su cresce di {{ $formatta($suMisura['perSlot']) }} per
                    ogni {{ $unitaUno }} in più, e non ha tetti.
                    ⚠️ Sotto il minimo si paga comunque il minimo: è il prezzo di avere il conto
                    aperto.
                </p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════ cosa ci fai ═══════════════════════ --}}
    <section class="alternata">
        <div class="contenitore">
            <p class="occhiello">Cosa comprende</p>
            <h2>Un posto è l'app intera, regalata.</h2>

            <div class="griglia" style="margin-top: var(--sp-3);">
                <div class="scheda">
                    <h3>👤 Per chi lo riceve</h3>
                    <p>
                        Diario, allenamenti, chat e assistente automatico
                        ({{ number_format($chiamateMensili, 0, ',', '.') }} elaborazioni al
                        mese). Non paga niente e non gli si chiede una carta.
                    </p>
                </div>
                <div class="scheda">
                    <h3>📋 Per te</h3>
                    <p>
                        Il pannello: {{ $unita }}, schede e piani modello, import da PDF o foto,
                        e la chat con ognuno.
                    </p>
                </div>
                <div class="scheda">
                    <h3>🔒 Quello che non vedi</h3>
                    <p>
                        Peso, misure e diario stanno sul telefono di chi li produce; i messaggi
                        sono cifrati fra i due telefoni. <a href="/professionisti">L'elenco
                        intero</a> è scritto prima della firma, non dopo.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════ la rivendita ═══════════════════════ --}}
    <section id="rivendita">
        <div class="contenitore">
            <p class="occhiello">Se lo giri ai tuoi</p>
            <h2>Puoi metterlo nella quota.</h2>

            <p class="guida">
                Molti lo inseriscono nel loro abbonamento mensile. Il prezzo lo fai tu: qui c'è
                un esempio con {{ $esempio['slot'] }} posti, al prezzo che suggeriamo.
            </p>

            <table class="tabella-prezzi" style="margin-top: var(--sp-3);">
                <tbody>
                    <tr>
                        <td>{{ $esempio['slot'] }} posti, piano {{ $esempio['piano']['nome'] }}</td>
                        <td style="text-align: right;">{{ $formatta($esempio['piano']['prezzo']) }} / mese</td>
                    </tr>
                    <tr>
                        <td>Girati a {{ $formatta($esempio['rivendita']) }} a persona</td>
                        <td style="text-align: right;">{{ $formatta($esempio['ricavo']) }} / mese</td>
                    </tr>
                    <tr>
                        <td><strong>Ti resta</strong></td>
                        <td style="text-align: right;"><strong>{{ $formatta($esempio['margine']) }} / mese</strong></td>
                    </tr>
                </tbody>
            </table>

            <p class="nota" style="margin-top: var(--sp-2);">
                🚨 <strong>{{ $formatta($esempio['rivendita']) }} è esattamente quanto costa il
                Plus a chi se lo paga da solo</strong>, e non è un caso: chi lo riceve può
                controllare in due tocchi, e deve trovare lo stesso numero. Sopra quella cifra
                stai vendendo a più del listino, e a scoprirlo è lui.
            </p>
        </div>
    </section>

    {{-- ═══════════════════════ farsi trovare ═══════════════════════ --}}
    <section class="alternata" id="visibilita">
        <div class="contenitore">
            <p class="occhiello">Farsi trovare</p>
            <h2>Comparire più in alto nel catalogo.</h2>

            <p class="guida">
                Nell'app c'è un catalogo di palestre e trainer. Comparire è gratis; comparire
                <strong>più in alto</strong> si paga a persona raggiunta:
                {{ number_format($costoVisualizzazione, 2, ',', '.') }} centesimi a
                visualizzazione, con un budget mensile minimo di
                {{ number_format($budgetMinimo, 0, ',', '.') }} €.
            </p>

            <p class="nota">
                💡 Decidi tu quanto spendere al mese: finito il budget, la scheda torna al suo
                posto naturale. E per mostrarla non serve sapere niente di chi guarda.
            </p>
        </div>
    </section>

    {{-- ═══════════════════════ domande ═══════════════════════ --}}
    <section id="domande-prezzi">
        <div class="contenitore stretto">
            <p class="occhiello">Domande</p>
            <h2>Prima di abbonarti.</h2>

            <div style="margin-top: var(--sp-3);">
                <details class="domanda">
                    <summary><strong>Cosa succede se supero i posti del piano?</strong></summary>
                    <p>
                        Niente di automatico: non ti addebitiamo di nascosto. Il pannello ti
                        dice che sei al limite, e passi al piano più grande quando decidi tu.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Posso cambiare piano dopo?</strong></summary>
                    <p>
                        Sì, dal pannello, in su e in giù. ⚠️ Scendere si può solo se il piano
                        nuovo tiene dentro le persone che hai già: il sistema te lo dice invece
                        di lasciare qualcuno fuori.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>E se un mese non uso niente?</strong></summary>
                    <p>
                        Con i piani a posti paghi il piano: i posti restano assegnati. Col su
                        misura paghi il canone più il minimo — è il prezzo di avere il conto
                        aperto e i dati al loro posto.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Serve una partita IVA per abbonarsi?</strong></summary>
                    <p>
                        No. Serve un'email, una password e il nome con cui ti conoscono i tuoi
                        {{ $unita }}. I dati per la fattura li chiede Stripe al pagamento.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Si disdice facilmente?</strong></summary>
                    <p>
                        Sì, dal pannello, senza scrivere a nessuno. L'abbonamento resta attivo
                        fino alla fine del periodo già pagato.
                    </p>
                </details>
            </div>
        </div>
    </section>

    <section>
        <div class="contenitore" style="text-align: center;">
            <h2>Comincia col piano più piccolo.</h2>
            <p class="guida" style="margin: var(--sp-2) auto 0;">
                Si cambia in qualunque momento, e nessuno ti chiede di decidere oggi quanto
                crescerai.
            </p>

            <div class="fila-bottoni centrata" style="margin-top: var(--sp-3);">
                <a href="/iscriviti/{{ $piani[0]['codice'] }}" class="bottone bottone-pieno bottone-grande">
                    Abbonati a {{ $piani[0]['nome'] }}
                </a>
                <a href="/professionisti" class="bottone bottone-vuoto bottone-grande">Come funziona</a>
            </div>
        </div>
    </section>

@endsection
