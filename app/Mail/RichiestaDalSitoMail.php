<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\RichiestaDiContatto;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 🔔 **«Qualcuno ha scritto dal sito»** — 22/09/2026.
 *
 * 📌 DA-FARE voce 19: *«una mail al committente per ogni richiesta dal sito»*.
 *
 * ⛔ **Fino a oggi la richiesta finiva solo in tabella**, e si vedeva soltanto
 * aprendo il pannello: chi scrive da un modulo di contatto si aspetta una
 * risposta in giornata, e una richiesta commerciale che aspetta tre giorni è
 * una richiesta persa.
 *
 * 💡 Gemella di [RichiestaDalPannello], che fa lo stesso mestiere per le
 * richieste di piano su misura **dal pannello** di una palestra: stesso
 * `replyTo`, così rispondere alla notifica vuol dire rispondere alla persona.
 */
class RichiestaDalSitoMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly RichiestaDiContatto $richiesta) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->richiesta->email, $this->richiesta->nome)],
            subject: 'Dal sito: '.$this->richiesta->tipo->label().' — '
                .($this->richiesta->organizzazione ?: $this->richiesta->nome),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.richiesta-dal-sito',
            with: ['r' => $this->richiesta],
        );
    }
}
