<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Le identita' esterne: «questo account Google e' questa persona».
 *
 * 🚨 **Una tabella a parte, non due colonne su `users`.** Una persona puo'
 * collegare sia Google sia Apple allo stesso account — succede spesso: ci si
 * iscrive col telefono e si accede dal tablet dell'altro sistema operativo — e
 * con due colonne su `users` il terzo fornitore richiederebbe una migrazione.
 *
 * 🚨 **Nessun `tenant_id`, ed e' voluto.** L'identita' appartiene alla persona,
 * e la persona appartiene a una palestra: il tenant si legge da `users`. Se
 * stesse anche qui, esisterebbero due verita' sulla stessa cosa e prima o poi
 * si contraddirebbero — e quella sbagliata aprirebbe la porta di una palestra
 * a qualcuno di un'altra.
 *
 * ⚠️ **L'unico su (provider, provider_user_id) e' il vincolo di sicurezza.**
 * Senza, lo stesso account Google potrebbe essere collegato a due utenti e il
 * secondo accesso finirebbe su quello sbagliato — un'entrata nell'account di
 * un'altra persona, non un bug cosmetico. Il database e' il posto dove questa
 * regola non si puo' dimenticare.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('social_identities', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('provider', 16);

            // Il `sub` del token: stabile per sempre, a differenza dell'email.
            $table->string('provider_user_id', 191);

            /*
             * L'email **al momento del collegamento**, e serve solo a farsi
             * un'idea guardando la tabella.
             *
             * ⚠️ Non e' un identificativo: con «Nascondi la mia email» Apple ne
             * consegna una di inoltro (`@privaterelay.appleid.com`) che cambia
             * se l'utente revoca e riautorizza. Cercare per email qui sarebbe
             * un modo elegante per non ritrovare piu' nessuno.
             */
            $table->string('email', 255)->nullable();

            // Solo Apple, e **solo alla primissima autorizzazione**: dopo non
            // lo manda piu'. Se non lo si salva subito, e' perso.
            $table->string('name', 255)->nullable();

            $table->timestamp('last_login_at')->nullable();

            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);

            // Una persona, un account per fornitore: due account Google sullo
            // stesso utente non sono un caso d'uso, sono un errore.
            $table->unique(['user_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('social_identities');
    }
};
