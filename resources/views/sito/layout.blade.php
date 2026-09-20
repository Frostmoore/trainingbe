{{--
    Il guscio del sito pubblico — rifatto il 20/09/2026 (la terza versione).

    ── 📌 Perché è stato rifatto ──────────────────────────────────────────────

    Il committente, davanti alla versione del mattino: *«La home fa schifo ar
    cazzo, non mi piace per niente l'hero, deve essere un'immagine a piena
    pagina … vatti a vedere su internet come sono fatti i siti dei competitors
    e fallo come i loro più o meno»*.

    ── 💡 Cosa dicono i siti guardati (Hevy, Trainerize, Yazio) ───────────────

    | Cosa | Come lo fanno tutti e tre |
    |---|---|
    | Apertura | **Immagine a tutta pagina**, titolo di tre parole, una riga sotto, i due pulsanti degli store |
    | Subito dopo | Le **schermate dell'app** dentro un telefono, una funzione per riga |
    | Prezzi | ⚠️ **Non sulla home**: una pagina per platea, raggiunta da una tendina |
    | Iscrizione | Trainerize la mette **in apertura**: nome, email, password, e si è dentro |
    | Prove sociali | Loghi e numeri — ⛔ noi non ne abbiamo e **non se ne inventano** |

    🚨 **Niente CDN, niente build, niente JavaScript.** Il backend gira su un
    server condiviso con altri due siti, e ogni dipendenza esterna è una cosa che
    può cadere portandosi dietro la pagina che dovrebbe vendere il prodotto. Lo
    stile è incorporato: è lungo, e continua a non valere una catena di build.
    ⚠️ E i **commenti qui dentro finiscono nella pagina** (stanno in un `<style>`):
    `the_home_does_not_pitch_the_reseller_margin` li legge come testo.
--}}
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('titolo', 'Training Companion')</title>
    <meta name="description" content="@yield('descrizione', 'L\'app per allenarsi e mangiare con la testa. Gratis, con i tuoi dati sul telefono.')">
    <meta name="theme-color" content="#0F766E">

    {{--
        🚨 **L'anteprima quando il link viene condiviso.** È il primo posto in cui
        il prodotto si presenta a chi non lo conosce, e presentarsi con un
        riquadro grigio è peggio che non farsi condividere.
    --}}
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="Training Companion">
    <meta property="og:locale" content="it_IT">
    <meta property="og:title" content="@yield('titolo', 'Training Companion')">
    <meta property="og:description" content="@yield('descrizione', 'L\'app per allenarsi e mangiare con la testa. Gratis.')">
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
        L'icona della scheda. 💡 **È lo stesso file del marchio**: due file che
        devono restare uguali finiscono sempre per non esserlo più.
    --}}
    @php($segnoIcona = app(\App\Support\ImmaginiDelSito::class)->marchio())
    <link rel="icon" href="{{ $segnoIcona ?? "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='9' fill='%230F766E'/%3E%3Crect x='9' y='9' width='14' height='14' rx='4' fill='%23ECFDF5'/%3E%3C/svg%3E" }}">

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
            --scuro: #0B1220;

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

        .contenitore { max-width: 1120px; margin: 0 auto; padding: 0 var(--sp-3); }
        .stretto { max-width: 760px; }

        /* ───────────────────────── tipografia ───────────────────────── */

        h1 {
            font-size: clamp(36px, 6.4vw, 60px); line-height: 1.04;
            letter-spacing: -0.035em; margin: 0 0 var(--sp-2); font-weight: 800;
        }
        h2 {
            font-size: clamp(27px, 3.6vw, 38px); line-height: 1.16;
            letter-spacing: -0.025em; margin: 0 0 var(--sp-2); font-weight: 750;
        }
        h3 { font-size: 19px; margin: 0 0 6px; letter-spacing: -0.01em; font-weight: 700; }
        a { color: var(--accento); }

        .occhiello {
            text-transform: uppercase; letter-spacing: .08em;
            font-size: 12px; font-weight: 700; color: var(--accento);
            margin-bottom: var(--sp-1);
        }

        .guida { color: var(--testo-tenue); font-size: clamp(17px, 2vw, 19px); max-width: 62ch; margin: 0; }
        .nota { color: var(--testo-tenue); font-size: 14px; }

        section { padding: var(--sp-6) 0; }

        /* 🚨 **Il nome dice cosa fa**: una sezione su due ha lo sfondo pieno, e
           il contrasto è l'unica cosa che divide una pagina lunga in blocchi
           leggibili. */
        section.alternata { background: var(--superficie); border-block: 1px solid var(--bordo); }

        /* L'aggancio deve fermare la sezione sotto la barra fissa in alto, non
           sotto il suo bordo superiore: senza, il titolo finisce coperto. */
        section[id] { scroll-margin-top: 76px; }

        /* ───────────────────────── bottoni ───────────────────────── */

        .bottone {
            display: inline-flex; align-items: center; justify-content: center;
            gap: 8px; padding: 11px 20px; border-radius: 10px;
            font-weight: 600; font-size: 15px; text-decoration: none;
            border: 1px solid transparent; cursor: pointer;
            font-family: inherit;
            transition: transform .06s ease, background .15s ease, border-color .15s ease;
        }
        .bottone:active { transform: translateY(1px); }
        .bottone-pieno { background: var(--accento); color: #fff; }
        .bottone-pieno:hover { background: var(--accento-scuro); }
        .bottone-vuoto { border-color: var(--bordo); color: var(--testo); background: var(--sfondo); }
        .bottone-vuoto:hover { border-color: var(--testo-tenue); }
        .bottone-grande { padding: 14px 26px; font-size: 16px; }

        /* Sopra una fotografia il bordo grigio sparisce: qui è bianco. */
        .su-foto .bottone-vuoto { border-color: rgba(255,255,255,.55); color: #fff; background: rgba(255,255,255,.10); }
        .su-foto .bottone-vuoto:hover { background: rgba(255,255,255,.20); }

        .fila-bottoni { display: flex; gap: var(--sp-2); flex-wrap: wrap; align-items: center; }
        .fila-bottoni.centrata { justify-content: center; }

        /*
           🚨 **La pillola «Presto sugli store» sta in una fila di pulsanti.**

           ⚠️ Con il suo disegno normale — piccola, bassa, con l'angolo tondo
           pieno — accanto a un pulsante grande sembrava **un pulsante rotto**:
           stessa posizione, stesso peso visivo, altezza diversa. 💡 Qui prende
           l'altezza di un pulsante e il bordo tratteggiato, che dice «questa non
           si preme» senza doverlo scrivere.
        */
        .fila-bottoni .pillola {
            padding: 13px 20px;
            border-radius: 10px;
            border-style: dashed;
            font-weight: 600;
        }
        .fila-bottoni .pillola:only-child { margin: 0; }

        /* ───────────────────────── navigazione ───────────────────────── */

        .navbar {
            position: sticky; top: 0; z-index: 50;
            background: color-mix(in srgb, var(--sfondo) 88%, transparent);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--bordo);
        }

        .navbar .contenitore { display: flex; align-items: center; gap: var(--sp-3); height: 64px; }

        .marchio {
            font-weight: 800; font-size: 18px; letter-spacing: -0.02em;
            color: var(--testo); text-decoration: none;
            display: flex; align-items: center; gap: 8px;
        }
        .marchio .punto { width: 10px; height: 10px; border-radius: 3px; background: var(--accento); display: inline-block; }

        .nav-voci { display: flex; gap: var(--sp-3); margin-left: auto; align-items: center; }
        .nav-voci > a { color: var(--testo-tenue); text-decoration: none; font-size: 15px; font-weight: 500; }
        .nav-voci > a:hover { color: var(--testo); }

        /*
           La tendina dei prezzi — 📌 chiesta così: *«Prezzi deve essere un menù
           a tendina con Individuale, trainer e palestra con tre pagine
           diverse»*.

           🚨 **`<details>`, non JavaScript.** Apre al clic e dalla tastiera, si
           chiude con Esc, e su un telefono si comporta come si aspetta chi lo
           usa. ⚠️ Con un menù fatto a mano servirebbero JavaScript, la gestione
           del fuoco e `aria-expanded` scritto a mano: tre cose che si rompono
           in silenzio, per avere lo stesso risultato.
        */
        .tendina { position: relative; }
        .tendina > summary {
            list-style: none; cursor: pointer;
            color: var(--testo-tenue); font-size: 15px; font-weight: 500;
            display: inline-flex; align-items: center; gap: 5px;
        }
        .tendina > summary::-webkit-details-marker { display: none; }
        .tendina > summary::after { content: "▾"; font-size: 11px; }
        .tendina[open] > summary, .tendina > summary:hover { color: var(--testo); }

        .tendina-voci {
            position: absolute; top: calc(100% + 12px); left: 50%; transform: translateX(-50%);
            min-width: 230px; padding: var(--sp-1);
            background: var(--sfondo); border: 1px solid var(--bordo);
            border-radius: 12px; box-shadow: 0 16px 40px rgba(0,0,0,.12);
        }
        .tendina-voci a {
            display: block; padding: 9px 12px; border-radius: 8px;
            color: var(--testo); text-decoration: none; font-size: 15px;
        }
        .tendina-voci a:hover { background: var(--accento-tenue); color: var(--accento); }
        .tendina-voci small { display: block; color: var(--testo-tenue); font-size: 12.5px; line-height: 1.35; }

        /* ⚠️ Sotto i 720 px le voci spariscono e resta l'azione: un menù a
           panino per quattro link è più lavoro per chi legge, non meno. La
           tendina resta, perché è l'unica strada verso i prezzi. */
        @media (max-width: 720px) {
            .nav-voci .solo-grande { display: none; }
            .navbar .contenitore { gap: var(--sp-2); }
            .tendina-voci { left: auto; right: 0; transform: none; }
        }

        /* ───────────────────────── l'apertura ─────────────────────────

           📌 *«deve essere un'immagine a piena pagina»*. La fotografia sta
           **sotto** al testo, non accanto: è il disegno di tutti e tre i siti
           guardati, ed è l'unico che regge sia su uno schermo largo sia su un
           telefono senza due versioni diverse.
        */

        .eroe {
            position: relative; isolation: isolate;
            min-height: min(86vh, 780px);
            display: grid; align-items: center;
            padding: var(--sp-6) 0 var(--sp-5);
            color: #fff;
            overflow: hidden;
        }

        .eroe-sfondo { position: absolute; inset: 0; z-index: -2; }
        .eroe-sfondo .figura { width: 100%; height: 100%; border: 0; border-radius: 0; }
        .eroe-sfondo img { width: 100%; height: 100%; object-fit: cover; }

        /* 🚨 Il velo non è estetica: senza, il titolo bianco su una fotografia
           chiara diventa **illeggibile**, e non si scopre finché qualcuno non
           carica l'immagine sbagliata. Va scuro abbastanza da reggere qualunque
           immagine ci finisca sotto. */
        .eroe-velo {
            position: absolute; inset: 0; z-index: -1;
            background:
                linear-gradient(180deg, rgba(4,12,20,.55) 0%, rgba(4,12,20,.72) 100%),
                radial-gradient(90% 70% at 15% 20%, rgba(15,118,110,.45) 0%, transparent 70%);
        }

        .eroe h1 { color: #fff; max-width: 15ch; }
        .eroe .guida { color: rgba(255,255,255,.88); font-size: clamp(18px, 2.2vw, 21px); max-width: 52ch; }
        .eroe .pillola { background: rgba(255,255,255,.14); color: #fff; border-color: rgba(255,255,255,.3); }

        /* La striscia in fondo all'apertura: tre fatti, non tre slogan. */
        .eroe-fatti {
            display: flex; flex-wrap: wrap; gap: var(--sp-3);
            margin-top: var(--sp-5); padding-top: var(--sp-3);
            border-top: 1px solid rgba(255,255,255,.22);
            color: rgba(255,255,255,.9); font-size: 15px;
        }
        .eroe-fatti span { display: inline-flex; align-items: center; gap: 8px; }

        /* ───────────────────────── figure e telefono ─────────────────────────

           🚨 Ogni figura ha il suo rapporto in `aspect-ratio`, quindi **occupa
           il suo spazio prima di essersi caricata**: senza, la pagina salta
           mentre le immagini arrivano e chi legge perde il segno.
        */

        .figura {
            width: 100%;
            border-radius: var(--raggio);
            overflow: hidden;
            background: var(--superficie);
            border: 1px solid var(--bordo);
        }
        .figura img { width: 100%; height: 100%; object-fit: cover; display: block; }

        /* 💡 Il posto vuoto non dice «manca un'immagine»: mette il segno del
           prodotto su una sfumatura dei suoi colori, e sembra una scelta. */
        .figura-vuota {
            width: 100%; height: 100%;
            display: grid; place-items: center;
            background:
                radial-gradient(120% 90% at 20% 0%, var(--accento-tenue) 0%, transparent 60%),
                linear-gradient(140deg, var(--superficie) 0%, var(--sfondo) 100%);
        }
        .figura-vuota .punto { width: 22px; height: 22px; border-radius: 7px; background: var(--accento); opacity: .30; }

        /* La figura in cima a una scheda: senza bordo proprio e senza angoli in
           basso, perché è incollata alla scheda che la contiene. */
        .scheda > .figura:first-child {
            border: 0; border-radius: 0;
            margin: calc(var(--sp-3) * -1) calc(var(--sp-3) * -1) var(--sp-3);
            width: auto;
        }

        /*
           Il telaio del telefono intorno a una schermata.

           💡 **Serve anche quando la schermata non c'è**: con il telaio, il
           riempimento vuoto si legge come un mockup; senza, come un rettangolo
           grigio in mezzo alla pagina. ⚠️ È il modo in cui il sito resta
           presentabile mentre le catture dall'emulatore non sono ancora state
           fatte.
        */
        .telefono {
            width: 100%; max-width: 268px; margin: 0 auto;
            border: 9px solid #0F172A; border-radius: 34px;
            background: #0F172A; overflow: hidden;
            box-shadow: 0 26px 60px rgba(2,10,20,.30);
            position: relative;
        }
        .telefono .figura { border: 0; border-radius: 26px; }
        .telefono::before {
            content: ""; position: absolute; top: 7px; left: 50%; transform: translateX(-50%);
            width: 74px; height: 6px; border-radius: 99px; background: rgba(255,255,255,.35); z-index: 2;
        }
        .telefoni-affiancati { display: flex; gap: var(--sp-2); justify-content: center; flex-wrap: wrap; }
        .telefoni-affiancati .telefono { max-width: 232px; }

        /* ───────────────────────── griglie e schede ───────────────────────── */

        .griglia { display: grid; gap: var(--sp-2); grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); }
        .griglia-2 { display: grid; gap: var(--sp-4); grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); align-items: center; }

        /* Una funzione per riga: testo da una parte, schermata dall'altra.
           `.rovescia` scambia le due colonne — ⚠️ solo da 860 px in su, perché
           su un telefono l'ordine visivo deve restare quello del documento
           (prima il titolo, poi l'immagine). */
        .riga-funzione { display: grid; gap: var(--sp-4); align-items: center; grid-template-columns: 1fr; }
        @media (min-width: 860px) {
            .riga-funzione { grid-template-columns: 1.05fr .95fr; }
            .riga-funzione.rovescia > *:first-child { order: 2; }
        }
        .riga-funzione + .riga-funzione { margin-top: var(--sp-6); }

        .scheda {
            border: 1px solid var(--bordo); border-radius: var(--raggio);
            padding: var(--sp-3); background: var(--sfondo);
        }
        .scheda.tenue { background: var(--superficie); }
        .scheda h3 { display: flex; align-items: center; gap: 9px; }

        .pillola {
            display: inline-block; padding: 5px 12px; border-radius: 999px;
            background: var(--accento-tenue); color: var(--accento);
            font-size: 13px; font-weight: 700;
            border: 1px solid color-mix(in srgb, var(--accento) 25%, transparent);
        }

        .elenco { list-style: none; padding: 0; margin: var(--sp-2) 0 0; font-size: 15px; }
        .elenco li { padding: 5px 0 5px 26px; position: relative; }
        .elenco li::before { content: "✓"; position: absolute; left: 0; top: 5px; color: var(--accento); font-weight: 800; }
        .elenco li.no { color: var(--testo-tenue); }
        .elenco li.no::before { content: "—"; color: var(--testo-tenue); }

        /* ───────────────────────── il listino ───────────────────────── */

        .griglia-pacchetti {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(230px, 1fr));
            gap: var(--sp-2);
            align-items: stretch;
        }
        .griglia-pacchetti .scheda { display: flex; flex-direction: column; }

        .scheda-prezzo { display: flex; flex-direction: column; }
        /* La scheda consigliata si stacca in altezza, non solo di colore.
           ⚠️ Niente `transform: scale()`: su una griglia sfalsa gli allineamenti
           e su schermo piccolo esce dal contenitore. */
        .scheda-prezzo.scelta {
            border-color: var(--accento); border-width: 2px;
            box-shadow: 0 10px 30px color-mix(in srgb, var(--accento) 14%, transparent);
        }

        .cifra { font-size: clamp(30px, 4vw, 38px); font-weight: 800; letter-spacing: -0.03em; line-height: 1.05; margin: var(--sp-1) 0 2px; }
        .cifra small { font-size: 15px; font-weight: 500; color: var(--testo-tenue); letter-spacing: 0; }
        .sotto-cifra { margin: 0 0 var(--sp-2); font-size: 14px; color: var(--testo-tenue); }
        .in-fondo { margin-top: auto; padding-top: var(--sp-3); }

        .tabella-prezzi { width: 100%; border-collapse: collapse; font-size: 16px; }
        .tabella-prezzi th { text-align: left; padding: 13px var(--sp-2); background: var(--superficie); border-bottom: 1px solid var(--bordo); }
        .tabella-prezzi td { padding: 13px var(--sp-2); border-bottom: 1px solid var(--bordo); vertical-align: top; }
        .tabella-prezzi tr:last-child td { border-bottom: 0; }
        .tabella-prezzi .si { color: var(--accento); font-weight: 700; }
        .tabella-prezzi .no { color: var(--testo-tenue); }

        /* Il confronto gratis / Plus: due colonne, e si legge in tre secondi. */
        .confronto { overflow-x: auto; border: 1px solid var(--bordo); border-radius: var(--raggio); background: var(--sfondo); }
        .confronto table { width: 100%; border-collapse: collapse; min-width: 520px; }
        .confronto th, .confronto td { padding: 13px var(--sp-2); text-align: left; border-bottom: 1px solid var(--bordo); }
        .confronto thead th { background: var(--superficie); font-size: 14px; }
        .confronto thead th:last-child { color: var(--accento); }
        .confronto tbody tr:last-child td { border-bottom: 0; }
        .confronto td:first-child { font-weight: 600; }

        /* ───────────────────────── moduli ───────────────────────── */

        .modulo { display: grid; gap: var(--sp-2); max-width: 520px; }
        .campo { display: grid; gap: 5px; }
        .campo label { font-size: 14px; font-weight: 600; }
        .campo input[type="text"], .campo input[type="email"], .campo input[type="password"] {
            padding: 11px 13px; border: 1px solid var(--bordo); border-radius: 10px;
            background: var(--sfondo); color: var(--testo); font: inherit; font-size: 16px;
            width: 100%;
        }
        .campo input:focus { outline: 2px solid var(--accento); outline-offset: 1px; border-color: var(--accento); }
        .campo .aiuto { font-size: 13px; color: var(--testo-tenue); }
        .campo-spunta { display: flex; gap: 10px; align-items: flex-start; font-size: 14.5px; }
        .campo-spunta input { margin-top: 4px; }
        .errore { color: #B91C1C; font-size: 13.5px; margin: 0; }
        @media (prefers-color-scheme: dark) { .errore { color: #FCA5A5; } }
        .avviso {
            border: 1px solid color-mix(in srgb, #B91C1C 35%, transparent);
            background: color-mix(in srgb, #B91C1C 8%, transparent);
            border-radius: 12px; padding: var(--sp-2); margin-bottom: var(--sp-3);
        }

        /* ───────────────────────── domande frequenti ───────────────────────── */

        details.domanda { border-bottom: 1px solid var(--bordo); padding: var(--sp-2) 0; }
        details.domanda summary {
            cursor: pointer; font-weight: 650; font-size: 17px;
            list-style: none; display: flex; justify-content: space-between; gap: var(--sp-2);
        }
        details.domanda summary::-webkit-details-marker { display: none; }
        details.domanda summary::after { content: "+"; color: var(--accento); font-weight: 800; }
        details.domanda[open] summary::after { content: "−"; }
        details.domanda p { color: var(--testo-tenue); margin: var(--sp-1) 0 0; max-width: 68ch; }

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

        /*
           🚨 **Niente `margin-top` qui.** Ce n'era uno di 40 px, e sotto a una
           sezione `.alternata` — che ha lo stesso sfondo del piè di pagina —
           disegnava una **striscia del colore della pagina** fra le due:
           sembrava un blocco vuoto caricato a metà. ⚠️ Si vedeva solo quando
           l'ultima sezione era alternata, cioè su tre pagine su sei, ed è il
           motivo per cui non saltava all'occhio scrivendola.

           💡 Lo spazio lo fa già il `padding` delle sezioni: aggiungerne qui è
           spazio contato due volte. ⚠️ E la parola che stavo per usare qui è
           fra quelle vietate sulla home — i commenti di questo foglio finiscono
           nella pagina, e un test le cerca.
        */
        footer {
            border-top: 1px solid var(--bordo); background: var(--superficie);
            padding: var(--sp-5) 0 var(--sp-4);
        }
        .colonne-pie { display: grid; gap: var(--sp-3); grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
        .colonne-pie h4 { font-size: 13px; text-transform: uppercase; letter-spacing: .06em; color: var(--testo-tenue); margin: 0 0 var(--sp-1); }
        .colonne-pie a { display: block; color: var(--testo); text-decoration: none; font-size: 15px; padding: 3px 0; }
        .colonne-pie a:hover { color: var(--accento); }
        .chiusura-pie { margin-top: var(--sp-4); padding-top: var(--sp-2); border-top: 1px solid var(--bordo); color: var(--testo-tenue); font-size: 14px; }

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
        {{-- 💡 Il nome resta **testo vero**, sempre: un marchio con dentro le
             lettere sarebbe illeggibile agli screen reader e ai motori di
             ricerca, e sfocato sugli schermi che non gli piacciono. --}}
        @php($segno = app(\App\Support\ImmaginiDelSito::class)->marchio())
        <a href="/" class="marchio">
            @if ($segno !== null)
                <img src="{{ $segno }}" alt="" width="28" height="28" style="border-radius: 7px;">
            @else
                <span class="punto"></span>
            @endif
            Training Companion
        </a>

        <div class="nav-voci">
            <a href="/#funzioni" class="solo-grande">Funzioni</a>

            <details class="tendina">
                <summary>Prezzi</summary>
                <div class="tendina-voci">
                    <a href="/prezzi/individuale">
                        Individuale
                        <small>Per chi si allena, da solo o seguito</small>
                    </a>
                    <a href="/prezzi/trainer">
                        Trainer
                        <small>Per chi segue allievi suoi</small>
                    </a>
                    <a href="/prezzi/palestra">
                        Palestra
                        <small>Per una sala con più istruttori</small>
                    </a>
                </div>
            </details>

            <a href="/professionisti" class="solo-grande">Per chi allena</a>
            <a href="/admin/login" class="bottone bottone-vuoto solo-grande">Pannello</a>

            {{-- 💡 Quando l'app sarà sugli store, questo diventa il pulsante
                 principale della barra senza toccare niente. --}}
            <x-sito.scarica />
        </div>
    </div>
</nav>

@yield('contenuto')

<footer>
    <div class="contenitore">
        <div class="colonne-pie">
            <div>
                <h4>Prodotto</h4>
                <a href="/#funzioni">Funzioni</a>
                <a href="/#gratis-o-plus">Gratis o Plus</a>
                <a href="/#dati">I tuoi dati</a>
                <a href="/#domande">Domande frequenti</a>
            </div>
            <div>
                <h4>Prezzi</h4>
                <a href="/prezzi/individuale">Individuale</a>
                <a href="/prezzi/trainer">Trainer</a>
                <a href="/prezzi/palestra">Palestra</a>
            </div>
            <div>
                <h4>Chi allena</h4>
                <a href="/professionisti">Palestre e trainer</a>
                <a href="/liberatoria-palestra">Liberatoria palestre</a>
                <a href="/liberatoria-trainer">Liberatoria trainer</a>
            </div>
            <div>
                <h4>Documenti</h4>
                {{-- 🚨 Erano due link a pagine che non esistevano: il piè di
                     pagina prometteva l'informativa e rispondeva 404. --}}
                <a href="/privacy">Informativa privacy</a>
                <a href="/condizioni">Condizioni d'uso</a>
                <a href="/admin/login">Pannello palestra</a>
            </div>
        </div>

        <div class="chiusura-pie">
            Training Companion · Diario, allenamenti e schede. L'app è gratis, e i tuoi dati restano sul tuo telefono.
        </div>
    </div>
</footer>

</body>
</html>
