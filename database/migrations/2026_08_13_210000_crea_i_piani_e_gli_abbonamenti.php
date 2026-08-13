<?php

declare(strict_types=1);

use App\Enums\PlanKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `plans` e `plan_subscriptions` — F4.1 della Parte B, 13/08/2026.
 *
 * ── 🚨 `ai_enabled` è una colonna e non un numero, e il perché non è ovvio ──
 *
 * `MemberAiQuota::capFor()` usa da sempre due convenzioni:
 *
 * | Valore | Significato |
 * |---|---|
 * | `null` | «non impostato, scendi al livello successivo» |
 * | `0` | **illimitato** |
 *
 * Quindi **non esiste un numero che voglia dire «niente AI»**. Mettere `0` a un
 * utente gratuito gli darebbe l'AI **senza limiti** — l'esatto contrario del
 * requisito B2. E invertire la convenzione (`0` = niente) cambierebbe il
 * significato di **dati già scritti**, che è la classe di errore che non si vede
 * finché non fattura.
 *
 * ✅ Da qui la decisione D2: un **flag** separato, controllato **prima** della
 * quota e non dentro di essa.
 *
 * ── Perché due tabelle e non una colonna su `tenants` ──────────────────────
 *
 * `tenants.plan` esiste già ed è una stringa libera con default `'starter'`:
 * non ha prezzi, non ha date, non dice cosa comprende. Serve la storia — quando
 * un abbonamento è cominciato, quando scade, cos'era prima — perché la domanda
 * «perché a marzo questo cliente aveva l'AI e ad aprile no» deve avere una
 * risposta.
 *
 * ⚠️ `tenants.plan` **NON viene toccata**: resta l'etichetta commerciale
 * mostrata nel pannello. Riusarla come chiave esterna verso `plans` vorrebbe
 * dire migrare stringhe libere scritte a mano, e una di esse sbagliata
 * lascerebbe un cliente senza piano.
 *
 * ── 💡 Perché l'abbonamento punta a un tenant e non a un utente ────────────
 *
 * Perché **ogni** utente ne ha uno, di tenant: gli iscritti a una palestra hanno
 * quello della palestra, e chi si è iscritto da solo ha il suo personale (F1).
 * Un abbonamento per utente moltiplicherebbe le righe per gli iscritti di una
 * palestra, che invece sono coperti dall'abbonamento **di quella palestra** — ed
 * è esattamente il modello commerciale deciso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plans', function (Blueprint $table): void {
            $table->id();

            // Il codice stabile, quello che si scrive nel codice sorgente e nei
            // test: `free`, `plus`, `trainer_free`… ⚠️ Il *nome* cambia con il
            // marketing, il codice no.
            $table->string('code', 40)->unique();
            $table->string('name', 80);
            $table->string('kind', 16)->default(PlanKind::Person->value);

            /*
             * 🚨 **La colonna che rende sostenibile il piano gratuito.**
             *
             * `STIMA-COSTI-AI.md` misura ≈ 1,15 $/mese per utente attivo che usa
             * l'AI. Un gratuito **con** AI costerebbe più di quanto rende, per
             * sempre. Questo `false` è ciò che tiene in piedi il modello.
             */
            $table->boolean('ai_enabled')->default(false);

            /*
             * Il tetto di token che questo piano concede a ciascuno.
             *
             * ⚠️ Stesse convenzioni degli altri livelli, di proposito: `null` =
             * «non lo decide il piano, scendi al default di sistema», `0` =
             * illimitato. Cambiarle **qui soltanto** vorrebbe dire avere due
             * significati per lo stesso valore nella stessa catena.
             */
            $table->unsignedBigInteger('ai_monthly_tokens_per_member')->nullable();

            // Quanti utenti può seguire un trainer indipendente. `null` = senza
            // limite. Serve a F6.5.
            $table->unsignedInteger('max_members')->nullable();

            // In centesimi, e non in euro con la virgola: i decimali in virgola
            // mobile sui soldi sono il modo classico per perdere un centesimo a
            // ogni somma.
            $table->unsignedInteger('price_cents')->default(0);

            $table->boolean('is_public')->default(true);
            $table->timestamps();

            $table->index(['kind', 'is_public']);
        });

        Schema::create('plan_subscriptions', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();

            $table->timestamp('starts_at')->useCurrent();

            /*
             * 🚨 `null` = **non scade**, e non «scaduto».
             *
             * È la distinzione da cui dipende tutto: `PianoAttivo` cerca
             * `ends_at IS NULL OR ends_at > now()`. Leggerlo al contrario
             * spegnerebbe l'AI a ogni cliente che non ha una data di fine —
             * cioè a tutti quelli che pagano regolarmente.
             */
            $table->timestamp('ends_at')->nullable();

            $table->timestamps();

            // ⚠️ Si cerca sempre «l'abbonamento attivo di questo tenant»: senza
            // questo indice diventa una scansione a ogni chiamata AI.
            $table->index(['tenant_id', 'ends_at']);
        });

        $this->portaDietroCioCheGiaEsiste();
    }

    /**
     * 🚨 **Senza questo blocco, F4 spegne l'AI a chi la sta pagando.**
     *
     * Il cancello `RequirePlanWithAi` nega l'AI a chi non ha un abbonamento con
     * `ai_enabled`. Al momento di questa migrazione **nessun tenant ne ha uno**,
     * perché la tabella nasce adesso: senza un backfill, il primo deploy
     * risponderebbe `403 plan_without_ai` a ogni palestra cliente.
     *
     * ⚠️ E sarebbe il tipo di guasto peggiore — non un errore, ma una funzione
     * che smette di esserci per tutti nello stesso istante, con il server che
     * risponde correttamente `403` a ogni chiamata.
     *
     * 💡 Il criterio è quello che conserva lo stato di fatto:
     *
     * | Chi | Piano | Perché |
     * |---|---|---|
     * | `kind = gym` | `gym` | Sono clienti paganti: **avevano** l'AI e la mantengono |
     * | `kind = personal` | `free` | Al 13/08/2026 non ne esiste nessuno, ma la regola dev'essere scritta prima che ne nasca uno |
     *
     * ⚠️ Il listino si scrive **qui dentro e non chiamando `PlanSeeder`**: un
     * seeder cambia nel tempo, e una migrazione che lo invoca cambierebbe
     * comportamento a posteriori — cioè ricostruire il database da zero
     * darebbe un risultato diverso da quello che è successo davvero.
     * `PlanSeeder` usa `updateOrCreate` sugli stessi codici, quindi i due
     * convergono senza pestarsi.
     */
    private function portaDietroCioCheGiaEsiste(): void
    {
        $adesso = now();

        $listino = [
            ['code' => 'free', 'name' => 'Gratuito', 'kind' => 'person', 'ai_enabled' => false, 'price_cents' => 0],
            ['code' => 'plus', 'name' => 'Plus', 'kind' => 'person', 'ai_enabled' => true, 'price_cents' => 499],
            ['code' => 'trainer_free', 'name' => 'Trainer — gratuito', 'kind' => 'trainer', 'ai_enabled' => false, 'price_cents' => 0],
            ['code' => 'trainer_pro', 'name' => 'Trainer — pro', 'kind' => 'trainer', 'ai_enabled' => true, 'price_cents' => 1999],
            ['code' => 'gym', 'name' => 'Palestra', 'kind' => 'gym', 'ai_enabled' => true, 'price_cents' => 4999],
        ];

        foreach ($listino as $piano) {
            DB::table('plans')->insert($piano + ['created_at' => $adesso, 'updated_at' => $adesso]);
        }

        $idPerCodice = DB::table('plans')->pluck('id', 'code');

        foreach (DB::table('tenants')->select('id', 'kind')->get() as $tenant) {
            DB::table('plan_subscriptions')->insert([
                'tenant_id' => $tenant->id,
                'plan_id' => $tenant->kind === 'personal' ? $idPerCodice['free'] : $idPerCodice['gym'],
                'starts_at' => $adesso,

                // 🚨 `null` = non scade. Le palestre che c'erano prima di questa
                // migrazione non devono ritrovarsi una scadenza che nessuno ha
                // mai concordato con loro.
                'ends_at' => null,
                'created_at' => $adesso,
                'updated_at' => $adesso,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('plan_subscriptions');
        Schema::dropIfExists('plans');
    }
};
