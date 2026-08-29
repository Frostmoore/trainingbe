<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `tenant_invites` — l'invito di una palestra a **una persona**, 3b-V.1.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«non mi piace il codice per iscriversi in palestra, preferisco un link di
 * invito»* — 28/08. E il 29/08: *«Il link d'invito deve essere monouso»*.
 *
 * ══ ⛔ PERCHE' NON BASTAVA IL `join_code` ═════════════════════════════════
 *
 * | | `join_code` | invito |
 * |---|---|---|
 * | Quante volte | infinite | **una sola** (`used_at`) |
 * | Per quanto | per sempre | **scade** (`expires_at`) |
 * | Revocabile | rigenerandolo **per tutti** | **uno per volta** (`revoked_at`) |
 * | Rifiutabile | — | 🆕 **si** (`rifiutato_il`) |
 *
 * 🚨 La critica al codice palestra era gia' scritta, e in questo repository, dal
 * **13/08** — in `TrainerIndipendenteTest`: *«chiunque lo conosca entra, quante
 * volte vuole, per sempre»*. Non era mai stata raccolta.
 *
 * 💡 E il caso concreto: **un link che finisce in una chat di gruppo non deve
 * far entrare venti persone**.
 *
 * ══ 🚨 STESSA FORMA DI `trainer_invites`, E NON PER SIMMETRIA ═════════════
 *
 * Le colonne sono le stesse perche' la **regola di validita' e' la stessa**, e
 * vive in `App\Models\Concerns\EUnInvito`. ⛔ Due tabelle simili con due regole
 * diverse sarebbero due bug che aspettano: quello che diverge e' sempre un
 * invito che continua a funzionare quando non dovrebbe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_invites', function (Blueprint $tabella): void {
            $tabella->id();

            /*
             * 🚨 La palestra che invita. E' anche lo **scope**: senza, un
             * gestore vedrebbe gli inviti delle altre palestre — cioe' gli
             * indirizzi email dei clienti dei suoi concorrenti. E' la stessa
             * ragione scritta su `TrainerInvite`.
             */
            $tabella->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            /*
             * Chi materialmente ha premuto «invita».
             *
             * ⚠️ `nullOnDelete` e non `cascade`: se quel dipendente se ne va e
             * il suo account viene cancellato, **l'invito resta**. Cancellarlo
             * butterebbe via l'iscrizione di una persona che non c'entra
             * niente con chi l'ha invitata.
             */
            $tabella->foreignId('invitato_da')->nullable()
                ->constrained('users')->nullOnDelete();

            /*
             * Il segreto che viaggia nel link.
             *
             * ⚠️ **Si conserva in chiaro, e va detto perche'**: non e' una
             * password ma un biglietto monouso a vita breve, e il riscatto deve
             * poterlo cercare per uguaglianza. Conservarne l'hash impedirebbe
             * l'indice unico e costringerebbe a una scansione a ogni tentativo.
             * 💡 La difesa e' la **lunghezza** (32 caratteri casuali), la
             * scadenza e l'uso singolo.
             */
            $tabella->string('token', 64)->unique();

            // A chi era destinato, se lo si sa: serve a mostrare alla palestra
            // «invito a mario@…» invece di un elenco di codici indistinguibili.
            $tabella->string('email')->nullable();

            $tabella->timestamp('expires_at');
            $tabella->timestamp('used_at')->nullable();
            $tabella->timestamp('revoked_at')->nullable();

            /*
             * 🆕 **Il rifiuto** — 3b-V.1.3.
             *
             * 📌 *«due tasti, uno per accettare e uno per rifiutare»*: il
             * rifiuto e' una risposta, e una risposta va registrata.
             *
             * 💡 Brucia l'invito, ed e' giusto: era per quella persona, e
             * quella persona ha detto no. Lasciarlo valido vorrebbe dire un
             * invito che nessuno usera' mai e che la palestra crede in piedi.
             */
            $tabella->timestamp('rifiutato_il')->nullable();

            // Chi e' entrato con questo invito. `nullOnDelete` e non `cascade`:
            // se quella persona cancella l'account, l'invito resta come traccia
            // del fatto che e' stato usato.
            $tabella->foreignId('accepted_by')->nullable()
                ->constrained('users')->nullOnDelete();

            $tabella->timestamps();

            $tabella->index(['tenant_id', 'used_at']);
        });

        /*
         * ── ⚠️ E il rifiuto vale anche per gli inviti dei trainer ──────────
         *
         * 🚨 **Due inviti che si comportano diversamente sono due bug che
         * aspettano.** `EUnInvito::eValido()` guarda `rifiutato_il`, e la guarda
         * su **tutti e due** i modelli: senza questa colonna, `TrainerInvite`
         * esploderebbe alla prima chiamata.
         *
         * 💡 Non e' scope che scappa: e' il prezzo di avere una regola sola. Il
         * pezzo dell'app che permette di rifiutare un invito di un trainer puo'
         * arrivare quando serve — la colonna intanto non da' fastidio a nessuno.
         */
        Schema::table('trainer_invites', function (Blueprint $tabella): void {
            $tabella->timestamp('rifiutato_il')->nullable()->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('trainer_invites', function (Blueprint $tabella): void {
            $tabella->dropColumn('rifiutato_il');
        });

        Schema::dropIfExists('tenant_invites');
    }
};
