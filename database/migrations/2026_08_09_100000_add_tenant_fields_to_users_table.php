<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Aggancia gli utenti alla palestra.
 *
 * `tenant_id` e' NULLABLE di proposito: il super admin della piattaforma non
 * appartiene a nessuna palestra. E' l'unico caso legittimo di NULL, e le policy
 * ci contano sopra (ADR-05).
 *
 * `onDelete('cascade')`: cancellare una palestra cancella i suoi utenti. Non e'
 * un problema perche' `tenants` usa softDeletes — una cancellazione vera e'
 * un'operazione deliberata di manutenzione, non qualcosa che capita per sbaglio
 * dal pannello.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('tenant_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->cascadeOnDelete();

            $table->string('phone', 32)->nullable()->after('email');
            $table->string('avatar_path')->nullable()->after('phone');
            $table->string('locale', 5)->default('it')->after('avatar_path');
            $table->boolean('is_active')->default(true)->after('locale');
            $table->timestamp('last_login_at')->nullable()->after('is_active');

            $table->softDeletes();

            // La coppia (palestra, stato) e' il filtro di ogni elenco del
            // pannello /admin: senza indice diventa una scansione completa
            // appena una palestra supera qualche centinaio di iscritti.
            $table->index(['tenant_id', 'is_active']);
        });

        // L'email e' unica per PALESTRA, non per piattaforma: la stessa persona
        // puo' essere iscritta a due palestre diverse con lo stesso indirizzo.
        // Va tolto il vincolo globale che Laravel mette di serie, altrimenti la
        // seconda iscrizione fallirebbe con un errore incomprensibile.
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique('users_email_unique');
            $table->unique(['tenant_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'email']);
            $table->dropIndex(['tenant_id', 'is_active']);
            $table->dropForeign(['tenant_id']);
            $table->dropColumn([
                'tenant_id', 'phone', 'avatar_path', 'locale',
                'is_active', 'last_login_at', 'deleted_at',
            ]);
            $table->unique('email');
        });
    }
};
