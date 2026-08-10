<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\AiAdvice;
use App\Models\BodyMetric;
use App\Models\DailyBurn;
use App\Models\DeviceToken;
use App\Models\FoodEntry;
use App\Models\FoodFavorite;
use App\Models\HealthSample;
use App\Models\Media;
use App\Models\Message;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * La cancellazione dell'account dell'iscritto — C6.
 *
 * 🚨 **Non è un miglioramento: è un requisito di App Store.** Ogni app che
 * permette di registrarsi deve permettere di cancellarsi, dall'app stessa e
 * senza scrivere a nessuno. Senza, la pubblicazione viene rifiutata.
 *
 * ── La parte difficile non è cancellare: è decidere cosa resta ──────────────
 *
 * Il diario, le misure, le foto e gli allenamenti sono suoi e di nessun altro:
 * spariscono.
 *
 * **I messaggi della chat no.** Cancellarli svuoterebbe la conversazione **dal
 * lato del trainer**, che si ritroverebbe le proprie risposte senza le domande —
 * e quella è la sua documentazione professionale, che può servirgli anche a
 * distanza di anni. L'equilibrio è: il trainer tiene lo storico, l'iscritto
 * smette di essere identificabile. Si sostituisce l'autore, non si riscrive il
 * testo: riscriverlo sarebbe falsificare una conversazione avvenuta.
 *
 * **La riga `users` si anonimizza, non si cancella.** `messages.sender_id` e
 * `audit_logs.user_id` ci puntano: una cancellazione a cascata porterebbe via
 * anche il registro di controllo, che è esattamente ciò che serve il giorno in
 * cui qualcuno contesta una cancellazione.
 */
class AccountEraser
{
    /** Quanto resta di una persona dopo: un segnaposto, non un buco. */
    public const NOME_ANONIMO = 'Utente eliminato';

    /**
     * Cancella tutto il cancellabile e anonimizza il resto.
     *
     * Tutto in una transazione: un'eliminazione a metà lascerebbe una persona
     * senza diario ma ancora identificabile, che è il peggiore dei due mondi.
     */
    public function erase(User $utente): void
    {
        DB::transaction(function () use ($utente): void {
            $this->cancellaDatiPersonali($utente);
            $this->cancellaFoto($utente);
            $this->anonimizzaMessaggi($utente);
            $this->revocaAccessi($utente);
            $this->anonimizzaUtente($utente);
        });
    }

    /**
     * Diario, misure, allenamenti, sonno, consigli.
     *
     * ⚠️ Le schede **che si è scritto da solo** spariscono; quelle prescritte
     * dal trainer restano, perché sono lavoro suo e possono essere riusate come
     * modello. Le sessioni però se ne vanno comunque: sono cronaca di ciò che
     * ha fatto questa persona.
     */
    private function cancellaDatiPersonali(User $utente): void
    {
        $id = $utente->getKey();

        // Le serie prima delle sessioni: `session_sets` ha la foreign key.
        DB::table('session_sets')
            ->whereIn('workout_session_id', WorkoutSession::withoutGlobalScopes()
                ->where('user_id', $id)->select('id'))
            ->delete();

        WorkoutSession::withoutGlobalScopes()->where('user_id', $id)->forceDelete();

        WorkoutPlan::withoutGlobalScopes()
            ->where('member_id', $id)
            ->where('created_by', $id)
            ->each(function (WorkoutPlan $p): void {
                $p->exercises()->delete();
                $p->forceDelete();
            });

        FoodEntry::withoutGlobalScopes()->where('user_id', $id)->delete();
        FoodFavorite::withoutGlobalScopes()->where('user_id', $id)->delete();
        DailyBurn::withoutGlobalScopes()->where('user_id', $id)->delete();
        BodyMetric::withoutGlobalScopes()->where('user_id', $id)->delete();
        HealthSample::withoutGlobalScopes()->where('user_id', $id)->delete();
        AiAdvice::withoutGlobalScopes()->where('user_id', $id)->delete();

        $utente->profile()->delete();
    }

    /**
     * Le foto, **file compresi**.
     *
     * 🚨 `Media::delete()` e non una `DELETE` sulla tabella: il modello di
     * medialibrary cancella anche il file dal disco. Una riga rimossa senza il
     * file lascerebbe sul server proprio le immagini più personali che questo
     * sistema conserva, dopo che qualcuno ha chiesto di sparire.
     */
    private function cancellaFoto(User $utente): void
    {
        Media::query()
            ->where('model_type', $utente->getMorphClass())
            ->where('model_id', $utente->getKey())
            ->get()
            ->each(fn (Media $m) => $m->delete());
    }

    /**
     * I messaggi: non si toccano affatto, ed è deliberato.
     *
     * `messages.sender_id` punta alla riga `users`, che **resta** (anonimizzata
     * e in soft delete): da quel momento il trainer, riaprendo la
     * conversazione, legge le stesse frasi firmate «Utente eliminato». Il testo
     * non si riscrive — riscriverlo sarebbe falsificare una conversazione
     * davvero avvenuta.
     *
     * 🚨 **Per questo la riga utente non si cancella davvero.** `sender_id` è
     * `NOT NULL` con `cascadeOnDelete`: una `forceDelete()` porterebbe via i
     * messaggi **da entrambi i lati**, e il trainer perderebbe le proprie
     * risposte insieme alle domande. Chi un giorno fosse tentato di trasformare
     * il soft delete in una cancellazione vera deve sapere che si porta dietro
     * questo.
     */
    private function anonimizzaMessaggi(User $utente): void
    {
        // Volutamente vuoto: l'anonimizzazione della riga utente è ciò che
        // anonimizza i messaggi. Il metodo resta perché il passaggio sia
        // esplicito in `erase()` e nessuno lo consideri dimenticato.
        unset($utente);
    }

    /**
     * Nessun dispositivo continua a scrivere su un account che non c'è più.
     *
     * Il token di ingest dell'orologio è dentro `users` e sparisce con
     * l'anonimizzazione; i token di Sanctum e le notifiche vanno tolti qui.
     */
    private function revocaAccessi(User $utente): void
    {
        $utente->tokens()->delete();

        DeviceToken::withoutGlobalScopes()
            ->where('user_id', $utente->getKey())
            ->delete();
    }

    /**
     * La riga resta, la persona no.
     *
     * L'email diventa un indirizzo irripetibile e non recapitabile: serve a non
     * violare l'unicità `(tenant_id, email)` se la stessa persona un domani si
     * riscrive, e a rendere evidente a chi legge il database che quella riga non
     * è più nessuno.
     */
    private function anonimizzaUtente(User $utente): void
    {
        $segnaposto = 'eliminato-'.Str::lower(Str::random(16)).'@utente-eliminato.invalid';

        $utente->forceFill([
            'name' => self::NOME_ANONIMO,
            'email' => $segnaposto,
            'username' => null,
            'phone' => null,
            'avatar_path' => null,
            'password' => bcrypt(Str::random(64)),
            'remember_token' => null,
            'health_ingest_token' => null,
            'is_active' => false,
            'email_verified_at' => null,
        ])->save();

        // Soft delete: la riga resta raggiungibile per le foreign key, ma esce
        // da ogni elenco, da ogni conteggio e da ogni tentativo di accesso.
        $utente->delete();
    }
}
