{{--
    Il benvenuto — 22/09/2026.

    Tre paragrafi e un pulsante: chi ha appena aperto un account vuole sapere
    cosa può fare adesso, non leggere una presentazione.
--}}
<x-mail::message>
# Ciao {{ $nome }},

il tuo account su **Training Companion** è pronto.

@if ($tipo === 'palestra')
Da adesso puoi entrare nel pannello della tua palestra: invitare gli iscritti e
i trainer, scrivere le schede e seguire chi si allena. Gli iscritti useranno
l'app, vestita dei colori della tua palestra.

<x-mail::button :url="$pannello">
Entra nel pannello
</x-mail::button>
@elseif ($tipo === 'trainer')
Da adesso hai il tuo spazio: inviti i tuoi allievi con un link, scrivi le loro
schede e le mandi dalla chat. Il tuo giro resta tuo, anche se domani lavorerai
dentro una palestra.

<x-mail::button :url="$pannello">
Entra nel pannello
</x-mail::button>
@else
Da adesso puoi registrare gli allenamenti, tenere il diario di quello che mangi
e ricevere le schede di chi ti segue.

<x-mail::button :url="$sito">
Scopri cosa puoi fare
</x-mail::button>
@endif

**Dove stanno i tuoi dati.** Allenamenti, diario, misure e foto restano sul tuo
telefono: non li teniamo sui nostri server. Sul server restano il tuo account e
le conversazioni cifrate, che nemmeno noi possiamo aprire.

Se questo account non l'hai aperto tu, scrivici: lo chiudiamo subito.
</x-mail::message>
