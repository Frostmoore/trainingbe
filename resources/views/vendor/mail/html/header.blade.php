@props(['url'])
{{--
    🎨 L'intestazione delle mail — 22/09/2026.

    ══ 🚨 DUE COSE CHE NELLE MAIL NON FUNZIONANO COME SUL SITO ═══════════════

    ⛔ **Niente SVG**: Gmail e Outlook non lo mostrano, e il marchio del sito è
    proprio un SVG (`marchio.svg`). Qui si usa `marchio-mail.png`, che è lo
    stesso segno bianco dell'app rasterizzato a 112px — grande il doppio di come
    si vede, perché gli schermi fitti lo raddoppiano.

    ⛔ **Niente indirizzi relativi**: una mail si legge fuori dal sito, e
    `/img/sito/…` lì dentro non esiste. L'indirizzo si compone da `app.url`.

    💡 Se il file non c'è, resta il **nome scritto**: un rettangolo rotto in cima
    a una mail la fa sembrare finta, ed è l'ultima cosa che serve a un messaggio
    che chiede di premere un pulsante.
--}}
@php($segno = public_path('img/sito/marchio-mail.png'))
@php($marchio = is_file($segno) ? rtrim((string) config('app.url'), '/').'/img/sito/marchio-mail.png' : null)
<tr>
<td class="header">
<a href="{{ $url }}" style="display: inline-block; text-decoration: none;">
<table cellpadding="0" cellspacing="0" role="presentation" align="center" style="margin: 0 auto;">
<tr>
@if ($marchio)
<td class="marchio"><img src="{{ $marchio }}" class="logo" alt="" width="28" height="28"></td>
@endif
<td class="nome">{{ $slot ?? config('app.name') }}</td>
</tr>
</table>
</a>
</td>
</tr>
