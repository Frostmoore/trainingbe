@extends('sito.layout')

@section('titolo', 'Invito — Training Companion')

{{--
    L'atterraggio di un invito di una PALESTRA — 3b-V.3.1.

    🚨 **Qui ci arriva solo chi NON ha l'app.** Su un telefono con l'app
    installata, Android apre direttamente la schermata dell'invito (App Links).
    Chi arriva qui è su un computer, o su un telefono senza l'app — cioè
    esattamente la persona che l'invito deve convincere.

    💡 Per questo si mostrano **le stesse cose** della schermata dell'app, e non
    un semplice «scarica l'app»: chi legge non sa ancora se gli interessa.

    ⛔ **Un solo messaggio per tutti i casi negativi**: scaduto, revocato, già
    usato, rifiutato e inesistente dicono la stessa cosa. Distinguerli
    permetterebbe di provare token a tappeto e capire quali sono esistiti.
--}}
@section('contenuto')
    @if ($valido)
        <h1>{{ $palestra ? $palestra.' ti ha invitato' : 'Sei stato invitato' }}</h1>

        @if ($descrizione)
            <p class="sottotitolo">{{ $descrizione }}</p>
        @endif

        @if (count($cosaOttieni) > 0)
            <h2>Cosa avrai</h2>
            <ul>
                @foreach ($cosaOttieni as $riga)
                    <li>
                        <strong>{{ $riga['titolo'] }}</strong><br>
                        {{ $riga['dettaglio'] }}
                    </li>
                @endforeach
            </ul>
        @endif

        {{--
            🚨 **Due tasti, e coprono due persone diverse.**

            · Chi l'app CE L'HA GIÀ: tocca «Apri nell'app» e ci arriva subito.
              ⚠️ Su un telefono con l'app installata gli App Links di solito
              aprono l'app da soli e qui non ci arriva nessuno — ma «di solito»
              non è «sempre»: la verifica può non essere ancora passata, o il
              link può essere stato aperto da dentro un'altra app che se lo
              tiene. Senza questo tasto quella persona resta bloccata su una
              pagina che le dice di installare una cosa che ha già.

            · Chi NON ce l'ha: va allo store, e il token viaggia con lui.
        --}}
        <p>
            <a class="bottone" href="{{ url('/invito-palestra/'.request()->route('token')) }}">
                Apri nell'app
            </a>
        </p>

        <p>
            <a class="bottone" href="{{ $store }}">Installa l'app</a>
        </p>

        <p class="sottotitolo">
            L'invito vale <strong>una volta sola</strong> e scade fra pochi
            giorni. Dopo l'installazione l'app si apre già qui: se non succede,
            torna su questa pagina e tocca «Apri nell'app».
        </p>
    @else
        <h1>Questo invito non è più valido</h1>
        <p class="sottotitolo">
            Può essere scaduto, o già stato usato da qualcun altro. Chiedi alla
            palestra di mandartene uno nuovo.
        </p>
    @endif
@endsection
