<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;

/**
 * Chi può leggere una conversazione — B8.
 *
 * 🚨 **La risposta è: soltanto i due che se la sono scritta. Nessun altro, mai.**
 *
 * Non è una regola di prodotto fra le altre: è una questione di riservatezza. Quello che un
 * iscritto scrive al proprio trainer riguarda infortuni, disturbi alimentari, gravidanze, umore.
 * Non è materiale che il titolare della palestra abbia titolo di leggere, e sapere che *potrebbe*
 * leggerlo basta a far smettere le persone di scrivere le cose che contano — cioè a rendere la chat
 * inutile per quello per cui esiste.
 *
 * ### 🚨 È l'UNICA policy senza scorciatoia per il super admin
 *
 * Tutte le altre cominciano con `before()` che lascia passare `isSuperAdmin()`. Qui **no**, e la
 * differenza è deliberata:
 *
 * - il super admin ha già una via legittima e **tracciata** per entrare nei dati di un cliente,
 *   l'impersonazione (B2.3), che scrive chi–chi–quando in `audit_logs`;
 * - una scorciatoia qui sarebbe invece un accesso **non tracciato** allo stesso materiale, e
 *   renderebbe la traccia dell'impersonazione una formalità aggirabile;
 * - il pannello di piattaforma non ha nessuna risorsa sulle conversazioni, quindi togliere la
 *   scorciatoia non toglie nessuna funzione a nessuno: toglie solo un modo di sbagliare.
 *
 * ### Dove si applica davvero il filtro
 *
 * Come per gli iscritti (§14.4 dell'atlante), una policy risponde su **un record alla volta** e non
 * ferma un elenco: il filtro vero è `Conversation::scopeForUser()`, che ogni query deve usare.
 * Questa classe copre l'altro caso — l'accesso diretto a un id — ed esiste anche per dire nero su
 * bianco quale sia la regola, così che chi aggiunge una schermata fra sei mesi la trovi scritta.
 */
class ConversationPolicy
{
    // 🚨 Nessun `before()`: vedi la nota di classe. Non è una dimenticanza.

    public function viewAny(User $utente): bool
    {
        // Chiunque può avere le **proprie** conversazioni. Quali siano, lo
        // decide lo scope, non questo metodo.
        return true;
    }

    public function view(User $utente, Conversation $conversazione): bool
    {
        return $this->partecipa($utente, $conversazione);
    }

    public function create(User $utente): bool
    {
        return true;
    }

    public function update(User $utente, Conversation $conversazione): bool
    {
        return $this->partecipa($utente, $conversazione);
    }

    /** Scrivere in un filo: solo chi ci sta dentro. */
    public function reply(User $utente, Conversation $conversazione): bool
    {
        return $this->partecipa($utente, $conversazione);
    }

    /**
     * Una conversazione non si cancella.
     *
     * Cancellarla toglierebbe a **entrambi** una cosa che appartiene a due
     * persone, e sarebbe anche il modo per far sparire una richiesta scomoda.
     */
    public function delete(User $utente, Conversation $conversazione): bool
    {
        return false;
    }

    /**
     * Le due condizioni insieme.
     *
     * Il tenant non è ridondante rispetto ai partecipanti: gli id sono numeri
     * progressivi globali, e senza il controllo basterebbe indovinarne uno per
     * arrivare al filo di un'altra palestra. È lo stesso doppio controllo di
     * `routes/channels.php`.
     */
    private function partecipa(User $utente, Conversation $conversazione): bool
    {
        return $conversazione->includes($utente)
            && $conversazione->tenant_id === $utente->tenant_id;
    }
}
