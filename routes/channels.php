<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;

/*
|--------------------------------------------------------------------------
| Canali di broadcasting — B8.2
|--------------------------------------------------------------------------
|
| 🚨 **Il controllo qui e' l'ultimo che c'e'.** Una volta che il client e'
| iscritto a un canale, riceve tutto quello che ci passa: non c'e' un secondo
| filtro a valle. Per questo si verificano DUE cose e non una — che l'utente sia
| uno dei due partecipanti **e** che la conversazione sia della sua palestra.
|
| La seconda sembra ridondante e non lo e': gli id sono numeri progressivi
| globali, e senza il controllo del tenant basterebbe indovinarne uno per
| ascoltare la chat di un altro cliente. Il global scope non aiuta qui, perche'
| l'autorizzazione dei canali gira **prima** che `ResolveTenant` abbia messo un
| contesto.
|
*/

Broadcast::channel('conversation.{conversationId}', function (User $user, int $conversationId): bool {
    $conversazione = Conversation::withoutGlobalScopes()->find($conversationId);

    if ($conversazione === null) {
        return false;
    }

    // 🚨 Passa dalla policy invece di ripetere le condizioni.
    //
    // Non è pigrizia: la regola «nemmeno impersonando» vive lì, e duplicarla
    // qui significherebbe che il giorno in cui cambia, questo canale resta
    // indietro — e un canale che resta indietro non dà nessun errore, continua
    // a consegnare messaggi a chi non dovrebbe riceverli.
    return Gate::forUser($user)->allows('view', $conversazione);
});
