<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\TrainerInvite;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 🎟️ **L'invito di un trainer al suo allievo** — 22/09/2026.
 *
 * ⛔ **Fino a oggi questa mail non esisteva.** L'invito nasceva, il server dava
 * il link e l'app lo copiava negli appunti: il trainer doveva mandarlo a mano
 * su WhatsApp. L'indirizzo email si poteva persino scrivere
 * (`InvitiDelTrainer::invita($trainer, $email)`) e non serviva a niente.
 *
 * 💡 Gemella di [InvitoInPalestraMail]: stesso mestiere, mittente diverso —
 * lì invita una palestra, qui una persona. ⚠️ E il nome del trainer si scrive,
 * perché è **l'unica** cosa che fa capire a chi riceve perché gli stiamo
 * scrivendo.
 */
class InvitoDelTrainerMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly TrainerInvite $invito,
        public readonly User $trainer,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->trainer->name.' ti ha invitato su Training Companion');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invito-del-trainer',
            with: [
                /*
                 * 🚨 `nomeTrainer` e non `trainer`: le proprieta' pubbliche
                 * della classe arrivano gia' alla vista, e una chiave di `with`
                 * con lo stesso nome **non le sostituisce**. In anteprima si
                 * vedeva stampato l'intero modello in JSON (22/09/2026).
                 */
                'nomeTrainer' => $this->trainer->name,
                /*
                 * 🚨 Il link si costruisce **sul server**, da `config('app.url')`:
                 * è la stessa regola di `TrainerController::invite`. Comporlo
                 * altrove vorrebbe dire due posti da cambiare il giorno che
                 * cambia il dominio, e uno dei due resterebbe indietro.
                 */
                'link' => rtrim((string) config('app.url'), '/').'/invito/'.$this->invito->token,
                'scade' => $this->invito->expires_at?->locale('it')->isoFormat('D MMMM YYYY'),
            ],
        );
    }
}
