{{--
    Il modulo d'iscrizione — 20/09/2026.

    ── 💡 Perché è corto così ─────────────────────────────────────────────────

    Perché ogni campo in più è gente che se ne va, e perché qui **non serve**:
    nome utente, telefono e partita IVA non li chiediamo (il nome utente lo
    ricava `IscrizioneDalSito` dall'indirizzo, i dati di fatturazione li chiede
    Stripe al pagamento). Trainerize, che vende la stessa cosa, ne chiede tre:
    nome dell'attività, email, password.

    🚨 **Il piano è già scelto e si vede**: chi arriva qui ha premuto «Abbonati»
    su una scheda, e deve ritrovare quella scheda — prezzo compreso — accanto al
    modulo. Un modulo che non ripete cosa si sta comprando fa tornare indietro a
    controllare, e chi torna indietro spesso non torna avanti.
--}}
@extends('sito.layout')

@section('titolo', 'Iscriviti a '.$piano->name.' — Training Companion')
@section('descrizione', 'Apri il tuo spazio su Training Companion e attiva il piano '.$piano->name.'.')

@section('contenuto')

    <section>
        <div class="contenitore">
            <div class="griglia-2" style="align-items: start;">

                {{-- ── il modulo ─────────────────────────────────────────── --}}
                <div>
                    <p class="occhiello">Iscrizione</p>
                    <h1 style="font-size: clamp(30px, 4.4vw, 42px);">
                        {{ $ePalestra ? 'Apri lo spazio della tua palestra.' : 'Apri il tuo spazio da trainer.' }}
                    </h1>

                    <p class="guida" style="margin-bottom: var(--sp-3);">
                        Ci vogliono due minuti: questi dati, e poi il pagamento su Stripe. Dopo
                        ti ritrovi nel pannello, già dentro.
                    </p>

                    @if ($errors->any())
                        {{-- ⚠️ Gli errori **anche in cima**, non solo sotto ai campi: su un
                             telefono il campo sbagliato può essere fuori schermo, e un modulo
                             che «non fa niente» quando si preme il pulsante sembra rotto. --}}
                        <div class="avviso">
                            <strong>Controlla questi punti:</strong>
                            <ul style="margin: var(--sp-1) 0 0; padding-left: 20px;">
                                @foreach ($errors->all() as $errore)
                                    <li>{{ $errore }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <form method="POST" action="/iscriviti/{{ $piano->code }}" class="modulo">
                        @csrf

                        @if ($ePalestra)
                            <div class="campo">
                                <label for="attivita">Nome della palestra</label>
                                <input type="text" id="attivita" name="attivita"
                                       value="{{ old('attivita') }}" required autocomplete="organization">
                                <span class="aiuto">È il nome che vedranno i tuoi iscritti dentro l'app.</span>
                            </div>
                        @endif

                        <div class="campo">
                            <label for="nome">{{ $ePalestra ? 'Il tuo nome e cognome' : 'Nome e cognome' }}</label>
                            <input type="text" id="nome" name="nome"
                                   value="{{ old('nome') }}" required autocomplete="name">
                            @if (! $ePalestra)
                                <span class="aiuto">È il nome con cui ti vedranno i tuoi {{ $unita }}.</span>
                            @endif
                        </div>

                        <div class="campo">
                            <label for="email">Email</label>
                            <input type="email" id="email" name="email"
                                   value="{{ old('email') }}" required autocomplete="email">
                            <span class="aiuto">Serve per entrare nel pannello e per le ricevute.</span>
                        </div>

                        <div class="campo">
                            <label for="password">Password</label>
                            <input type="password" id="password" name="password"
                                   required autocomplete="new-password">
                            <span class="aiuto">Almeno 8 caratteri, con lettere e numeri. Più è lunga, meglio è.</span>
                        </div>

                        <div class="campo">
                            <label for="password_confirmation">Ripeti la password</label>
                            <input type="password" id="password_confirmation" name="password_confirmation"
                                   required autocomplete="new-password">
                        </div>

                        <label class="campo-spunta">
                            <input type="checkbox" name="accetto" value="1" @checked(old('accetto'))>
                            <span>
                                Ho più di 18 anni e accetto le
                                <a href="/condizioni" target="_blank" rel="noopener">condizioni d'uso</a>;
                                ho letto l'<a href="/privacy" target="_blank" rel="noopener">informativa privacy</a>.
                                🚨 Prima di vedere i dati di qualcuno firmerai anche la
                                <a href="/{{ $ePalestra ? 'liberatoria-palestra' : 'liberatoria-trainer' }}" target="_blank" rel="noopener">liberatoria dell'art. 28</a>,
                                nel pannello.
                            </span>
                        </label>

                        <div>
                            <button type="submit" class="bottone bottone-pieno bottone-grande">
                                Vai al pagamento
                            </button>
                        </div>

                        <p class="nota" style="margin: 0;">
                            Il pagamento lo gestisce Stripe: la carta la scrivi da loro, noi non la
                            vediamo e non la conserviamo. Si disdice dal pannello, quando vuoi.
                        </p>
                    </form>
                </div>

                {{-- ── il piano scelto ───────────────────────────────────── --}}
                <div class="scheda scheda-prezzo scelta">
                    <p class="occhiello" style="margin-top: 0;">Stai attivando</p>
                    <h3 style="margin-top: 0;">{{ $piano->name }}</h3>
                    <p class="cifra">{{ $formatta((int) $piano->price_cents) }}<small> / mese</small></p>
                    <p class="sotto-cifra">IVA inclusa · si disdice quando vuoi</p>

                    <ul class="elenco">
                        <li><strong>{{ $piano->max_members }} {{ $unita }}</strong> abbonati</li>
                        @if ($piano->max_trainers !== null)
                            <li><strong>{{ $piano->max_trainers }} trainer</strong> al lavoro</li>
                        @endif
                        <li>
                            Ognuno riceve l'app intera, assistente compreso
                            ({{ number_format($chiamateMensili, 0, ',', '.') }} elaborazioni al mese)
                        </li>
                        <li>Schede e piani illimitati, chat cifrata con ognuno</li>
                        @if ($ePalestra)
                            <li>L'app vestita coi colori e il logo della palestra</li>
                        @endif
                    </ul>

                    <div class="in-fondo">
                        <a href="{{ $tornaA }}" class="bottone bottone-vuoto" style="width: 100%;">
                            Guarda gli altri piani
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </section>

@endsection
