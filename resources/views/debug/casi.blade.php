{{--
    🧪 **I 25 casi di prova** — 63.H, 27/09/2026. SOLO IN LOCALE.

    📌 Il committente: «ho bisogno di una view di debug in locale con una serie di
    link di accesso che mi permettano di verificare ogni singolo caso per conto
    mio. Ogni link si deve aprire su una nuova pagina».

    🔑 La metà che conta di questa pagina NON sono i collegamenti: sono i
    **verdetti** accanto a ciascuno. ⛔ Per venticinque casi il problema non è
    entrare, è sapere se quello che si vede è giusto — e senza i verdetti scritti
    qui la fiducia si sposterebbe dai test alla mia memoria.

    ⚠️ Niente Tailwind e niente build: questa pagina deve funzionare anche quando
    gli asset non sono compilati. Uno strumento di diagnosi che dipende dalla
    catena di build è uno strumento che manca proprio quando serve.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>I 25 casi di prova — solo in locale</title>
    <style>
        :root {
            --sfondo: #0f172a; --carta: #1e293b; --bordo: #334155;
            --testo: #e2e8f0; --tenue: #94a3b8;
            --verde: #34d399; --rosso: #f87171; --ambra: #fbbf24; --petrolio: #0f766e;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; padding: 24px 16px;
            background: var(--sfondo); color: var(--testo);
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            line-height: 1.5;
        }
        .contenitore { max-width: 1100px; margin: 0 auto; }
        h1 { font-size: 1.6rem; margin: 0 0 4px; }
        .sottotitolo { color: var(--tenue); margin: 0 0 24px; }
        .allarme {
            background: #422006; border: 1px solid var(--ambra);
            border-radius: 10px; padding: 12px 16px; margin-bottom: 20px;
        }
        .credenziali {
            background: var(--carta); border: 1px solid var(--bordo);
            border-radius: 10px; padding: 16px; margin-bottom: 24px;
        }
        code, .mono {
            font-family: ui-monospace, "Cascadia Code", Consolas, monospace;
            background: #0b1120; padding: 2px 6px; border-radius: 4px;
        }
        .password { font-size: 1.15rem; color: var(--verde); }
        h2 { font-size: 1.15rem; margin: 28px 0 12px; border-bottom: 1px solid var(--bordo); padding-bottom: 6px; }
        .griglia { display: grid; gap: 12px; grid-template-columns: 1fr; }
        @media (min-width: 760px) { .griglia { grid-template-columns: 1fr 1fr; } }
        .caso {
            background: var(--carta); border: 1px solid var(--bordo);
            border-radius: 10px; padding: 14px 16px;
        }
        .caso h3 { margin: 0 0 4px; font-size: 1rem; }
        .chiave {
            display: inline-block; background: var(--petrolio); color: #fff;
            border-radius: 5px; padding: 1px 7px; font-size: .8rem;
            font-weight: 700; margin-right: 6px;
        }
        ul { margin: 8px 0 0; padding-left: 20px; }
        li { margin: 2px 0; font-size: .92rem; }
        .deve li { color: var(--verde); }
        .nondeve li { color: var(--rosso); }
        .etichetta { color: var(--tenue); font-size: .8rem; text-transform: uppercase; letter-spacing: .04em; margin-top: 10px; }
        a.entra {
            display: inline-block; margin-top: 12px;
            background: var(--petrolio); color: #fff; text-decoration: none;
            padding: 8px 14px; border-radius: 8px; font-weight: 600;
        }
        a.entra:hover { filter: brightness(1.15); }
        .email { display: block; margin-top: 10px; font-size: .95rem; }
        button {
            background: var(--verde); color: #05261a; border: 0;
            padding: 10px 18px; border-radius: 8px; font-weight: 700;
            font-size: 1rem; cursor: pointer;
        }
        .esito { background: #052e21; border: 1px solid var(--verde); border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; }
        .errore { background: #451a1a; border: 1px solid var(--rosso); border-radius: 10px; padding: 12px 16px; margin-bottom: 20px; }
    </style>
</head>
<body>
<div class="contenitore">

    <h1>🧪 I 25 casi di prova</h1>
    <p class="sottotitolo">
        Voce <strong>63.H</strong> — l'accesso, verificato senza passare da me.
    </p>

    <div class="allarme">
        ⚠️ <strong>Questa pagina esiste solo in locale.</strong> Tre guardie indipendenti:
        l'ambiente, l'interruttore <code>casi_di_prova.attiva</code>, e la classe che si rifiuta
        di essere costruita altrove. ⛔ Gli account hanno tutti il dominio
        <code>{{ \App\Support\Debug\CasiDiProva::DOMINIO }}</code>, e l'ingresso rifiuta
        qualunque altro indirizzo.
    </div>

    @if (session('fatto'))
        <div class="esito">✅ {{ session('fatto') }}</div>
    @endif

    @if (session('errore'))
        <div class="errore">⛔ {{ session('errore') }}</div>
    @endif

    <div class="credenziali">
        <form method="POST" action="{{ route('debug.casi.ricostruisci') }}">
            @csrf
            <button type="submit">
                {{ $pronti ? '🔄 Ricostruisci tutto da zero' : '🏗️ Costruisci i 25 casi' }}
            </button>
        </form>

        <p style="margin: 14px 0 0">
            🔑 La password è la stessa per tutti e venticinque:
            <strong class="mono password">{{ $password }}</strong>
        </p>
        <p style="margin: 6px 0 0; color: var(--tenue); font-size: .9rem">
            ⚠️ Non è un segreto: vive solo su database locali, in account che esistono per essere provati.
        </p>
    </div>

    @php
        $pannello = array_values(array_filter($casi, fn (array $c): bool => $c['dove'] === 'pannello'));
        $app = array_values(array_filter($casi, fn (array $c): bool => $c['dove'] === 'app'));
    @endphp

    <h2>🖥️ Il pannello — {{ count($pannello) }} casi, si entra con un clic</h2>

    <div class="griglia">
        @foreach ($pannello as $caso)
            <div class="caso">
                <h3><span class="chiave">{{ $caso['chiave'] }}</span>{{ $caso['titolo'] }}</h3>

                <div class="etichetta">Cosa DEVE vedere</div>
                <ul class="deve">
                    @foreach ($caso['deve'] as $riga)<li>✅ {{ $riga }}</li>@endforeach
                </ul>

                @if ($caso['nonDeve'] !== [])
                    <div class="etichetta">Cosa NON deve vedere</div>
                    <ul class="nondeve">
                        @foreach ($caso['nonDeve'] as $riga)<li>⛔ {{ $riga }}</li>@endforeach
                    </ul>
                @endif

                {{-- 🔑 «Ogni link si deve aprire su una nuova pagina»: target + rel. --}}
                <a class="entra"
                   href="{{ route('debug.casi.entra', $caso['chiave']) }}"
                   target="_blank"
                   rel="noopener">Entra come {{ $caso['chiave'] }} →</a>

                <span class="email mono">{{ $caso['email'] }}</span>
            </div>
        @endforeach
    </div>

    <h2>📱 L'app — {{ count($app) }} casi, si digitano nell'emulatore</h2>

    <p style="color: var(--tenue); margin: -4px 0 14px">
        ⚖️ Qui non c'è un collegamento, ed è una scelta: un selettore di account dentro l'app
        avrebbe voluto una <strong>rotta di accesso nuova sul server</strong>, cioè esattamente la
        forma di cosa vietata dalla voce 64. 💡 Così si entra dalla <strong>porta normale</strong>,
        che è anche quella che si sta provando.
    </p>

    <div class="griglia">
        @foreach ($app as $caso)
            <div class="caso">
                <h3><span class="chiave">{{ $caso['chiave'] }}</span>{{ $caso['titolo'] }}</h3>

                <div class="etichetta">Cosa DEVE vedere</div>
                <ul class="deve">
                    @foreach ($caso['deve'] as $riga)<li>✅ {{ $riga }}</li>@endforeach
                </ul>

                @if ($caso['nonDeve'] !== [])
                    <div class="etichetta">Cosa NON deve vedere</div>
                    <ul class="nondeve">
                        @foreach ($caso['nonDeve'] as $riga)<li>⛔ {{ $riga }}</li>@endforeach
                    </ul>
                @endif

                <div class="etichetta">Da digitare nell'app</div>
                <span class="email mono" style="font-size: 1.05rem; color: var(--verde)">{{ $caso['email'] }}</span>
            </div>
        @endforeach
    </div>

    <h2>⛔ Quello che questa pagina NON prova</h2>
    <ul>
        <li>⛔ <strong>La produzione.</strong> Una regola verificata solo in locale non è verificata:
            il 27/09 la prova sulle schede nominative era verde in sviluppo e <strong>rossa in
            produzione</strong>, 4 righe su 4. È il punto <strong>63.G</strong>.</li>
        <li>⛔ <strong>I piani a consumo.</strong> Là non c'è un abbonamento che finisce ma una fattura
            mensile non pagata, e «dopo quante» si sospende è una decisione mai presa.</li>
    </ul>

</div>
</body>
</html>
