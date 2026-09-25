@extends('sito.layout')

@section('titolo', 'Non ricevere più offerte — Training Companion')
@section('descrizione', 'Smetti di ricevere le email promozionali di Training Companion.')

@section('contenuto')
    {{--
        🔕 **La pagina di disiscrizione** — voce 43, art. 130, c. 4, 25/09/2026.

        🚨 **Questa pagina non spegne niente**: a spegnere è il bottone, che fa
        un `POST`. ⛔ I filtri antispam e le anteprime dei client di posta aprono
        i link da soli, e un `GET` che eseguisse vorrebbe dire persone
        disiscritte da un antivirus.

        ⚠️ **Dice cosa NON si spegne**, e non è cortesia: chi teme di perdere
        l'avviso che il suo abbonamento sta per scadere non preme il bottone — e
        un diritto che nessuno esercita per paura resta sulla carta.
    --}}
    <section>
        <div class="contenitore" style="max-width: 44rem; padding-block: 4rem;">

            @if ($giaFatto)
                <h1>È fatto.</h1>

                <p>
                    @if ($email)
                        A <strong>{{ $email }}</strong> non mandiamo più
                    @else
                        Non mandiamo più
                    @endif
                    email promozionali né messaggi nell'app che propongono un acquisto.
                </p>

                <p>
                    Se cambi idea, l'interruttore è nell'app:
                    <strong>Profilo → Privacy → Le offerte</strong>.
                </p>
            @else
                <h1>Non ricevere più offerte</h1>

                <p>
                    @if ($email)
                        Stai per dire che a <strong>{{ $email }}</strong> non vuoi più ricevere
                    @else
                        Stai per dire che non vuoi più ricevere
                    @endif
                    le nostre <strong>email promozionali</strong> né i messaggi nell'app che
                    propongono un acquisto.
                </p>

                {{-- 🚨 Il confine, scritto prima del bottone e non dopo. --}}
                <p>
                    <strong>Continui a ricevere</strong> gli avvisi di servizio — per esempio
                    che l'abbonamento sta per scadere — le notifiche dei messaggi che ti
                    scrivono le persone, e le email necessarie a usare il servizio: password,
                    inviti, ricevute.
                </p>

                <p>
                    E se ti spetta una promozione <strong>la ricevi comunque</strong>: quello
                    che si spegne è l'annuncio, non il regalo.
                </p>

                <form method="POST" action="{{ $azione }}">
                    @csrf
                    <button type="submit" class="bottone bottone-primario">
                        Non mandarmi più offerte
                    </button>
                </form>

                <p style="margin-top: 2rem; font-size: .9rem;">
                    Puoi anche farlo dall'app, e tornare indietro quando vuoi:
                    <strong>Profilo → Privacy → Le offerte</strong>.
                </p>
            @endif

        </div>
    </section>
@endsection
