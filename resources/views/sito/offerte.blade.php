@extends('sito.layout')

@section('titolo', 'Offerte — Training Companion')

{{--
    Le offerte pubbliche — P8 di plan_promozioni.md (opzione 38).

    ⚠️ Solo quelle per tutti e senza condizioni: le altre sono mirate e qui
    sarebbero una promessa a chi non le può avere. Nome e descrizione li scrive
    una persona dal pannello: sempre escapati.
--}}
@section('contenuto')
    <h1>Offerte</h1>

    @if ($offerte->isEmpty())
        <p class="sottotitolo">In questo momento non ci sono offerte aperte a tutti. Ripassa presto.</p>
    @else
        <p class="sottotitolo">Si applicano da sole nell'app, al momento di pagare.</p>

        @foreach ($offerte as $offerta)
            <section style="margin-top: 1.5rem;">
                <h2>{{ $offerta->nome }}</h2>
                <p><strong>{{ ucfirst($offerta->scrittoBene()) }}</strong>@if ($offerta->scade_il) — fino al {{ $offerta->scade_il->format('d/m/Y') }}@endif</p>
                @if ($offerta->descrizione)
                    <p>{!! nl2br(e($offerta->descrizione)) !!}</p>
                @endif
            </section>
        @endforeach
    @endif
@endsection
