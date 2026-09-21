<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\ComeSiAttiva;
use App\Filament\God\Resources\Promozioni\PromozioneResource;
use App\Models\Promozione;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 🎁 **«C'è un'offerta per te» / «Hai ricevuto un regalo»** — 24/09/2026, P8 di
 * `plan_promozioni.md` (opzione 37).
 *
 * ⚠️ `$ricevuta = true` quando è già arrivato qualcosa (gettoni, mesi): la mail
 * lo dice al passato. `false` quando è un'offerta da usare (uno sconto): la
 * mail la presenta.
 */
class PromozionePerTeMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Promozione $promozione,
        public readonly User $utente,
        public readonly bool $ricevuta,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->ricevuta
                ? 'Hai ricevuto un regalo: '.$this->promozione->nome
                : 'Un\'offerta per te: '.$this->promozione->nome,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.promozione-per-te',
            with: [
                'nome' => $this->utente->name,
                'titolo' => $this->promozione->nome,
                'cosa' => ucfirst($this->promozione->scrittoBene()),
                'descrizione' => $this->promozione->descrizione,
                'ricevuta' => $this->ricevuta,
                'scade' => $this->promozione->scade_il?->format('d/m/Y'),

                // ⛔ Niente codici (24/09/2026): un'offerta col link si attiva dal link, e il link sta nella mail.
                'link' => $this->promozione->attivazione === ComeSiAttiva::Link
                    ? PromozioneResource::link($this->promozione)
                    : null,
            ],
        );
    }
}
