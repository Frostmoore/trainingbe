{{--
    La ricevuta di chi ci scrive dal sito — 22/09/2026.

    ⚠️ `nl2br(e($messaggio))`: il testo è suo e non è HTML, ma gli a capo vanno
    tenuti — in Markdown una riga a capo sola sparisce, e tre paragrafi
    tornerebbero indietro come un muro.
--}}
<x-mail::message>
# Ciao {{ $nome }},

abbiamo ricevuto la tua richiesta: la leggiamo e ti rispondiamo a questo
indirizzo.

<x-mail::panel>
{!! nl2br(e($messaggio)) !!}
</x-mail::panel>

Se nel frattempo ti viene in mente qualcos'altro, rispondi pure scrivendoci di
nuovo dal sito: troviamo tutto insieme.
</x-mail::message>
