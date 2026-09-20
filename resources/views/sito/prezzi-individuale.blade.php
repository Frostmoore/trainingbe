{{--
    I prezzi di chi si allena — rifatta il 20/09/2026 sera, sui fatti del codice.

    ── 🚨 Perché è stata rifatta ──────────────────────────────────────────────

    Perché la prima stesura **raccontava un prodotto che non esiste**. Il
    committente, riga per riga:

    | Diceva | La verità nel codice |
    |---|---|
    | «Gratis: allenamenti, schede, storico» | ⛔ Senza abbonamento si usano **le 3 schede più recenti** e **solo di un giorno** (`limiti_delle_schede.dart`) |
    | «Plus: scrivi il pasto e lo conta lei» come cosa inclusa | ⛔ Le richieste fatte a mano **si pagano a gettoni sempre**, abbonati o no (`CancelloDeiGettoni`, 31/08) |
    | «150 elaborazioni incluse» | ⛔ Quella quota copre **solo l'automatico**, e il numero non si pubblica |
    | Niente sulle chiamate automatiche del non abbonato | ⛔ Non partono: senza abbonamento non c'è nessuna quota inclusa |

    💡 **La divisione vera è una sola, e regge tutta la pagina**: quello che
    parte **da solo** lo paga l'abbonamento; quello che **chiedi tu** lo pagano i
    gettoni. 🚨 Dirlo prima è l'unico modo onesto di far spendere qualcuno.

    ── 🚨 E i numeri non si scrivono qui dentro ───────────────────────────────

    Vengono da `config/listino.php` attraverso `Listino`. Un prezzo copiato a
    mano è un prezzo che un giorno dirà una cosa diversa da quella che il
    sistema incassa.
--}}
@extends('sito.layout')

@section('titolo', 'Prezzi per chi si allena — Training Companion')
@section('descrizione', 'L\'app è gratis e non scade. Il Plus costa 8 € al mese: toglie i limiti sulle schede e accende l\'assistente automatico.')

@section('contenuto')

    <section style="padding-bottom: var(--sp-4);">
        <div class="contenitore">
            <span class="pillola">Individuale</span>
            <h1 style="margin-top: var(--sp-2);">Gratis si usa davvero.<br>Il Plus toglie i limiti.</h1>

            <div class="griglia-2" style="margin-top: var(--sp-3);">
                <p class="guida">
                    Diario, peso, misure, chat e copia di sicurezza non costano niente e non
                    scadono. L'abbonamento fa due cose: toglie i <strong>limiti sulle
                    schede</strong> e accende l'assistente <strong>automatico</strong>, quello
                    che lavora senza che tu chieda niente.
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
                        <li>Diario alimentare, catalogo completo e preferiti</li>
                        <li>Peso, misure, foto dei progressi</li>
                        <li>Chat con chi ti allena, senza limite</li>
                        <li>Copia di sicurezza cifrata, ed esporta i tuoi dati</li>
                        <li>Allenamenti col cronometro e lo storico degli esercizi</li>
                        <li class="no"><strong>Solo le 3 schede più recenti</strong>, e solo di un giorno</li>
                        <li class="no">L'assistente non lavora da solo</li>
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
                        <li><strong>Schede senza limiti</strong>, anche su più giorni</li>
                        <li>Il <strong>consiglio del giorno</strong>, che arriva da solo fino a tre volte</li>
                        <li>L'<strong>analisi della scheda</strong> dopo l'allenamento</li>
                        <li>L'andamento di un esercizio nel tempo, letto dall'assistente</li>
                        <li>Scrivere senza limite anche a chi non ti segue</li>
                    </ul>

                    <div class="in-fondo">
                        <p class="nota" style="margin: 0 0 var(--sp-1);">
                            🚨 Il Plus si attiva <strong>dentro l'app</strong>: Profilo → Abbonamento.
                        </p>
                        <x-sito.scarica />
                    </div>
                </div>
            </div>

            <div class="scheda tenue" style="margin-top: var(--sp-3);">
                <h3 style="margin-top: 0;">⚠️ Una cosa da sapere prima di pagare</h3>
                <p style="margin-bottom: 0;">
                    <strong>Quello che chiedi tu all'assistente si paga a gettoni in tutti e
                    due i casi</strong>: scrivere un pasto a parole, fotografare il piatto,
                    trascrivere una scheda da un PDF. 💡 Non è un limite del piano gratuito —
                    è che ognuna di quelle richieste costa a noi nel momento in cui la fai, e
                    preferiamo dirlo prima invece di nasconderlo in un tetto mensile.
                </p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════════ i gettoni ═══════════════════════ --}}
    <section class="alternata" id="gettoni">
        <div class="contenitore">
            <p class="occhiello">Per quello che chiedi tu</p>
            <h2>I gettoni: si comprano una volta e restano lì.</h2>

            <p class="guida">
                Servono per le richieste che parti tu: una stima da una frase vale
                <strong>1 gettone</strong>, una da foto <strong>10</strong>, la trascrizione di
                una scheda o di un piano alimentare <strong>50</strong>. ⚠️ Non scadono, e
                quando finiscono l'app continua a funzionare come prima.
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
                💡 Il costo lo trovi scritto nell'app <strong>prima</strong> di premere, ogni
                volta. E i gettoni restano tuoi: non sono della palestra e non scadono se cambi
                piano.
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
                    <summary><strong>Cosa cambia davvero fra gratis e Plus?</strong></summary>
                    <p>
                        Due cose. Senza abbonamento puoi usare <strong>le 3 schede più
                        recenti</strong> e solo quelle di un giorno, e l'assistente non parte
                        mai da solo. Col Plus le schede sono tutte, anche su più giorni, e
                        l'assistente ti scrive il consiglio del giorno e legge l'andamento
                        delle tue sedute.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Se compro dei gettoni, l'assistente lavora da solo?</strong></summary>
                    <p>
                        No. I gettoni pagano <strong>quello che chiedi tu</strong>; il consiglio
                        del giorno e l'analisi dopo l'allenamento sono compresi
                        nell'abbonamento, e senza abbonamento non partono — ⛔ nemmeno se hai
                        gettoni da spendere. 💡 È voluto: nessuno deve vedersi consumare il
                        saldo da una cosa che non ha chiesto.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>E se mi abbono, le foto dei piatti sono gratis?</strong></summary>
                    <p>
                        No: quelle sono richieste che parti tu, e si pagano a gettoni anche col
                        Plus. Una stima da foto ne vale dieci. ⚠️ Lo scriviamo grande perché è
                        la cosa che più facilmente si scopre dopo aver pagato.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Cosa succede alle mie schede se non rinnovo?</strong></summary>
                    <p>
                        Restano tutte dove sono: nessuna si cancella. Tornano utilizzabili le
                        tre più recenti di un giorno, e le altre le rivedi disattivate. 🚨 Una
                        scheda che il tuo trainer ha scritto per te non la buttiamo perché è
                        scaduto un abbonamento.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Me lo può pagare la mia palestra?</strong></summary>
                    <p>
                        Sì. Palestre e trainer comprano dei <strong>posti</strong> e li regalano
                        a chi vogliono: chi lo riceve ha esattamente il Plus, senza pagare
                        niente. Se lascia la palestra il regalo finisce, ma i suoi dati e le sue
                        schede restano suoi.
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
