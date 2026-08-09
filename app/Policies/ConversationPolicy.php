<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Conversation;
use App\Models\User;
use App\Support\Impersonation\Impersonator;

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
 * differenza è deliberata: il pannello di piattaforma non ha nessuna risorsa sulle conversazioni,
 * quindi togliere la scorciatoia non toglie nessuna funzione a nessuno — toglie solo un modo di
 * sbagliare.
 *
 * ### 🚨 E NEMMENO IMPERSONANDO
 *
 * Questa è la parte che conta davvero, ed è quella che si dimentica.
 *
 * Durante un'impersonazione `auth()->user()` **è** la persona impersonata: per ogni altra regola
 * del sistema il super admin *è* quel trainer, ed è esattamente il punto dell'impersonazione. Ma
 * qui quella sostituzione produrrebbe l'esatto contrario di quello che la riservatezza della chat
 * deve garantire: basterebbe impersonare un trainer per leggere tutte le sue conversazioni con gli
 * iscritti.
 *
 * Il fatto che l'impersonazione sia **tracciata** non basta. La traccia dice che qualcuno è
 * entrato in un account, non che ha letto una conversazione: rende l'accesso ricostruibile, non
 * legittimo. E soprattutto non cambia niente per l'iscritto, che ha scritto quelle cose al proprio
 * trainer credendo — giustamente — che le leggesse solo lui.
 *
 * Quindi: **una sessione impersonata non legge NESSUNA conversazione**, sua o altrui. Non è un
 * caso limite da gestire, è la regola.
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

    /**
     * Si può avere un elenco di conversazioni?
     *
     * Chiunque può avere le **proprie** — quali siano lo decide lo scope, non
     * questo metodo. Ma non chi sta impersonando: per lui l'elenco non esiste,
     * e i tre punti d'ingresso (API, pannello, canali) lo chiedono qui invece
     * di ripetere il controllo.
     */
    public function viewAny(User $utente): bool
    {
        return ! $this->staImpersonando();
    }

    public function view(User $utente, Conversation $conversazione): bool
    {
        return $this->partecipa($utente, $conversazione);
    }

    public function create(User $utente): bool
    {
        return ! $this->staImpersonando();
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
        // 🚨 Prima di tutto il resto: durante un'impersonazione l'utente
        // "partecipante" è la persona impersonata, e senza questa riga il
        // controllo qui sotto direbbe di sì.
        if ($this->staImpersonando()) {
            return false;
        }

        return $conversazione->includes($utente)
            && $conversazione->tenant_id === $utente->tenant_id;
    }

    /**
     * La sessione corrente è di qualcuno nei panni di un altro?
     *
     * Legge la sessione e non l'utente, perché è l'unico posto in cui la
     * differenza esiste: l'utente autenticato, durante un'impersonazione, è
     * indistinguibile da quello vero.
     */
    private function staImpersonando(): bool
    {
        return app(Impersonator::class)->isImpersonating();
    }
}
