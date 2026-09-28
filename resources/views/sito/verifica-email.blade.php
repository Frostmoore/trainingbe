@extends('sito.layout')

@section('titolo', 'Conferma del tuo indirizzo — Training Companion')
@section('descrizione', 'La conferma dell\'indirizzo email per Training Companion.')

@section('contenuto')
    {{--
        ✅ **La pagina che conferma l'indirizzo** — voce 62.6, 28/09/2026.

        ⚠️ **Tre esiti, e si dicono tutti e tre**: fatto, già fatto, non valido.
        ⛔ Accorpare «già fatto» a «fatto» sarebbe comodo e quasi vero — ma chi
        tocca un link due volte (le app di posta li precaricano) merita di
        sapere che non ha rotto niente.

        🚨 E «non valido» non dice PERCHE': se l'id non esiste o se l'impronta
        non torna. Distinguerli direbbe a chi prova link a caso se un id esiste.

        💡 In tutti e tre i casi si ricorda che l'accesso NON dipende da questa
        pagina: è la voce 63, e vale anche nei testi.
    --}}
    <section>
        <div class="contenitore" style="max-width: 44rem; padding-block: 4rem;">

            @if ($esito === 'fatto')
                <h1>Fatto{{ isset($nome) ? ', '.$nome : '' }}.</h1>

                <p>
                    Il tuo indirizzo è confermato. Ora puoi vedere nell'app
                    <strong>gli inviti che hai ricevuto</strong> da una palestra o da un trainer.
                </p>

                <p>Puoi chiudere questa pagina e tornare nell'app.</p>

            @elseif ($esito === 'gia-fatto')
                <h1>Era già confermato.</h1>

                <p>
                    Questo indirizzo risultava già confermato: <strong>non hai rotto niente</strong>.
                    Succede quando si tocca il link due volte, o quando il programma di posta lo
                    apre da solo.
                </p>

                <p>Puoi chiudere questa pagina e tornare nell'app.</p>

            @else
                <h1>Questo link non vale più.</h1>

                <p>
                    Può essere scaduto — valgono un'ora — oppure essere stato fatto per un
                    indirizzo che nel frattempo hai cambiato.
                </p>

                <p>
                    Non è un problema: <strong>chiedine un altro dall'app</strong>, dalla schermata
                    degli inviti.
                </p>
            @endif

            <p style="margin-top: 2rem; opacity: .75;">
                ⚠️ In ogni caso <strong>entri nell'app come sempre</strong>: l'accesso non dipende
                da questa conferma. Serve solo a farti vedere gli inviti ricevuti.
            </p>

        </div>
    </section>
@endsection
