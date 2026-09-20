{{--
    Per palestre e trainer — pagina nuova, 20/09/2026.

    ── 🚨 Perché esiste ───────────────────────────────────────────────────────

    Perché fino a ieri il discorso a chi **compra** stava spalmato fra la home —
    dove disturba chi si allena — e la pagina dei prezzi, dove arriva senza che
    nessuno abbia spiegato cosa si sta comprando.

    ⚠️ **Qui si dice anche quello che NON si può fare**, e non è un atto di
    modestia: una palestra che scopre dopo la firma di non poter vedere il peso
    dei propri iscritti si sente presa in giro. Scritto prima, è il motivo per
    sceglierci.
--}}
@extends('sito.layout')

@section('titolo', 'Per palestre e trainer — Training Companion')
@section('descrizione', 'Dai ai tuoi iscritti un\'app che funziona, senza diventare il custode dei loro dati sanitari. Piani da 30 € al mese per i trainer e da 60 € per le palestre.')

@section('contenuto')

    <section style="padding-top: var(--sp-5);">
        <div class="contenitore griglia-2">
            <div>
                <span class="pillola">Per chi allena</span>
                <h1 style="margin-top: var(--sp-2);">I tuoi iscritti hanno un'app.<br>Tu non diventi il custode dei loro dati.</h1>

                <p class="guida">
                    Regali l'abbonamento a chi vuoi tu, i tuoi trainer lavorano dentro, e la
                    parte delicata — peso, misure, diario, messaggi — non passa né da te né
                    da noi. È una scelta di architettura, e ti toglie un problema che non
                    sapevi di avere.
                </p>

                <div class="fila-bottoni" style="margin-top: var(--sp-3);">
                    <a href="/prezzi/trainer" class="bottone bottone-pieno bottone-grande">Piani trainer</a>
                    <a href="/prezzi/palestra" class="bottone bottone-pieno bottone-grande">Piani palestra</a>
                    <a href="#responsabilita" class="bottone bottone-vuoto bottone-grande">Cosa firmi</a>
                </div>

                <p class="nota" style="margin-top: var(--sp-2);">
                    Da {{ $formatta($daTrainer) }} al mese per un trainer, da
                    {{ $formatta($daPalestra) }} per una palestra. Ci si abbona da qui, in
                    due minuti.
                </p>
            </div>

            <x-sito.figura
                nome="platea-palestra"
                alt="La sala pesi di una palestra durante un allenamento"
                priorita
            />
        </div>
    </section>

    {{-- ═══════════════════ come funziona per loro ═══════════════════ --}}
    <section class="alternata">
        <div class="contenitore">
            <p class="occhiello">Come funziona</p>
            <h2>Un piano, dei posti, e li dai a chi vuoi tu.</h2>

            <div class="griglia" style="margin-top: var(--sp-3);">
                <div class="scheda">
                    <h3>1. Scegli il piano</h3>
                    <p>
                        Ogni piano ha un numero di <strong>posti</strong>. Un posto è il piano
                        <strong>Plus intero</strong> regalato a una persona: allenamenti e
                        schede senza limiti, sonno e recupero, progressi, e il consiglio che
                        arriva da solo. ⛔ Non una versione ridotta.
                    </p>
                </div>

                <div class="scheda">
                    <h3>2. Inviti le persone</h3>
                    <p>
                        Mandi un link. Chi lo apre entra nella tua palestra, con i tuoi
                        colori e il tuo logo. Chi non ha l'app viene accompagnato a
                        scaricarla e torna dov'era.
                    </p>
                </div>

                <div class="scheda">
                    <h3>3. I trainer lavorano</h3>
                    <p>
                        Scrivono schede e piani alimentari, li mandano in chat, e vedono gli
                        iscritti che affidi loro. Le palestre hanno anche un tetto di
                        <strong>posti trainer</strong>, largo apposta.
                    </p>
                </div>
            </div>

            <div class="scheda tenue" style="margin-top: var(--sp-3);">
                <h3 style="margin-top: 0;">💡 Due tetti, e valgono tutti e due</h3>
                <p style="margin-bottom: 0;">
                    Un piano palestra dice quanti <strong>iscritti</strong> puoi abbonare e
                    quanti <strong>trainer</strong> puoi far lavorare. I trainer sono tanti
                    rispetto agli iscritti, perché è normale avere più istruttori che
                    abbonamenti regalati — ma restano un massimo. Se ti servono trainer
                    illimitati, c'è il piano su misura.
                </p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ cosa NON si vede ═══════════════════ --}}
    <section id="cosa-non-vedi">
        <div class="contenitore">
            <p class="occhiello">Da leggere prima</p>
            <h2>Cosa non potrai vedere, nemmeno chiedendocelo.</h2>

            <p class="guida">
                Non è una regola che qualcuno può togliere: quei dati sui nostri server non
                ci sono. Stanno sul telefono della persona, o dentro buste cifrate che si
                aprono solo lì.
            </p>

            <table class="tabella-prezzi" style="margin-top: var(--sp-3);">
                <thead>
                    <tr><th>Se chiedi…</th><th>La risposta</th><th>Perché</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td>I messaggi fra un trainer e un iscritto</td>
                        <td><strong>Impossibile</strong></td>
                        <td>Cifrati fra i due telefoni: la chiave non è sui nostri server</td>
                    </tr>
                    <tr>
                        <td>Peso, misure, foto dei progressi</td>
                        <td><strong>Impossibile</strong></td>
                        <td>Stanno sul telefono dell'iscritto</td>
                    </tr>
                    <tr>
                        <td>Il diario alimentare</td>
                        <td><strong>Impossibile</strong></td>
                        <td>Idem</td>
                    </tr>
                    <tr>
                        <td>Quale scheda segue Tal dei Tali</td>
                        <td><strong>Impossibile</strong></td>
                        <td>Il legame persona ↔ scheda non è scritto sul server</td>
                    </tr>
                </tbody>
            </table>

            <div class="scheda tenue" style="margin-top: var(--sp-3);">
                <h3 style="margin-top: 0;">🚨 Perché è un vantaggio per te</h3>
                <p style="margin-bottom: 0;">
                    Dati che non hai non puoi perderli, e non ti rendono il custode di uno
                    schedario sanitario dei tuoi iscritti. Da un programma post-infortunio si
                    capisce cos'è successo al corpo di chi lo esegue: tenerne l'elenco
                    nominativo sarebbe una responsabilità in più, non una funzione in più.
                </p>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ cosa vedi ═══════════════════ --}}
    <section class="alternata">
        <div class="contenitore">
            <p class="occhiello">Il pannello</p>
            <h2>Quello che vedi, e che ti serve davvero.</h2>

            <div style="margin-top: var(--sp-3); margin-bottom: var(--sp-3);">
                <x-sito.figura
                    nome="pannello"
                    alt="Il pannello di una palestra: elenco degli iscritti, trainer e schede modello"
                />
            </div>

            <div class="griglia">
                <div class="scheda">
                    <h3>👥 Gli iscritti</h3>
                    <p>Nome, email, da quando sono con te, chi li segue. Inviti e disiscrizioni con un tocco.</p>
                </div>

                <div class="scheda">
                    <h3>🏋 I trainer</h3>
                    <p>Chi lavora con te e quanti iscritti segue. Chi affidare a chi lo decidi tu.</p>
                </div>

                <div class="scheda">
                    <h3>📋 Schede e piani modello</h3>
                    <p>Il patrimonio della palestra: i modelli restano tuoi anche quando un trainer se ne va.</p>
                </div>

                <div class="scheda">
                    <h3>📄 Import da PDF o foto</h3>
                    <p>
                        Le schede che hai su carta diventano schede vere: un modello le
                        trascrive, tu le controlli riga per riga.
                        <span class="nota">Ogni trascrizione costa 50 gettoni.</span>
                    </p>
                </div>

                <div class="scheda">
                    <h3>💳 Il tuo piano</h3>
                    <p>Quanto stai usando, quanto pagherai, e il passaggio a un piano più grande quando serve.</p>
                </div>

                <div class="scheda">
                    <h3>🎨 I tuoi colori</h3>
                    <p>Logo e colori della palestra dentro l'app dei tuoi iscritti: la usano, e vedono te.</p>
                </div>
            </div>
        </div>
    </section>

    {{-- ═══════════════════ responsabilità ═══════════════════ --}}
    <section id="responsabilita">
        <div class="contenitore">
            <p class="occhiello">Cosa firmi</p>
            <h2>Una liberatoria, prima di vedere i dati di qualcuno.</h2>

            <p class="guida">
                I dati dei tuoi iscritti li tratti <strong>per conto nostro</strong>, come
                responsabile ai sensi dell'art. 28 del GDPR. Prima di cominciare firmi un
                documento che dice cosa puoi farci e cosa no: si firma nel pannello, in due
                minuti, e senza allegati da stampare.
            </p>

            <div class="griglia" style="margin-top: var(--sp-3);">
                <div class="scheda">
                    <h3>Puoi</h3>
                    <p>
                        Vedere i tuoi iscritti, assegnare trainer, scrivere schede e piani,
                        invitare e disiscrivere. Tutto dentro la piattaforma.
                    </p>
                </div>

                <div class="scheda">
                    <h3>Non puoi</h3>
                    <p>
                        Usare gli indirizzi per promozioni, esportare elenchi da tenere
                        altrove, cedere i dati a terzi, o continuare a trattarli dopo che una
                        persona se n'è andata.
                    </p>
                </div>

                <div class="scheda">
                    <h3>Devi</h3>
                    <p>
                        Dare un account a testa senza condividerlo, togliere l'accesso a chi
                        lascia entro 24 ore, e avvisarci entro 24 ore se qualcosa va storto.
                    </p>
                </div>
            </div>

            <p style="margin-top: var(--sp-3);">
                I testi completi sono pubblici:
                <a href="/liberatoria-palestra">quello per le palestre</a> e
                <a href="/liberatoria-trainer">quello per i trainer</a>.
            </p>
        </div>
    </section>

    {{-- ═══════════════════ chiusura ═══════════════════ --}}
    <section class="alternata">
        <div class="contenitore" style="text-align: center;">
            <h2>Vuoi vedere i numeri?</h2>
            <p class="guida" style="margin: var(--sp-2) auto 0;">
                Tre piani per i trainer, tre per le palestre, e un piano su misura quando i
                numeri diventano grandi.
            </p>

            <div class="fila-bottoni centrata" style="margin-top: var(--sp-3);">
                <a href="/prezzi/trainer" class="bottone bottone-pieno bottone-grande">Sono un trainer</a>
                <a href="/prezzi/palestra" class="bottone bottone-pieno bottone-grande">Siamo una palestra</a>
            </div>

            <p class="nota" style="margin-top: var(--sp-2);">
                Hai già un account? <a href="/admin/login">Entra nel pannello</a>.
            </p>
        </div>
    </section>

@endsection
