{{--
    Un regalo arrivato, o un'offerta da usare — P8 di plan_promozioni.md.

    ⚠️ Nome e descrizione li scrive una persona dal pannello: sempre escapati.
--}}
<x-mail::message>
# Ciao {{ $nome }},

@if ($ricevuta)
hai appena ricevuto **{{ $titolo }}**: {{ $cosa }}.
@else
c'è un'offerta per te, **{{ $titolo }}**: {{ $cosa }}.
@endif

@if ($descrizione)
{!! nl2br(e($descrizione)) !!}
@endif

@if (! $ricevuta)
@if ($link)
<x-mail::button :url="$link">
Attivala
</x-mail::button>

Una volta attivata, lo sconto si applica da solo al prossimo pagamento.@if ($scade) Vale fino al {{ $scade }}.@endif
@else
Si applica da sola al prossimo pagamento nell'app.@if ($scade) Vale fino al {{ $scade }}.@endif
@endif
@endif

—<br>
Training Companion
</x-mail::message>
