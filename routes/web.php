<?php

use App\Http\Controllers\SitoController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Responses\RoleAwareLoginResponse;
use App\Support\Impersonation\Impersonator;
use Illuminate\Support\Facades\Route;

/*
| Il sito pubblico — F9.
|
| 🚨 Qui c'era ancora `view('welcome')`, la pagina di benvenuto di Laravel mai
| toccata: l'indirizzo principale del prodotto mostrava il logo del framework.
|
| ⚠️ **Il listino si legge dal database**, non è scritto nel template: un prezzo
| in una vista è un prezzo che un giorno dirà una cosa diversa da quella che il
| sistema fattura.
*/
Route::get('/', [SitoController::class, 'home'])->name('sito.home');

/*
| I prezzi — 16/08/2026.
|
| 🚨 Pagina propria e non un'ancora nella home: e' il documento che una palestra
| apre, guarda e rimanda a qualcun altro. Un indirizzo che finisce con `#prezzi`
| e' un indirizzo che qualcuno incolla male.
*/
Route::get('/prezzi', [SitoController::class, 'prezzi'])->name('sito.prezzi');

/*
| I documenti legali.
|
| 🚨 **Erano gia' linkati dal pie' di pagina, e rispondevano 404.** Il sito
| prometteva l'informativa privacy e le condizioni d'uso da quando esiste.
|
| ⚠️ `{quale}` e' vincolato a due valori: senza, il nome finisce in un percorso
| di file e `/documento/../../.env` diventa una lettura arbitraria.
*/
/*
|--------------------------------------------------------------------------
| L'orecchio di Stripe — 17/08/2026
|--------------------------------------------------------------------------
|
| 🚨 **Fuori da `auth`, fuori dal tenant, esente da CSRF.** Un webhook arriva
| da un server, non da un browser: non ha sessione, non ha cookie e non ha un
| token da mettere in un modulo. L'unica cosa che lo autentica e' la **firma**,
| e la verifica quella sta nel controller.
|
| ⚠️ Sta **prima** di `/{quale}`: quest'ultima e' una rotta con segnaposto, e
| messa sopra si prenderebbe anche `stripe`.
*/
Route::post('/stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

Route::get('/{quale}', [SitoController::class, 'documento'])
    ->whereIn('quale', ['privacy', 'condizioni'])
    ->name('sito.documento');

/*
| L'atterraggio di un invito personale — F6.2.
|
| 🚨 **È un `GET` e NON riscatta niente.** Il riscatto ha bisogno di un modulo
| (nome, email, password); e un `GET` che consumasse l'invito lo brucerebbe al
| primo servizio di messaggistica che apre il link per fare l'anteprima.
*/
Route::get('/invito/{token}', [SitoController::class, 'invito'])
    ->where('token', '[A-Za-z0-9]{32}')
    ->name('sito.invito');

/*
| L'atterraggio di un invito di una PALESTRA — 3b-V.3.1.
|
| 🚨 **Percorso diverso da quello sopra, e non e' pignoleria.** `/invito` e' gia'
| degli inviti dei trainer: con lo stesso indirizzo, il link di una palestra
| avrebbe aperto quella pagina — che non trova il token e scrive «invito non piu'
| valido». Un invito buono che si presenta come scaduto.
|
| ⚠️ **Questa pagina serve a chi NON ha l'app**, ed e' la parte che si
| sottovaluta: su un telefono con l'app installata Android apre direttamente
| l'app (App Links), e qui non ci arriva nessuno. Ci arriva chi tocca il link da
| un computer, o da un telefono senza l'app — cioe' esattamente la persona che
| l'invito deve convincere.
|
| 🚨 **`GET` e non riscatta niente**, per la stessa ragione scritta qui sopra: i
| servizi di messaggistica aprono i link per farne l'anteprima, e un `GET` che
| consumasse l'invito lo brucerebbe prima che qualcuno lo legga.
*/
Route::get('/invito-palestra/{token}', [SitoController::class, 'invitoInPalestra'])
    ->where('token', '[A-Za-z0-9]{32}')
    ->name('sito.invito-palestra');

/*
| Un solo indirizzo di accesso.
|
| Entrambi i pannelli mostrano la stessa pagina di login e la risposta smista
| per ruolo, ma serve un URL unico da dare alle persone — e serve la rotta
| chiamata `login`, che e' quella su cui Laravel rimanda chi non e'
| autenticato.
*/
Route::redirect('/login', '/admin/login')->name('login');

/*
| L'uscita dall'impersonazione (B2.3).
|
| Sta fuori dai pannelli e non dentro, perche' deve funzionare **da entrambi** e
| soprattutto dal pannello della palestra, dove l'utente impersonato si trova ma
| il super admin, tecnicamente, non e' piu' se stesso.
|
| Nessun controllo oltre a `auth`: chi non sta impersonando trova un rimando
| innocuo. Un errore qui sarebbe la cosa peggiore — la via d'uscita deve essere
| l'ultima a rompersi.
*/
Route::get('/impersonation/stop', function (Impersonator $impersonator) {
    $original = $impersonator->stop();

    return redirect()->to(
        $original !== null
            ? RoleAwareLoginResponse::urlFor($original) ?? '/admin'
            : '/admin',
    );
})->middleware('auth')->name('impersonation.stop');

/*
|--------------------------------------------------------------------------
| 💳 Il ritorno da Stripe — 3b-H, 26/08/2026
|--------------------------------------------------------------------------
|
| 🚨 **Servono a esistere**, non a fare qualcosa. Stripe pretende un
| `success_url` e un `cancel_url`, e mandare la persona su un 404 dopo che ha
| pagato e' il modo piu' rapido per farle credere che i soldi siano spariti.
|
| ⚠️ **Qui NON si accredita niente.** L'accredito lo fa il webhook, che e'
| firmato: questa pagina la puo' aprire chiunque scrivendo l'indirizzo, e
| accreditare da qui vorrebbe dire regalare gettoni a chi conosce l'URL.
|
| 💡 Per questo il testo dice «ci stiamo pensando» e non «fatto»: al ritorno il
| webhook potrebbe non essere ancora arrivato, e promettere un saldo che non
| c'e' ancora farebbe ricaricare la schermata dell'app per niente.
*/
Route::get('/pagamento/ok', fn () => response(
    '<!doctype html><meta charset="utf-8">'
    .'<meta name="viewport" content="width=device-width,initial-scale=1">'
    .'<title>Pagamento ricevuto</title>'
    .'<div style="font:16px system-ui;max-width:32rem;margin:20vh auto;padding:0 1.5rem;text-align:center">'
    .'<h1 style="font-size:1.4rem">Pagamento ricevuto</h1>'
    .'<p>Puoi tornare all\'app: fra qualche secondo trovi tutto al suo posto.</p>'
    .'</div>',
))->name('pagamento.ok');

Route::get('/pagamento/annullato', fn () => response(
    '<!doctype html><meta charset="utf-8">'
    .'<meta name="viewport" content="width=device-width,initial-scale=1">'
    .'<title>Pagamento annullato</title>'
    .'<div style="font:16px system-ui;max-width:32rem;margin:20vh auto;padding:0 1.5rem;text-align:center">'
    .'<h1 style="font-size:1.4rem">Non hai pagato niente</h1>'
    .'<p>Puoi tornare all\'app e riprovare quando vuoi.</p>'
    .'</div>',
))->name('pagamento.annullato');
