<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\RichiestaDiContatto;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 📨 **«Abbiamo ricevuto la tua richiesta»** — 22/09/2026.
 *
 * 📌 Era già scritto nel DA-FARE (voce 19): *«una mail al committente per ogni
 * richiesta dal sito, con risposta automatica a chi scrive»*.
 *
 * 💡 Serve a una cosa sola: **far sapere che il modulo ha funzionato**. Chi
 * scrive a un'azienda e non riceve niente non sa se il messaggio è partito, e
 * nel dubbio riscrive — oppure va altrove.
 *
 * ⚠️ Ricopia il messaggio che ha scritto, **escapato**: è suo, ed è anche la
 * prova di cosa ci ha chiesto. ⛔ Non promette tempi che non possiamo mantenere.
 */
class RichiestaRicevutaMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly RichiestaDiContatto $richiesta) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Abbiamo ricevuto la tua richiesta');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.richiesta-ricevuta',
            with: [
                'nome' => $this->richiesta->nome,
                'messaggio' => $this->richiesta->messaggio,
            ],
        );
    }
}
