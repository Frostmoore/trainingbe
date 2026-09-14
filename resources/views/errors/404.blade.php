{{--
    La pagina che non c'è — 14/09/2026.

    ── 🚨 Perché è stata scritta ──────────────────────────────────────────────

    Il sito **non aveva nessuna pagina d'errore**: qualunque indirizzo sbagliato
    dava il bianco di Laravel, con scritto «404 | Not Found» in mezzo allo
    schermo e **nessun modo di tornare indietro**.

    ⚠️ Trovato il 14/09 aprendo dal telefono un indirizzo dell'app
    (`/calendario`): le rotte dell'app non sono collegamenti del sito — solo
    `/invito-palestra` lo è — e chi ne riceve uno trovava una pagina vuota che
    non somigliava a niente.

    💡 **Due cose sole servono a una pagina d'errore**: dire cosa è successo
    senza far sentire stupido chi legge, e dare una strada. ⛔ Le illustrazioni
    e le battute no.
--}}
@extends('sito.layout')

@section('titolo', 'Pagina non trovata — Training Companion')
@section('descrizione', 'Questo indirizzo non esiste. Torna alla home.')

@section('contenuto')
    <section>
        <div class="contenitore stretto" style="text-align: center;">
            <div class="occhiello">Errore 404</div>

            <h1>Questo indirizzo non esiste.</h1>

            <p class="guida" style="margin: 0 auto var(--sp-4);">
                {{--
                    ⚠️ **Non si dà la colpa a chi legge.** «Hai sbagliato a
                    digitare» è quasi sempre falso — di solito è un link vecchio
                    nostro — e quando è vero non aiuta comunque.
                --}}
                Può darsi che la pagina sia stata spostata, o che il collegamento
                che hai seguito sia vecchio.
            </p>

            <div style="display: flex; gap: var(--sp-2); justify-content: center; flex-wrap: wrap;">
                <a href="/" class="bottone bottone-pieno bottone-grande">Torna alla home</a>
                <a href="/prezzi" class="bottone bottone-vuoto bottone-grande">Vedi i prezzi</a>
            </div>

            {{--
                🚨 **Il caso vero per cui questa pagina esiste**: un indirizzo
                dell'app aperto nel browser. 💡 Chi ci arriva ha già l'app, o la
                sta cercando: dirglielo è l'unica cosa utile che si può fare.
            --}}
            <div class="scheda tenue" style="margin-top: var(--sp-5); text-align: left;">
                <h3>Stavi cercando una pagina dell'app?</h3>
                <p class="nota" style="margin-top: 6px;">
                    Il diario, gli allenamenti e i messaggi vivono
                    <strong>dentro l'applicazione</strong>, non su questo sito: i
                    tuoi dati stanno sul telefono, e da qui non si aprono.
                    Aprila dal telefono e li trovi lì.
                </p>
            </div>
        </div>
    </section>
@endsection
