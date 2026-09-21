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
 * 📬 Una richiesta di piano su misura, dal pannello della palestra — F8.6.
 *
 * Va al titolare (`config('services.contatti.email')`), con **«rispondi a»**
 * la palestra: chi la legge risponde dalla sua casella e la risposta arriva a
 * chi l'ha scritta, non a noi stessi.
 */
class RichiestaDalPannello extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly RichiestaDiContatto $richiesta) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            replyTo: [new Address($this->richiesta->email, $this->richiesta->nome)],
            subject: 'Piano su misura: '.($this->richiesta->organizzazione ?? $this->richiesta->nome),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.richiesta-dal-pannello',
            with: ['r' => $this->richiesta],
        );
    }
}
