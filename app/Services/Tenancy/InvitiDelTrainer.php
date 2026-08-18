<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TipoConversazione;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Billing\PianoAttivo;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inviti personali di un trainer indipendente — F6.2, F6.4, F6.5.
 *
 * 🚨 **Il punto unico da cui passano le tre regole del rapporto trainer↔utente**:
 * quanti utenti può avere, come ne invita uno, e cosa succede quando lo
 * disattiva. Sparse fra controller e pannello divergerebbero.
 */
class InvitiDelTrainer
{
    public function __construct(
        private readonly PianoAttivo $piano,
        private readonly CreaTenantPersonale $creaTenantPersonale,
    ) {}

    /**
     * Quanti utenti può ancora accettare. `null` = senza limite — F6.5.
     *
     * ⚠️ **Gli inviti ancora validi contano come posti occupati.** Senza,
     * un trainer con tre posti potrebbe mandare trenta inviti e ritrovarsi con
     * trenta utenti: il limite verrebbe verificato al momento sbagliato, cioè
     * quando è troppo tardi per dire di no a qualcuno che ha già cliccato.
     */
    public function postiRimasti(User $trainer): ?int
    {
        $max = $this->piano->per($trainer)->max_members;

        if ($max === null) {
            return null;
        }

        $occupati = $trainer->assignedMembers()->count()
            + TrainerInvite::withoutGlobalScopes()
                ->where('trainer_id', $trainer->getKey())
                ->validi()
                ->count();

        return max(0, $max - $occupati);
    }

    /**
     * Crea un invito monouso.
     *
     * @throws ValidationException quando i posti sono finiti
     */
    public function invita(User $trainer, ?string $email = null): TrainerInvite
    {
        $rimasti = $this->postiRimasti($trainer);

        if ($rimasti !== null && $rimasti <= 0) {
            /*
             * 💡 Il messaggio dice **cosa fare**, non solo cosa non si può fare.
             * «Hai raggiunto il limite» lascia la persona ferma; dire che il
             * piano si può cambiare è l'unica informazione utile in quel momento.
             */
            throw ValidationException::withMessages([
                'invito' => __('Hai raggiunto il numero di utenti compreso nel tuo piano. Per averne di più serve un piano superiore.'),
            ]);
        }

        return TrainerInvite::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'trainer_id' => $trainer->getKey(),
            'token' => TrainerInvite::generaToken(),
            'email' => $email,
            'expires_at' => now()->addDays(TrainerInvite::GIORNI_DI_VITA),
        ]);
    }

    /**
     * Riscatta un invito: nasce l'utente, con il suo tenant personale, e il
     * legame con il trainer.
     *
     * 🚨 **`$maggiorenne` e `$condizioni` sono parametri obbligatori e senza
     * valore di serie** (§2.4). Un `= true` qui dentro vorrebbe dire dichiarare
     * la maggiore età al posto di chi si iscrive — esattamente ciò che lo
     * sbarramento esiste per impedire. Questa è una **porta d'ingresso nuova**,
     * e nasce con lo sbarramento già montato.
     *
     * ⚠️ Il posto si ricontrolla **adesso**, non solo alla creazione
     * dell'invito: fra i due momenti passa fino a una settimana, e nel
     * frattempo il trainer può aver riempito i posti con altri inviti.
     *
     * @param  array<string, mixed>  $attributi  password, username, telefono…
     *
     * @throws ValidationException
     */
    public function riscatta(
        string $token,
        string $nome,
        string $email,
        array $attributi,
        bool $maggiorenne,
        bool $condizioni,
    ): User {
        if (! $maggiorenne || ! $condizioni) {
            throw ValidationException::withMessages([
                'age_confirmed' => __('Per iscriverti devi dichiarare di essere maggiorenne e accettare le condizioni.'),
            ]);
        }

        $invito = TrainerInvite::withoutGlobalScopes()->where('token', $token)->first();

        if ($invito === null || ! $invito->eValido()) {
            /*
             * 🚨 Un solo messaggio per «non esiste», «già usato», «scaduto» e
             * «revocato». Distinguerli permetterebbe di provare token a tappeto
             * e capire quali sono esistiti — e quando.
             */
            throw ValidationException::withMessages([
                'token' => __('Questo invito non è più valido.'),
            ]);
        }

        $trainer = $invito->trainer;

        if ($trainer === null || ! $trainer->is_active) {
            throw ValidationException::withMessages([
                'token' => __('Questo invito non è più valido.'),
            ]);
        }

        $rimasti = $this->postiRimasti($trainer);

        if ($rimasti !== null && $rimasti <= 0) {
            throw ValidationException::withMessages([
                'token' => __('Questo invito non è più valido.'),
            ]);
        }

        $utente = ($this->creaTenantPersonale)($nome, $email, $attributi);

        $utente->forceFill([
            'age_confirmed_at' => now(),
            'terms_accepted_at' => now(),
        ])->save();

        /*
         * 🚨 **`trainer_member.tenant_id` è quello del TRAINER** — F6.1, C4.
         *
         * La colonna è NOT NULL e va decisa. È quello del trainer perché è lui
         * che «possiede» il rapporto: altrimenti non vedrebbe i propri legami
         * sotto il proprio scope, cioè non vedrebbe i propri utenti.
         */
        $utente->assignedTrainers()->attach($trainer->getKey(), [
            'tenant_id' => $trainer->tenant_id,
            'assigned_at' => now(),
            'assigned_by' => $trainer->getKey(),
        ]);

        $invito->forceFill([
            'used_at' => now(),
            'accepted_by' => $utente->getKey(),
        ])->save();

        return $utente;
    }

    /**
     * 🚨 **Il riscatto di chi ha GIA' un account** — M6.2, 18/08/2026.
     *
     * ── Perche' non bastava `riscatta()` ───────────────────────────────────
     *
     * Quello crea una persona nuova: e' la strada di chi arriva dal link senza
     * avere ancora niente. ⚠️ Ma il caso che la Parte M ha reso normale e'
     * l'opposto: qualcuno che **usa gia' l'app**, ha scritto a un trainer dal
     * catalogo, ha finito i tre messaggi, e riceve da lui un invito **in chat**.
     * Quella persona un account ce l'ha, e mandarla su un modulo di iscrizione
     * le direbbe di crearne un secondo.
     *
     * 💡 **E qui non si chiede l'abbonamento**, che e' tutto il punto della
     * decisione del committente: *«il link d'invito assegna chi lo usa — anche
     * se non abbonato — a chi l'ha creato»*. Diventare allievo di un trainer
     * apre la chat illimitata **con lui**, che e' un rapporto vero e non un
     * contatto a freddo.
     *
     * 🚨 **Non tocca il tenant.** Chi si allena da solo resta nel proprio spazio
     * personale: il legame `trainer_member` attraversa i tenant di proposito
     * (F6.1). ⚠️ Spostarlo dentro quello del trainer sarebbe un trasloco di dati
     * che nessuno ha chiesto, per un rapporto che si puo' interrompere domani.
     *
     * @throws ValidationException
     */
    public function riscattaPerChiEsiste(string $token, User $utente): User
    {
        $invito = TrainerInvite::withoutGlobalScopes()->where('token', $token)->first();

        // 🚨 Un solo messaggio per «non esiste», «gia' usato», «scaduto» e
        // «revocato»: distinguerli permetterebbe di provare token a tappeto.
        $nonValido = fn (): never => throw ValidationException::withMessages([
            'token' => __('Questo invito non è più valido.'),
        ]);

        if ($invito === null || ! $invito->eValido()) {
            $nonValido();
        }

        /*
         * 🚨 `withoutGlobalScopes()`, e senza questa riga NIENTE funziona.
         *
         * ⚠️ `TrainerInvite::trainer()` e' una relazione su `User`, che usa
         * `BelongsToTenant`: risolta qui viene filtrata dal tenant di **chi sta
         * riscattando**, che per definizione e' un altro. Il trainer risulta
         * `null` e ogni invito valido viene respinto come «non piu' valido».
         *
         * 💡 La strada della **registrazione** (`riscatta()`) non ha questo
         * problema perche' li' non c'e' nessuno autenticato, quindi nessun
         * contesto: e' lo stesso codice che si comporta in due modi a seconda di
         * chi lo chiama. Terza volta che questa trappola si presenta nella
         * Parte M — vedi §49.6 e §50.6 dell'atlante.
         */
        $trainer = User::query()->withoutGlobalScopes()->find($invito->trainer_id);

        if ($trainer === null || ! $trainer->is_active) {
            $nonValido();
        }

        /*
         * ⚠️ **Non si e' allievi di se' stessi.** Un trainer che apre il proprio
         * link — capita, provando come si vede — si ritroverebbe fra i propri
         * allievi, e da li' in ogni conteggio di posti occupati.
         */
        if ($trainer->is($utente)) {
            $nonValido();
        }

        $rimasti = $this->postiRimasti($trainer);

        if ($rimasti !== null && $rimasti <= 0) {
            $nonValido();
        }

        return DB::transaction(function () use ($invito, $trainer, $utente): User {
            $gia = $utente->assignedTrainers()->where('users.id', $trainer->getKey())->exists();

            if (! $gia) {
                /*
                 * 🚨 `trainer_member.tenant_id` e' quello del **trainer** — F6.1:
                 * e' lui che possiede il rapporto, e sotto il proprio scope deve
                 * vedere i propri allievi.
                 */
                $utente->assignedTrainers()->attach($trainer->getKey(), [
                    'tenant_id' => $trainer->tenant_id,
                    'assigned_at' => now(),
                    'assigned_by' => $trainer->getKey(),
                ]);
            }

            /*
             * 🚨 **La conversazione che c'era gia' diventa illimitata** — M6.2.
             *
             * ⚠️ Senza, la persona verrebbe assegnata al trainer e resterebbe
             * comunque bloccata nel filo dove si erano conosciuti: assegnata,
             * con la storia sotto gli occhi, e la penna ferma.
             *
             * 💡 `between()` la trova o la crea: se l'invito arriva da fuori
             * dalla chat, quella conversazione nasce adesso — gia' senza limite.
             */
            $c = Conversation::between($trainer, $utente);
            $c->tipo = TipoConversazione::Iscritto;
            $c->save();

            $invito->forceFill([
                'used_at' => now(),
                'accepted_by' => $utente->getKey(),
            ])->save();

            return $utente;
        });
    }

    /**
     * 🚨 **«Disattivare» un utente chiude SOLO i messaggi** — decisione D5.
     *
     * Il legame resta, la storia si conserva, il canale si chiude. **Reversibile.**
     *
     * 💡 E copre più di quanto sembri: dopo D11/D13 i piani viaggiano *dentro la
     * chat*, quindi chiudere il canale **chiude anche la consegna di piani
     * nuovi**. Non serve una seconda regola.
     *
     * 🚨 **Ciò che NON si può fare, e va scritto perché qualcuno lo chiederà**:
     * revocare i piani **già ricevuti**. Vivono sul telefono e il server non può
     * togliere ciò che non ha mai avuto. L'unica strada sarebbe chiedere all'app
     * di nasconderli — cioè fidarsi del client, che non si fa.
     */
    public function disattiva(User $trainer, User $utente): void
    {
        $utente->assignedTrainers()->updateExistingPivot($trainer->getKey(), [
            'disattivato_il' => now(),
        ]);
    }

    public function riattiva(User $trainer, User $utente): void
    {
        $utente->assignedTrainers()->updateExistingPivot($trainer->getKey(), [
            'disattivato_il' => null,
        ]);
    }

    /** Il piano di questo trainer prevede un limite di utenti? */
    public function limiteDelPiano(User $trainer): ?int
    {
        return $this->piano->per($trainer)->max_members;
    }

    /** 💡 Comodo per il sito e per il pannello: «sei sul piano X». */
    public function codicePiano(User $trainer): string
    {
        return $this->piano->per($trainer)->code ?? Plan::FREE;
    }
}
