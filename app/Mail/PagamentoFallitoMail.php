<?php

declare(strict_types=1);

namespace App\Mail;

use App\Models\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 💳 **Il pagamento non è andato a buon fine** — 22/09/2026.
 *
 * ⛔ **Prima finiva solo nel log**: `Cassa::pagamentoFallito()` segnava la data
 * sulla riga dell'abbonamento e scriveva un `Log::warning`. Nessuno avvisava
 * chi paga — e chi non lo sa non cambia la carta, quindi l'abbonamento scade
 * per una cosa che si sistemava in due minuti.
 *
 * ⚠️ **Non dice «il servizio è sospeso»**, perché non lo è: Stripe riprova nei
 * giorni successivi. Dire il falso per fare fretta è il modo più rapido di non
 * essere creduti la volta che il servizio si ferma davvero.
 */
class PagamentoFallitoMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Tenant $spazio,
        public readonly ?string $quale = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Il pagamento di Training Companion non è riuscito');
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.pagamento-fallito',
            with: [
                // 🚨 `nomeSpazio`: vedi la nota in `InvitoDelTrainerMail`.
                'nomeSpazio' => $this->spazio->name,
                'quale' => $this->quale,
                'pannello' => rtrim((string) config('app.url'), '/').'/admin/il-tuo-piano',
            ],
        );
    }
}
