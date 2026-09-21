{{--
    Il PDF di una fattura — fase F8, 23/09/2026.

    ⚠️ Per dompdf: niente flexbox né grid (non li conosce), solo tabelle e
    blocchi. Il carattere è DejaVu Sans, che ha «€» e le accentate.
    ⚠️ Tutto quello che si legge qui viene da dati FISSATI all'emissione
    (`$fattura->cessionario`), mai dalla palestra di oggi.
--}}
@php
    /** @var \App\Models\Fattura $fattura */
    $euro = fn (int $cent): string => number_format($cent / 100, 2, ',', '.').' €';
    $riga = fn (?string ...$parti): string => implode(' ', array_filter($parti, fn ($p) => filled($p)));
@endphp
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <title>Fattura {{ $fattura->numeroPerEsteso() }}</title>
    <style>
        @page { margin: 28mm 18mm 24mm 18mm; }
        body { font-family: "DejaVu Sans", sans-serif; font-size: 10px; color: #1f2937; }
        h1 { font-size: 20px; margin: 0; letter-spacing: .5px; }
        .piccolo { font-size: 9px; color: #6b7280; }
        .intestazione { width: 100%; margin-bottom: 18px; }
        .intestazione td { vertical-align: top; }
        .riquadro { border: 1px solid #d1d5db; border-radius: 4px; padding: 8px 10px; }
        .etichetta { font-size: 8px; text-transform: uppercase; letter-spacing: .6px; color: #6b7280; margin-bottom: 3px; }
        table.righe { width: 100%; border-collapse: collapse; margin-top: 18px; }
        table.righe th { background: #f3f4f6; text-align: left; font-size: 9px; padding: 6px; border-bottom: 1px solid #d1d5db; }
        table.righe td { padding: 8px 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
        .num { text-align: right; white-space: nowrap; }
        table.totali { width: 45%; margin-left: 55%; margin-top: 12px; border-collapse: collapse; }
        table.totali td { padding: 4px 6px; }
        table.totali tr.grande td { font-size: 13px; font-weight: bold; border-top: 1px solid #1f2937; }
        .note { margin-top: 22px; font-size: 9px; line-height: 1.45; }
        .note p { margin: 0 0 6px 0; }
        .piede { position: fixed; bottom: -12mm; left: 0; right: 0; font-size: 8px; color: #9ca3af; text-align: center; }
    </style>
</head>
<body>
    <table class="intestazione">
        <tr>
            <td style="width: 55%;">
                <div class="etichetta">Emessa da</div>
                <strong>{{ $cedente['denominazione'] }}</strong><br>
                {{ $riga($cedente['indirizzo'], $cedente['numero_civico'] ?? null) }}<br>
                {{ $riga($cedente['cap'], $cedente['comune'], '('.$cedente['provincia'].')') }}<br>
                P.IVA {{ $cedente['partita_iva'] }} · C.F. {{ $cedente['codice_fiscale'] }}<br>
                <span class="piccolo">PEC {{ $cedente['pec'] }} · {{ $cedente['email'] }}</span>
            </td>
            <td style="width: 45%; text-align: right;">
                <h1>FATTURA</h1>
                <div style="margin-top: 6px; font-size: 12px;">
                    n. <strong>{{ $fattura->numeroPerEsteso() }}</strong><br>
                    del <strong>{{ $fattura->data?->format('d/m/Y') }}</strong>
                </div>
            </td>
        </tr>
    </table>

    <div class="riquadro">
        <div class="etichetta">Intestata a</div>
        <strong>{{ $cliente['ragione_sociale'] ?? '' }}</strong><br>
        {{ $cliente['indirizzo'] ?? '' }}<br>
        {{ $riga($cliente['cap'] ?? null, $cliente['comune'] ?? null, filled($cliente['provincia'] ?? null) ? '('.$cliente['provincia'].')' : null) }}
        @if (($cliente['nazione'] ?? 'IT') !== 'IT') · {{ $cliente['nazione'] }} @endif
        <br>
        @if (filled($cliente['partita_iva'] ?? null)) P.IVA {{ $cliente['partita_iva'] }} @endif
        @if (filled($cliente['codice_fiscale'] ?? null)) · C.F. {{ $cliente['codice_fiscale'] }} @endif
        <br>
        <span class="piccolo">
            @if (filled($cliente['codice_sdi'] ?? null) && $cliente['codice_sdi'] !== '0000000')
                Codice destinatario {{ $cliente['codice_sdi'] }}
            @elseif (filled($cliente['pec'] ?? null))
                PEC {{ $cliente['pec'] }}
            @endif
        </span>
    </div>

    <table class="righe">
        <thead>
            <tr>
                <th>Descrizione</th>
                <th class="num">Quantità</th>
                <th class="num">Prezzo</th>
                <th class="num">IVA</th>
                <th class="num">Importo</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td>{{ $fattura->descrizione }}</td>
                <td class="num">1</td>
                <td class="num">{{ $euro($fattura->imponibile_cent) }}</td>
                <td class="num">N2.2</td>
                <td class="num">{{ $euro($fattura->imponibile_cent) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="totali">
        <tr><td>Imponibile</td><td class="num">{{ $euro($fattura->imponibile_cent) }}</td></tr>
        <tr><td>IVA</td><td class="num">0,00 €</td></tr>
        <tr class="grande"><td>Totale</td><td class="num">{{ $euro($fattura->totale_cent) }}</td></tr>
        <tr><td colspan="2" class="piccolo" style="text-align: right;">Già pagata con carta</td></tr>
    </table>

    <div class="note">
        <p>{{ $dicitura }}</p>

        @if ($fattura->bollo_cent > 0)
            <p>Imposta di bollo di {{ $euro($fattura->bollo_cent) }} assolta in modo virtuale
               ai sensi dell'art. 6, comma 2, del D.M. 17 giugno 2014.</p>
        @endif

        <p class="piccolo">{{ $nota }}</p>
    </div>

    <div class="piede">
        {{ $cedente['denominazione'] }} · fattura {{ $fattura->numeroPerEsteso() }} del {{ $fattura->data?->format('d/m/Y') }}
    </div>
</body>
</html>
