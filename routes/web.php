<?php

use App\Http\Controllers\IscrizioneController;
use App\Http\Controllers\NienteOfferteController;
use App\Http\Controllers\OffertaController;
use App\Http\Controllers\ReimpostaPasswordController;
use App\Http\Controllers\ScriviciController;
use App\Http\Controllers\SitoController;
use App\Http\Controllers\StripeWebhookController;
use App\Support\Debug\CasiDiProva;
use Illuminate\Support\Facades\Auth;
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

/*
| ✉️ Il modulo di contatto - 21/09/2026.
|
| 📌 *«tanto c'e' scrivici. Mettici un modulo di contatto cosi' mi scrivono»*.
|
| ⛔ Prima al suo posto c'era un `mailto:`, che su un telefono senza posta
| configurata non fa niente: il cliente preme e non succede nulla.
|
| ⚠️ `throttle:5,10` **solo sul POST**: cinque invii ogni dieci minuti per
| indirizzo IP. Sulla GET no - e' una pagina pubblica come le altre, e chi
| ricarica il modulo tre volte perche' ha sbagliato un campo non va punito.
|
| 🚨 Stanno **sopra** `/{quale}`: quella e' la rotta dei documenti legali con
| segnaposto, e messa prima si prenderebbe anche `scrivici`.
*/
/*
| 🔑 La password dimenticata - 22/09/2026, fase F4.
|
| 📌 *«Eh sì va creata la pagina di reimpostazione password»*.
|
| ⚠️ **Sta sul sito e non dietro l'autenticazione**: ci arriva chi non riesce
| a entrare, e una pagina protetta gli chiederebbe di accedere per poter rifare
| la password di chi non riesce ad accedere.
|
| 🚨 Il token e' vincolato a 64 caratteri alfanumerici: senza il vincolo, un
| token storto finisce in una query invece che in un 404.
|
| ⚠️ `throttle` sul POST: e' la rotta che **cambia una password**.
*/
Route::get('/reimposta-password/{token}', [ReimpostaPasswordController::class, 'modulo'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->name('sito.reimposta-password');

Route::post('/reimposta-password/{token}', [ReimpostaPasswordController::class, 'salva'])
    ->where('token', '[A-Za-z0-9]{64}')
    ->middleware('throttle:6,1')
    ->name('sito.reimposta-password.salva');

Route::get('/scrivici', [ScriviciController::class, 'modulo'])->name('sito.scrivici');

/*
| 🔗 L'offerta di un piano su misura - 21/09/2026.
|
| 📌 *«certo che il piano cucito a mano deve essere fatto con stripe»*.
|
| ⛔ Prima il piano su misura si **assegnava** dal pannello: il cliente si
| ritrovava il servizio acceso e la fattura da nessuna parte. Adesso il pannello
| crea un **link**, e da quel link si paga su Stripe come da qualunque altro
| piano.
|
| 🚨 **Il token e' l'autorizzazione**: rende comprabile un piano che non e' a
| listino, e solo a chi ha il link. Vincolato a 32 caratteri alfanumerici come i
| token degli inviti - senza il vincolo, un token storto finirebbe in una query
| invece che in un 404.
|
| ⚠️ `throttle` sul POST: e' una rotta che puo' **creare un account**, come
| `/iscriviti`.
*/
Route::get('/offerta/{token}', [OffertaController::class, 'mostra'])
    ->where('token', '[A-Za-z0-9]{32}')
    ->name('sito.offerta');

Route::post('/offerta/{token}', [OffertaController::class, 'attiva'])
    ->where('token', '[A-Za-z0-9]{32}')
    ->middleware('throttle:6,1')
    ->name('sito.offerta.attiva');

Route::post('/scrivici', [ScriviciController::class, 'invia'])
    ->middleware('throttle:5,10')
    ->name('sito.scrivici.invia');

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
/*
| 🆕 🤝 «Porta un amico» e 🎁 le offerte pubbliche — P8 di plan_promozioni.md.
|
| ⚠️ `/amico/{codice}` è un GET che non collega niente (come gli inviti): il
| collegamento lo fa l'app dopo l'iscrizione. Codice vincolato a 8 caratteri
| alfanumerici, come lo genera `User::codiceAmico()`.
*/
Route::get('/amico/{codice}', [SitoController::class, 'amico'])
    ->where('codice', '[A-Za-z0-9]{8}')
    ->middleware('throttle:30,1')
    ->name('sito.amico');

Route::get('/offerte', [SitoController::class, 'offerte'])->name('sito.offerte');

/*
| 🆕 🔗 Il link di attivazione di una promozione — 24/09/2026. ⛔ Niente codici
| sconto: le promozioni si attivano da sole o da questo link. `GET` che non
| attiva niente (come gli inviti); con l'app installata lo apre l'app, dal
| browser porta alla pagina dell'offerta nel pannello (S2: non sul sito).
*/
Route::get('/promo/{codice}', [SitoController::class, 'promo'])
    ->where('codice', '[A-Za-z0-9]{1,40}')
    ->middleware('throttle:30,1')
    ->name('sito.promo');

Route::get('/invito/{token}', [SitoController::class, 'invito'])
    ->where('token', '[A-Za-z0-9]{32}')
    ->name('sito.invito');

/*
| 🔕 **Disiscriversi dalle email promozionali** — voce 43, art. 130, c. 4,
| Codice privacy. 25/09/2026.
|
| 📌 Il committente, 25/09: *«in ogni email promozionale per disiscriversi
| dalle email promozionali. Quelle transazionali o urgenti restano»*.
|
| 🚨 **`signed` e non `auth`**: chi riceve una nostra email deve potersi
| opporre **senza entrare**. ⛔ Un collegamento che porta alla schermata di
| accesso non e' una «modalita' semplificata»: chi non ricorda la password
| rinuncia, e l'opposizione resta teorica.
|
| ⚠️ **Il `GET` non spegne niente**, e la ragione e' la stessa scritta qui
| sotto per gli inviti: i filtri antispam e le anteprime dei client di posta
| aprono i link **da soli**. ⛔ Un `GET` che eseguisse vorrebbe dire persone
| disiscritte da un antivirus, senza aver toccato niente.
|
| 🔑 A spegnere e' il `POST`, che e' anche quello che il client di posta
| chiama da solo quando si preme «Annulla iscrizione» (RFC 8058, header
| `List-Unsubscribe-Post`).
*/
Route::get('/niente-offerte/{user}', [NienteOfferteController::class, 'mostra'])
    ->whereNumber('user')
    ->middleware(['signed', 'throttle:30,1'])
    ->name('sito.niente-offerte');

Route::post('/niente-offerte/{user}', [NienteOfferteController::class, 'spegni'])
    ->whereNumber('user')
    ->middleware(['signed', 'throttle:30,1'])
    ->name('sito.niente-offerte.spegni');

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
| ⛔ QUI C'ERA L'USCITA DALL'IMPERSONAZIONE — tolta il 27/09/2026, voce 64.
|
| 🚨 Non serve più, perché non c'è più nessun modo di **entrare**: il pulsante nel
| pannello di piattaforma è andato via con lei.
|
| ⚠️ Si toglie l'uscita **per ultima nel ragionamento e insieme nel commit**: una
| via d'uscita senza porta d'ingresso è codice morto, ma una porta d'ingresso senza
| uscita sarebbe una trappola. ⛔ Vanno via nello stesso momento.
|
| 📌 *«è un data breach costruito apposta per farmi mandare in galera»*. La regola
| sta in `divieti.md` del 27/09/2026 e non si riapre senza una decisione nuova.
*/

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

/*
|--------------------------------------------------------------------------
| 🧪 I 25 CASI DI PROVA — 63.H, 27/09/2026. SOLO IN LOCALE.
|--------------------------------------------------------------------------
|
| 📌 Il committente: *«per assicurarmi che non fai cazzate, ho bisogno di una
| view di debug in locale con una serie di link di accesso che mi permettano di
| verificare ogni singolo caso per conto mio. Ogni link si deve aprire su una
| nuova pagina»*.
|
| ══ 🚨 TRE GUARDIE, E NESSUNA E' RIDONDANTE ═══════════════════════════════
|
| 1. `app()->environment('local')` — l'ambiente;
| 2. `config('casi_di_prova.attiva')` — l'interruttore, che segue l'ambiente;
| 3. `CasiDiProva::__construct()` — **si rifiuta di esistere** fuori da locale.
|
| ⛔ Sembrano tre copie della stessa cosa e non lo sono: la prima e la seconda
| proteggono la **rotta**, la terza protegge la **classe** da qualunque altra
| strada — un comando, un test, un pezzo di codice scritto fra un anno.
|
| ══ ⛔ E QUESTA NON E' L'IMPERSONAZIONE ═══════════════════════════════════
|
| 🚨 La voce **64** la vieta, e `divieti.md` del 27/09 dice *«nessun
| `Auth::login($altro)`, per nessuna ragione»*. ⚠️ **L'unica eccezione scritta è
| questa**, e la differenza non è il meccanismo: è il **bersaglio**. Qui si entra
| in account **creati da questa vista**, su un database locale: non c'è nessuna
| persona vera di cui violare i dati.
|
| 🔑 Ed è per questo che l'ingresso pretende che l'indirizzo finisca con
| `CasiDiProva::DOMINIO`: senza quella riga, un nome nell'URL diventerebbe un modo
| per entrare in un account qualsiasi.
|
*/
if (CasiDiProva::vistaAttiva()) {
    Route::prefix('debug/casi')->group(function (): void {
        Route::get('/', function (CasiDiProva $casi) {
            return view('debug.casi', [
                'casi' => $casi->elenco(),
                'password' => CasiDiProva::PASSWORD,
                'pronti' => $casi->utente('P1') !== null,
            ]);
        })->name('debug.casi');

        Route::post('/ricostruisci', function (CasiDiProva $casi) {
            $casi->ricostruisci();

            return redirect()->route('debug.casi')->with('fatto', 'I venticinque casi sono pronti.');
        })->name('debug.casi.ricostruisci');

        Route::get('/entra/{chiave}', function (string $chiave, CasiDiProva $casi) {
            $utente = $casi->utente($chiave);

            if ($utente === null) {
                return redirect()->route('debug.casi')
                    ->with('errore', "Il caso {$chiave} non è ancora stato costruito. Premi «Ricostruisci tutto».");
            }

            /*
             * 🚨 **La riga che impedisce a questa vista di diventare una porta.**
             *
             * ⛔ Senza, `/debug/casi/entra/<qualcosa>` con un valore inventato
             * potrebbe raggiungere un account vero — e in locale un database vero
             * ci finisce spesso, ripristinato da una copia di sicurezza.
             */
            if (! str_ends_with((string) $utente->email, '@'.CasiDiProva::DOMINIO)) {
                abort(403, 'Questa vista entra solo negli account che ha creato lei.');
            }

            Auth::guard('web')->login($utente);

            session()->put('password_hash_web', $utente->getAuthPassword());

            return redirect()->to('/admin');
        })->where('chiave', '[A-Za-z0-9]{1,6}')->name('debug.casi.entra');
    });
}
