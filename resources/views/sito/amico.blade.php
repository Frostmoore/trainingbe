@extends('sito.layout')

@section('titolo', 'Un amico ti ha invitato — Training Companion')

{{--
    «Porta un amico», per chi non ha l'app — P8 di plan_promozioni.md.

    🚨 Non collega niente: il codice lo scrive l'app dopo l'iscrizione
    (`POST /api/v1/account/amico`). Qui si dice solo chi ti ha invitato e cosa
    scrivere.
--}}
@section('contenuto')
    @if ($valido)
        <h1>{{ $nome }} ti ha invitato</h1>
        <p class="sottotitolo">
            Scarica l'app Training Companion e iscriviti. Quando te lo chiede,
            scrivi il codice amico <strong>{{ $codice }}</strong>: se c'è un'offerta
            per chi arriva con un amico, la ricevete tutti e due.
        </p>
    @else
        <h1>Questo codice non esiste</h1>
        <p class="sottotitolo">
            Controlla di aver copiato il link per intero, o chiedi a chi ti ha
            invitato di mandartelo di nuovo.
        </p>
    @endif
@endsection
