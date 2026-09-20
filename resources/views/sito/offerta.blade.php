{{--
    La pagina di un'offerta su misura - 21/09/2026.

    Il committente: "certo che il piano cucito a mano deve essere fatto con
    stripe". Questa e' la pagina che il cliente apre dal link concordato: vede
    cosa ha davanti, quanto costa, e paga.

    Perche' ripete i numeri del piano
    ---------------------------------

    Perche' l'accordo e' stato preso a voce o per mail, e chi paga deve
    ritrovare qui gli stessi numeri: posti, prezzo, minimo. Un pulsante
    "attiva" senza le cifre accanto e' un pulsante che nessuno preme.
--}}
@extends('sito.layout')

@section('titolo', 'Il tuo piano — Training Companion')
@section('descrizione', 'Il piano concordato per la tua attività, pronto da attivare.')

@section('contenuto')

    <section>
        <div class="contenitore">

            @if (session('avviso'))
                <div class="avviso" style="max-width: 760px;">{{ session('avviso') }}</div>
            @endif

            <div class="griglia-2" style="align-items: start;">

                {{-- ── il piano ──────────────────────────────────────────── --}}
                <div class="scheda scheda-prezzo scelta">
                    <p class="occhiello" style="margin-top: 0;">Concordato per te</p>
                    <h3 style="margin-top: 0;">{{ $piano->name }}</h3>

                    @if ($piano->aConsumo())
                        <p class="cifra">{{ $formatta((int) $piano->prezzo_iscritto_cent) }}<small> / {{ $ePalestra ? 'iscritto' : 'allievo' }}</small></p>
                        <p class="sotto-cifra">
                            più {{ $formatta((int) ($piano->canone_cent ?? 0)) }} al mese
                            @if ($piano->minimo_iscritti)
                                · minimo {{ $piano->minimo_iscritti }} {{ $unita }}
                            @endif
                        </p>
                    @else
                        <p class="cifra">{{ $formatta((int) $piano->price_cents) }}<small> / mese</small></p>
                        <p class="sotto-cifra">IVA inclusa · si disdice quando vuoi</p>
                    @endif

                    <ul class="elenco">
                        @if ($piano->max_members === null)
                            <li><strong>{{ ucfirst($unita) }} senza tetto</strong>: si paga quello che si usa</li>
                        @else
                            <li><strong>{{ $piano->max_members }} {{ $unita }}</strong> abbonati</li>
                        @endif

                        @if ($piano->max_trainers !== null)
                            <li><strong>{{ $piano->max_trainers }} trainer</strong> al lavoro</li>
                        @endif

                        <li>Ognuno riceve <strong>il piano Plus intero</strong>: allenamenti e schede senza limiti, sonno, recupero, progressi</li>
                        <li>Tu scrivi schede e piani senza limiti, con una chat cifrata per ognuno</li>

                        @if ($ePalestra)
                            <li>L'app vestita coi colori e il logo della palestra</li>
                        @endif
                    </ul>

                    @if ($offerta->scade_il && $valida)
                        <p class="nota" style="margin-bottom: 0;">
                            ⚠️ Questo prezzo vale fino al <strong>{{ $offerta->scade_il->format('d/m/Y') }}</strong>.
                        </p>
                    @endif
                </div>

                {{-- ── cosa fare ─────────────────────────────────────────── --}}
                <div>
                    @if (! $valida)
                        <p class="occhiello">Questo link</p>
                        <h1 style="font-size: clamp(30px, 4.4vw, 42px);">Non si può più usare.</h1>

                        <p class="guida">{{ $motivo }}</p>

                        <div class="fila-bottoni" style="margin-top: var(--sp-3);">
                            <a href="/scrivici?tipo={{ $ePalestra ? 'palestra' : 'trainer' }}"
                               class="bottone bottone-pieno bottone-grande">Scrivici</a>
                            <a href="/admin/login" class="bottone bottone-vuoto bottone-grande">Vai al pannello</a>
                        </div>
                    @else
                        <p class="occhiello">Il tuo piano</p>
                        <h1 style="font-size: clamp(30px, 4.4vw, 42px);">
                            {{ $giaDentro ? 'Tutto pronto: manca il pagamento.' : 'Attivalo in due minuti.' }}
                        </h1>

                        <p class="guida" style="margin-bottom: var(--sp-3);">
                            @if ($giaDentro)
                                Il pagamento lo gestisce Stripe: la carta la scrivi da loro, noi non la
                                vediamo e non la conserviamo.
                            @elseif ($offerta->tenant_id !== null)
                                Entra col tuo account di titolare e torna qui: il piano è già pronto per
                                la tua {{ $ePalestra ? 'palestra' : 'attività' }}.
                            @else
                                Questi dati, poi il pagamento su Stripe. Dopo ti ritrovi nel pannello,
                                già dentro.
                            @endif
                        </p>

                        @if ($errors->any())
                            <div class="avviso">
                                <strong>Controlla questi punti:</strong>
                                <ul style="margin: var(--sp-1) 0 0; padding-left: 20px;">
                                    @foreach ($errors->all() as $errore)
                                        <li>{{ $errore }}</li>
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if ($giaDentro || $offerta->tenant_id === null)
                            <form method="POST" action="/offerta/{{ $offerta->token }}" class="modulo">
                                @csrf

                                @unless ($giaDentro)
                                    @if ($ePalestra)
                                        <div class="campo">
                                            <label for="attivita">Nome della palestra</label>
                                            <input type="text" id="attivita" name="attivita"
                                                   value="{{ old('attivita') }}" required autocomplete="organization">
                                        </div>
                                    @endif

                                    <div class="campo">
                                        <label for="nome">{{ $ePalestra ? 'Il tuo nome e cognome' : 'Nome e cognome' }}</label>
                                        <input type="text" id="nome" name="nome"
                                               value="{{ old('nome') }}" required autocomplete="name">
                                    </div>

                                    <div class="campo">
                                        <label for="email">Email</label>
                                        <input type="email" id="email" name="email"
                                               value="{{ old('email', $offerta->email) }}" required autocomplete="email">
                                        <span class="aiuto">Serve per entrare nel pannello e per le ricevute.</span>
                                    </div>

                                    <div class="campo">
                                        <label for="password">Password</label>
                                        <input type="password" id="password" name="password"
                                               required autocomplete="new-password">
                                        <span class="aiuto">Almeno 8 caratteri, con lettere e numeri.</span>
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
                                @endunless

                                <div>
                                    <button type="submit" class="bottone bottone-pieno bottone-grande">
                                        Vai al pagamento
                                    </button>
                                </div>

                                <p class="nota" style="margin: 0;">
                                    Il pagamento lo gestisce Stripe: la carta la scrivi da loro, noi non la
                                    vediamo e non la conserviamo. Si disdice dal pannello, quando vuoi.
                                    @if ($piano->aConsumo())
                                        ⚠️ Su questo piano si salva la carta e si fattura a <strong>mese chiuso</strong>,
                                        su quanti {{ $unita }} hai davvero avuto.
                                    @endif
                                </p>
                            </form>
                        @else
                            <div class="fila-bottoni">
                                <a href="/admin/login" class="bottone bottone-pieno bottone-grande">Entra nel pannello</a>
                                <a href="/scrivici?tipo={{ $ePalestra ? 'palestra' : 'trainer' }}"
                                   class="bottone bottone-vuoto bottone-grande">Scrivici</a>
                            </div>
                        @endif
                    @endif
                </div>

            </div>
        </div>
    </section>

@endsection
