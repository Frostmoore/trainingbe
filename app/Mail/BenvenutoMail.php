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
 * 👋 **La prima mail: l'account è aperto** — 22/09/2026.
 *
 * 📌 Il committente: *«adesso mi devi creare tutte le email di comunicazione …
 * nuovo account, cambio password, comunicazione generica»*.
 *
 * ══ 💡 PERCHÉ NON È UNA MAIL DI VERIFICA ═══════════════════════════════════
 *
 * ⛔ Qui **non** si chiede di confermare l'indirizzo: la verifica dell'email non
 * esiste ancora nel prodotto (DA-FARE 19), e una mail che dice «conferma qui»
 * con un link che non c'è sarebbe peggio del silenzio.
 *
 * 💡 Quello che fa: dice **chi siamo**, cosa può fare adesso, e dove stanno i
 * suoi dati — cioè le tre domande di chi ha appena aperto un account.
 *
 * ⚠️ Non si manda a chi entra da un invito: quella persona ha già ricevuto la
 * mail dell'invito, e due mail di seguito per lo stesso gesto sembrano un
 * errore. Si manda dalla registrazione dall'app e dall'iscrizione dal sito.
 */
class BenvenutoMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /** Chi ha aperto l'account: cambia cosa si trova davanti al primo accesso. */
    public const PERSONA = 'persona';

    public const TRAINER = 'trainer';

    public const PALESTRA = 'palestra';

    public function __construct(
        public readonly User $persona,
        public readonly string $tipo = self::PERSONA,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Benvenuto su Training Companion');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.benvenuto',
            with: [
                'nome' => $this->persona->name,
                'tipo' => $this->tipo,
                // 🚨 Il pannello sta sul sito, l'app sul telefono: chi si iscrive
                // dal sito come palestra o trainer entra dal pannello, e mandarlo
                // sullo store lo farebbe girare a vuoto.
                'pannello' => rtrim((string) config('app.url'), '/').'/admin/login',
                'sito' => (string) config('app.url'),
            ],
        );
    }
}
