<?php

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
