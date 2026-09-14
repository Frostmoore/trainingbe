{{--
    Quando siamo noi ad aver sbagliato — 14/09/2026.

    ⚠️ **Dice che è colpa nostra, e non chiede di riprovare all'infinito.** Un
    «riprova più tardi» senza dire che il guasto lo vediamo anche noi lascia
    pensare che la persona debba fare qualcosa.

    ⛔ **Niente dettagli tecnici**: il codice dell'errore non aiuta chi legge, e
    a noi arriva dal log.
--}}
@extends('sito.layout')

@section('titolo', 'Qualcosa non ha funzionato — Training Companion')
@section('descrizione', 'Un errore dalla nostra parte.')

@section('contenuto')
    <section>
        <div class="contenitore stretto" style="text-align: center;">
            <div class="occhiello">Errore</div>

            <h1>Qualcosa non ha funzionato.</h1>

            <p class="guida" style="margin: 0 auto var(--sp-4);">
                È un guasto dalla nostra parte, non tuo. Lo vediamo anche noi:
                non c'è niente che tu debba fare, se non riprovare fra poco.
            </p>

            <a href="/" class="bottone bottone-pieno bottone-grande">Torna alla home</a>
        </div>
    </section>
@endsection
