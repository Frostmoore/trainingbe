<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\WorkoutPlan;

/**
 * Chi tocca le schede — B3.6.
 *
 * Il trainer lavora sui **propri** iscritti e sui modelli della palestra;
 * l'amministratore su tutto quello della sua palestra. L'iscritto non arriva
 * qui: usa l'app, dove il controllo e' nel controller API.
 */
class WorkoutPlanPolicy
{
    public function before(User $utente, string $ability): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $utente): bool
    {
        return $utente->isGymAdmin() || $utente->isTrainer();
    }

    public function view(User $utente, WorkoutPlan $piano): bool
    {
        if ($utente->tenant_id !== $piano->tenant_id) {
            return false;
        }

        // La scheda assegnata a questa persona, se pubblicata: e' il caso
        // dell'iscritto che apre la propria scheda nell'app. Una bozza resta
        // invisibile — e' lavoro in corso del trainer.
        if ($piano->member_id === $utente->getKey() && $piano->isVisibleToMember()) {
            return true;
        }

        if ($utente->isGymAdmin()) {
            return true;
        }

        if (! $utente->isTrainer()) {
            return false;
        }

        // I modelli della palestra sono di tutti i trainer: e' il loro scopo.
        if ($piano->isTemplate()) {
            return true;
        }

        return $utente->assignedMembers()
            ->where('users.id', $piano->member_id)
            ->exists();
    }

    /**
     * Chi puo' creare una scheda.
     *
     * 🚨 **Anche l'iscritto** (decisione D1 della fase C): si scrive le proprie.
     * Un'app in cui puoi solo subire la scheda di qualcun altro non serve a chi
     * si allena senza un trainer che lo segue — cioe' alla maggioranza degli
     * iscritti di una palestra.
     *
     * Cio' che l'iscritto crea nasce con `member_id = created_by = sé stesso`:
     * lo impone `WorkoutPlanController::store()`, non questa policy, perche' qui
     * la scheda ancora non esiste.
     */
    public function create(User $utente): bool
    {
        return true;
    }

    /**
     * Chi puo' modificare una scheda.
     *
     * 🚨 **Il discriminante e' `created_by`, non il ruolo.** Nella stessa tabella
     * convivono due cose diverse: la scheda **prescritta dal trainer** — che
     * l'iscritto esegue e non tocca — e quella che **l'iscritto si e' scritto**,
     * che e' sua.
     *
     * Distinguerle per ruolo sarebbe sbagliato in tutte e due le direzioni: un
     * trainer e' anche una persona che si allena, e la scheda che scrive per sé
     * deve restare sua; un iscritto che potesse correggere la prescrizione del
     * proprio trainer renderebbe la prescrizione priva di senso.
     */
    public function update(User $utente, WorkoutPlan $piano): bool
    {
        if ($utente->tenant_id !== $piano->tenant_id) {
            return false;
        }

        // Ciò che uno si è scritto per sé è suo, qualunque ruolo abbia.
        if ($this->ePersonale($utente, $piano)) {
            return true;
        }

        // Chi non lavora nella palestra non tocca nient'altro.
        if (! $utente->isGymAdmin() && ! $utente->isTrainer()) {
            return false;
        }

        return $this->view($utente, $piano);
    }

    /**
     * La scheda che questa persona ha scritto **per sé**.
     *
     * Servono entrambe le condizioni: `created_by` da solo renderebbe
     * modificabile dal trainer anche la scheda che ha prescritto a un altro —
     * il che è vero, ma passa dal ramo del ruolo, non da questo.
     */
    private function ePersonale(User $utente, WorkoutPlan $piano): bool
    {
        return $piano->member_id === $utente->getKey()
            && $piano->created_by === $utente->getKey();
    }

    /**
     * ══ ⛔ IL VINCOLO SULLE SEDUTE E' CADUTO CON LE SEDUTE — 23/08/2026 ═════
     *
     * 🚨 Qui c'era: *«una scheda con allenamenti gia' fatti non si cancella: le
     * sessioni ci puntano, e cancellarla lascerebbe uno storico senza
     * origine»*. Era giusto, e non e' piu' possibile: `workout_sessions` **non
     * esiste** (FASE 11.6.3), e la domanda «questa scheda e' stata usata?» sul
     * server non ha piu' risposta.
     *
     * ⚠️ **Il difetto che ha fatto emergere la cosa**: il pannello chiama la
     * policy per decidere se mostrare il pulsante «elimina», e l'editor delle
     * schede rispondeva **500** — non un errore di logica, una tabella
     * mancante. Lo ha trovato `PannelloDeiPianiTest`.
     *
     * ── 💡 Perche' si puo' permettere la cancellazione ──────────────────────
     *
     * Lo storico adesso sta **sul telefono**, e non e' una riga che punta a una
     * scheda: e' una copia completa della seduta, con il suo titolo dentro
     * (`SeduteAllenamento.titolo`). ⛔ Cancellare la scheda sul server **non
     * lascia orfano niente** — l'allenamento resta leggibile perche' non
     * dipendeva da lei.
     *
     * 🚨 E il vincolo a database (`restrictOnDelete`) e' caduto insieme alla
     * tabella che lo portava: non c'e' piu' nemmeno l'errore SQL da evitare.
     */
    public function delete(User $utente, WorkoutPlan $piano): bool
    {
        return $this->update($utente, $piano);
    }
}
