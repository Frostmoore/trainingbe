<?php

declare(strict_types=1);

namespace Tests\Feature\Nutrition;

use App\Enums\UserRole;
use App\Models\Food;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Nutrition\Catalogo\ChiaveAlimento;
use App\Services\Nutrition\Catalogo\RicercaAlimenti;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * I suggerimenti mentre si scrive — Parte L, 17/08/2026.
 *
 * L'ordine chiesto dal committente:
 *
 *     inseriti da me → più selezionati da me → più selezionati in generale →
 *     inseriti da altri
 */
class RicercaAlimentiTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $io;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa');
        $this->io = $this->creaUtente($this->alfa, UserRole::Member, 'io@alfa.test');
    }

    /**
     * 🚨 **L'ordine, tutto in un test**: prima i più scelti in generale, poi
     * per nome. ⛔ Fino al 26/09/2026 venivano prima «i miei», contati su
     * `food_entries`: il diario ha lasciato il server (A1 di `plan_blocco_A.md`)
     * e quell'ordine lo fa il telefono, che il diario ce l'ha.
     */
    #[Test]
    public function the_most_used_overall_come_first(): void
    {
        $this->alimento('Pollo arrosto', usi: 5);
        $this->alimento('Pollo al curry', usi: 5);
        $this->alimento('Pollo alla cacciatora', usi: 900);
        $this->alimento('Pollo strano', usi: 0);

        $ordine = app(RicercaAlimenti::class)
            ->cerca('pollo', $this->io)
            ->pluck('nome')
            ->all();

        $this->assertSame([
            'Pollo alla cacciatora',    // il più scelto in generale
            'Pollo al curry',           // a parità di scelte, per nome
            'Pollo arrosto',
            'Pollo strano',             // mai scelto
        ], $ordine);
    }

    /**
     * 🚨 **Gli alimenti privati di un altro non si vedono.**
     *
     * ⚠️ È la ragione per cui esiste la soglia delle due conferme: senza questo
     * confine, il nome che una persona ha scritto per sé finirebbe nel
     * suggerimento di sconosciuti.
     */
    #[Test]
    public function you_never_see_someone_elses_private_food(): void
    {
        $altro = $this->creaUtente($this->alfa, UserRole::Member, 'altro@alfa.test');

        $suo = $this->alimento('Pollo della nonna', pubblico: false);

        $trovati = app(RicercaAlimenti::class)
            ->cerca('pollo', $this->io);

        $this->assertCount(0, $trovati);
    }

    /*
     * ⛔ Qui c'era `you_always_see_your_own`: provava il diario sul server, che dal 26/09/2026 non
     * c'è più (A1 di `plan_blocco_A.md`). Il diario sta sul telefono.
     */

    /**
     * 💡 Chi cerca «pollo» deve trovare «Petto di pollo», non solo quelli che
     * cominciano per pollo.
     */
    #[Test]
    public function the_search_also_matches_inside_the_name(): void
    {
        $this->alimento('Petto di pollo');

        $this->assertCount(1, app(RicercaAlimenti::class)
            ->cerca('pollo', $this->io));
    }

    /** ⚠️ Accenti e maiuscole non devono impedire di trovare niente. */
    #[Test]
    public function accents_and_capitals_do_not_hide_a_food(): void
    {
        $this->alimento('Purè di patate');

        $this->assertCount(1, app(RicercaAlimenti::class)
            ->cerca('PURE', $this->io));
    }

    /**
     * 🚨 **`%` e `_` dentro il testo cercato sono caratteri jolly per `LIKE`.**
     *
     * ⚠️ Chi cerca «_» senza protezione si porterebbe indietro il catalogo
     * intero, una richiesta per tasto premuto.
     */
    #[Test]
    public function wildcards_typed_by_a_person_are_not_wildcards(): void
    {
        $this->alimento('Petto di pollo');
        $this->alimento('Riso bianco');

        $ricerca = app(RicercaAlimenti::class);

        $this->assertCount(0, $ricerca->cerca('%%', $this->io));
        $this->assertCount(0, $ricerca->cerca('__', $this->io));
    }

    /** ⚠️ Sotto i due caratteri qualunque ricerca restituisce mezzo catalogo. */
    #[Test]
    public function one_letter_searches_nothing(): void
    {
        $this->alimento('Petto di pollo');

        $this->assertCount(0, app(RicercaAlimenti::class)
            ->cerca('p', $this->io));
    }

    /**
     * \U0001F6A8 **Il CREA viene prima di Open Food Facts**, a parità di uso.
     *
     * ── Perché, e come si è scoperto ───────────────────────────────
     *
     * Provando l'app sul telefono con un milione e mezzo di prodotti Open Food
     * Facts in catalogo, cercare «pollo» restituiva soprattutto **roba di
     * scaffale**: nomi in lingue diverse, marche mai sentite, valori trascritti
     * da volontari.
     *
     * \U0001F4A1 È una differenza **di qualità della fonte**, non di popolarità: le
     * 832 voci CREA sono misure di laboratorio, gli 1,57 milioni di Open Food
     * Facts sono etichette copiate da chiunque. ⚠️ L'ordinamento per `usi` da
     * solo non poteva saperlo — all'inizio nessuno ha usato niente, e a parità
     * di zero decideva l'ordine alfabetico.
     */
    #[Test]
    public function crea_comes_before_open_food_facts(): void
    {
        $this->alimento('Pollo aaa off', fonte: 'OFF:8001234567890');
        $this->alimento('Pollo zzz crea', fonte: 'CREA:106140');

        $ordine = app(RicercaAlimenti::class)
            ->cerca('pollo', $this->io)
            ->pluck('nome')
            ->all();

        // \U0001F4A1 Senza la regola vincerebbe l'ordine alfabetico, e quindi l'OFF.
        $this->assertSame(['Pollo zzz crea', 'Pollo aaa off'], $ordine);
    }

    /*
     * ⛔ Qui c'era `your_own_foods_come_before_even_crea`: provava il diario sul server, che dal 26/09/2026 non
     * c'è più (A1 di `plan_blocco_A.md`). Il diario sta sul telefono.
     */

    /** \U0001F4A1 E fra due Open Food Facts decide sempre l'uso. */
    #[Test]
    public function between_two_off_products_usage_still_decides(): void
    {
        $this->alimento('Pollo poco usato', usi: 2, fonte: 'OFF:1');
        $this->alimento('Pollo molto usato', usi: 900, fonte: 'OFF:2');

        $ordine = app(RicercaAlimenti::class)
            ->cerca('pollo', $this->io)
            ->pluck('nome')
            ->all();

        $this->assertSame(['Pollo molto usato', 'Pollo poco usato'], $ordine);
    }

    // ═══════════════════ la rotta ═══════════════════

    /**
     * 🚨 **La nota di provenienza esce nella risposta**, ed è l'attribuzione.
     *
     * CREA chiede «una chiara indicazione della fonte originale», Open Food
     * Facts chiede l'attribuzione ODbL. ⚠️ Tenerla solo in banca dati è metà
     * del lavoro: la licenza chiede che la veda **chi usa il dato**.
     */
    #[Test]
    public function the_endpoint_returns_the_attribution(): void
    {
        $this->alimento('Petto di pollo');

        $this->actingAs($this->io)
            ->getJson('/api/v1/foods/search?q=pollo')
            ->assertOk()
            ->assertJsonPath('data.0.nome', 'Petto di pollo')
            ->assertJsonPath('data.0.note', 'Fonte: CREA Centro di ricerca Alimenti e Nutrizione')
            ->assertJsonPath('data.0.kcal_100', 100.0);
    }

    /** ⚠️ E non escono i numeri interni: quante persone hanno mangiato una cosa non riguarda nessuno. */
    #[Test]
    public function the_endpoint_does_not_leak_the_internal_counters(): void
    {
        $this->alimento('Petto di pollo');

        $this->actingAs($this->io)
            ->getJson('/api/v1/foods/search?q=pollo')
            ->assertOk()
            ->assertJsonMissingPath('data.0.conferme')
            ->assertJsonMissingPath('data.0.usi');
    }

    #[Test]
    public function the_endpoint_refuses_a_search_that_is_too_short(): void
    {
        $this->actingAs($this->io)
            ->getJson('/api/v1/foods/search?q=p')
            ->assertStatus(422);
    }

    /** 🚨 E chi non ha fatto l'accesso non interroga il catalogo. */
    #[Test]
    public function the_endpoint_needs_a_logged_in_person(): void
    {
        $this->getJson('/api/v1/foods/search?q=pollo')->assertStatus(401);
    }

    // ───────────────────────── aiutanti ─────────────────────────

    private function alimento(
        string $nome,
        int $usi = 0,
        bool $pubblico = true,
        string $fonte = 'CREA:000001',
    ): Food {
        return Food::query()->create([
            'chiave' => app(ChiaveAlimento::class)->da($nome),
            'nome' => $nome,
            'kcal_100' => 100.0, 'protein_100' => 23.3, 'carbs_100' => 0.0, 'fat_100' => 0.8,
            'fonte' => $fonte,
            'note' => 'Fonte: CREA Centro di ricerca Alimenti e Nutrizione',
            'usi' => $usi,
            'conferme' => 2,
            'pubblico' => $pubblico,
        ]);
    }
}
