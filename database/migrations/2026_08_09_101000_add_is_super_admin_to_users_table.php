<?php

declare(strict_types=1);

use App\Enums\UserRole;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Il super admin è una PROPRIETÀ DELL'ACCOUNT, non un ruolo dentro una palestra.
 *
 * Perché non un ruolo spatie come gli altri tre:
 *
 * 1. **Non si può.** In modalità teams, `model_has_roles.tenant_id` è NOT NULL
 *    ed è parte della chiave primaria: un'assegnazione senza palestra fallisce
 *    con «Column 'tenant_id' cannot be null», e non è aggiustabile perché in
 *    MySQL una colonna in chiave primaria non può essere nullable.
 *
 * 2. **Non si deve.** Anche riuscendoci, i ruoli sono limitati alla palestra
 *    corrente: `isSuperAdmin()` tornerebbe `false` appena il super admin entra
 *    in una palestra — cioè proprio quando gli serve, per esempio durante
 *    un'impersonazione (B2.3). Un permesso che sparisce dove serve è peggio di
 *    un permesso assente.
 *
 * Gli altri tre ruoli restano ruoli spatie, perché sono davvero per-palestra:
 * lo stesso utente può essere trainer in una e iscritto in un'altra.
 *
 * @see UserRole::isPlatformLevel()
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_super_admin')->default(false)->after('is_active');

            // Parziale nella pratica: i super admin sono pochissimi, quindi
            // l'indice serve a trovarli in fretta nel pannello /god senza
            // scandire tutti gli utenti della piattaforma.
            $table->index('is_super_admin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_super_admin']);
            $table->dropColumn('is_super_admin');
        });
    }
};
