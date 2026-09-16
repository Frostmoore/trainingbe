<?php

declare(strict_types=1);

namespace App\Filament\Gym\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Lo scoping del trainer, applicato dove conta davvero — B3.5.
 *
 * 🚨 **Va fatto in `getEloquentQuery()`, non nella vista.**
 * Una policy risponde su un record alla volta: non impedisce a un elenco di
 * mostrare tutti gli iscritti della palestra, ne' a un'esportazione, ne' a una
 * ricerca globale. Nascondere le righe in Blade sarebbe anche peggio — i dati
 * sarebbero comunque nella risposta.
 *
 * ⛔ **La regola di B3.5 era**: *«il gym_admin vede tutta la palestra, il trainer
 * solo i propri assegnati»*.
 *
 * 🆕 **Dal 16/09/2026 non è più così**, e il perché sta per esteso dentro
 * `limitaAgliAssegnati()`: un trainer vede tutti gli iscritti della palestra —
 * **in lettura** — perché è quello che vede già nell'app, e due risposte diverse
 * alla stessa domanda sono peggio di una risposta larga. ⚖️ Quello che B3.5
 * proteggeva davvero — poterci **fare** qualcosa — resta chiuso altrove.
 */
trait ScopedToTrainer
{
    /**
     * Limita una query agli iscritti visibili a chi sta guardando.
     *
     * @param  string  $colonna  la colonna che punta all'iscritto
     */
    protected static function limitaAgliAssegnati(Builder $query, string $colonna = 'id'): Builder
    {
        $utente = auth()->user();

        if (! $utente instanceof User) {
            // Nessun utente: si restituisce il vuoto, non tutto. In un pannello
            // questo caso non capita, ma il valore prudente e' quello che non
            // fa danni se un giorno capitasse.
            return $query->whereRaw('1 = 0');
        }

        if ($utente->isSuperAdmin() || $utente->isGymAdmin()) {
            return $query;
        }

        /*
         * ══ 🆕 UN TRAINER VEDE TUTTA LA PALESTRA, IN LETTURA — 16/09/2026 ════
         *
         * 📌 Il committente, dopo la regola del 15/09: *«tutti in lettura, ma le
         * schede e i piani entrano nel suo profilo personale, non può
         * assegnarli dal server»*.
         *
         * ⛔ **Qui c'era `whereIn($colonna, $assegnati)`** — B3.5, *«il trainer
         * vede solo i propri assegnati»*. 🚨 Ma dal 15/09 nell'app lo stesso
         * trainer vede **tutti** gli iscritti della palestra: due risposte
         * diverse alla stessa domanda, a seconda di dove si guardava.
         *
         * ⚖️ **E la ragione di B3.5 non si perde**: era *«un trainer che se ne
         * va non deve portarsi via l'elenco clienti»*. Quello che B3.5
         * proteggeva davvero non era la **vista** — in sala quelle persone le
         * incontra tutte — ma il **poterci fare qualcosa**. E quello resta
         * chiuso, da due porte che erano già lì:
         *
         * | Cosa | Chi può |
         * |---|---|
         * | creare, modificare, cancellare un iscritto | ⛔ solo il `gym_admin` (`UserPolicy`) |
         * | assegnare una scheda a qualcuno dal pannello | ⛔ **nessuno**: da D4 il campo non esiste più |
         *
         * 💡 Le schede che il trainer compone qui nascono **senza destinatario**
         * e sono sue; arrivano alla persona **dalla chat cifrata**, che il
         * pannello non può nemmeno leggere. Cioè: vede chi c'è, e lavora solo
         * con chi gli parla.
         */
        if (! $utente->isTrainer()) {
            return $query->whereRaw('1 = 0');
        }

        return $query;
    }
}
