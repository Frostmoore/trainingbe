{{--
    I prezzi di chi si allena — terza stesura, 20/09/2026 notte.

    ── 🚨 La fonte è il codice, non la memoria ────────────────────────────────

    Ogni riga di questa pagina viene dal **gate dell'abbonamento letto nell'app,
    file per file**, e riassunto in `codebase_reference_trainingbe.md` §103.1.
    Le due stesure precedenti si erano inventate mezza pagina:

    | Dicevano | Verità |
    |---|---|
    | «Gratis: allenamenti e schede, tutto» | ⛔ **Un allenamento al giorno**, 3 schede, solo di un giorno |
    | Niente sulle card bloccate | ⛔ Sono cinque in «Oggi» — sonno, carico, recupero, composizione, allenamento — più sei valori nell'intestazione |
    | Niente su settimana e progressi | ⛔ Dietro il gate tutti e due |
    | «i passi non si leggono» | ⛔ Si leggono, e c'è una barra a dieci tacche verso l'obiettivo |

    💡 **La divisione che regge tutto**: quello che parte **da solo** lo paga
    l'abbonamento; quello che **chiedi tu** lo pagano i gettoni — abbonati o no.

    🚨 E i numeri vengono da `config/listino.php` attraverso `Listino`: un prezzo
    copiato a mano è un prezzo che un giorno dirà una cosa diversa da quella che
    il sistema incassa.
--}}
@extends('sito.layout')

@section('titolo', 'Prezzi per chi si allena — Training Companion')
@section('descrizione', 'L\'app è gratis e non scade. Il Plus costa 8 € al mese: allenamenti senza limiti, schede su più giorni, sonno, recupero, carico e progressi.')

@section('contenuto')

    <section style="padding-bottom: var(--sp-4);">
        <div class="contenitore">
            <span class="pillola">Individuale</span>
            <h1 style="margin-top: var(--sp-2);">Gratis si usa davvero.<br>Il Plus apre il resto.</h1>

            <div class="griglia-2" style="margin-top: var(--sp-3);">
                <p class="guida">
                    Diario, passi, peso, chat e copia di sicurezza non costano niente e non
                    scadono. L'abbonamento toglie i limiti sugli allenamenti e accende la metà
                    dell'app che <strong>legge i tuoi dati</strong>: spunto di oggi, carico e
                    carica, recupero, com'è fatto, e i tuoi allenamenti. 💡 I dati restano tuoi
                    comunque: l'abbonamento apre le letture, non i numeri.
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
                        <li>Diario alimentare, catalogo completo, preferiti, macro</li>
                        <li>Calorie del giorno, bruciate e <strong>passi</strong> con la loro barra</li>
                        <li>Peso, misure, foto dei progressi e grafici</li>
                        <li>Chat con chi ti allena, senza limite</li>
                        <li>Cronometro, storico degli esercizi, esercizi tuoi</li>
                        <li>Copia di sicurezza cifrata, «scarica i miei dati», elimina account</li>
                        <li class="no"><strong>Un allenamento al giorno</strong> avviato dall'app</li>
                        <li class="no"><strong>Le 3 schede più recenti</strong>, e solo di un giorno</li>
                        <li class="no">Sonno, recupero, carico e composizione: card sfumate</li>
                        <li class="no">Niente settimana programmata, niente progressi</li>
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
                        <li><strong>Allenamenti senza limiti</strong>, e tutte le tue schede</li>
                        <li>Schede su <strong>più giorni</strong>, e la settimana programmata</li>
                        <li>L'<strong>andamento di ogni esercizio</strong>, coi primati</li>
                        <li><strong>Recupero e sonno</strong>: com'è andata la notte, e cosa farne</li>
                        <li><strong>Carico e carica</strong>: quanto hai speso e quanto ti resta</li>
                        <li><strong>Com'è fatto</strong> il peso che hai perso o messo</li>
                        <li>Sonno, HRV, battito e prontezza in cima a «Oggi»</li>
                        <li>Il <strong>consiglio del giorno</strong>, che arriva da solo</li>
                        <li>L'<strong>analisi della scheda</strong> dopo l'allenamento</li>
                        <li>Il <strong>colore dell'app</strong>, e scrivere senza limite a chi non ti segue</li>
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
                        Tre cose. <strong>Gli allenamenti</strong>: senza abbonamento ne fai
                        partire uno al giorno e usi le 3 schede più recenti, solo di un giorno.
                        <strong>Le analisi</strong>: sonno, recupero, carico e carica,
                        composizione, progressi e settimana programmata sono dietro
                        l'abbonamento — le card le vedi, sfumate.
                        <strong>L'assistente automatico</strong>: il consiglio del giorno e
                        l'analisi della scheda arrivano da soli solo se sei abbonato.
                    </p>
                </details>

                <details class="domanda">
                    <summary><strong>Cosa resta gratis, per sempre?</strong></summary>
                    <p>
                        Il diario alimentare intero, col catalogo e i preferiti; le calorie del
                        giorno, le bruciate e i passi; il peso con i suoi grafici; la chat con
                        chi ti allena; il cronometro e lo storico degli esercizi; la copia di
                        sicurezza e l'esportazione dei tuoi dati.
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
                        a chi vogliono: chi lo riceve ha <strong>esattamente il Plus</strong>,
                        senza pagare niente. Se lascia la palestra il regalo finisce, ma i suoi
                        dati e le sue schede restano suoi.
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
