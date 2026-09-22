{{--
    L'avviso di sicurezza — 22/09/2026.

    ⛔ Niente pulsanti, e niente link per «annullare»: una mail di sicurezza che
    insegna a premere un link è la stessa cosa che vorrebbe mandare un truffatore.
    Chi non ha cambiato la password deve venire da noi, non seguire un link.
--}}
<x-mail::message>
# Ciao {{ $nome }},

la password del tuo account Training Companion è stata cambiata il
**{{ $quando }}**.

Se sei stato tu, va tutto bene: non devi fare niente.

**Se non sei stato tu**, l'account non è più al sicuro. Scrivici subito da
[{{ parse_url((string) config('app.url'), PHP_URL_HOST) }}/scrivici]({{ route('sito.scrivici') }})
e lo blocchiamo: da quel momento chi ha la password nuova non entra più.

💡 Ricordati che le schede e i messaggi ricevuti restano leggibili sul tuo
telefono anche se qualcuno entrasse nell'account: sono cifrati con una chiave
che sta lì, e la password di accesso non la apre.
</x-mail::message>
