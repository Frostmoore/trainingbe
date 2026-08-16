{{--
    Un'immagine del sito, o il suo posto vuoto — 16/08/2026.

    ── 🚨 Perche' non e' un `<img>` e basta ───────────────────────────────────

    Perche' le immagini vanno generate e caricate a mano, e fino a quel momento
    un `<img src>` secco mostrerebbe l'icona dell'immagine rotta: il sito
    sembrerebbe **guasto** mentre e' solo incompleto.

    Quando il file non c'e', qui si disegna un riempimento con i colori del
    prodotto — che sembra una scelta, non un errore. Quando arriva, compare da
    solo: non c'e' nessun template da ricordarsi di cambiare.

    ⚠️ **`width` e `height` ci sono sempre**, anche sul riempimento. Senza, la
    pagina salta mentre le immagini si caricano e chi sta leggendo perde il
    punto in cui era.

    Parametri:
      nome     — la chiave in `ImmaginiDelSito::ATTESE`
      alt      — 🚨 obbligatorio e descrittivo: e' quello che sente chi usa uno
                 screen reader, ed e' anche quello che si legge se l'immagine
                 non arriva
      priorita — `true` solo per l'immagine in apertura, che e' l'unica visibile
                 subito. Tutte le altre si caricano quando servono
      classe   — classi in piu' sul contenitore
--}}
@props([
    'nome',
    'alt',
    'priorita' => false,
    'classe' => '',
])

@php
    $immagini = app(\App\Support\ImmaginiDelSito::class);
    $misura = \App\Support\ImmaginiDelSito::ATTESE[$nome] ?? ['larghezza' => 4, 'altezza' => 3];
    $percorso = $immagini->url($nome);
@endphp

<div class="figura {{ $classe }}" style="aspect-ratio: {{ $misura['larghezza'] }} / {{ $misura['altezza'] }};">
    @if ($percorso !== null)
        <img
            src="{{ $percorso }}"
            alt="{{ $alt }}"
            width="{{ $misura['larghezza'] }}"
            height="{{ $misura['altezza'] }}"
            @if ($priorita) fetchpriority="high" @else loading="lazy" @endif
            decoding="async"
        >
    @else
        {{--
            💡 Il riempimento non dice «immagine mancante» a chi guarda: dice
            una cosa sola, il segno del prodotto, su una sfumatura dei suoi
            colori. Chi non sa che manca un'immagine non se ne accorge.

            🚨 `aria-hidden` perche' non porta nessuna informazione: farlo
            leggere a uno screen reader sarebbe rumore al posto di una
            descrizione.
        --}}
        <div class="figura-vuota" aria-hidden="true" data-attesa="{{ $nome }}">
            <span class="punto"></span>
        </div>
    @endif
</div>
