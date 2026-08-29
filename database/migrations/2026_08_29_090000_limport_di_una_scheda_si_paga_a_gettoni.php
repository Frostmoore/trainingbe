<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'import di una scheda da PDF ricorda **come e' stato pagato** — U.6.
 *
 * ── 🚨 Perche' serve una colonna e non basta `true` ────────────────────────
 *
 * Fra il cancello e il consumo c'e' una **coda**: il cancello si apre quando la
 * palestra carica il PDF, i gettoni si scalano minuti dopo, nel job, e solo se
 * la lettura e' riuscita. La decisione deve viaggiare, e l'unica strada fra una
 * richiesta HTTP e un job e' il database.
 *
 * ⚠️ **Scrivere `true` nel job** sarebbe la seconda sede della stessa regola
 * commerciale — quella che `CancelloDeiGettoni` esiste apposta per non avere. E
 * il giorno che il prezzo cambia, quella copia resta indietro **in silenzio**.
 *
 * 💡 E' la stessa colonna, con lo stesso nome e lo stesso default, che
 * `importazioni_piani` e `stime_cibo` hanno gia': tre code, una forma sola.
 *
 * ── ⚠️ `default(false)` e le righe vecchie ─────────────────────────────────
 *
 * Gli import gia' in tabella sono stati fatti **quando non si pagavano**, ed e'
 * giusto che restino a `false`: riscriverli a `true` vorrebbe dire far pagare a
 * posteriori qualcosa che era gratis quando e' stato chiesto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workout_plan_imports', function (Blueprint $tabella): void {
            $tabella->boolean('paga_con_gettoni')->default(false)->after('escalated');
        });
    }

    public function down(): void
    {
        Schema::table('workout_plan_imports', function (Blueprint $tabella): void {
            $tabella->dropColumn('paga_con_gettoni');
        });
    }
};
