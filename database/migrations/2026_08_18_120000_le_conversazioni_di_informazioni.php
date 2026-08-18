<?php

declare(strict_types=1);

use App\Enums\TipoConversazione;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le conversazioni che attraversano due palestre — 18/08/2026. **M3.1 e M4.1.**
 *
 * ── 🚨 Il problema che questa migrazione risolve ───────────────────────────
 *
 * Fino a oggi una conversazione stava **dentro una palestra**: `tenant_id` era
 * il tenant dell'iscritto, e i due capi del filo appartenevano allo stesso.
 *
 * ⚠️ Dal catalogo nasce un caso nuovo: qualcuno del tenant A scrive al
 * proprietario del tenant B. Con `tenant_id` obbligatorio, **uno dei due non
 * vedrebbe mai la conversazione** — il global scope la filtrerebbe via, e la
 * policy la negherebbe (`tenant_id === $utente->tenant_id`).
 *
 * 🚨 E non darebbe nessun errore: il messaggio si scriverebbe, e semplicemente
 * il destinatario non lo troverebbe mai. E' il modo peggiore di rompersi.
 *
 * ── 💡 La soluzione: `tenant_id` NULL vuol dire «di nessuna palestra» ──────
 *
 * E' lo stesso schema gia' in uso su `exercises` e `media`
 * (`BelongsToTenantOrGlobal`): `NULL` = riga visibile in ogni contesto.
 *
 * 🚨 **Non e' un buco nell'isolamento**, e la ragione va capita prima di
 * toccare questo file: il controllo vero su una conversazione **non e' mai
 * stato il tenant**. E' `Conversation::includes()`, che confronta i due id dei
 * partecipanti con **l'id di chi chiede** — indovinare l'id di una
 * conversazione non aiuta, perche' bisogna comunque *essere* uno dei due. Il
 * tenant era una seconda cintura, e per i fili fra due palestre quella cintura
 * e' semplicemente la misura sbagliata.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $tabella): void {
            /*
             * 🚨 `tenant_id` diventa **nullable**.
             *
             * ⚠️ `change()` su una colonna con chiave esterna: la FK resta, e va
             * bene — `NULL` e' sempre ammesso da una chiave esterna, non punta a
             * niente e non viola niente.
             */
            $tabella->foreignId('tenant_id')->nullable()->change();

            /*
             * Il genere del filo. **Predefinito `iscritto`**, cosi' tutte le
             * conversazioni scritte prima di oggi restano quello che erano senza
             * bisogno di riscriverle.
             */
            $tabella->string('tipo', 16)->default(TipoConversazione::Iscritto->value)->after('member_id');

            /*
             * 🚨 **Il conteggio per parte**: `{"12": 3, "45": 1}`.
             *
             * ⚠️ Un contatore solo non basta, perche' il limite e' **di
             * ciascuno**: tre messaggi miei e tre suoi. Con un numero unico, chi
             * scrive per primo consumerebbe anche la quota dell'altro — e chi
             * riceve una domanda non potrebbe rispondere.
             *
             * 💡 JSON e non una tabella a parte: sono due numeri per riga, si
             * leggono sempre insieme alla conversazione, e non si interrogano
             * mai per conto loro. Una tabella qui sarebbe una JOIN in piu' su
             * ogni messaggio per conservare due interi.
             */
            $tabella->json('messaggi_di')->nullable()->after('tipo');

            /*
             * 💡 Serve a `index()`: l'elenco delle conversazioni di una persona
             * ora attraversa i tenant, quindi il filtro utile e' il tipo.
             */
            $tabella->index('tipo');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $tabella): void {
            $tabella->dropIndex(['tipo']);
            $tabella->dropColumn(['tipo', 'messaggi_di']);

            /*
             * ⚠️ **Il ritorno indietro non e' sicuro se esistono gia' fili fra
             * due palestre**: quelle righe hanno `tenant_id` nullo e la colonna
             * tornerebbe `NOT NULL`. Vanno cancellate prima, ed e' voluto che si
             * debba deciderlo a mano — non e' una cosa che una migrazione deve
             * fare di nascosto ai messaggi di qualcuno.
             */
            $tabella->foreignId('tenant_id')->nullable(false)->change();
        });
    }
};
