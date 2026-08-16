{{--
    I pulsanti per scaricare l'app — 16/08/2026.

    ── 🚨 Perche' non e' un `<a href>` scritto secco ──────────────────────────

    Perche' **l'app non e' ancora sugli store**, e un pulsante «Scarica» che non
    scarica niente e' peggio di nessun pulsante: chi lo preme una volta e non
    succede niente non lo preme piu', e non torna a controllare.

    Finche' `config('app_mobile.*')` e' vuoto si dice «presto», che e' vero.
    Il giorno della pubblicazione si riempiono due valori e i pulsanti compaiono
    da soli **in tutti i punti in cui questo componente e' usato**.

    Parametri:
      grande — `true` per l'apertura e la chiusura, `false` per la barra in alto
--}}
@props(['grande' => false])

@php
    $android = config('app_mobile.android');
    $ios = config('app_mobile.ios');
    $misura = $grande ? ' bottone-grande' : '';
@endphp

@if ($android || $ios)
    <div style="display: flex; gap: var(--sp-1); flex-wrap: wrap;">
        @if ($android)
            <a href="{{ $android }}" class="bottone bottone-pieno{{ $misura }}" rel="noopener">Scarica per Android</a>
        @endif
        @if ($ios)
            <a href="{{ $ios }}" class="bottone {{ $android ? 'bottone-vuoto' : 'bottone-pieno' }}{{ $misura }}" rel="noopener">Scarica per iPhone</a>
        @endif
    </div>
@else
    {{--
        💡 **Solo la riga, nessun pulsante.** Un pulsante spento si prova a
        premere lo stesso, e uno che rimanda altrove al posto di scaricare
        promette una cosa e ne fa un'altra. Finche' non c'e' niente da
        scaricare, qui si dice come stanno le cose e basta.
    --}}
    <span class="pillola">Presto su Google Play e App Store</span>
@endif
