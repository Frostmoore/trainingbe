<?php

declare(strict_types=1);

namespace App\Mail;

use App\Enums\UserRole;
use App\Models\InvitoInPalestra;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * 🔗 **L'invito di una palestra, spedito** — 23/09/2026, fase F5.5.
 *
 * 📌 Il committente: l'invito deve **generare un link nuovo ogni volta** e
 * potersi **mandare per email**.
 *
 * ══ ⚠️ FINO A IERI IL LINK SI COPIAVA A MANO, E C'ERA UNA RAGIONE ═══════
 *
 * Stava scritto in `ListMembers`: *«mandare le email d'invito vorrebbe dire un
 * mittente, un template, la reputazione del dominio e la gestione dei
 * rimbalzi — cioè un pezzo di prodotto»*. 💡 Quel pezzo adesso c'è: è lo stesso
 * che manda il link della password (F4.0).
 *
 * ⚠️ **Oggi finisce nel log** (`MAIL_MAILER=log`), come le altre. 🚨 E il
 * pannello continua a mostrare il link a schermo: non è ridondanza, è la strada
 * che resta buona finché il trasporto non c'è — e il giorno che c'è, serve
 * comunque a chi vuole mandarlo su WhatsApp invece che per email.
 *
 * ── 🚨 Cosa NON c'è dentro ────────────────────────────────────────────────
 *
 * ⛔ Nessun nome di persona: l'invito è legato a un indirizzo, non a
 * un'anagrafica, e nella maggior parte dei casi chi lo manda scrive solo
 * l'email. 💡 «Ciao» e basta è meglio di un «Ciao null».
 */
class InvitoInPalestraMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly InvitoInPalestra $invito) {}

    public function envelope(): Envelope
    {
        $palestra = $this->invito->palestra?->name;

        return new Envelope(
            subject: $palestra === null
                ? 'Il tuo invito su Training Companion'
                : "{$palestra} ti ha invitato su Training Companion",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.invito-in-palestra',
            with: [
                'palestra' => $this->invito->palestra?->name ?? 'Una palestra',
                /*
                 * ⚠️ **`/invito-palestra` e non `/invito`**: quello è già degli
                 * inviti dei **trainer indipendenti**. Con lo stesso percorso,
                 * il link di una palestra aprirebbe la loro pagina — che non
                 * trova il token e scrive «invito non più valido». 🚨 Un invito
                 * buono che si presenta come scaduto, senza nessun errore.
                 */
                'link' => url("/invito-palestra/{$this->invito->token}"),
                'giorni' => InvitoInPalestra::GIORNI_DI_VITA,
                'comeTrainer' => $this->invito->ruolo === UserRole::Trainer,
            ],
        );
    }
}
