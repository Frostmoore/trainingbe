{{--
    La notifica a noi di una richiesta dal sito — 22/09/2026.

    ⚠️ Tutto quello che c'è dentro l'ha scritto uno sconosciuto: `e()` sempre,
    e `nl2br` solo sul messaggio, che è l'unico posto dove gli a capo contano.
--}}
<x-mail::message>
# Richiesta dal sito — {{ $r->tipo->label() }}

- **Chi scrive:** {{ $r->nome }} ({{ $r->email }})
@if ($r->organizzazione)
- **Organizzazione:** {{ $r->organizzazione }}
@endif
@if ($r->telefono)
- **Telefono:** {{ $r->telefono }}
@endif
@if ($r->quantoEGrande() !== '')
- **Dimensioni:** {{ $r->quantoEGrande() }}
@endif

<x-mail::panel>
{!! nl2br(e($r->messaggio)) !!}
</x-mail::panel>

Rispondendo a questa mail rispondi direttamente a chi l'ha scritta.
La richiesta è anche in «Richieste dal sito», dove si risponde dal pannello e
resta scritto cosa è stato risposto.
</x-mail::message>
