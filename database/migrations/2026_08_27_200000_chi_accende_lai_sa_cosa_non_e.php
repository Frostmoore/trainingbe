<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ⚖️ Chi accende l'AI dichiara di sapere cosa NON e' — 3b-J.3, 27/08/2026.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * 📌 Il committente: *«tra i consensi mettiamo anche un consenso obbligatorio se
 * vuoi attivare l'ai che dice che "mi rendo conto che tutto cio' che e' prodotto
 * dall'ai non e' mai un consiglio medico, ma solo una stima stocastica generata
 * da un modello di intelligenza artificiale e che non devo farci alcun tipo di
 * affidamento perche' ne va della mia vita e della mia salute"»*.
 *
 * ══ 🚨 PERCHE' UNA COLONNA, E NON UNA SPUNTA NELL'APP ═════════════════════
 *
 * Perche' l'art. 7(1) chiede di poter **dimostrare** che e' stato dato. ⛔ Una
 * casella spuntata in un widget non lascia traccia da nessuna parte: il giorno
 * che qualcuno sostiene di non aver mai letto niente, non c'e' niente da
 * mostrare.
 *
 * 💡 E' una **data** come gli altri consensi, per la stessa ragione scritta in
 * `Consensi`: serve a dire *«accettato il 27 agosto»*, non a dire *«si'»*.
 *
 * ══ ⚠️ E' UNA PRESA D'ATTO, NON UN PERMESSO ═══════════════════════════════
 *
 * ⛔ Non e' una base giuridica per trattare dati — quella resta `ai_consent_at`.
 * Questa dice **«ho capito cosa sto usando»**, ed e' l'unica difesa che abbiamo
 * contro la cosa che ci fa davvero paura: qualcuno che prende una frase
 * generata da un modello per un parere sanitario.
 *
 * 🚨 Per questo il server **rifiuta** di accendere l'AI senza: se la protezione
 * vivesse solo nell'interfaccia, basterebbe una chiamata all'API per saltarla —
 * e sarebbe esattamente il percorso di chi poi si fa male.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * ⚠️ **Accanto agli altri consensi**, non in una tabella a parte: si
             * leggono sempre insieme, si revocano insieme, e chi guarda la
             * riga di un utente deve vederli tutti e quattro senza una join.
             */
            $table->timestamp('ai_disclaimer_at')
                ->nullable()
                ->after('ai_consent_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ai_disclaimer_at');
        });
    }
};
