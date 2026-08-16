{{--
    Il guscio del sito pubblico — F9, ridisegnato il 16/08/2026.

    🚨 **Niente CDN, niente build.** Il backend gira su un server condiviso con
    altri due siti, e ogni dipendenza esterna in più è una cosa che può cadere
    portandosi dietro la pagina che dovrebbe vendere il prodotto. Lo stile è
    inline: è più lungo di prima, e continua a non valere una catena di build.

    ── 💡 Da dove viene questo disegno ────────────────────────────────────────

    Guardando come si presentano i prodotti dello stesso settore (Trainerize,
    TrueCoach): navigazione corta con una sola azione, un'affermazione grande in
    apertura, sezioni alternate testo/immagine, i prezzi in tre schede con una
    evidenziata, le domande frequenti in fondo, un piè di pagina a colonne.

    ⚠️ **Una cosa che loro hanno e noi no: le prove sociali.** Loro mettono i
    loghi dei clienti e i numeri («400K trainer»). Noi non abbiamo né gli uni né
    gli altri, e **inventarli sarebbe una bugia** — quindi quella fascia qui non
    c'è. Al suo posto si dice cosa siamo davvero: un prodotto in prova.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titolo', 'Training Companion')</title>
    <meta name="description" content="@yield('descrizione', 'L\'app per chi si allena, gratis. L\'AI è un supplemento che la palestra accende a chi la vuole.')">
    <meta name="theme-color" content="#0F766E">

    {{--
        🚨 **L'anteprima quando il link viene condiviso.**

        Fino al 16/08 non c'era: mandare l'indirizzo su WhatsApp mostrava un
        riquadro grigio con dentro il dominio. ⚠️ È il primo posto in cui il
        prodotto si presenta a qualcuno che non lo conosce, e presentarsi con un
        riquadro vuoto è peggio che non farsi condividere.
    --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Training Companion">
    <meta property="og:locale" content="it_IT">
    <meta property="og:title" content="@yield('titolo', 'Training Companion')">
    <meta property="og:description" content="@yield('descrizione', 'L\'app per chi si allena, gratis.')">
    <meta property="og:url" content="{{ url()->current() }}">

    @php($anteprima = app(\App\Support\ImmaginiDelSito::class)->url('og'))
    @if ($anteprima !== null)
        {{-- ⚠️ Assoluto e non relativo: i servizi che leggono questo tag non
             hanno un indirizzo di base da cui risolverlo. --}}
        <meta property="og:image" content="{{ url($anteprima) }}">
        <meta property="og:image:width" content="1200">
        <meta property="og:image:height" content="630">
        <meta name="twitter:card" content="summary_large_image">
    @else
        {{-- 💡 Nessun `og:image` finto: un tag che punta a un file inesistente
             fa mostrare il riquadro rotto invece di nessun riquadro. --}}
        <meta name="twitter:card" content="summary">
    @endif

    {{--
        💡 **L'icona è disegnata qui dentro, non è un file.** È il quadratino
        del marchio: un SVG di duecento byte incorporato non è una richiesta in
        più al server e non è un file da ricordarsi di caricare.
    --}}
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='9' fill='%230F766E'/%3E%3Crect x='9' y='9' width='14' height='14' rx='4' fill='%23ECFDF5'/%3E%3C/svg%3E">

    <style>
        :root {
            --accento: #0F766E;
            --accento-scuro: #0B5D57;
            --accento-tenue: #ECFDF5;
            --testo: #111827;
            --testo-tenue: #6B7280;
            --bordo: #E5E7EB;
            --sfondo: #FFFFFF;
            --superficie: #F9FAFB;

            --sp-1: 8px;  --sp-2: 16px; --sp-3: 24px;
            --sp-4: 40px; --sp-5: 64px; --sp-6: 96px;
            --raggio: 16px;
        }

        /* ⚠️ Il tema scuro si rispetta: una pagina bianca accecante di notte è
           la prima cosa che fa chiudere una scheda. */
        @media (prefers-color-scheme: dark) {
            :root {
                --accento: #2DD4BF;
                --accento-scuro: #5EEAD4;
                --accento-tenue: #12211F;
                --testo: #F3F4F6;
                --testo-tenue: #9CA3AF;
                --bordo: #1F2937;
                --sfondo: #0B1220;
                --superficie: #111827;
            }
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            color: var(--testo);
            background: var(--sfondo);
            line-height: 1.65;
            -webkit-font-smoothing: antialiased;
        }

        .contenitore { max-width: 1080px; margin: 0 auto; padding: 0 var(--sp-3); }
        .stretto { max-width: 760px; }

        /* ───────────────────────── figure ─────────────────────────

           🚨 Ogni figura ha il suo rapporto scritto in `aspect-ratio`, quindi
           **occupa il suo spazio prima di essersi caricata**. Senza, la pagina
           salta mentre le immagini arrivano e chi sta leggendo perde il segno.
        */

        .figura {
            width: 100%;
            border-radius: var(--raggio);
            overflow: hidden;
            background: var(--superficie);
            border: 1px solid var(--bordo);
        }

        .figura img {
            width: 100%; height: 100%;
            object-fit: cover;
            display: block;
        }

        /* ⚠️ Le fotografie sono prodotte in luce piena, e di notte «bucano» la
           pagina. Un velo appena percettibile le rimette nel tono senza
           spegnerle. */
        @media (prefers-color-scheme: dark) {
            .figura img { filter: brightness(.86) saturate(.94); }
        }

        /*
           💡 Il posto vuoto quando l'immagine non è ancora stata caricata.
           Non dice «manca un'immagine»: mette il segno del prodotto su una
           sfumatura dei suoi colori, e sembra una scelta.
        */
        .figura-vuota {
            width: 100%; height: 100%;
            display: grid; place-items: center;
            background:
                radial-gradient(120% 90% at 20% 0%, var(--accento-tenue) 0%, transparent 60%),
                linear-gradient(140deg, var(--superficie) 0%, var(--sfondo) 100%);
        }

        .figura-vuota .punto {
            width: 22px; height: 22px; border-radius: 7px;
            background: var(--accento);
            opacity: .30;
        }

        /* La figura in apertura: sta sotto al telefono disegnato, quindi non
           deve reggere da sola l'attenzione. */
        .figura-eroe { position: absolute; inset: 0; z-index: 0; }
        .figura-eroe .figura, .figura-eroe img { height: 100%; border-radius: var(--raggio); }

        /* 🚨 Il velo sopra la fotografia dell'apertura. Senza, il telefono
           disegnato in CSS si perde su una foto piena di dettagli e il testo
           dentro non si legge più. */
        .velo-eroe {
            position: absolute; inset: 0; z-index: 1;
            border-radius: var(--raggio);
            background: linear-gradient(160deg,
                color-mix(in srgb, var(--sfondo) 55%, transparent) 0%,
                color-mix(in srgb, var(--sfondo) 82%, transparent) 100%);
        }

        /* La figura in cima a una scheda: senza bordo proprio e senza angoli
           in basso, perché è incollata alla scheda che la contiene. */
        .scheda > .figura:first-child {
            border: 0;
            border-radius: 0;
            margin: calc(var(--sp-3) * -1) calc(var(--sp-3) * -1) var(--sp-3);
            width: auto;
        }

        /* ───────────────────────── navigazione ───────────────────────── */

        .navbar {
            position: sticky; top: 0; z-index: 50;
            background: color-mix(in srgb, var(--sfondo) 88%, transparent);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--bordo);
        }

        .navbar .contenitore {
            display: flex; align-items: center; gap: var(--sp-3);
            height: 64px;
        }

        .marchio {
            font-weight: 800; font-size: 18px; letter-spacing: -0.02em;
            color: var(--testo); text-decoration: none;
            display: flex; align-items: center; gap: 8px;
        }

        .marchio .punto {
            width: 10px; height: 10px; border-radius: 3px;
            background: var(--accento); display: inline-block;
        }

        .nav-voci { display: flex; gap: var(--sp-3); margin-left: auto; align-items: center; }
        .nav-voci a { color: var(--testo-tenue); text-decoration: none; font-size: 15px; font-weight: 500; }
        .nav-voci a:hover { color: var(--testo); }

        /* ⚠️ Sotto i 640 px le voci spariscono e resta l'azione: un menù a
           panino per tre link è più lavoro per chi legge, non meno. */
        @media (max-width: 640px) { .nav-voci .solo-grande { display: none; } }

        /* ───────────────────────── bottoni ───────────────────────── */

        .bottone {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; padding: 11px 20px; border-radius: 10px;
            font-weight: 600; font-size: 15px; text-decoration: none;
            border: 1px solid transparent; cursor: pointer;
            transition: transform .06s ease, background .15s ease;
        }
        .bottone:active { transform: translateY(1px); }
        .bottone-pieno { background: var(--accento); color: #fff; }
        .bottone-pieno:hover { background: var(--accento-scuro); }
        .bottone-vuoto { border-color: var(--bordo); color: var(--testo); background: var(--sfondo); }
        .bottone-vuoto:hover { border-color: var(--testo-tenue); }
        .bottone-grande { padding: 14px 26px; font-size: 16px; }

        /* ───────────────────────── tipografia ───────────────────────── */

        h1 {
            font-size: clamp(34px, 6vw, 56px); line-height: 1.08;
            letter-spacing: -0.03em; margin: 0 0 var(--sp-2); font-weight: 800;
        }
        h2 {
            font-size: clamp(26px, 3.4vw, 34px); line-height: 1.2;
            letter-spacing: -0.02em; margin: 0 0 var(--sp-2); font-weight: 750;
        }
        h3 { font-size: 19px; margin: 0 0 6px; letter-spacing: -0.01em; font-weight: 700; }

        .occhiello {
            text-transform: uppercase; letter-spacing: .08em;
            font-size: 12px; font-weight: 700; color: var(--accento);
            margin-bottom: var(--sp-1);
        }

        .guida { color: var(--testo-tenue); font-size: clamp(17px, 2vw, 19px); max-width: 62ch; margin: 0; }
        .nota { color: var(--testo-tenue); font-size: 14px; }

        section { padding: var(--sp-6) 0; }
        section.tenue { background: var(--superficie); border-block: 1px solid var(--bordo); }
        .intestazione-sezione { margin-bottom: var(--sp-4); }

        /* ───────────────────────── griglie e schede ───────────────────────── */

        .griglia { display: grid; gap: var(--sp-2); grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        .griglia-2 { display: grid; gap: var(--sp-4); grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); align-items: center; }

        .scheda {
            border: 1px solid var(--bordo); border-radius: var(--raggio);
            padding: var(--sp-3); background: var(--sfondo);
        }
        .scheda.tenue { background: var(--superficie); }

        .scheda-prezzo { display: flex; flex-direction: column; position: relative; }
        .scheda-prezzo.scelta { border-color: var(--accento); border-width: 2px; }

        .etichetta {
            position: absolute; top: -12px; left: var(--sp-3);
            background: var(--accento); color: #fff;
            font-size: 12px; font-weight: 700; letter-spacing: .04em;
            padding: 4px 10px; border-radius: 999px;
        }

        .prezzo { font-size: 40px; font-weight: 800; letter-spacing: -0.03em; line-height: 1.1; margin: var(--sp-2) 0 2px; }
        .prezzo small { font-size: 15px; font-weight: 500; color: var(--testo-tenue); letter-spacing: 0; }

        .elenco { list-style: none; padding: 0; margin: var(--sp-2) 0 0; font-size: 15px; }
        .elenco li { padding: 5px 0 5px 26px; position: relative; }
        .elenco li::before {
            content: "✓"; position: absolute; left: 0; top: 5px;
            color: var(--accento); font-weight: 800;
        }
        .elenco li.no { color: var(--testo-tenue); }
        .elenco li.no::before { content: "—"; color: var(--testo-tenue); }

        .pillola {
            display: inline-block; padding: 5px 12px; border-radius: 999px;
            background: var(--accento-tenue); color: var(--accento);
            font-size: 13px; font-weight: 700; border: 1px solid color-mix(in srgb, var(--accento) 25%, transparent);
        }

        /* ───────────────────────── domande frequenti ───────────────────────── */

        details {
            border-bottom: 1px solid var(--bordo); padding: var(--sp-2) 0;
        }
        details summary {
            cursor: pointer; font-weight: 650; font-size: 17px;
            list-style: none; display: flex; justify-content: space-between; gap: var(--sp-2);
        }
        details summary::-webkit-details-marker { display: none; }
        details summary::after { content: "+"; color: var(--accento); font-weight: 800; }
        details[open] summary::after { content: "−"; }
        details p { color: var(--testo-tenue); margin: var(--sp-1) 0 0; max-width: 68ch; }

        /* ───────────────────────── documenti ───────────────────────── */

        .documento { max-width: 760px; }
        .documento h1 { font-size: clamp(28px, 4vw, 38px); margin-top: 0; }
        .documento h2 { font-size: 24px; margin-top: var(--sp-4); }
        .documento h3 { font-size: 18px; margin-top: var(--sp-3); }
        .documento table { width: 100%; border-collapse: collapse; margin: var(--sp-2) 0; font-size: 15px; display: block; overflow-x: auto; }
        .documento th, .documento td { border: 1px solid var(--bordo); padding: 8px 12px; text-align: left; }
        .documento th { background: var(--superficie); }
        .documento blockquote {
            margin: var(--sp-2) 0; padding: var(--sp-2); border-left: 3px solid var(--accento);
            background: var(--superficie); border-radius: 0 8px 8px 0;
        }
        .documento blockquote p:first-child { margin-top: 0; }
        .documento blockquote p:last-child { margin-bottom: 0; }
        .documento code { background: var(--superficie); padding: 2px 6px; border-radius: 5px; font-size: 14px; }
        .documento hr { border: 0; border-top: 1px solid var(--bordo); margin: var(--sp-4) 0; }
        .documento ul, .documento ol { padding-left: 22px; }
        .documento li { margin: 4px 0; }

        /* ───────────────────────── piè di pagina ───────────────────────── */

        footer {
            border-top: 1px solid var(--bordo); background: var(--superficie);
            padding: var(--sp-5) 0 var(--sp-4); margin-top: var(--sp-4);
        }
        .colonne-pie { display: grid; gap: var(--sp-3); grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
        .colonne-pie h4 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--testo-tenue); margin: 0 0 var(--sp-1); }
        .colonne-pie a { display: block; color: var(--testo); text-decoration: none; font-size: 15px; padding: 3px 0; }
        .colonne-pie a:hover { color: var(--accento); }
        .chiusura-pie { margin-top: var(--sp-4); padding-top: var(--sp-2); border-top: 1px solid var(--bordo); color: var(--testo-tenue); font-size: 14px; }

        a { color: var(--accento); }

        /* 💡 Chi ha chiesto meno animazioni le ottiene: non è un dettaglio di
           gusto, per qualcuno è mal di testa. */
        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            * { transition: none !important; }
        }
    </style>
</head>
<body>

<nav class="navbar">
    <div class="contenitore">
        <a href="/" class="marchio"><span class="punto"></span> Training Companion</a>
        <div class="nav-voci">
            <a href="/#come-funziona" class="solo-grande">Come funziona</a>
            <a href="/prezzi" class="solo-grande">Prezzi</a>
            <a href="/admin/login" class="bottone bottone-vuoto">Entra</a>
        </div>
    </div>
</nav>

@yield('contenuto')

<footer>
    <div class="contenitore">
        <div class="colonne-pie">
            <div>
                <h4>Prodotto</h4>
                <a href="/#come-funziona">Come funziona</a>
                <a href="/prezzi">Prezzi</a>
                <a href="/#domande">Domande frequenti</a>
            </div>
            <div>
                <h4>Documenti</h4>
                {{-- 🚨 Erano due link a pagine che non esistevano: il piè di
                     pagina prometteva l'informativa e rispondeva 404. --}}
                <a href="/privacy">Informativa privacy</a>
                <a href="/condizioni">Condizioni d'uso</a>
            </div>
            <div>
                <h4>Accesso</h4>
                <a href="/admin/login">Pannello palestra</a>
            </div>
        </div>

        <div class="chiusura-pie">
            Training Companion · L'app funziona senza AI, gratis e per sempre.
        </div>
    </div>
</footer>

</body>
</html>
