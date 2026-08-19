<?php

declare(strict_types=1);

namespace Tests\Feature\Nutrition;

use App\Enums\TipoPianoAlimentare;
use App\Enums\UserRole;
use App\Models\NutritionPlan;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Consigli alimentari, non diete — N19.
 *
 * ── 🚨 Cosa difendono questi test ──────────────────────────────────────────
 *
 * In Italia l'elaborazione di una dieta e' un atto riservato a medici, biologi
 * nutrizionisti e dietisti (art. 348 c.p.). Un trainer puo' dare **consigli**:
 * un elenco di alimenti. Non un piano con quantita', pasti e orari.
 *
 * ⚠️ **E il vincolo deve stare sul SERVER.** Nascondere i campi
 * nell'interfaccia non basta: l'API e' pubblica e autenticata, e chiunque puo'
 * mandarci un JSON con dentro `days`. Un vincolo che vive solo nel client non
 * e' un vincolo — e' una speranza.
 */
class ConsigliAlimentariTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $trainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $this->trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'coach@olimpo.it');
    }

    /** Un giorno con un pasto e un alimento con i grammi: cioe' una dieta. */
    private function conQuantita(): array
    {
        return [
            'name' => 'Definizione',
            'days' => [[
                'name' => 'Giorno 1',
                'meals' => [[
                    'meal' => 'lunch',
                    'items' => [[
                        'description' => 'Petto di pollo',
                        'grams' => 200,
                    ]],
                ]],
            ]],
        ];
    }

    // ─────────────────────── quello che un trainer PUO' fare ───────────────

    #[Test]
    public function un_trainer_manda_un_elenco_di_alimenti(): void
    {
        $this->actingAs($this->trainer)
            ->postJson('/api/v1/nutrition-plans', [
                'name' => 'Cosa tenere in dispensa',
                'notes' => "Pollo\nRiso\nBroccoli\nUova",
            ])
            ->assertCreated();

        $piano = NutritionPlan::withoutGlobalScopes()->firstOrFail();

        // 💡 Senza dirlo, esce **consigli**: il default e' il tipo che si puo'
        // scrivere, non quello riservato.
        $this->assertSame(TipoPianoAlimentare::Consigli, $piano->tipo);
    }

    #[Test]
    public function il_tipo_si_puo_dire_esplicitamente(): void
    {
        $this->actingAs($this->trainer)
            ->postJson('/api/v1/nutrition-plans', [
                'name' => 'Consigli',
                'tipo' => 'consigli',
            ])
            ->assertCreated();

        $this->assertSame(
            TipoPianoAlimentare::Consigli,
            NutritionPlan::withoutGlobalScopes()->firstOrFail()->tipo,
        );
    }

    // ────────────────── quello che un trainer NON puo' fare ────────────────

    #[Test]
    public function un_trainer_non_puo_scrivere_quantita_e_giorni(): void
    {
        /*
         * 🚨 **Il test che difende la decisione di N19.**
         *
         * ⚠️ Questa richiesta l'app non la manderebbe mai — i campi non ci
         * sono nemmeno. Ma l'API si puo' chiamare a mano, e il giorno che
         * qualcuno lo fa deve trovare un no.
         */
        $this->actingAs($this->trainer)
            ->postJson('/api/v1/nutrition-plans', $this->conQuantita())
            ->assertStatus(422)
            ->assertJsonPath('code', 'consigli_senza_quantita');

        $this->assertDatabaseCount('nutrition_plans', 0);
    }

    #[Test]
    public function un_trainer_non_puo_dichiararsi_autore_di_un_piano(): void
    {
        /*
         * 🚨 E nemmeno chiamandolo con il suo nome: il tipo `piano` non e'
         * scrivibile da nessuno oggi — il ruolo che potra' farlo (N22) e'
         * predisposto ma **non attivo**.
         *
         * 💡 403 e non 422: la richiesta e' ben formata, il problema e' **chi**
         * la scrive.
         */
        $this->actingAs($this->trainer)
            ->postJson('/api/v1/nutrition-plans', [
                'name' => 'Dieta',
                'tipo' => 'piano',
            ])
            ->assertStatus(403)
            ->assertJsonPath('code', 'piano_riservato');

        $this->assertDatabaseCount('nutrition_plans', 0);
    }

    #[Test]
    public function nemmeno_modificandone_uno_gia_scritto(): void
    {
        // ⚠️ Lo store e' la porta piu' ovvia, e sarebbe stato facile presidiare
        // solo quella: chi vuole aggirare il vincolo passa dalla seconda.
        $this->actingAs($this->trainer)
            ->postJson('/api/v1/nutrition-plans', ['name' => 'Consigli'])
            ->assertCreated();

        $piano = NutritionPlan::withoutGlobalScopes()->firstOrFail();

        $this->actingAs($this->trainer)
            ->putJson("/api/v1/nutrition-plans/{$piano->id}", $this->conQuantita())
            ->assertStatus(422)
            ->assertJsonPath('code', 'consigli_senza_quantita');
    }

    #[Test]
    public function un_tipo_inventato_non_passa(): void
    {
        $this->actingAs($this->trainer)
            ->postJson('/api/v1/nutrition-plans', [
                'name' => 'X',
                'tipo' => 'dieta_miracolosa',
            ])
            ->assertStatus(422);
    }

    // ───────────────────────── la regola, da sola ──────────────────────────

    #[Test]
    public function oggi_nessuno_puo_scrivere_un_piano_vero(): void
    {
        /*
         * 🚨 **Non e' una svista: e' lo stato voluto** finche' N22 non si
         * accende. Questo test esiste per rendere la cosa **deliberata**: il
         * giorno che qualcuno aggiunge il ruolo, questo test si rompe e lo
         * costringe a decidere consapevolmente.
         */
        foreach ([UserRole::Trainer, UserRole::GymAdmin, UserRole::Member] as $ruolo) {
            $chi = $this->creaUtente($this->palestra, $ruolo, "{$ruolo->value}@olimpo.it");

            $this->assertFalse(
                TipoPianoAlimentare::Piano->scrivibileDa($chi),
                "{$ruolo->value} puo' scrivere un piano vero",
            );
        }
    }

    #[Test]
    public function i_consigli_li_puo_dare_chi_segue_qualcuno(): void
    {
        foreach ([UserRole::Trainer, UserRole::GymAdmin] as $ruolo) {
            $chi = $this->creaUtente($this->palestra, $ruolo, "c-{$ruolo->value}@olimpo.it");

            $this->assertTrue(
                TipoPianoAlimentare::Consigli->scrivibileDa($chi),
                "{$ruolo->value} non puo' dare consigli",
            );
        }

        // 💡 Un iscritto no: non segue nessuno.
        $iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'io@olimpo.it');

        $this->assertFalse(TipoPianoAlimentare::Consigli->scrivibileDa($iscritto));
    }

    #[Test]
    public function solo_il_piano_ammette_le_quantita(): void
    {
        $this->assertFalse(TipoPianoAlimentare::Consigli->ammetteQuantita());
        $this->assertTrue(TipoPianoAlimentare::Piano->ammetteQuantita());
    }
}
