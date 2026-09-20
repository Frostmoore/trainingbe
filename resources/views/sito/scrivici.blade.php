{{--
    Il modulo di contatto - 21/09/2026.

    Il committente, sul piano su misura: "tanto c'e' scrivici. Mettici un
    modulo di contatto cosi' mi scrivono".

    Perche' chiede il numero di iscritti e di trainer
    ------------------------------------------------

    Perche' sono le due domande da cui dipende il preventivo, e chiederle qui
    evita il giro di mail che le chiederebbe dopo. Non sono obbligatorie: chi
    non le sa ancora non deve restare fuori dal modulo.

    Il campo trappola
    -----------------

    "sito_web" e' nascosto via CSS e deve restare vuoto. Non e' JavaScript,
    quindi non viola la regola del sito senza script; chi lo compila riceve
    comunque la pagina del grazie, e nessuna riga viene scritta.
--}}
@extends('sito.layout')

@section('titolo', 'Scrivici — Training Companion')
@section('descrizione', 'Raccontaci cosa ti serve: piani su misura per palestre e trainer, con il numero di posti e di istruttori che vuoi tu.')

@section('contenuto')

    <section>
        <div class="contenitore">

            @if ($fatto)
                {{-- La conferma prende il posto del modulo: chi ha scritto non
                     deve chiedersi se deve scrivere di nuovo. --}}
                <div style="max-width: 640px;">
                    <p class="occhiello">Ricevuto</p>
                    <h1 style="font-size: clamp(30px, 4.4vw, 42px);">Grazie, ti scriviamo noi.</h1>

                    <p class="guida">
                        La tua richiesta è arrivata. Rispondiamo di solito entro un giorno
                        lavorativo, all'indirizzo che ci hai lasciato — da una persona, non da
                        un automatismo.
                    </p>

                    <div class="fila-bottoni" style="margin-top: var(--sp-3);">
                        <a href="/" class="bottone bottone-pieno bottone-grande">Torna alla home</a>
                        <a href="/prezzi/palestra" class="bottone bottone-vuoto bottone-grande">Guarda i piani</a>
                    </div>
                </div>
            @else
                <div class="griglia-2" style="align-items: start;">

                    {{-- ── il modulo ─────────────────────────────────────── --}}
                    <div>
                        <p class="occhiello">Scrivici</p>
                        <h1 style="font-size: clamp(30px, 4.4vw, 42px);">
                            Ti serve qualcosa che il listino non ha?
                        </h1>

                        <p class="guida" style="margin-bottom: var(--sp-3);">
                            Più posti, più istruttori, un accordo diverso: si fa. Dicci quanto sei
                            grande e ti rispondiamo con un preventivo vero, non con un
                            «dipende».
                        </p>

                        @if ($errors->any())
                            {{-- Gli errori anche in cima, non solo sotto ai campi: su un
                                 telefono il campo sbagliato puo' essere fuori schermo. --}}
                            <div class="avviso">
                                <strong>Controlla questi punti:</strong>
                                <ul style="margin: var(--sp-1) 0 0; padding-left: 20px;">
                                    @foreach ($errors->all() as $errore)
                                        <li>{{ $errore }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        <form method="POST" action="/scrivici" class="modulo">
                            @csrf

                            <div class="campo">
                                <label for="tipo">Chi sei</label>
                                <select id="tipo" name="tipo" required>
                                    @foreach (\App\Enums\TipoDiRichiesta::cases() as $caso)
                                        <option value="{{ $caso->value }}"
                                            @selected((old('tipo') ?? $tipo->value) === $caso->value)>
                                            {{ $caso->comeSiPresenta() }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="campo">
                                <label for="nome">Nome e cognome</label>
                                <input type="text" id="nome" name="nome"
                                       value="{{ old('nome') }}" required autocomplete="name">
                            </div>

                            <div class="campo">
                                <label for="organizzazione">Nome della palestra o dell'attività</label>
                                <input type="text" id="organizzazione" name="organizzazione"
                                       value="{{ old('organizzazione') }}" autocomplete="organization">
                                <span class="aiuto">Se non ce l'hai ancora, lascialo vuoto.</span>
                            </div>

                            <div class="campo">
                                <label for="email">Email</label>
                                <input type="email" id="email" name="email"
                                       value="{{ old('email') }}" required autocomplete="email">
                                <span class="aiuto">È l'indirizzo a cui ti rispondiamo.</span>
                            </div>

                            <div class="campo">
                                <label for="telefono">Telefono</label>
                                <input type="text" id="telefono" name="telefono"
                                       value="{{ old('telefono') }}" autocomplete="tel">
                                <span class="aiuto">Facoltativo. Per certe cose una telefonata è più corta di dieci mail.</span>
                            </div>

                            <div class="griglia-2" style="gap: var(--sp-2);">
                                <div class="campo">
                                    <label for="iscritti">Quanti iscritti</label>
                                    <input type="text" inputmode="numeric" id="iscritti" name="iscritti"
                                           value="{{ old('iscritti') }}">
                                    <span class="aiuto">Quelli a cui daresti l'abbonamento.</span>
                                </div>

                                <div class="campo">
                                    <label for="trainer">Quanti trainer</label>
                                    <input type="text" inputmode="numeric" id="trainer" name="trainer"
                                           value="{{ old('trainer') }}">
                                    <span class="aiuto">Gli istruttori che devono lavorarci dentro.</span>
                                </div>
                            </div>

                            <div class="campo">
                                <label for="messaggio">Cosa ti serve</label>
                                <textarea id="messaggio" name="messaggio" rows="5" required>{{ old('messaggio') }}</textarea>
                                <span class="aiuto">Anche due righe: bastano per capire e risponderti bene.</span>
                            </div>

                            {{-- Il campo trappola. Nascosto, e senza JavaScript. --}}
                            <div class="trappola" aria-hidden="true">
                                <label for="sito_web">Sito web</label>
                                <input type="text" id="sito_web" name="sito_web" value="" tabindex="-1" autocomplete="off">
                            </div>

                            <label class="campo-spunta">
                                <input type="checkbox" name="accetto" value="1" @checked(old('accetto'))>
                                <span>
                                    Ho letto l'<a href="/privacy" target="_blank" rel="noopener">informativa privacy</a>
                                    e acconsento al trattamento di questi dati per ricevere una risposta.
                                </span>
                            </label>

                            <div>
                                <button type="submit" class="bottone bottone-pieno bottone-grande">
                                    Manda la richiesta
                                </button>
                            </div>

                            <p class="nota" style="margin: 0;">
                                Quello che scrivi qui serve a risponderti, e a nient'altro: niente
                                newsletter, niente pubblicità, niente giro ad altri. Lo teniamo
                                24 mesi.
                            </p>
                        </form>
                    </div>

                    {{-- ── cosa si puo' chiedere ─────────────────────────── --}}
                    <div class="scheda">
                        <h3 style="margin-top: 0;">Cosa si può concordare</h3>

                        <ul class="elenco">
                            <li><strong>Più posti trainer</strong> di quelli del listino: i piani ne danno da 2 a 20, sopra si decide insieme</li>
                            <li><strong>Numeri grandi di iscritti</strong>, con il prezzo a persona che scende</li>
                            <li><strong>Più sedi</strong> della stessa insegna</li>
                            <li><strong>Fatturazione</strong> e tempi di pagamento diversi dal mensile con carta</li>
                        </ul>

                        <p class="nota">
                            ⚠️ Quello che <strong>non</strong> si concorda è l'accesso ai dati
                            sanitari dei tuoi iscritti: peso, misure e diario restano sul loro
                            telefono, per chiunque, a qualunque prezzo.
                            <a href="/professionisti#responsabilita">Qui c'è il perché</a>.
                        </p>

                        <div class="in-fondo">
                            <a href="/prezzi/palestra" class="bottone bottone-vuoto" style="width: 100%;">
                                Prima guarda i piani
                            </a>
                        </div>
                    </div>

                </div>
            @endif

        </div>
    </section>

@endsection
