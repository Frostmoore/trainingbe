{{--
    La risposta a chi ci ha scritto dal sito — F9.3, 23/09/2026.

    ⚠️ Il testo lo scrive una persona: `nl2br(e(...))`, mai HTML. In fondo, la
    richiesta originale, così chi legge sa a cosa stiamo rispondendo.
--}}
<x-mail::message>
# Ciao {{ $nome }},

{!! nl2br(e($testo)) !!}

—<br>
Training Companion

@if ($messaggio)
<x-mail::panel>
**Ci avevi scritto:**<br>
{!! nl2br(e(\Illuminate\Support\Str::limit($messaggio, 1500))) !!}
</x-mail::panel>
@endif
</x-mail::message>
