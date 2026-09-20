{{--
    Lo smistamento dei prezzi — 20/09/2026.

    ── 📌 Perché questa pagina è diventata un bivio ───────────────────────────

    Il committente: *«Prezzi deve essere un menù a tendina con Individuale,
    trainer e palestra con tre pagine diverse»*. La tendina sta nella barra in
    alto; questa pagina è quello che trova chi arriva su `/prezzi` da un link
    vecchio, da un motore di ricerca o scrivendo l'indirizzo.

    ⚠️ **Non è un redirect**, ed è voluto: un redirect sceglierebbe per conto di
    chi arriva, e qui non c'è niente da cui indovinare se sia una persona, un
    trainer o una palestra. Tre porte con scritto cosa c'è dietro costano un
    clic e non sbagliano mai.
--}}
@extends('sito.layout')

@section('titolo', 'Prezzi — Training Companion')
@section('descrizione', 'L\'app è gratis. Il Plus costa 8 € al mese; per trainer e palestre ci sono i piani a posti, da 30 € e da 60 € al mese.')

@section('contenuto')

    <section style="padding-bottom: var(--sp-4);">
        <div class="contenitore" style="text-align: center;">
            <span class="pillola">Listino</span>
            <h1 style="margin-top: var(--sp-2);">Per chi stai guardando?</h1>

            <p class="guida" style="margin: 0 auto;">
                L'app è gratis per chiunque. Quello che si paga è l'assistente con
                l'intelligenza artificiale — e chi allena altre persone compra i posti per
                regalarglielo. Tutti i prezzi sono quelli finali: niente da aggiungere alla
                cassa.
            </p>
        </div>
    </section>

    <section style="padding-top: 0;">
        <div class="contenitore">
            <div class="griglia">
                <div class="scheda scheda-prezzo">
                    <h3 style="margin-top: 0;">🙋 Individuale</h3>
                    <p class="cifra">{{ $formatta($prezzoSingolo) }}<small> / mese</small></p>
                    <p class="sotto-cifra">…e l'app resta gratis anche senza</p>
                    <p>
                        Per chi si allena, da solo o seguito da qualcuno. Il Plus accende
                        l'assistente automatico; senza, l'app funziona per intero.
                    </p>
                    <div class="in-fondo">
                        <a href="/prezzi/individuale" class="bottone bottone-pieno">Gratis o Plus</a>
                    </div>
                </div>

                <div class="scheda scheda-prezzo scelta">
                    <h3 style="margin-top: 0;">🏋 Trainer</h3>
                    <p class="cifra">da {{ $formatta($daTrainer) }}<small> / mese</small></p>
                    <p class="sotto-cifra">posti per i tuoi allievi</p>
                    <p>
                        Per chi segue allievi suoi: abboni le persone che vuoi, scrivi schede e
                        piani, e hai una chat con ciascuno.
                    </p>
                    <div class="in-fondo">
                        <a href="/prezzi/trainer" class="bottone bottone-pieno">Piani per i trainer</a>
                    </div>
                </div>

                <div class="scheda scheda-prezzo">
                    <h3 style="margin-top: 0;">🏛 Palestra</h3>
                    <p class="cifra">da {{ $formatta($daPalestra) }}<small> / mese</small></p>
                    <p class="sotto-cifra">posti iscritto e posti trainer</p>
                    <p>
                        Per una sala con più istruttori: l'app si veste dei tuoi colori e i
                        modelli restano della palestra.
                    </p>
                    <div class="in-fondo">
                        <a href="/prezzi/palestra" class="bottone bottone-pieno">Piani per le palestre</a>
                    </div>
                </div>
            </div>

            <p class="nota" style="margin-top: var(--sp-3); text-align: center;">
                Non sai da dove cominciare? <a href="/professionisti">Come funziona per chi allena</a>.
            </p>

            <div style="margin-top: var(--sp-4);">
                <x-sito.figura
                    nome="prezzi"
                    alt="Attrezzi da palestra allineati lungo una parete"
                />
            </div>
        </div>
    </section>

@endsection
