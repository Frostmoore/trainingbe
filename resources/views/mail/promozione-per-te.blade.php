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

<small>
Ricevi questa email perché sei iscritto a Training Companion e possiamo proporti offerte sui servizi
che usi già. **Non vuoi più riceverle?** [Basta un clic]({{ $disiscriviti }}).

Continuerai a ricevere gli avvisi di servizio e le email necessarie a usare il servizio — password,
inviti, ricevute — e le promozioni che ti spettano restano tue: quello che si spegne è l'annuncio.
</small>
</x-mail::message>
