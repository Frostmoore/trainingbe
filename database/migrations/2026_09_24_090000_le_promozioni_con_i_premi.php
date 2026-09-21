<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🎁 **Le promozioni con i premi** — 24/09/2026, fase P1 di `plan_promozioni.md`.
 *
 * 📌 *«su promozioni->nuovo ci deve essere una scelta: a) Gettoni b) Abbonamenti
 * c) entrambe. Dopo di che, devo avere un form diverso per ciascuna scelta.
 * Devo avere ogni possibilità immaginabile qui»*.
 *
 * ══ 🚨 PERCHÉ I PREMI IN UNA TABELLA LORO ════════════════════════════════
 *
 * «Abbonamento scontato **e** gettoni in regalo» è una promozione sola (opzione
 * 17 del piano). ⛔ Con `tipo_sconto` + `valore` sulla riga se ne esprime uno —
 * lo stesso errore che F2 aveva evitato per i bersagli. Ogni premio è una riga.
 *
 * ══ 🚨 PERCHÉ LE ASSEGNAZIONI ════════════════════════════════════════════
 *
 * Il massimo per persona, il budget, i numeri e la revoca si contano da qui;
 * e l'indice unico `(promozione_id, riferimento)` rende il webhook idempotente
 * — Stripe rimanda gli eventi, e uno sconto contato due volte esaurisce
 * un'offerta a metà.
 *
 * ══ ⚠️ LA MIGRAZIONE DEI DATI ════════════════════════════════════════════
 *
 * Si fa con `DB::table()` e non coi modelli: i modelli cambiano, una migration
 * no. Ogni vecchia riga diventa un premio di sconto per ciascuna voce di
 * `su_cosa`, con lo stesso tipo e lo stesso valore. Il vecchio coupon Stripe
 * era `duration: once`: il premio sull'abbonamento nasce `una_volta`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('promozioni', function (Blueprint $tabella): void {
            /** `gettoni` | `abbonamenti` | `entrambi`: decide quali premi si possono dare. */
            $tabella->string('genere', 20)->default('abbonamenti')->after('descrizione');

            /** Quante volte la stessa persona. `null` = senza limite. */
            $tabella->unsignedInteger('massimo_per_persona')->nullable()->after('massimo_usi');

            /** Tetti: centesimi scontati, gettoni regalati. `null` = nessun tetto. */
            $tabella->unsignedInteger('budget_cent')->nullable()->after('massimo_per_persona');
            $tabella->unsignedInteger('budget_gettoni')->nullable()->after('budget_cent');

            /** Fra le non cumulabili vince la priorità più alta; a parità, la più conveniente. */
            $tabella->smallInteger('priorita')->default(0)->after('cumulabile');

            /** Con `attivazione = evento` (fase P6). */
            $tabella->string('evento', 40)->nullable()->after('attivazione');
            $tabella->json('evento_parametri')->nullable()->after('evento');

            /** Fase P8: come si avvisa (`app`, `email`) e dove si vede (`app`, `pannello`, `sito`). */
            $tabella->json('avviso')->nullable();
            $tabella->json('visibile_in')->nullable();

            /** L'ultima «Esegui» di massa (fase P2). */
            $tabella->timestamp('eseguita_il')->nullable();
        });

        Schema::create('promozione_premi', function (Blueprint $tabella): void {
            $tabella->id();
            $tabella->foreignId('promozione_id')->constrained('promozioni')->cascadeOnDelete();

            /** `App\Enums\TipoDiPremio`. */
            $tabella->string('tipo', 30);

            /** `destinatario` | `chi_ha_invitato` (porta un amico, fase P6). */
            $tabella->string('a_chi', 20)->default('destinatario');

            $tabella->unsignedInteger('gettoni')->nullable();

            /**
             * 🚨 `percentuale` o `importo`, come in F2: 20 è un quinto o venti
             * centesimi, e non si deduce dal valore.
             */
            $tabella->string('tipo_sconto', 20)->nullable();

            /** Percentuale 1-100, oppure **centesimi**. Per il bonus in gettoni: la percentuale in più. */
            $tabella->unsignedInteger('valore')->nullable();

            /** Il prezzo speciale di un pacchetto, in centesimi. */
            $tabella->unsignedInteger('prezzo_cent')->nullable();

            $tabella->foreignId('pacchetto_id')->nullable()->constrained('pacchetti_gettoni')->nullOnDelete();
            $tabella->foreignId('plan_id')->nullable()->constrained('plans')->nullOnDelete();
            $tabella->foreignId('da_plan_id')->nullable()->constrained('plans')->nullOnDelete();

            /** Per lo sconto sull'abbonamento: `una_volta` | `n_volte` | `per_sempre`. */
            $tabella->string('durata', 20)->nullable();

            /** N rinnovi, N mesi, N periodi. */
            $tabella->unsignedSmallInteger('volte')->nullable();

            /** Giorni di prova, o dopo quanti giorni scadono i gettoni regalati. */
            $tabella->unsignedSmallInteger('giorni')->nullable();

            $tabella->unsignedSmallInteger('posti')->nullable();

            /** Per `sblocca_sconto`: su cosa vale lo sconto sbloccato. */
            $tabella->string('sblocca_genere', 20)->nullable();

            $tabella->timestamps();

            $tabella->index(['promozione_id', 'tipo']);
        });

        Schema::create('promozione_assegnazioni', function (Blueprint $tabella): void {
            $tabella->id();
            $tabella->foreignId('promozione_id')->constrained('promozioni')->cascadeOnDelete();
            $tabella->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $tabella->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();

            /** `assegnata` | `usata` | `revocata` | `scaduta`. */
            $tabella->string('stato', 20)->default('assegnata');

            /** `automatica` | `codice` | `link` | `massa` | `evento` | `amico`. */
            $tabella->string('origine', 20);

            /**
             * 🚨 L'id della sessione Stripe, o il giro di massa: **unico con la
             * promozione**, ed è ciò che rende il webhook idempotente.
             */
            $tabella->string('riferimento', 120)->nullable();

            $tabella->unsignedInteger('gettoni')->default(0);
            $tabella->unsignedInteger('scontato_cent')->default(0);

            $tabella->foreignId('plan_subscription_id')->nullable()->constrained('plan_subscriptions')->nullOnDelete();

            $tabella->timestamp('scade_il')->nullable();
            $tabella->timestamp('prossimo_accredito_il')->nullable();
            $tabella->timestamp('usata_il')->nullable();
            $tabella->timestamp('revocata_il')->nullable();
            $tabella->foreignId('revocata_da_id')->nullable()->constrained('users')->nullOnDelete();

            $tabella->timestamps();

            $tabella->unique(['promozione_id', 'riferimento']);
            $tabella->index(['user_id', 'promozione_id']);
            $tabella->index(['stato', 'prossimo_accredito_il']);
        });

        // ── i vecchi sconti diventano premi ──
        $adesso = now();

        foreach (DB::table('promozioni')->get() as $vecchia) {
            $suCosa = json_decode((string) $vecchia->su_cosa, true) ?: [];

            $tipi = [
                'gettoni' => 'sconto_gettoni',
                'canone' => 'sconto_abbonamento',
                'consumo' => 'sconto_consumo',
            ];

            foreach ($tipi as $voce => $tipo) {
                if (! in_array($voce, $suCosa, true)) {
                    continue;
                }

                DB::table('promozione_premi')->insert([
                    'promozione_id' => $vecchia->id,
                    'tipo' => $tipo,
                    'a_chi' => 'destinatario',
                    'tipo_sconto' => $vecchia->tipo_sconto,
                    'valore' => $vecchia->valore,
                    'durata' => $tipo === 'sconto_abbonamento' ? 'una_volta' : null,
                    'created_at' => $adesso,
                    'updated_at' => $adesso,
                ]);
            }

            $conGettoni = in_array('gettoni', $suCosa, true);
            $conAltro = array_diff($suCosa, ['gettoni']) !== [];

            DB::table('promozioni')->where('id', $vecchia->id)->update([
                'genere' => match (true) {
                    $conGettoni && $conAltro => 'entrambi',
                    $conGettoni => 'gettoni',
                    default => 'abbonamenti',
                },
            ]);
        }

        Schema::table('promozioni', function (Blueprint $tabella): void {
            $tabella->dropColumn(['tipo_sconto', 'valore', 'su_cosa']);
        });
    }

    /**
     * ⚠️ **Perdita dichiarata**: le vecchie colonne tengono un premio solo, e
     * ci torna il **primo** premio di sconto. Una promozione con più premi, o
     * con soli regali, torna indietro a metà.
     */
    public function down(): void
    {
        Schema::table('promozioni', function (Blueprint $tabella): void {
            $tabella->string('tipo_sconto', 20)->default('percentuale');
            $tabella->unsignedInteger('valore')->default(0);
            $tabella->json('su_cosa')->nullable();
        });

        $voci = [
            'sconto_gettoni' => 'gettoni',
            'sconto_abbonamento' => 'canone',
            'sconto_consumo' => 'consumo',
        ];

        foreach (DB::table('promozioni')->get() as $promozione) {
            $premi = DB::table('promozione_premi')
                ->where('promozione_id', $promozione->id)
                ->whereIn('tipo', array_keys($voci))
                ->orderBy('id')
                ->get();

            $primo = $premi->first();

            DB::table('promozioni')->where('id', $promozione->id)->update([
                'tipo_sconto' => $primo->tipo_sconto ?? 'percentuale',
                'valore' => $primo->valore ?? 0,
                'su_cosa' => json_encode($premi->map(fn ($p) => $voci[$p->tipo])->unique()->values()->all()),
            ]);
        }

        Schema::dropIfExists('promozione_assegnazioni');
        Schema::dropIfExists('promozione_premi');

        Schema::table('promozioni', function (Blueprint $tabella): void {
            $tabella->dropColumn([
                'genere', 'massimo_per_persona', 'budget_cent', 'budget_gettoni', 'priorita',
                'evento', 'evento_parametri', 'avviso', 'visibile_in', 'eseguita_il',
            ]);
        });
    }
};
