@extends('sito.layout')

@section('titolo', 'Invito — Training Companion')

{{--
    L'atterraggio di un invito personale — F6.2.

    🚨 **Un solo messaggio per tutti i casi negativi**: scaduto, già usato,
    revocato e inesistente dicono la stessa cosa. Distinguerli permetterebbe di
    provare token a tappeto e capire quali sono esistiti, e quando.
--}}
@section('contenuto')
    @if ($valido)
        <h1>{{ $trainer ? $trainer.' ti ha invitato' : 'Sei stato invitato' }}</h1>
        <p class="sottotitolo">
            Apri l'app Training Companion e tocca «Ho un invito». Questo link vale
            <strong>una volta sola</strong> e scade fra pochi giorni.
        </p>
    @else
        <h1>Questo invito non è più valido</h1>
        <p class="sottotitolo">
            Può essere scaduto o già stato usato. Chiedi al tuo trainer di
            mandartene uno nuovo.
        </p>
    @endif
@endsection
