<x-mail::layout>
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')">
{{ config('app.name') }}
</x-mail::header>
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{--
    🦶 Il piè di pagina — 22/09/2026.

    🚨 **Dice dove si risponde**, e non è cortesia: il mittente è `noreply@`, e
    una mail che non lo scrive si prende risposte che non legge nessuno — cioè
    persone convinte di aver chiesto aiuto.

    ⚖️ E porta l'informativa: è la mail il momento in cui una persona si chiede
    «perché ce l'hanno il mio indirizzo», e la risposta deve stare a un tocco.

    ⛔ Niente «se non vuoi più ricevere queste mail»: queste sono **mail di
    servizio** (password, inviti, risposte a una richiesta) e non si disdicono,
    come non si disdice la ricevuta di un pagamento. La riga per disiscriversi
    la mette la mail delle **promozioni**, che è l'unica che la deve avere.
--}}
<x-slot:footer>
<x-mail::footer>
{{ config('app.name') }} — [{{ parse_url((string) config('app.url'), PHP_URL_HOST) }}]({{ config('app.url') }})

Non rispondere a questa mail: l'indirizzo non è letto da nessuno.
Per parlare con noi: [{{ parse_url((string) config('app.url'), PHP_URL_HOST) }}/scrivici]({{ route('sito.scrivici') }})

[Informativa sulla privacy]({{ route('sito.documento', 'privacy') }}) · © {{ date('Y') }}
</x-mail::footer>
</x-slot:footer>
</x-mail::layout>
