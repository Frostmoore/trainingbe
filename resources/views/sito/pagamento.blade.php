{{--
    Il ritorno da Stripe — 20/09/2026.

    ── 🚨 Perché non è più una stringa dentro `routes/web.php` ────────────────

    Perché fino a ieri diceva **una cosa sola**: *«Puoi tornare all'app»*. Era
    vero finché da Stripe tornava solo chi aveva comprato gettoni o l'abbonamento
    dall'app. 🆕 Dal 20/09 da qui torna anche una palestra che si è appena
    iscritta dal sito, e a lei «torna all'app» non dice niente: il suo posto è
    il pannello.

    💡 La differenza la fa la **sessione**: chi si è iscritto dal sito è entrato
    prima di uscire verso Stripe, chi ha pagato dall'app no. ⚠️ Non è un
    controllo di sicurezza — questa pagina non accredita niente e non decide
    niente — è solo il modo di dare la strada giusta a chi legge.

    ── ⛔ E non dice «fatto» ──────────────────────────────────────────────────

    Questa pagina **non è la conferma del pagamento**: chiunque può aprirla
    scrivendo l'indirizzo, e al ritorno il webhook potrebbe non essere ancora
    arrivato. Promettere un saldo che non c'è ancora farebbe ricaricare la
    schermata per niente.
--}}
@extends('sito.layout')

@section('titolo', $riuscito ? 'Pagamento ricevuto — Training Companion' : 'Pagamento annullato — Training Companion')

@section('contenuto')

    <section>
        <div class="contenitore stretto" style="text-align: center;">
            @if ($riuscito)
                <span class="pillola">Ci siamo</span>
                <h1 style="margin-top: var(--sp-2);">Pagamento ricevuto.</h1>

                @auth
                    <p class="guida" style="margin: 0 auto;">
                        Il tuo spazio è pronto. Dal pannello puoi invitare le persone, scrivere
                        le schede e vedere cosa stai pagando.
                        🚨 La prima volta ti verrà chiesto di firmare la liberatoria dell'art. 28:
                        sono due minuti, e senza non si vedono i dati di nessuno.
                    </p>

                    <div class="fila-bottoni centrata" style="margin-top: var(--sp-3);">
                        <a href="/admin" class="bottone bottone-pieno bottone-grande">Entra nel pannello</a>
                    </div>
                @else
                    <p class="guida" style="margin: 0 auto;">
                        Puoi tornare all'app: fra qualche secondo trovi tutto al suo posto.
                    </p>

                    <div class="fila-bottoni centrata" style="margin-top: var(--sp-3);">
                        <x-sito.scarica grande />
                    </div>
                @endauth
            @else
                <span class="pillola">Nessun addebito</span>
                <h1 style="margin-top: var(--sp-2);">Non hai pagato niente.</h1>

                <p class="guida" style="margin: 0 auto;">
                    Hai chiuso il pagamento prima di confermarlo, e non è stato addebitato
                    niente.
                    @auth
                        Il tuo account però c'è: puoi riprendere dal pannello quando vuoi.
                    @endauth
                </p>

                <div class="fila-bottoni centrata" style="margin-top: var(--sp-3);">
                    @auth
                        <a href="/admin" class="bottone bottone-pieno bottone-grande">Vai al pannello</a>
                    @endauth
                    <a href="/prezzi" class="bottone bottone-vuoto bottone-grande">Guarda di nuovo i piani</a>
                </div>
            @endif
        </div>
    </section>

@endsection
