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
 * 🔐 **La password è cambiata** — 22/09/2026.
 *
 * ══ 🚨 PERCHÉ ESISTE, VISTO CHE NON CHIEDE NIENTE ═════════════════════════
 *
 * ⛔ Una password cambiata **senza avviso** è il modo in cui un accesso rubato
 * resta invisibile: chi è entrato nella casella di posta di qualcuno chiede il
 * reset, sceglie una password nuova, e il proprietario se ne accorge solo
 * quando non riesce più a entrare — cioè quando è tardi.
 *
 * 💡 Questa mail non ha un pulsante: dice **cosa è successo e quando**, e cosa
 * fare se non è stato lui. Una mail di sicurezza con dentro un link su cui
 * premere insegnerebbe esattamente l'abitudine che rende possibile il raggiro.
 */
class PasswordCambiataMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly User $persona) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'La tua password è stata cambiata');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.password-cambiata',
            with: [
                'nome' => $this->persona->name,
                'quando' => now()->locale('it')->isoFormat('D MMMM YYYY [alle] HH:mm'),
            ],
        );
    }
}
