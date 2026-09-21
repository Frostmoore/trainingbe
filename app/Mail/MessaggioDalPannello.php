<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * ✉️ **Una mail scritta a mano dal pannello** — 22/09/2026, fase F4.5.
 *
 * 📌 Il committente: una sezione *«manda una mail»* sulla scheda di una
 * persona. 💡 È il gesto del supporto: rispondere a chi ha scritto, avvisare
 * chi ha un problema, spiegare un addebito — senza uscire dal pannello e senza
 * copiare l'indirizzo in un client di posta.
 *
 * ══ 🚨 NON È UNA CAMPAGNA, ED È UNA DIFFERENZA CHE CONTA ═════════════════
 *
 * ⛔ Non c'è nessun invio in massa, nessuna lista, nessun modello. Va a **una**
 * persona, la scrive **una** persona, e chi la manda finisce in fondo al
 * messaggio. 💡 Gli invii in massa hanno regole loro (consenso, disiscrizione,
 * tracciamento) che questo strumento non implementa **di proposito**: un tasto
 * comodo che diventa un mailer senza disiscrizione è un problema legale, non
 * una funzionalità in più.
 *
 * ⚠️ **Oggi finisce nel log** (`MAIL_MAILER=log`, DA-FARE §3), come tutte le
 * altre: il codice è quello vero e il giorno che c'è Zeptomail parte da sé.
 */
class MessaggioDalPannello extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $destinatario,
        public readonly string $oggetto,
        public readonly string $testo,
        public readonly ?string $daParteDi = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->oggetto);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.messaggio-dal-pannello',
            with: [
                'nome' => $this->destinatario->name,
                'testo' => $this->testo,
                'daParteDi' => $this->daParteDi,
            ],
        );
    }
}
