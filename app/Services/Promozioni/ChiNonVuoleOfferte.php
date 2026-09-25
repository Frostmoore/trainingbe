<?php

declare(strict_types=1);

namespace App\Services\Promozioni;

use App\Models\Avviso;
use App\Models\User;

/**
 * 🔕 **Chi ha detto «non mandarmi offerte»** — voce 43, DPIA R16, 25/09/2026.
 *
 * ══ 🔑 PERCHE' UNA CLASSE PER UN `if` ══════════════════════════════════════
 *
 * ⛔ Perché quell'`if` serve in **due posti lontani fra loro** —
 * `AvvisaIDestinatari` (push ed email delle promozioni) e `QualeAvvisoMostrare`
 * (gli avvisi in-app) — e una regola che serve in due posti non va scritta in
 * nessuno dei due. 🚨 Due copie divergono al primo ritocco, e qui la copia che
 * diverge **manda pubblicità a chi ha detto di no**: non dà nessun errore, e se
 * ne accorge solo la persona che l'aveva rifiutata.
 *
 * 💡 E c'è la seconda ragione, più forte: **il confine fra ciò che si spegne e
 * ciò che non si spegne è una decisione di prodotto**, non un dettaglio di due
 * controller. Sta scritto qui sotto, in un posto solo, e si legge.
 *
 * ══ ⚠️ IL CONFINE ══════════════════════════════════════════════════════════
 *
 * | Si spegne | Non si spegne |
 * |---|---|
 * | avvisi delle **promozioni** (push, email) | avvisi **urgenti** e di servizio |
 * | avvisi in-app **commerciali** | notifiche dei **messaggi** |
 * | | email di sistema: password, inviti, ricevute |
 *
 * ⛔ Spegnere anche il resto vorrebbe dire che chi rifiuta la pubblicità perde
 * l'avviso che il suo abbonamento sta per scadere. 🚨 È il modo di trasformare
 * un diritto in una trappola.
 */
class ChiNonVuoleOfferte
{
    /**
     * Questa persona ha detto di no alle offerte?
     *
     * 💡 Un metodo e non `$utente->niente_offerte` letto a mano: il giorno che
     * la regola si complica — una scadenza, una revoca, un consenso diverso per
     * canale — cambia **qui**, e i due chiamanti non se ne accorgono.
     */
    public function loHaDetto(User $utente): bool
    {
        return (bool) $utente->niente_offerte;
    }

    /**
     * Questa **promozione** si può annunciare a questa persona?
     *
     * ⚠️ Riguarda solo l'**annuncio**: ⛔ non toglie la promozione a nessuno.
     * 💡 Chi ha spento le offerte e ne riceve una lo scopre dal pannello o dal
     * saldo — quello che non riceve è la **pubblicità**, che è ciò a cui si è
     * opposto. 🚨 Confondere le due cose vorrebbe dire togliere un regalo già
     * assegnato per una preferenza sulle notifiche.
     */
    public function siPuoAnnunciareA(User $utente): bool
    {
        return ! $this->loHaDetto($utente);
    }

    /**
     * Questo **avviso in-app** si può mostrare a questa persona?
     *
     * 🚨 **L'urgente passa sempre**, anche se marcato commerciale: un avviso di
     * servizio non può morire per una preferenza sulla pubblicità. ⚠️ Ed è il
     * motivo per cui `urgente` va usato con parsimonia — dal pannello è una
     * casella, e una casella che scavalca un rifiuto va meritata.
     */
    public function puoVedere(User $utente, Avviso $avviso): bool
    {
        if ($avviso->urgente || ! $avviso->commerciale) {
            return true;
        }

        return ! $this->loHaDetto($utente);
    }
}
