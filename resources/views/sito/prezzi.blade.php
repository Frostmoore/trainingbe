{{--
    I prezzi — riscritti il 20/09/2026, coi prezzi VERI.

    ── 🚨 Fino al 19/09 erano segnaposto ──────────────────────────────────────

    `config/listino.php` lo diceva a chiare lettere: «non si vende a nessuno su
    questi numeri». Adesso ci si vende, e questa pagina è il posto in cui quei
    numeri si leggono per intero.

    ── 💡 Le regole di questa pagina ──────────────────────────────────────────

    1. **Nessun numero scritto a mano nel markup.** Tutto viene da
       `config/listino.php` attraverso `Listino`: se un prezzo cambia lì, cambia
       qui, nella modale dell'app e su Stripe. Un prezzo copiato a mano è un
       prezzo che un giorno sarà diverso dagli altri due.
    2. **Si dice a quanto viene il singolo posto**, e si dice dove conviene
       cambiare piano. Chi compra il conto lo fa comunque: farglielo trovare
       fatto è onestà, non gentilezza.
    3. **Il margine della rivendita sta QUI e non sulla home** — chi si allena
       non deve leggere che è un prodotto rivendibile addosso a lui.
--}}
@extends('sito.layout')

@section('titolo', 'Prezzi — Training Companion')
@section('descrizione', 'L\'app è gratis. Si paga solo l\'assistente AI: 8 € al mese per chi si allena da solo, a posti per trainer e palestre.')

@section('contenuto')

    <section style="padding-top: var(--sp-5);">
        <div class="contenitore">
            <span class="pillola">Listino</span>
            <h1 style="margin-top: var(--sp-2);">L'app è gratis.<br>Si paga solo l'assistente.</h1>

            <p class="guida">
                Diario, allenamenti, schede e chat non costano niente e non scadono. Quello
                che si paga è l'intelligenza artificiale, perché costa a ogni domanda: c'è
                un abbonamento per l'assistente automatico e ci sono i gettoni per le
                funzioni a richiesta.
            </p>

            <p style="margin-top: var(--sp-2); font-size: 14px; color: var(--testo-tenue);">
                Tutti i prezzi sono quelli finali: niente da aggiungere alla cassa.
            </p>

            <div style="margin-top: var(--sp-3);">
                <x-sito.figura
                    nome="prezzi"
                    alt="Attrezzi da palestra allineati lungo una parete"
                    priorita
                />
            </div>
        </div>
    </section>

    {{-- ═══════════════════ chi si allena da solo ═══════════════════ --}}
    <section class="alternata" id="da-solo">
        <div class="contenitore">
            <p class="occhiello">Per chi si allena da solo</p>
            <h2>Un abbonamento, {{ $formatta($prezzoSingolo) }} al mese.</h2>

            <div class="griglia-2" style="margin-top: var(--sp-3);">
                <div class="scheda scheda-prezzo scelta">
                    <h3 style="margin-top: 0;">Plus</h3>
                    <p style="font-size: 32px; font-weight: 700; margin: var(--sp-1) 0;">
                        {{ $formatta($prezzoSingolo) }}<span style="font-size: 16px; font-weight: 400; color: var(--testo-tenue);"> / mese</span>
                    </p>
                    <p>
                        Comprende l'assistente <strong>automatico</strong>: lo spunto del
                        giorno, fino a {{ number_format($chiamateMensili, 0, ',', '.') }}
                        elaborazioni al mese — cinque al giorno.
                    </p>
                    <p style="margin-bottom: 0; font-size: 14px; color: var(--testo-tenue);">
                        Si disdice quando vuoi, dall'app. Quello che hai già segnato resta tuo.
                    </p>
                </div>

                <div class="scheda tenue">
                    <h3 style="margin-top: 0;">E senza abbonamento?</h3>
                    <p>
                        L'app resta completa: diario, allenamenti, schede, chat col trainer,
                        peso e misure. Non c'è niente che si spegne dopo trenta giorni.
                    </p>
                    <p style="margin-bottom: 0;">
                        💡 Se ti allena una palestra o un trainer, l'abbonamento
                        <strong>può regalartelo</strong>: in quel caso non paghi niente tu.
                    </p>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ gettoni ═══════════════════ --}}
    <section id="gettoni">
        <div class="contenitore">
            <p class="occhiello">Le funzioni a richiesta</p>
            <h2>I gettoni: si comprano una volta e restano lì.</h2>

            <p class="guida">
                Riconoscere un pasto da una foto, trascrivere una scheda da un PDF: sono
                cose che costano a ogni chiamata, e si pagano a gettoni. ⚠️ Non scadono, e
                non c'è un abbonamento nascosto dietro.
            </p>

            <div class="griglia-pacchetti" style="margin-top: var(--sp-3);">
                @foreach ($pacchetti as $p)
                    <div class="scheda scheda-prezzo @if ($loop->last) scelta @endif">
                        <h3 style="margin-top: 0;">{{ $p['nome'] }}</h3>
                        <p style="font-size: 28px; font-weight: 700; margin: var(--sp-1) 0;">
                            {{ $formatta($p['prezzo']) }}
                        </p>
                        <p style="margin: 0;">{{ number_format($p['gettoni'], 0, ',', '.') }} gettoni</p>
                        <p style="margin-top: auto; padding-top: var(--sp-2); font-size: 14px; color: var(--testo-tenue);">
                            {{ $p['nota'] }} · {{ number_format($p['centPerGettone'], 1, ',', '.') }} cent a gettone
                        </p>
                    </div>
                @endforeach
            </div>

            <p style="margin-top: var(--sp-3); font-size: 14px; color: var(--testo-tenue);">
                Quanto costa una cosa? Il conto lo trovi nell'app prima di premere: una
                stima da testo vale un gettone, una da foto dieci, la trascrizione di una
                scheda cinquanta.
            </p>
        </div>
    </section>

    {{-- ═══════════════════ trainer ═══════════════════ --}}
    <section class="alternata" id="trainer">
        <div class="contenitore">
            <p class="occhiello">Per i trainer</p>
            <h2>Paghi i posti, non le funzioni.</h2>

            <p class="guida">
                Un <strong>posto</strong> è un abbonamento completo regalato a un tuo
                allievo: lui non paga niente e ha l'assistente automatico. Le funzioni a
                richiesta restano a gettoni, per tutti.
            </p>

            @php($pianiTrainer = $listino->piani('trainer'))
            @php($proTrainer = $pianiTrainer[count($pianiTrainer) - 1])
            @php($misuraTrainer = $listino->suMisura('trainer'))

            <div class="griglia-pacchetti" style="margin-top: var(--sp-3);">
                @foreach ($pianiTrainer as $piano)
                    <div class="scheda scheda-prezzo @if ($loop->index === 1) scelta @endif">
                        <h3 style="margin-top: 0;">{{ $piano['nome'] }}</h3>
                        <p style="font-size: 28px; font-weight: 700; margin: var(--sp-1) 0;">
                            {{ $formatta($piano['prezzo']) }}<span style="font-size: 15px; font-weight: 400; color: var(--testo-tenue);"> / mese</span>
                        </p>
                        <p style="margin: 0;"><strong>{{ $piano['slot'] }} allievi</strong> abbonati</p>
                        <p style="margin-top: auto; padding-top: var(--sp-2); font-size: 14px; color: var(--testo-tenue);">
                            {{ $piano['nota'] }} · {{ $formatta($piano['perSlot']) }} ad allievo
                        </p>
                    </div>
                @endforeach

                <div class="scheda scheda-prezzo">
                    <h3 style="margin-top: 0;">{{ $misuraTrainer['nome'] }}</h3>
                    <p style="font-size: 28px; font-weight: 700; margin: var(--sp-1) 0;">
                        {{ $formatta($misuraTrainer['perSlot']) }}<span style="font-size: 15px; font-weight: 400; color: var(--testo-tenue);"> / allievo</span>
                    </p>
                    <p style="margin: 0;">più {{ $formatta($misuraTrainer['canone']) }} al mese</p>
                    <p style="margin-top: auto; padding-top: var(--sp-2); font-size: 14px; color: var(--testo-tenue);">
                        da {{ $misuraTrainer['minimo'] }} allievi in su
                    </p>
                </div>
            </div>

            <div class="scheda tenue" style="margin-top: var(--sp-3);">
                <h3 style="margin-top: 0;">💡 Dove conviene cambiare</h3>
                <p style="margin-bottom: 0;">
                    Con {{ $misuraTrainer['minimo'] }} allievi il piano su misura costa
                    <strong>{{ $formatta($listino->costoSuMisura('trainer', $misuraTrainer['minimo'])) }}</strong>
                    al mese, contro i {{ $formatta($proTrainer['prezzo']) }} del
                    {{ $proTrainer['nome'] }}. Da lì in su cresce di
                    {{ $formatta($misuraTrainer['perSlot']) }} per ogni allievo in più, e non
                    ha tetti.
                </p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ palestre ═══════════════════ --}}
    <section id="palestre">
        <div class="contenitore">
            <p class="occhiello">Per le palestre</p>
            <h2>Posti per gli iscritti, posti per i trainer.</h2>

            <p class="guida">
                Ogni piano dice quanti iscritti puoi abbonare e quanti trainer possono
                lavorare. 🚨 Valgono tutti e due: i trainer sono larghi apposta, ma restano
                un massimo.
            </p>

            @php($pianiPalestre = $listino->piani('palestre'))
            @php($proPalestra = $pianiPalestre[count($pianiPalestre) - 1])
            @php($misuraPalestra = $listino->suMisura('palestre'))

            <div class="griglia-pacchetti" style="margin-top: var(--sp-3);">
                @foreach ($pianiPalestre as $piano)
                    <div class="scheda scheda-prezzo @if ($loop->index === 1) scelta @endif">
                        <h3 style="margin-top: 0;">{{ $piano['nome'] }}</h3>
                        <p style="font-size: 28px; font-weight: 700; margin: var(--sp-1) 0;">
                            {{ $formatta($piano['prezzo']) }}<span style="font-size: 15px; font-weight: 400; color: var(--testo-tenue);"> / mese</span>
                        </p>
                        <p style="margin: 0;">
                            <strong>{{ $piano['slot'] }} iscritti</strong> abbonati<br>
                            <strong>{{ $piano['trainer'] }} trainer</strong> al lavoro
                        </p>
                        <p style="margin-top: auto; padding-top: var(--sp-2); font-size: 14px; color: var(--testo-tenue);">
                            {{ $piano['nota'] }} · {{ $formatta($piano['perSlot']) }} a iscritto
                        </p>
                    </div>
                @endforeach

                <div class="scheda scheda-prezzo">
                    <h3 style="margin-top: 0;">{{ $misuraPalestra['nome'] }}</h3>
                    <p style="font-size: 28px; font-weight: 700; margin: var(--sp-1) 0;">
                        {{ $formatta($misuraPalestra['perSlot']) }}<span style="font-size: 15px; font-weight: 400; color: var(--testo-tenue);"> / iscritto</span>
                    </p>
                    <p style="margin: 0;">
                        più {{ $formatta($misuraPalestra['canone']) }} al mese<br>
                        <strong>trainer illimitati</strong>
                    </p>
                    <p style="margin-top: auto; padding-top: var(--sp-2); font-size: 14px; color: var(--testo-tenue);">
                        da {{ $misuraPalestra['minimo'] }} iscritti in su
                    </p>
                </div>
            </div>

            <div class="scheda tenue" style="margin-top: var(--sp-3);">
                <h3 style="margin-top: 0;">💡 Dove conviene cambiare</h3>
                <p style="margin-bottom: 0;">
                    Con {{ $misuraPalestra['minimo'] }} iscritti il su misura costa
                    <strong>{{ $formatta($listino->costoSuMisura('palestre', $misuraPalestra['minimo'])) }}</strong>
                    contro i {{ $formatta($proPalestra['prezzo']) }} del
                    {{ $proPalestra['nome'] }}, e toglie il tetto ai trainer. ⚠️ Sotto il minimo si paga comunque il minimo: è il prezzo di
                    avere il conto aperto.
                </p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ la rivendita ═══════════════════ --}}
    <section class="alternata" id="rivendita">
        <div class="contenitore">
            <p class="occhiello">Se lo rivendi</p>
            <h2>Puoi girarlo ai tuoi iscritti.</h2>

            <p class="guida">
                Molte palestre lo inseriscono nella quota mensile. Il prezzo lo fai tu: qui
                sotto c'è un esempio con {{ $esempio['slot'] }} posti e il prezzo che
                suggeriamo.
            </p>

            <table class="tabella-prezzi" style="margin-top: var(--sp-3);">
                <tbody>
                    <tr>
                        <td>{{ $esempio['slot'] }} posti, piano {{ $esempio['piano']['nome'] }}</td>
                        <td style="text-align: right;">{{ $formatta($esempio['piano']['prezzo']) }} / mese</td>
                    </tr>
                    <tr>
                        <td>Rivenduti a {{ $formatta($esempio['rivendita']) }} a persona</td>
                        <td style="text-align: right;">{{ $formatta($esempio['ricavo']) }} / mese</td>
                    </tr>
                    <tr>
                        <td><strong>Ti resta</strong></td>
                        <td style="text-align: right;"><strong>{{ $formatta($esempio['margine']) }} / mese</strong></td>
                    </tr>
                </tbody>
            </table>

            <p style="margin-top: var(--sp-2); font-size: 14px; color: var(--testo-tenue);">
                ⚠️ È un prezzo <strong>suggerito</strong>, e conviene tenerlo onesto: chi lo
                riceve può scoprire in due tocchi che da solo pagherebbe
                {{ $formatta($prezzoSingolo) }}.
            </p>
        </div>
    </section>

    {{-- ═══════════════════ pubblicità ═══════════════════ --}}
    <section id="visibilita">
        <div class="contenitore">
            <p class="occhiello">Farsi trovare</p>
            <h2>Comparire più in alto nel catalogo.</h2>

            <p class="guida">
                Nell'app c'è un catalogo di palestre e trainer. Comparire è gratis; comparire
                <strong>più in alto</strong> si paga, e si paga a persona raggiunta:
                {{ number_format($costoVisualizzazione, 2, ',', '.') }} centesimi a
                visualizzazione, con un budget mensile minimo di
                {{ number_format($budgetMinimo, 0, ',', '.') }} €.
            </p>

            <p style="font-size: 14px; color: var(--testo-tenue);">
                💡 Decidi tu quanto spendere al mese: finito il budget, la scheda torna al
                suo posto naturale. E per mostrarla non serve sapere niente di chi guarda.
            </p>
        </div>
    </section>

    {{-- ═══════════════════ domande sui soldi ═══════════════════ --}}
    <section class="alternata" id="domande-prezzi">
        <div class="contenitore">
            <p class="occhiello">Domande</p>
            <h2>Sui soldi, in chiaro.</h2>

            <div style="margin-top: var(--sp-3); display: grid; gap: var(--sp-2);">
                <details class="scheda">
                    <summary><strong>Cosa succede se supero i posti del piano?</strong></summary>
                    <p>
                        Niente di automatico: non ti addebitiamo di nascosto. Il pannello ti
                        dice che sei al limite, e passi al piano più grande quando decidi tu.
                    </p>
                </details>

                <details class="scheda">
                    <summary><strong>E se un mese non uso niente?</strong></summary>
                    <p>
                        Con i piani a posti paghi comunque il piano: i posti restano assegnati.
                        Con il piano su misura paghi il canone più il minimo — è il prezzo di
                        avere il conto aperto e i dati al loro posto.
                    </p>
                </details>

                <details class="scheda">
                    <summary><strong>I gettoni scadono?</strong></summary>
                    <p>No. Restano finché li usi, anche se cambi piano.</p>
                </details>

                <details class="scheda">
                    <summary><strong>Come si paga?</strong></summary>
                    <p>
                        Con carta, attraverso Stripe. I dati della carta li scrivi da loro:
                        noi non li vediamo e non li conserviamo.
                    </p>
                </details>

                <details class="scheda">
                    <summary><strong>Si disdice facilmente?</strong></summary>
                    <p>
                        Sì, dal pannello o dall'app, senza scrivere a nessuno. L'abbonamento
                        resta attivo fino alla fine del periodo già pagato.
                    </p>
                </details>
            </div>
        </div>
    </section>

    <section>
        <div class="contenitore" style="text-align: center;">
            <h2>Pronto a cominciare?</h2>
            <div style="display: flex; gap: var(--sp-2); justify-content: center; flex-wrap: wrap; margin-top: var(--sp-3);">
                <a href="/" class="bottone bottone-pieno bottone-grande">Scopri l'app</a>
                <a href="/professionisti" class="bottone bottone-vuoto bottone-grande">Sono una palestra</a>
            </div>
        </div>
    </section>

@endsection
