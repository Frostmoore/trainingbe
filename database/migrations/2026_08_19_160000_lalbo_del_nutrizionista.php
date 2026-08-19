<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'iscrizione all'albo, autocertificata — N22.2.
 *
 * ── 🚨 Perche' si raccoglie ────────────────────────────────────────────────
 *
 * *«raccogliamo la dichiarazione e il numero di iscrizione all'albo e basta, un
 * controllo diretto mi pare eccessivo, ci e' sufficiente l'autocertificazione»*
 * — il committente, 19/08/2026.
 *
 * ⚠️ **Un ruolo che si autodichiara non sposta nessuna responsabilita'.** Se
 * chiunque potesse spuntare «sono nutrizionista», avremmo ricreato il problema
 * di §4.11 con un passaggio in piu' — e con l'aggravante di averlo scritto nel
 * prodotto. La dichiarazione firmata sposta la responsabilita' su chi la firma.
 *
 * ⚠️ **Ma non e' una verifica**, e va saputo: gli albi hanno ricerche
 * pubbliche, e il giorno che servisse davvero la si puo' fare. Questa riga sta
 * qui per ricordare che **non e' stata fatta**.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * 💡 Quale albo: `onb` (biologi), `tsrm_pstrp` (dietisti),
             * `omceo` (medici). Stringa e non enum perche' gli albi cambiano
             * nome piu' spesso di quanto cambi questo codice.
             *
             * ⚠️ **Niente `after()`, e non e' pigrizia**: la prima stesura
             * diceva `after('role')`, ma `role` NON e' una colonna di `users` —
             * i ruoli vivono nelle tabelle di spatie/permission. La migrazione
             * falliva con «Unknown column 'role'», e sul server condiviso
             * sarebbe morta **a meta'**. L'ordine delle colonne non serve a
             * nessuno; ancorarle a una che non esiste serve a rompersi.
             */
            $table->string('albo', 32)->nullable();
            $table->string('albo_numero', 64)->nullable();

            /*
             * 🚨 **Quando l'ha dichiarato**, e non «se».
             *
             * ⚠️ Un booleano `albo_dichiarato` non direbbe **quando**, ed e'
             * l'unica cosa che conta davvero in una dichiarazione: se un giorno
             * qualcuno contestasse, la domanda sara' «cosa aveva dichiarato, e
             * a che data».
             */
            $table->timestamp('albo_dichiarato_il')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['albo', 'albo_numero', 'albo_dichiarato_il']);
        });
    }
};
