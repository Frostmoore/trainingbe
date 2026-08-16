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
        💡 Non un pulsante spento, che si prova comunque a premere: una riga che
        dice come stanno le cose, e accanto l'azione che invece **funziona
        adesso** — leggere come funziona.
    --}}
    <div style="display: flex; gap: var(--sp-2); flex-wrap: wrap; align-items: center;">
        <a href="#come-funziona" class="bottone bottone-pieno{{ $misura }}">Guarda come funziona</a>
        <span class="pillola">Presto su Google Play e App Store</span>
    </div>
@endif
