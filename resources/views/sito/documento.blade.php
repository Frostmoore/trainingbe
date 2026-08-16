@extends('sito.layout')

@section('titolo', $titolo.' — Training Companion')
@section('descrizione', $titolo.' di Training Companion.')

@section('contenuto')
    {{--
        🚨 **Il documento si rende dal suo sorgente, non si riscrive in HTML.**

        Un testo legale copiato in una vista è un testo che diverge dal
        documento che lo genera, e il giorno che qualcuno aggiorna l'uno e non
        l'altro nessuno se ne accorge — perché nessuno rilegge una pagina
        legale per controllo.

        💡 `resources/legal/*.md` è **la** versione pubblicata: sta nel
        repository che viene deployato, quindi ciò che si legge sul sito e ciò
        che sta in git sono la stessa cosa per costruzione.
    --}}
    <section>
        <div class="contenitore documento">
            {!! $corpo !!}

            <hr>
            <p class="nota">
                Hai una domanda su questo documento? Scrivici prima di accettare qualcosa
                che non ti torna: è il momento in cui serve.
            </p>
        </div>
    </section>
@endsection
