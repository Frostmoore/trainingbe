{{--
    La barra che ricorda «non sei tu».

    Sta in cima a ogni pagina dei pannelli e non si puo' chiudere, di proposito:
    il rischio vero dell'impersonazione non e' entrare, e' **dimenticarsi di
    essere entrati** e poi modificare i dati di un cliente credendo di stare nel
    proprio account.
--}}
@if ($impersonating)
    <div style="position:sticky;top:0;z-index:9999;display:flex;align-items:center;justify-content:center;
                gap:.75rem;flex-wrap:wrap;padding:.55rem 1rem;background:#b91c1c;color:#fff;
                font-size:.875rem;font-weight:600;line-height:1.3;text-align:center;">
        <span>
            Stai usando l'account di <strong>{{ $target }}</strong>@if ($tenant) — {{ $tenant }}@endif.
            Sei entrato come <strong>{{ $original }}</strong>.
        </span>
        <a href="{{ route('impersonation.stop') }}"
           style="background:#fff;color:#b91c1c;padding:.25rem .75rem;border-radius:.375rem;
                  text-decoration:none;font-weight:700;white-space:nowrap;">
            Torna al mio account
        </a>
    </div>
@endif
