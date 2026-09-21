@extends('sito.layout')

@section('titolo', 'Un\'offerta per te — Training Companion')

{{--
    Il link di attivazione di una promozione, per chi non ha l'app — 24/09/2026.

    ⛔ Non attiva niente: l'attivazione ha bisogno di sapere chi sei, e la fa
    l'app (o il pannello). Nome e descrizione li scrive una persona dal pannello:
    sempre escapati.
--}}
@section('contenuto')
    @if ($offerta)
        <h1>{{ $offerta->nome }}</h1>
        <p class="sottotitolo"><strong>{{ ucfirst($offerta->scrittoBene()) }}</strong></p>
        @if ($offerta->descrizione)
            <p>{!! nl2br(e($offerta->descrizione)) !!}</p>
        @endif
        <p>
            Apri questo link dal telefono, con l'app Training Companion installata,
            e tocca <strong>«Attiva»</strong>.
        </p>
        <p>
            Hai una palestra o sei un trainer?
            <a href="{{ $pannello }}">Attivala dal pannello</a>.
        </p>
    @else
        <h1>Questa offerta non è più disponibile</h1>
        <p class="sottotitolo">
            Può essere finita, esaurita o sospesa. Se pensi che sia un errore,
            scrivici.
        </p>
    @endif
@endsection
