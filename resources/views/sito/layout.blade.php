{{--
    Il guscio del sito pubblico — F9.

    🚨 **Niente CDN, niente build.** Il backend gira su un server condiviso con
    altri due siti, e ogni dipendenza esterna in più è una cosa che può cadere
    portandosi dietro la pagina che dovrebbe vendere il prodotto. Lo stile è
    inline: sono ottanta righe, e non valgono una catena di build.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titolo', 'Training Companion')</title>
    <meta name="description" content="@yield('descrizione', 'Allenamento e alimentazione, per palestre, trainer indipendenti e per chi si allena da solo.')">
    <style>
        :root {
            --primary: #0F766E;
            --scuro: #111827;
            --grigio: #6B7280;
            --bordo: #E5E7EB;
            --sfondo: #FFFFFF;
            --tenue: #F9FAFB;
        }

        /* ⚠️ Il tema scuro si rispetta: una pagina bianca accecante di notte è
           la prima cosa che fa chiudere una scheda. */
        @media (prefers-color-scheme: dark) {
            :root {
                --scuro: #F3F4F6;
                --grigio: #9CA3AF;
                --bordo: #374151;
                --sfondo: #0B1220;
                --tenue: #111827;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: var(--scuro);
            background: var(--sfondo);
            line-height: 1.6;
        }

        .contenitore { max-width: 1040px; margin: 0 auto; padding: 0 20px; }
        header { padding: 28px 0; border-bottom: 1px solid var(--bordo); }
        .marchio { font-weight: 800; font-size: 20px; letter-spacing: -0.02em; }
        h1 { font-size: clamp(28px, 5vw, 44px); line-height: 1.15; letter-spacing: -0.02em; margin: 48px 0 12px; }
        h2 { font-size: 24px; margin: 48px 0 8px; letter-spacing: -0.01em; }
        .sottotitolo { color: var(--grigio); font-size: 18px; max-width: 60ch; }

        .griglia { display: grid; gap: 16px; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); margin: 24px 0; }

        .scheda {
            border: 1px solid var(--bordo);
            border-radius: 14px;
            padding: 20px;
            background: var(--tenue);
        }

        .scheda h3 { margin: 0 0 4px; font-size: 18px; }
        .prezzo { font-size: 28px; font-weight: 800; margin: 8px 0 4px; }
        .prezzo small { font-size: 14px; font-weight: 500; color: var(--grigio); }
        .elenco { list-style: none; padding: 0; margin: 12px 0 0; font-size: 15px; }
        .elenco li { padding: 4px 0 4px 22px; position: relative; }
        .elenco li::before { content: "✓"; position: absolute; left: 0; color: var(--primary); font-weight: 700; }
        .elenco li.no { color: var(--grigio); }
        .elenco li.no::before { content: "—"; color: var(--grigio); }

        .nota { color: var(--grigio); font-size: 14px; }
        footer { margin-top: 64px; padding: 24px 0; border-top: 1px solid var(--bordo); color: var(--grigio); font-size: 14px; }
        a { color: var(--primary); }
    </style>
</head>
<body>
<header>
    <div class="contenitore marchio">Training Companion</div>
</header>

<main class="contenitore">
    @yield('contenuto')
</main>

<footer>
    <div class="contenitore">
        {{-- ⚠️ I documenti privacy vanno pubblicati **prima** che il sito vada
             online: A5 li elenca, e sono anche il requisito che Google pretende
             per Health Connect. --}}
        Training Companion · <a href="/privacy">Informativa privacy</a> ·
        <a href="/condizioni">Condizioni d'uso</a>
    </div>
</footer>
</body>
</html>
