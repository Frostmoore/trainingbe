{{--
    L'invito di un trainer — 22/09/2026.

    Corta: chi la legge deve capire da chi arriva e premere il pulsante.
    Le tre righe di «cosa ottieni» sono le stesse della schermata dell'invito
    nell'app, e devono restare uguali: chi legge la mail e poi apre l'app non
    deve trovarsi davanti due promesse diverse.
--}}
<x-mail::message>
# {{ $nomeTrainer }} ti ha invitato

**{{ $nomeTrainer }}** ti ha invitato a farti seguire su Training Companion.
Accettando, diventa il tuo trainer.

<x-mail::button :url="$link">
Accetta l'invito
</x-mail::button>

- **Ti manda le schede**: arrivano sul telefono e le usi in palestra, anche senza rete.
- **Potete scrivervi senza limiti**: i messaggi li leggete solo voi due, nemmeno noi possiamo aprirli.
- **I tuoi dati restano tuoi**: allenamenti, diario e misure stanno sul tuo telefono. Lui vede il tuo nome, la tua email e la foto del profilo.

@if ($scade)
L'invito vale fino al **{{ $scade }}** e si usa una volta sola.
@else
L'invito si usa una volta sola.
@endif

Se non sai chi sia, ignora questo messaggio: senza il tuo tocco non succede niente.

<x-slot:subcopy>
Se il pulsante non funziona, copia questo indirizzo nel browser del telefono:
[{{ $link }}]({{ $link }})
</x-slot:subcopy>
</x-mail::message>
