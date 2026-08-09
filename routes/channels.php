<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

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

    return $conversazione->includes($user)
        && $conversazione->tenant_id === $user->tenant_id;
});
