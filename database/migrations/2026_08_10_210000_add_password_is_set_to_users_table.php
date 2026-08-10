<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «Questa persona ha una password che conosce?» — G8.
 *
 * 🚨 **Non si puo' dedurre dalla colonna `password`.** Chi entra con Google o
 * Apple ne riceve comunque una, casuale e lunga 32 caratteri
 * (`SocialAuthController::collegaOCrea()`): serve perche' l'account non sia
 * accessibile con una password vuota da nessun percorso, presente o futuro. Il
 * risultato e' che `password` e' valorizzata per tutti, e guardarla non dice
 * niente.
 *
 * Senza questa colonna l'app dovrebbe indovinare: mostrare «cambia password» a
 * chi e' entrato con Google gli chiederebbe **quella attuale**, che non ha mai
 * saputo — un modulo che non si puo' compilare e che sembra un guasto.
 *
 * `true` di serie perche' tutti gli account esistenti sono nati da una
 * registrazione con email: la password se la sono scelta loro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('password_is_set')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('password_is_set');
        });
    }
};
