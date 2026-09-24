<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🆔 **Il nome utente diventa un nome pubblico** — blocco B3, 24/09/2026.
 *
 * 📌 Il committente, il 23/09: *«per quanto riguarda la chat bisognerà
 * inventarsi un sistema di username per poter scrivere a chiunque, così una
 * palestra o un trainer possono invitare direttamente un allievo o un trainer
 * da app a app»*. Le cinque decisioni sono del 24/09 (`memory/decisioni.md`).
 *
 * ══ 🚨 CAMBIA LA NATURA DI UNA COLONNA CHE ESISTE GIA' ══════════════════════
 *
 * ⚠️ `users.username` c'è dal 09/08/2026, ma serve **solo ad accedere**: è un
 * dato di accesso, non un nome pubblico. Renderlo cercabile lo trasforma in un
 * **trattamento** — per questo la trovabilità nasce **spenta**, e per questo
 * serve una riga nell'informativa.
 *
 * ── Le tre colonne, una per decisione ───────────────────────────────────────
 *
 * | Colonna | Decisione | Perché |
 * |---|---|---|
 * | `trovabile` | 53.1 | ⛔ **spenta di serie**: chi si allena non si è iscritto per farsi cercare |
 * | `username_cambiato_il` | 53.5b | si cambia **una volta ogni 30 giorni** — cambiarlo di continuo è il modo di sfuggire a chi ti ha bloccato |
 * | `nomi_utente_bruciati` | 53.4 | ⛔ **mai riciclati**, nemmeno dopo la cancellazione |
 *
 * ══ 🚨 PERCHE' UNA TABELLA A PARTE PER I NOMI BRUCIATI ══════════════════════
 *
 * ⛔ Non basta lasciare la riga in `users`: un account **cancellato davvero**
 * (non `deleted_at`, proprio cancellato — lo fa `AccountController::destroy`
 * dopo il periodo di grazia) porterebbe via il nome, che tornerebbe libero.
 *
 * 🚨 E un nome liberato e riassegnato fa arrivare **a un'altra persona** i
 * messaggi destinati alla prima. ⚠️ Non c'è modo di accorgersene dopo: chi
 * scrive vede il nome giusto e una persona che risponde.
 *
 * 💡 La tabella tiene **solo il nome**, non a chi apparteneva: serve a dire
 * «questo non si riassegna», e tenere l'utente sarebbe conservare un legame
 * con una persona che ha chiesto di sparire.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * ⛔ **Spenta di serie, e `false` non è nullable**: un `null` qui
             * vorrebbe dire «non si sa», e in una ricerca «non si sa» finirebbe
             * per somigliare a «sì» il giorno che qualcuno scrive
             * `where('trovabile', '!=', false)`.
             */
            $table->boolean('trovabile')->default(false)->after('username');

            /*
             * ⚠️ `null` = mai cambiato. 💡 Chi non l'ha mai cambiato può farlo
             * subito: la finestra dei 30 giorni parte dal **primo** cambio, non
             * dalla registrazione.
             */
            $table->timestamp('username_cambiato_il')->nullable()->after('trovabile');

            // 🔎 La ricerca filtra sempre su questo: senza indice diventa una
            //    scansione di tutta la tabella a ogni lettera digitata.
            $table->index('trovabile');
        });

        Schema::create('nomi_utente_bruciati', function (Blueprint $table): void {
            $table->id();

            /*
             * 🚨 `unique`: lo stesso nome non si brucia due volte. ⚠️ E la
             * lunghezza è **la stessa di `users.username`** (30): un nome più
             * lungo non può esistere, e uno più corto verrebbe troncato in
             * silenzio — cioè bruciato **male**, lasciando libero il vero nome.
             */
            $table->string('nome', 30)->unique();

            /*
             * 💡 Solo **quando**, non **di chi**: serve a dire «questo non si
             * riassegna». Tenere l'utente vorrebbe dire conservare un legame
             * con una persona che ha chiesto di sparire.
             */
            $table->timestamp('bruciato_il');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nomi_utente_bruciati');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['trovabile']);
            $table->dropColumn(['trovabile', 'username_cambiato_il']);
        });
    }
};
