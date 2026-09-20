<?php

use App\Http\Controllers\IscrizioneController;
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
| I prezzi — 16/08/2026, divisi in tre il 20/09/2026.
|
| 🚨 Pagina propria e non un'ancora nella home: e' il documento che una palestra
| apre, guarda e rimanda a qualcun altro. Un indirizzo che finisce con `#prezzi`
| e' un indirizzo che qualcuno incolla male.
|
| 📌 *«Prezzi deve essere un menu' a tendina con Individuale, trainer e palestra
| con tre pagine diverse»*. Le tre platee comprano cose diverse con unita'
| diverse, e su una pagina sola ognuna deve scorrere oltre i prezzi degli altri.
|
| ⚠️ `/prezzi` **resta** e fa da bivio: chi ci arriva da un link vecchio o da un
| motore di ricerca trova tre porte, non un 404.
*/
Route::get('/prezzi', [SitoController::class, 'prezzi'])->name('sito.prezzi');
Route::get('/prezzi/individuale', [SitoController::class, 'prezziIndividuale'])->name('sito.prezzi.individuale');
Route::get('/prezzi/trainer', [SitoController::class, 'prezziTrainer'])->name('sito.prezzi.trainer');
Route::get('/prezzi/palestra', [SitoController::class, 'prezziPalestra'])->name('sito.prezzi.palestra');

/*
| L'iscrizione dal sito — 20/09/2026.
|
| 📌 *«ci deve essere un modo per permettere alle palestre e ai trainers di
| abbonarsi direttamente dal sito»*. ⛔ Prima non c'era: il listino mostrava i
| prezzi e finiva li'.
|
| 🚨 **Il codice del piano non e' vincolato qui ma nel controller**
| (`IscrizioneDalSito::pianoComprabile()`), e non e' una dimenticanza: l'elenco
| dei piani comprabili e' una **regola commerciale** — niente piano individuale,
| niente su misura, niente piani ritirati — e vive dove si puo' spiegare. Un
| `whereIn` in questo file sarebbe una seconda copia di quella regola, muta.
|
| ⚠️ `throttle` sul POST: e' una rotta che **crea account** senza autenticazione.
| Sei tentativi al minuto per indirizzo IP bastano a chiunque stia comprando
| davvero, e tolgono di mezzo chi ci prova in automatico.
*/
Route::get('/iscriviti/{piano}', [IscrizioneController::class, 'modulo'])
    ->where('piano', '[a-z0-9_]{2,40}')
    ->name('sito.iscriviti');

Route::post('/iscriviti/{piano}', [IscrizioneController::class, 'iscrivi'])
    ->where('piano', '[a-z0-9_]{2,40}')
    ->middleware('throttle:6,1')
    ->name('sito.iscriviti.invia');

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

/*
| Per palestre e trainer - 20/09/2026.
|
| # Pagina propria e non una sezione della home: chi si allena e chi compra
|   leggono due cose diverse, e mescolarle fa smettere di leggere tutti e due.
*/
Route::get('/professionisti', [SitoController::class, 'professionisti'])
    ->name('sito.professionisti');

Route::get('/{quale}', [SitoController::class, 'documento'])
    ->whereIn('quale', ['privacy', 'condizioni', 'liberatoria-palestra', 'liberatoria-trainer'])
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
| 🔗 `assetlinks.json` — quello che fa aprire il link DALL'APP — 3b-V.3.2.
|
| Android verifica questo file per decidere se un link puo' aprire l'app senza
| chiedere niente a nessuno.
|
| 🚨 **Quando smette di funzionare non succede niente di visibile.** Se la
| verifica fallisce, il telefono apre il **browser** — e il browser mostra la
| nostra pagina web, che funziona. Nessuno si lamenta: il prodotto e' solo
| peggiore, in silenzio, e ogni persona invitata fa due passaggi invece di uno.
|
| ⚠️ **Perche' una rotta e non solo il file statico.** Il file c'e' anche in
| `public/.well-known/`, e in condizioni normali lo serve il web server. ⛔ Ma
| una cartella che comincia con un punto e' esattamente il genere di cosa che
| una configurazione «di sicurezza» blocca per abitudine: con la rotta, se il
| web server lo serve vince lui (stesso contenuto), e se lo blocca risponde
| Laravel. 💡 E diventa una cosa che si puo' **provare**.
|
| ⛔ L'impronta e' quella della chiave di **debug**: il giorno che si firma per
| la pubblicazione va rifatta, o il link torna ad aprire il browser.
*/
Route::get('/.well-known/assetlinks.json', function () {
    $percorso = public_path('.well-known/assetlinks.json');

    abort_unless(is_readable($percorso), 404);

    return response()->file($percorso, ['Content-Type' => 'application/json']);
})->name('sito.assetlinks');

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
Route::get('/pagamento/ok', fn () => view('sito.pagamento', ['riuscito' => true]))
    ->name('pagamento.ok');

Route::get('/pagamento/annullato', fn () => view('sito.pagamento', ['riuscito' => false]))
    ->name('pagamento.annullato');
