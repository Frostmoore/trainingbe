<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Services\Auth\LaVerificaDellEmail;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ✅ **«Conferma il tuo indirizzo»** — voce 62.6, 28/09/2026.
 *
 * ══ ⚠️ E NON SI MANDA A TUTTI, NE' SUBITO ═════════════════════════════════
 *
 * ⛔ Non parte alla registrazione. 🚨 Chi si iscrive ha già ricevuto la mail di
 * benvenuto, e due mail di fila per lo stesso gesto sembrano un errore — è la
 * stessa ragione per cui `BenvenutoMail` non si manda a chi arriva da un invito.
 *
 * 💡 Parte **quando serve a qualcosa**: quando una persona chiede di vedere gli
 * inviti ricevuti e l'indirizzo non è confermato. ⚖️ A quel punto la richiesta ha
 * un motivo che si capisce, invece di essere un compito dato a freddo.
 *
 * ══ 🔑 COSA DICE, E COSA NON DICE ═════════════════════════════════════════
 *
 * ⛔ **Non minaccia niente**: l'accesso non dipende da questa mail, e dirlo
 * sarebbe falso. 💡 Dice a cosa serve — vedere gli inviti — e che il link scade.
 */
class VerificaLEmailMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $persona,
        public readonly string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Conferma il tuo indirizzo email');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.verifica-l-email',
            with: [
                'nome' => $this->persona->name,
                'link' => $this->link,
                'minuti' => LaVerificaDellEmail::MINUTI,
            ],
        );
    }
}
