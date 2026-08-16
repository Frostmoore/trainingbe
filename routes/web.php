<?php

use App\Http\Controllers\SitoController;
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
