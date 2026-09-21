{{--
    La mail del reset — 22/09/2026, fase F4.

    Corta di proposito: chi la legge ha un problema solo, e lo vuole risolvere
    in un tocco. Tutto quello che non è il pulsante allontana dal pulsante.
--}}
<x-mail::message>
# Ciao {{ $nome }},

hai chiesto di rifare la password di Training Companion.

<x-mail::button :url="$link">
Scegli una password nuova
</x-mail::button>

Il link vale **{{ $minuti }} minuti** e si usa una volta sola.

Se non sei stato tu, puoi ignorare questo messaggio: la password non cambia
finché non ne scegli una nuova da qui.

Buon allenamento,<br>
Training Companion
</x-mail::message>
