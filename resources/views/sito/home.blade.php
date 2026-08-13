@extends('sito.layout')

@section('titolo', 'Training Companion — allenamento e alimentazione')

{{--
    F9.1 — tre percorsi, tre pubblici.

    🚨 **L'ordine non è casuale**: prima le persone, poi i trainer, poi le
    palestre. Il gratuito è la porta d'ingresso del prodotto, e la sua funzione
    vera è la **conversione**, non la cassa: metterlo in fondo lo nasconderebbe
    proprio a chi deve entrarci.
--}}
@section('contenuto')
    <h1>Allenamento e alimentazione,<br>senza rifare i conti ogni volta.</h1>
    <p class="sottotitolo">
        Scheda, diario, peso e progressi in un posto solo. Che ti alleni da solo,
        che segua altre persone, o che gestisca una palestra.
    </p>

    {{-- ── Le persone ────────────────────────────────────────────────── --}}
    <h2>Per chi si allena</h2>
    <p class="nota">
        Ti registri e cominci. Il codice della palestra serve solo se ce l'hai.
    </p>

    <div class="griglia">
        @foreach ($perPersone as $piano)
            <div class="scheda">
                <h3>{{ $piano->name }}</h3>
                <div class="prezzo">
                    {{ $piano->eGratuito() ? 'Gratis' : number_format($piano->prezzoEuro(), 2, ',', '.').' €' }}
                    @unless ($piano->eGratuito())<small>/mese</small>@endunless
                </div>
                <ul class="elenco">
                    <li>Diario, allenamenti, storico e peso</li>
                    <li>Piani ricevuti dal tuo trainer</li>
                    {{-- 🚨 `ai_enabled` viene dal database, non da una riga
                         scritta qui: il listino e il cancello devono dire la
                         stessa cosa, sempre. --}}
                    <li class="{{ $piano->ai_enabled ? '' : 'no' }}">
                        {{ $piano->ai_enabled
                            ? 'Stima da testo e da foto, consiglio del giorno'
                            : 'Niente funzioni con l\'AI' }}
                    </li>
                </ul>
            </div>
        @endforeach
    </div>

    {{-- ── I trainer indipendenti ─────────────────────────────────────── --}}
    <h2>Per i trainer indipendenti</h2>
    <p class="nota">
        Componi schede e piani dal computer, li assegni dal telefono. I tuoi
        utenti entrano con un link tuo — non serve una palestra.
    </p>

    <div class="griglia">
        @foreach ($perTrainer as $piano)
            <div class="scheda">
                <h3>{{ $piano->name }}</h3>
                <div class="prezzo">
                    {{ $piano->eGratuito() ? 'Gratis' : number_format($piano->prezzoEuro(), 2, ',', '.').' €' }}
                    @unless ($piano->eGratuito())<small>/mese</small>@endunless
                </div>
                <ul class="elenco">
                    <li>{{ $piano->max_members === null ? 'Utenti illimitati' : 'Fino a '.$piano->max_members.' utenti' }}</li>
                    <li>Chat cifrata con i tuoi utenti</li>
                    <li class="{{ $piano->ai_enabled ? '' : 'no' }}">
                        {{ $piano->ai_enabled ? 'Quota AI condivisa con i tuoi' : 'Niente funzioni con l\'AI' }}
                    </li>
                </ul>
            </div>
        @endforeach
    </div>

    {{-- ── Le palestre ────────────────────────────────────────────────── --}}
    <h2>Per le palestre</h2>
    <p class="nota">
        L'app con i tuoi colori e il tuo logo. I tuoi trainer, i tuoi iscritti,
        il tuo codice d'invito.
    </p>

    <div class="griglia">
        @foreach ($perPalestre as $piano)
            <div class="scheda">
                <h3>{{ $piano->name }}</h3>
                <div class="prezzo">
                    {{ number_format($piano->prezzoEuro(), 2, ',', '.') }} €<small>/mese</small>
                </div>
                <ul class="elenco">
                    <li>App white-label con il tuo marchio</li>
                    <li>Pannello per lo staff, schede e piani</li>
                    <li>Quota AI inclusa per ogni iscritto</li>
                </ul>
            </div>
        @endforeach
    </div>

    {{--
        ⚠️ **Il pagamento non c'è ancora, e la pagina non finge il contrario.**

        `plan_parte_b.md` F9.3 lascia il fornitore **da decidere**, ed è una
        decisione che porta con sé fatturazione elettronica, condizioni e
        recesso. 🚨 Un pulsante «Abbonati» che non porta da nessuna parte è
        peggio di nessun pulsante: fa sembrare rotto proprio il momento in cui
        qualcuno stava per pagare.
    --}}
    <h2>Come si comincia</h2>
    <p class="nota">
        Scarica l'app e registrati. Per i piani a pagamento, scrivici: l'attivazione
        è ancora manuale.
    </p>
@endsection

