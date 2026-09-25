<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\ComeSiAttiva;
use App\Filament\God\Resources\Promozioni\PromozioneResource;
use App\Http\Controllers\NienteOfferteController;
use App\Models\Promozione;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
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

    /**
     * 🆕 📬 **Gli header standard della disiscrizione** — RFC 8058, 25/09/2026.
     *
     * 🔑 **È questo che fa comparire «Annulla iscrizione» accanto al mittente**
     * in Gmail, Apple Mail e Outlook. 💡 Un collegamento in fondo al testo lo
     * trova chi scorre fino in fondo; questo lo vede **chiunque**, prima ancora
     * di aprire il messaggio.
     *
     * ⚠️ `List-Unsubscribe-Post` dice al client che può chiamare quel `POST`
     * da solo: chi preme il pulsante ha finito lì, senza aprire niente.
     * 🚨 Per questo quel `POST` è escluso dal CSRF — Gmail non ha la nostra
     * sessione — e la sua difesa è la **firma**.
     *
     * 💡 E c'è anche il `mailto:`, che è il ripiego di chi non sa fare il
     * `POST`: l'RFC vuole almeno uno dei due, e averli tutti e due non costa
     * niente.
     */
    public function headers(): Headers
    {
        $indirizzo = NienteOfferteController::indirizzoDelPost($this->utente->getKey());

        return new Headers(
            text: [
                'List-Unsubscribe' => '<mailto:info@smp-digital.it?subject=Non%20mandarmi%20offerte>, <'.$indirizzo.'>',
                'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
            ],
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

                /*
                 * 🆕 🔕 **Il collegamento per disiscriversi** — voce 43, art. 130,
                 * c. 4, Codice privacy. 25/09/2026.
                 *
                 * 📌 Il committente: *«in ogni email promozionale per
                 * disiscriversi dalle email promozionali»*.
                 *
                 * ⚠️ **Qui e non in un layout comune**, ed è voluto: le altre
                 * nostre email sono **transazionali** (password, inviti,
                 * ricevute) e non devono avere nessun «annulla iscrizione».
                 * ⛔ Metterlo nel layout vorrebbe dire offrire di disiscriversi
                 * da una ricevuta — cioè promettere qualcosa che non possiamo
                 * mantenere, perché quelle email le mandiamo comunque.
                 */
                'disiscriviti' => NienteOfferteController::indirizzo($this->utente->getKey()),

                // ⛔ Niente codici (24/09/2026): un'offerta col link si attiva dal link, e il link sta nella mail.
                'link' => $this->promozione->attivazione === ComeSiAttiva::Link
                    ? PromozioneResource::link($this->promozione)
                    : null,
            ],
        );
    }
}
