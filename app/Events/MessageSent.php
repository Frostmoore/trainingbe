<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Un messaggio nuovo, spinto ai due partecipanti — B8.2.
 *
 * 🚨 **Canale privato, e l'autorizzazione sta in `routes/channels.php`.**
 * Un canale pubblico `conversation.{id}` sarebbe leggibile da chiunque conosca
 * un numero: la chat fra un trainer e un iscritto contiene infortuni, obiettivi
 * e a volte questioni personali.
 *
 * Il payload contiene **solo cio' che serve a disegnare la bolla**. Niente dati
 * del mittente oltre all'id: chi riceve l'evento e' gia' dentro la conversazione
 * e sa con chi sta parlando, mentre un payload generoso finisce nei log del
 * broker.
 */
class MessageSent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public readonly Message $message,
    ) {}

    /**
     * Annuncia il messaggio senza che un guasto del broker rompa la richiesta.
     *
     * 🚨 **Serve un metodo apposta, e non basta `dispatch()`.**
     * Con la coda in modalita' sincrona — che e' la configurazione di sviluppo e
     * di piu' di uno staging — il broadcast avviene **dentro** la richiesta: se
     * il processo Reverb non risponde, `PusherBroadcaster` lancia e l'API
     * restituisce 500 **anche se il messaggio e' stato salvato**. L'utente
     * vedrebbe «invio fallito» per un messaggio che invece c'e', e lo
     * riscriverebbe.
     *
     * Il messaggio in chat deve poter esistere anche senza socket: l'app ricade
     * sul polling a 15 secondi, che e' il contratto previsto da B8.4 proprio per
     * questo caso.
     */
    public static function announce(Message $message): void
    {
        try {
            self::dispatch($message);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('conversation.'.$this->message->conversation_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return $this->message->toApiArray();
    }
}
