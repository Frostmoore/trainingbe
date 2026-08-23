<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\FoodEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Services\Tenancy\EsciDaUnaPalestra;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Uscire da una palestra — 3b-P.13.3, 23/08/2026.
 *
 * ══ 🚨 COSA VA DIMOSTRATO, E PERCHÉ NON BASTA «FUNZIONA» ══════════════════
 *
 * Questa è una **migrazione di dati fra tenant**, cioè la categoria in cui un
 * errore non si vede: le righe restano, i conteggi tornano, e il difetto è che
 * un dato è finito nel posto sbagliato — dove lo legge chi non deve.
 *
 * ⛔ I due modi di sbagliarla sono **opposti**, e servono test per entrambi:
 *
 * 1. **portarsi via troppo**: le schede del trainer, i messaggi, la libreria
 *    esercizi — roba della palestra che finisce in un tenant personale;
 * 2. **lasciarsi indietro qualcosa**: il diario, il profilo — e la persona esce
 *    da una porta e trova la casa vuota.
 */
class EsciDallaPalestraTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $trainer;

    private User $mario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra();
        $this->trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'coach@demo.test');
        $this->mario = $this->creaUtente($this->palestra, UserRole::Member, 'mario@demo.test');
    }

    private function esci(?User $chi = null): Tenant
    {
        return app(EsciDaUnaPalestra::class)($chi ?? $this->mario);
    }

    // ───────────────────────── quello che se ne va ─────────────────────────

    #[Test]
    public function il_diario_segue_la_persona(): void
    {
        $voce = $this->ctx()->runAs($this->palestra, fn (): FoodEntry => FoodEntry::create([
            'user_id' => $this->mario->getKey(),
            'eaten_at' => now(),
            'meal' => 'lunch',
            'description' => 'Riso e pollo',
            'grams' => 300,
            'kcal' => 500,
        ]));

        $personale = $this->esci();

        $this->assertSame(
            $personale->getKey(),
            FoodEntry::withoutGlobalScopes()->find($voce->getKey())?->tenant_id,
            'senza il diario la persona esce e trova la casa vuota',
        );
    }

    #[Test]
    public function la_scheda_che_si_e_scritta_da_sola_se_ne_va_con_lei(): void
    {
        $sua = $this->ctx()->runAs($this->palestra, fn (): WorkoutPlan => WorkoutPlan::create([
            'tenant_id' => $this->palestra->getKey(),
            'member_id' => $this->mario->getKey(),
            'created_by' => $this->mario->getKey(),
            'name' => 'La mia scheda',
        ]));

        $personale = $this->esci();

        $this->assertSame(
            $personale->getKey(),
            WorkoutPlan::withoutGlobalScopes()->find($sua->getKey())?->tenant_id,
        );
    }

    // ───────────────────────── quello che resta ─────────────────────────

    #[Test]
    public function la_scheda_del_trainer_resta_al_trainer(): void
    {
        /*
         * 📌 **La decisione del committente**, 23/08/2026: *«Le schede del
         * trainer restano al trainer»*.
         *
         * 🚨 `member_id` da solo la porterebbe via: è assegnata a Mario. Serve
         * **anche** `created_by`, ed è l'unica differenza fra questo test e il
         * precedente.
         */
        $prescritta = $this->ctx()->runAs($this->palestra, fn (): WorkoutPlan => WorkoutPlan::create([
            'tenant_id' => $this->palestra->getKey(),
            'member_id' => $this->mario->getKey(),
            'created_by' => $this->trainer->getKey(),
            'name' => 'Riabilitazione spalla',
        ]));

        $this->esci();

        $this->assertSame(
            $this->palestra->getKey(),
            WorkoutPlan::withoutGlobalScopes()->find($prescritta->getKey())?->tenant_id,
            'è lavoro del trainer, e resta suo',
        );
    }

    #[Test]
    public function il_legame_con_la_palestra_si_scioglie(): void
    {
        // ⚠️ Il pivot vuole il `tenant_id`: e' una tabella isolata come le
        // altre, e `attach()` da solo non lo mette.
        DB::table('trainer_member')->insert([
            'tenant_id' => $this->palestra->getKey(),
            'trainer_id' => $this->trainer->getKey(),
            'member_id' => $this->mario->getKey(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->esci();

        $this->assertDatabaseMissing('trainer_member', [
            'tenant_id' => $this->palestra->getKey(),
            'member_id' => $this->mario->getKey(),
        ]);
    }

    // ───────────────────────── chi non può ─────────────────────────

    #[Test]
    public function un_trainer_non_esce_da_solo(): void
    {
        /*
         * ⛔ Se ne andasse portandosi il tenant, la palestra resterebbe con
         * schede firmate da un utente che non c'è più e i suoi iscritti senza
         * trainer. 🚨 È una faccenda commerciale, e la fa la palestra.
         */
        $this->comeApp($this->trainer)
            ->postJson('/api/v1/account/leave-gym')
            ->assertStatus(422);
    }

    #[Test]
    public function chi_non_ha_una_palestra_non_puo_uscirne(): void
    {
        $this->esci();

        // 💡 La seconda chiamata rifiuta da sola: è il motivo per cui la rotta
        // non ha bisogno di un limite di frequenza.
        $this->comeApp($this->mario->fresh())
            ->postJson('/api/v1/account/leave-gym')
            ->assertStatus(422);
    }

    // ───────────────────────── la rete di sicurezza ─────────────────────────

    #[Test]
    public function ogni_tabella_con_tenant_id_ha_una_regola(): void
    {
        /*
         * ══ 🚨 IL TEST CHE CONTA DAVVERO ═══════════════════════════════════
         *
         * ⚠️ Gli altri verificano le regole di **oggi**. Questo verifica che
         * domani nessuno aggiunga una tabella senza decidere da che parte va.
         *
         * ⛔ È la lezione di `health_readings`, rimasta invisibile per settimane
         * perché era nata **dopo** l'eraser: una classificazione che non si
         * accorge delle tabelle nuove protegge solo quelle vecchie.
         */
        $ignote = array_diff(
            EsciDaUnaPalestra::tabelleConTenant(),
            EsciDaUnaPalestra::SEGUONO_LA_PERSONA,
            EsciDaUnaPalestra::RESTANO_ALLA_PALESTRA,
            EsciDaUnaPalestra::REGOLE_PROPRIE,
        );

        $this->assertSame(
            [],
            array_values($ignote),
            "Tabelle senza una regola per l'uscita da una palestra.\n\n"
                ."Delle tre l'una:\n"
                ." 1. i dati sono della persona → SEGUONO_LA_PERSONA;\n"
                ." 2. sono della palestra → RESTANO_ALLA_PALESTRA, col motivo;\n"
                ." 3. servono una regola loro → REGOLE_PROPRIE, e il codice.\n\n"
                ."Non lasciarla così: una tabella non classificata è un dato che\n"
                ."finisce nel tenant sbagliato senza che nessuno se ne accorga.",
        );
    }

    #[Test]
    public function e_nessuna_tabella_e_classificata_due_volte(): void
    {
        // ⚠️ Un nome in due elenchi vuol dire due regole in conflitto, e vince
        // quella che il codice esegue per prima — cioè il caso.
        $tutte = array_merge(
            EsciDaUnaPalestra::SEGUONO_LA_PERSONA,
            EsciDaUnaPalestra::RESTANO_ALLA_PALESTRA,
            EsciDaUnaPalestra::REGOLE_PROPRIE,
        );

        $this->assertSame(
            array_values(array_unique($tutte)),
            $tutte,
            'una tabella compare in più di un elenco',
        );
    }
}
