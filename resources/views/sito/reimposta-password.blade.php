{{--
    La pagina della password nuova — 22/09/2026, fase F4.

    Non dice mai di chi e' il link: niente nome, niente email. Chi apre un link
    trovato in giro non deve scoprire a chi appartiene.
--}}
@extends('sito.layout')

@section('titolo', 'Rifai la password — Training Companion')
@section('descrizione', 'Scegli una password nuova per il tuo account Training Companion.')

@section('contenuto')

    <section>
        <div class="contenitore stretto">

            @if (session('avviso'))
                <div class="avviso">{{ session('avviso') }}</div>
            @endif

            @if (! $valido)
                <p class="occhiello">Password</p>
                <h1 style="font-size: clamp(30px, 4.4vw, 42px);">Questo link non vale più.</h1>

                <p class="guida">
                    I link per rifare la password durano un'ora e si usano una volta sola: è
                    quello che impedisce a chi lo trovasse dopo di entrare al posto tuo.
                </p>

                <div class="fila-bottoni" style="margin-top: var(--sp-3);">
                    <a href="/admin/login" class="bottone bottone-pieno bottone-grande">Vai all'accesso</a>
                    <a href="/scrivici" class="bottone bottone-vuoto bottone-grande">Scrivici</a>
                </div>
            @else
                <p class="occhiello">Password</p>
                <h1 style="font-size: clamp(30px, 4.4vw, 42px);">Scegline una nuova.</h1>

                <p class="guida" style="margin-bottom: var(--sp-3);">
                    Almeno 8 caratteri, con lettere e numeri. Più è lunga, meglio è: la
                    lunghezza conta molto più dei caratteri strani.
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

                <form method="POST" action="/reimposta-password/{{ $token }}" class="modulo">
                    @csrf

                    <div class="campo">
                        <label for="password">Password nuova</label>
                        <input type="password" id="password" name="password"
                               required autocomplete="new-password" autofocus>
                    </div>

                    <div class="campo">
                        <label for="password_confirmation">Ripetila</label>
                        <input type="password" id="password_confirmation" name="password_confirmation"
                               required autocomplete="new-password">
                    </div>

                    <div>
                        <button type="submit" class="bottone bottone-pieno bottone-grande">
                            Salva la password
                        </button>
                    </div>

                    <p class="nota" style="margin: 0;">
                        ⚠️ Cambiandola, le sessioni già aperte si chiudono: dovrai rientrare
                        anche dall'app.
                    </p>
                </form>
            @endif

        </div>
    </section>

@endsection
