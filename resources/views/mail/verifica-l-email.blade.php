{{--
    La mail di conferma dell'indirizzo — voce 62.6, 28/09/2026.

    ⛔ Non minaccia niente e non mette fretta: l'accesso NON dipende da questa
    mail, e scriverlo sarebbe falso (voce 63). Dice a cosa serve e che scade.
--}}
<x-mail::message>
# Ciao {{ $nome }},

per farti vedere gli **inviti che hai ricevuto** dobbiamo essere sicuri che questo
indirizzo sia davvero tuo.

<x-mail::button :url="$link">
Conferma il tuo indirizzo
</x-mail::button>

Il link vale **{{ $minuti }} minuti**.

Se non l'hai chiesto tu puoi ignorare questo messaggio: **non cambia niente**, e
continui a entrare nell'app come hai sempre fatto.

Grazie,<br>
Il team di Training Companion
</x-mail::message>
