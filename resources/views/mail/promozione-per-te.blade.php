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
Si applica da sola al prossimo pagamento nell'app.@if ($scade) Vale fino al {{ $scade }}.@endif
@endif

—<br>
Training Companion
</x-mail::message>
