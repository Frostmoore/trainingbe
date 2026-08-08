<?php

declare(strict_types=1);

use App\Enums\TenantStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La palestra. È il tenant: tutto il resto del dominio le appartiene tramite
 * `tenant_id` (vedi ADR-01 in memory/plan_trainingbe.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();

            $table->string('name', 120);
            $table->string('slug', 60)->unique();

            // il codice che l'iscritto digita nell'app per trovare la sua palestra
            $table->string('join_code', 12)->unique();

            $table->string('status', 20)->default(TenantStatus::Trial->value);
            $table->string('plan', 20)->default('starter');

            // branding white-label: è il payload dell'endpoint pubblico /branding/lookup
            $table->string('logo_path')->nullable();
            $table->string('color_primary', 7)->default('#111827');
            $table->string('color_secondary', 7)->default('#6B7280');
            $table->string('color_accent', 7)->default('#F59E0B');

            $table->string('contact_email')->nullable();
            $table->string('locale', 5)->default('it');
            $table->string('timezone', 64)->default('Europe/Rome');

            // AI: null = nessun tetto; ai_driver sovrascrive config('ai.default')
            $table->unsignedBigInteger('ai_monthly_token_cap')->nullable();
            $table->string('ai_driver', 20)->nullable();

            // ripostiglio tipizzato: NIENTE che debba finire in una WHERE
            $table->json('settings')->nullable();

            $table->timestamp('trial_ends_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
