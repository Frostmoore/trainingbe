{{--
    L'invito di una palestra — 23/09/2026, fase F5.5.

    Corta: chi la legge deve capire da chi arriva e premere il pulsante.
    Tutto quello che non è il pulsante allontana dal pulsante.

    ⚠️ Niente nome di persona: l'invito è legato a un indirizzo, non a
    un'anagrafica.
--}}
<x-mail::message>
# {{ $palestra }} ti ha invitato

@if ($comeTrainer)
Ti hanno invitato a **lavorare come trainer** su Training Companion.
@else
Ti hanno invitato a **entrare come iscritto** su Training Companion.
@endif

<x-mail::button :url="$link">
Accetta l'invito
</x-mail::button>

Il link vale **per te soltanto**, si usa **una volta sola** e scade fra
**{{ $giorni }} giorni**.

@if (! $comeTrainer)
Se hai già un account, accettando entrerai in questa palestra con quello: i tuoi
allenamenti restano dove sono.
@endif

Se non ti aspettavi questo messaggio, puoi ignorarlo: senza il link non succede
niente.

Buon allenamento,<br>
Training Companion
</x-mail::message>
