<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\RispostaAllaRichiesta;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ✉️ La risposta dal pannello a una richiesta dal sito — F9.3.
 *
 * 💡 «Rispondi a» è la casella dei contatti (`services.contatti.email`): se chi
 * riceve risponde alla mail, la risposta arriva a una persona e non a un
 * indirizzo che nessuno legge.
 */
class RispostaAllaRichiestaMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly RispostaAllaRichiesta $risposta) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address((string) config('services.contatti.email'), 'Training Companion')],
            subject: $this->risposta->oggetto,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.risposta-alla-richiesta',
            with: [
                'nome' => $this->risposta->richiesta?->nome,
                'testo' => $this->risposta->testo,
                'messaggio' => $this->risposta->richiesta?->messaggio,
            ],
        );
    }
}
