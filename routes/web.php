<?php

use App\Http\Responses\RoleAwareLoginResponse;
use App\Support\Impersonation\Impersonator;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

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
