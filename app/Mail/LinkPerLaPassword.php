<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use App\Services\Auth\LaReimpostazioneDellaPassword;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 📧 **La mail col link per rifare la password** — 22/09/2026, fase F4.
 *
 * ⚠️ **Oggi finisce nel log**, e va bene: `MAIL_MAILER=log` finché non c'è
 * Zeptomail (DA-FARE §3). 📌 Il committente: *«ovviamente adesso non funziona
 * perché non è connesso zeptomail, ma quando lo sarà ovviamente dovrà partire
 * un'email (per il momento il log va bene)»*.
 *
 * 💡 Per questo il codice è quello **vero**: il giorno che il trasporto c'è,
 * parte da sé. ⛔ Un finto «link copiabile» come soluzione sarebbe stato da
 * rifare daccapo.
 */
class LinkPerLaPassword extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $persona,
        public readonly string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Rifai la tua password di Training Companion');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.link-per-la-password',
            with: [
                'nome' => $this->persona->name,
                'link' => $this->link,
                'minuti' => LaReimpostazioneDellaPassword::MINUTI,
            ],
        );
    }
}
