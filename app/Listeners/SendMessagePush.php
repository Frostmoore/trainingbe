<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\MessageSent;
use App\Services\Push\PushSender;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Throwable;

/**
 * La notifica push di un messaggio di chat — B10.6.
 *
 * 🚨 **In coda, e con l'eccezione catturata.** Il messaggio in chat si salva
 * anche se la notifica non parte: l'utente lo vedra' aprendo l'app. Il contrario
 * — un messaggio perso perche' Firebase era giu' — sarebbe inaccettabile.
 *
 * 🚨 **Il corpo del messaggio NON finisce nella notifica.** Le anteprime
 * compaiono sulla schermata di blocco, cioe' su un telefono appoggiato al tavolo
 * di una palestra: una chat che contiene infortuni e questioni personali non
 * deve essere leggibile senza sbloccare. Si manda «Hai un nuovo messaggio» e
 * l'id della conversazione, che basta all'app per aprirla al punto giusto.
 */
class SendMessagePush implements ShouldQueue
{
    public function __construct(
        private readonly PushSender $push,
        private readonly TenantContext $tenants,
    ) {}

    public function handle(MessageSent $evento): void
    {
        try {
            $messaggio = $evento->message;
            $conversazione = $messaggio->conversation;

            if ($conversazione === null) {
                return;
            }

            $mittente = $messaggio->sender;
            $destinatario = $mittente !== null ? $conversazione->otherParty($mittente) : null;

            if ($destinatario === null) {
                return;
            }

            $palestra = $conversazione->tenant;

            if ($palestra === null) {
                return;
            }

            // Il listener gira in coda: nessun contesto di palestra alle spalle.
            $this->tenants->runAs($palestra, function () use ($destinatario, $mittente, $conversazione): void {
                $this->push->toUser(
                    $destinatario,
                    $mittente?->name ?? 'Nuovo messaggio',
                    'Hai un nuovo messaggio.',
                    ['conversation_id' => $conversazione->id],
                );
            });
        } catch (Throwable $e) {
            report($e);
        }
    }
}
