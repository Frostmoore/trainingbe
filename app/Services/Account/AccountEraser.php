<?php

declare(strict_types=1);

namespace App\Services\Account;

use App\Models\AiAdvice;
use App\Models\ChatKey;
use App\Models\DailyBurn;
use App\Models\DeviceToken;
use App\Models\FoodEntry;
use App\Models\FoodFavorite;
use App\Models\Media;
use App\Models\RecoveryKey;
use App\Models\SocialIdentity;
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

        /*
         * ══ ⛔ GLI ALLENAMENTI NON SI CANCELLANO PIU' DA QUI — 11.6.4 ═══════
         *
         * 🚨 `session_sets`, `workout_sessions` e `daily_burns` **non esistono
         * piu'**: sono cadute con la FASE 11.6.3 il 23/08/2026, e i dati stanno
         * sul telefono. Cercarle qui farebbe **esplodere la cancellazione
         * dell'account** — cioe' rompere qualcosa nel momento peggiore, a meta'
         * di un'operazione irreversibile.
         *
         * ⚠️ **E non c'e' niente da mettere al loro posto**: quei dati sono
         * sull'apparecchio della persona. Li cancella disinstallando l'app, o
         * dal pulsante in «Privacy e consensi». Il server non li ha, quindi non
         * puo' e non deve cancellarli — ed e' scritto nell'informativa.
         *
         * 💡 Questo commento resta al posto del codice: chi legge
         * `cancellaDatiPersonali` deve capire **perche'** gli allenamenti non
         * ci sono, invece di crederli dimenticati.
         */

        WorkoutPlan::withoutGlobalScopes()
            ->where('member_id', $id)
            ->where('created_by', $id)
            ->each(function (WorkoutPlan $p): void {
                $p->exercises()->delete();
                $p->forceDelete();
            });

        FoodEntry::withoutGlobalScopes()->where('user_id', $id)->delete();
        FoodFavorite::withoutGlobalScopes()->where('user_id', $id)->delete();
        AiAdvice::withoutGlobalScopes()->where('user_id', $id)->delete();

        /*
         * ⚠️ Qui c'era `HealthSample`. La tabella non esiste piu' (S1.6): sonno,
         * HRV e battito vivono sul telefono di chi li produce.
         *
         * 🚨 **E qui c'era anche un difetto, che vale la pena ricordare.**
         * `health_readings` (HRV e battito a riposo) **non veniva cancellata da
         * nessuno**: sopravviveva alla cancellazione dell'account. Era una
         * violazione del diritto alla cancellazione su una categoria
         * particolare, ed e' rimasta invisibile per settimane perche' la tabella
         * era nata dopo questo metodo e nessuno lo aveva riaperto.
         *
         * La lezione non e' «ricordarsi di aggiornare l'eraser»: e' che una
         * regola affidata alla memoria e' gia' rotta. In **S9.3** entra il test
         * che passa in rassegna **tutte** le tabelle con `user_id` e verifica
         * che dopo `erase()` non sopravviva nessuna riga.
         */

        /*
         * 🚨 **Le chiavi (S6), aggiunte QUI e non dopo.**
         *
         * E' esattamente il difetto di `health_readings` raccontato sopra: una
         * tabella nuova con `user_id` che nessuno ricollega all'eraser. La
         * differenza e' che questa volta la riga si scrive **nella stessa
         * sessione in cui nasce la tabella**, invece di fidarsi di ricordarsene.
         *
         * ⚠️ Cancellare il pacchetto incartato non e' un dettaglio: e' materiale
         * attaccabile offline. Chi chiede di sparire non deve lasciare da noi
         * qualcosa contro cui provare password per sempre.
         *
         * 💡 Il pacchetto sparisce, i **messaggi restano** (cifrati, illeggibili
         * da chiunque, compresi noi). Non e' una contraddizione: sono
         * documentazione professionale del trainer, e senza la chiave non
         * identificano piu' nessuno — sono byte casuali con accanto «Utente
         * eliminato».
         */
        RecoveryKey::query()->where('user_id', $id)->delete();
        ChatKey::query()->where('user_id', $id)->delete();

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

        /*
         * 🚨 **Il legame con Google e Apple, trovato mancante in S9.3.**
         *
         * La foreign key di `social_identities` e' `cascadeOnDelete`, quindi
         * sembrava a posto — ⚠️ ma la riga `users` **resta in soft delete**, e
         * una cancellazione che non avviene non fa scattare nessuna cascade.
         *
         * Il sintomo non era quello che verrebbe da pensare: non si rientrava
         * nell'account cancellato (`SocialIdentity::user()` punta a un modello
         * con `SoftDeletes` e restituisce `null`). Il danno era peggiore e
         * silenzioso: **quella persona non poteva piu' registrarsi, mai**, con
         * quell'account Google — l'identita' risultava presa da un utente che
         * non esiste piu', e l'errore diceva soltanto «Non e' stato possibile
         * completare l'accesso».
         *
         * 💡 E' anche l'unica riga che collega la persona a un identificativo
         * **di un altro fornitore**: tenerla dopo una richiesta di cancellazione
         * non avrebbe avuto nessuna giustificazione.
         */
        SocialIdentity::query()
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
            'is_active' => false,
            'email_verified_at' => null,
        ])->save();

        // Soft delete: la riga resta raggiungibile per le foreign key, ma esce
        // da ogni elenco, da ogni conteggio e da ogni tentativo di accesso.
        $utente->delete();
    }
}
