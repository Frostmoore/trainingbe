<?php

declare(strict_types=1);

use App\Models\ImportazionePiano;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Un'importazione puo' avere piu' documenti, e non solo PDF — Parte K, K1.
 *
 * ══ 📌 PERCHE' ═══════════════════════════════════════════════════════════
 *
 * 📌 Il committente, il 03/09/2026: *«L'import di pdf per le schede e per i
 * piani alimentari deve funzionare anche con le immagini»*.
 *
 * 🚨 **E piu' d'una alla volta**: una scheda su carta sono spesso due o tre
 * pagine fotografate. ⛔ Accettarne una sola vorrebbe dire che chi ne fotografa
 * una perde il resto **senza accorgersene** — la bozza esce con meta' scheda,
 * plausibile e incompleta.
 *
 * ══ ⚠️ PERCHE' UNA COLONNA JSON E NON TRE COLONNE IN PIU' ════════════════
 *
 * Perche' `token`, `nome_file` e `byte_totali` descrivevano **un** file, e con
 * cinque non descrivono piu' niente: ⛔ `nome_file` diventerebbe il nome del
 * primo, e chi legge crederebbe che sia tutto.
 *
 * 💡 `documenti` e' l'unica verita': una lista di `{token, nome, mime, byte}`,
 * nell'ordine in cui vanno letti — che per delle pagine fotografate **e'
 * l'informazione principale**.
 *
 * ⚠️ Le tre colonne vecchie **restano**, e diventano derivate: `nome_file` e'
 * cio' che si mostra («Piano.pdf», oppure «3 immagini»), `byte_totali` e' la
 * somma. 🚨 Si calcolano in `apri()`, in un punto solo: due sedi della stessa
 * somma divergono, e la copia e' sempre quella che sbaglia.
 *
 * ⛔ **`token` invece non serve piu'** e non si lascia: una colonna che non
 * significa piu' niente e' una colonna su cui un domani qualcuno scrive una
 * query. Si porta dentro `documenti` e sparisce.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('importazioni_piani', function (Blueprint $table): void {
            /*
             * 🚨 **La lista dei documenti, nell'ordine di lettura.**
             *
             * `[{"token": "...", "nome": "pagina1.jpg", "mime": "image/jpeg",
             *   "byte": 812345}, …]`
             *
             * ⚠️ Nullable perche' le righe che esistono adesso non ce l'hanno:
             * la riempie il passo qui sotto, e da li' in poi non e' mai vuota.
             */
            $table->json('documenti')->nullable()->after('token');

            /*
             * 💡 `pdf` oppure `immagini`. Non si deduce dal `mime` del primo:
             * serve a dire **all'app** quale avvertenza mostrare, ed e' una
             * proprieta' dell'importazione, non del singolo file.
             */
            $table->string('tipo', 16)->default('pdf')->after('documenti');
        });

        /*
         * ── 🚨 Le righe che esistono si portano dietro il loro file ────────
         *
         * ⛔ Senza questo passo, un'importazione gia' aperta perderebbe il
         * riferimento al PDF: il job leggerebbe una lista vuota e fallirebbe
         * **senza dire perche'**.
         *
         * 💡 Sono al massimo sette giorni di righe (`DURATA_GIORNI`), quindi
         * pochissime — ma «poche» non e' «nessuna».
         */
        DB::table('importazioni_piani')->orderBy('id')->chunkById(100, function ($righe): void {
            foreach ($righe as $riga) {
                DB::table('importazioni_piani')
                    ->where('id', $riga->id)
                    ->update([
                        'documenti' => json_encode([[
                            'token' => $riga->token,
                            'nome' => $riga->nome_file,
                            'mime' => 'application/pdf',
                            'byte' => (int) $riga->byte_totali,
                        ]]),
                        'tipo' => ImportazionePiano::TIPO_PDF,
                    ]);
            }
        });

        Schema::table('importazioni_piani', function (Blueprint $table): void {
            $table->dropColumn('token');
        });
    }

    public function down(): void
    {
        Schema::table('importazioni_piani', function (Blueprint $table): void {
            $table->string('token', 64)->nullable()->after('tenant_id');
        });

        /*
         * ⚠️ **Si recupera solo il primo documento**, ed e' quello che il
         * rollback puo' fare: la colonna vecchia ne teneva uno. 🚨 Chi torna
         * indietro con un'importazione da tre fotografie ne ritrova una — ed e'
         * meglio di una riga che punta al nulla.
         */
        DB::table('importazioni_piani')->orderBy('id')->chunkById(100, function ($righe): void {
            foreach ($righe as $riga) {
                $documenti = json_decode((string) $riga->documenti, true);

                DB::table('importazioni_piani')
                    ->where('id', $riga->id)
                    ->update(['token' => $documenti[0]['token'] ?? null]);
            }
        });

        Schema::table('importazioni_piani', function (Blueprint $table): void {
            $table->dropColumn(['documenti', 'tipo']);
        });
    }
};
