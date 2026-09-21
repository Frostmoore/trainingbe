{{--
    Una mail scritta a mano dal pannello — 22/09/2026, fase F4.5.

    Il testo lo scrive una persona, quindi qui non si aggiunge niente che non
    sia stato scritto: nessuna promozione in coda, nessun invito a scaricare
    l'app. Chi manda una mail di supporto ha già detto tutto quello che voleva.

    ⚠️ `nl2br(e($testo))`: l'a capo va tenuto, ma il testo NON è HTML. In
    Markdown una riga a capo sola sparisce, e un messaggio scritto in tre
    paragrafi arriverebbe come un muro.
--}}
<x-mail::message>
# Ciao {{ $nome }},

{!! nl2br(e($testo)) !!}

@if ($daParteDi)
—<br>
{{ $daParteDi }}<br>
Training Companion
@else
Training Companion
@endif
</x-mail::message>
