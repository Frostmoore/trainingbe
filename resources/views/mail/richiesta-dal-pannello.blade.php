{{--
    Una richiesta di piano su misura dal pannello — 23/09/2026, F8.6.

    ⚠️ Il messaggio lo scrive la palestra: `nl2br(e(...))`, mai HTML.
--}}
<x-mail::message>
# Richiesta di piano su misura

**{{ $r->organizzazione ?? '—' }}** chiede un piano su misura dal pannello.

- **Chi scrive:** {{ $r->nome }} ({{ $r->email }})
@if ($r->telefono)
- **Telefono:** {{ $r->telefono }}
@endif
- **Dimensioni:** {{ $r->quantoEGrande() }}

{!! nl2br(e($r->messaggio)) !!}

Rispondendo a questa mail rispondi direttamente a chi l'ha scritta. La trovi anche in «Richieste dal sito».
</x-mail::message>
