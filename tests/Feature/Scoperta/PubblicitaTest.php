<?php

declare(strict_types=1);

namespace Tests\Feature\Scoperta;

use App\Enums\UserRole;
use App\Models\Campagna;
use App\Models\Comune;
use App\Models\ProfiloPubblico;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Visualizzazione;
use App\Services\Scoperta\ChiaveComune;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * La pubblicità a visualizzazione — Parte M5, 18/08/2026.
 *
 * 🚨 **Ogni test qui difende una riga di fattura.** Il committente ha scelto il
 * pagamento a evento (0,02 €/visualizzazione): un conteggio sbagliato non è un
 * difetto di visualizzazione, è un addebito a qualcuno.
 */
class PubblicitaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestraA;

    private Tenant $palestraB;

    private ProfiloPubblico $schedaA;

    private ProfiloPubblico $schedaB;

    private User $chiGuarda;

    private Comune $rimini;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rimini = Comune::create([
            'codice' => '099014', 'nome' => 'Rimini',
            'chiave' => app(ChiaveComune::class)->da('Rimini'),
            'provincia' => 'RN', 'provincia_nome' => 'Rimini', 'regione' => 'Emilia-Romagna',
            'popolazione' => 150_951, 'lat' => 44.060249, 'lng' => 12.565599, 'attivo' => true,
        ]);

        // 🚨 «Alfa» viene prima di «Zeta» in ordine alfabetico: così, se la
        // sponsorizzazione non funzionasse, l'ordine sarebbe comunque A→B e il
        // test passerebbe per il motivo sbagliato. Quindi lo sponsor è **Zeta**.
        $this->palestraA = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->palestraB = $this->creaPalestra('Zeta', 'zeta', 'ZETA2345');

        $this->schedaA = $this->scheda($this->palestraA, 'Alfa Fitness');
        $this->schedaB = $this->scheda($this->palestraB, 'Zeta Fitness');

        $terzo = $this->creaPalestra('Mu', 'mu', 'MUMU2345');
        $this->chiGuarda = $this->creaUtente($terzo, UserRole::Member, 'guarda@esempio.it');
        $this->chiGuarda->forceFill(['comune_id' => $this->rimini->id])->save();
    }

    // ────────────────── l'ordine e l'etichetta ──────────────────

    #[Test]
    public function una_campagna_attiva_porta_la_scheda_in_cima(): void
    {
        $this->accendi($this->schedaB, budgetEuro: 10);

        Sanctum::actingAs($this->chiGuarda);

        $this->getJson('/api/v1/catalogo')
            ->assertOk()
            ->assertJsonPath('data.0.titolo', 'Zeta Fitness')
            ->assertJsonPath('data.0.sponsorizzato', true);
    }

    #[Test]
    public function l_etichetta_sponsorizzato_c_e_sempre(): void
    {
        /*
         * 🚨 Presentare a pagamento qualcosa che sembra un risultato di ricerca
         * è **pubblicità occulta**. Basta la parola, ma deve esserci.
         */
        $this->accendi($this->schedaB, budgetEuro: 10);

        Sanctum::actingAs($this->chiGuarda);

        $dati = $this->getJson('/api/v1/catalogo')->assertOk()->json('data');

        $sponsor = collect($dati)->firstWhere('titolo', 'Zeta Fitness');
        $normale = collect($dati)->firstWhere('titolo', 'Alfa Fitness');

        $this->assertTrue($sponsor['sponsorizzato']);
        $this->assertFalse($normale['sponsorizzato'], 'Chi non paga non va etichettato.');
    }

    #[Test]
    public function una_campagna_spent_a_non_compare_in_cima_e_non_e_etichettata(): void
    {
        $campagna = $this->accendi($this->schedaB, budgetEuro: 10);
        $campagna->update(['attiva' => false]);

        Sanctum::actingAs($this->chiGuarda);

        $this->getJson('/api/v1/catalogo')
            ->assertOk()
            ->assertJsonPath('data.0.titolo', 'Alfa Fitness')
            ->assertJsonPath('data.0.sponsorizzato', false);
    }

    #[Test]
    public function una_campagna_esaurit_a_non_compare_in_cima(): void
    {
        /*
         * 🚨 ⚠️ Guardare solo `campagna_id` avrebbe lasciato in testa le
         * campagne finite — **gratis**, e togliendo il posto a chi paga davvero.
         */
        $campagna = $this->accendi($this->schedaB, budgetEuro: 10);
        $campagna->update([
            'mese' => Campagna::meseCorrente(),
            'speso_mese_cent' => $campagna->budget_mensile_cent,
        ]);

        Sanctum::actingAs($this->chiGuarda);

        $this->getJson('/api/v1/catalogo')
            ->assertOk()
            ->assertJsonPath('data.0.titolo', 'Alfa Fitness');
    }

    #[Test]
    public function lo_speso_di_un_mese_passato_non_conta(): void
    {
        // 💡 L'azzeramento è **pigro**: non c'è nessun job il primo del mese.
        $campagna = $this->accendi($this->schedaB, budgetEuro: 10);
        $campagna->update([
            'mese' => '2020-01',
            'speso_mese_cent' => 999_999,
        ]);

        Sanctum::actingAs($this->chiGuarda);

        $this->getJson('/api/v1/catalogo')
            ->assertOk()
            ->assertJsonPath('data.0.titolo', 'Zeta Fitness');
    }

    // ────────────────── il conteggio ──────────────────

    #[Test]
    public function guardare_il_catalogo_conta_una_visualizzazione(): void
    {
        $campagna = $this->accendi($this->schedaB, budgetEuro: 10);

        Sanctum::actingAs($this->chiGuarda);
        $this->getJson('/api/v1/catalogo')->assertOk();

        $this->assertSame(1, Visualizzazione::where('campagna_id', $campagna->id)->count());
        $this->assertSame(2, $campagna->fresh()->speso_mese_cent);
    }

    #[Test]
    public function la_stessa_persona_nello_stesso_giorno_conta_un_a_volta(): void
    {
        /*
         * 🚨 **La regola che decide la fattura.**
         *
         * ⚠️ «Ogni volta che compare» sarebbe ingiusto e manipolabile: chi
         * scorre l'elenco su e giù genererebbe venti visualizzazioni, e un
         * difetto nostro che ricarica in continuazione moltiplicherebbe la
         * fattura di qualcuno senza che nessuno abbia fatto niente.
         */
        $campagna = $this->accendi($this->schedaB, budgetEuro: 10);

        Sanctum::actingAs($this->chiGuarda);

        for ($i = 0; $i < 5; $i++) {
            $this->getJson('/api/v1/catalogo')->assertOk();
        }

        $this->assertSame(1, Visualizzazione::where('campagna_id', $campagna->id)->count());
        $this->assertSame(2, $campagna->fresh()->speso_mese_cent, 'Cinque aperture = due centesimi.');
    }

    #[Test]
    public function persone_diverse_contano_separatamente(): void
    {
        $campagna = $this->accendi($this->schedaB, budgetEuro: 10);

        foreach (['uno@esempio.it', 'due@esempio.it'] as $email) {
            $altro = $this->creaUtente($this->palestraA, UserRole::Member, $email);
            Sanctum::actingAs($altro);
            $this->getJson('/api/v1/catalogo')->assertOk();
        }

        $this->assertSame(2, Visualizzazione::where('campagna_id', $campagna->id)->count());
        $this->assertSame(4, $campagna->fresh()->speso_mese_cent);
    }

    #[Test]
    public function chi_no_n_e_autenticato_non_fa_spendere_niente(): void
    {
        /*
         * 🚨 **Le visite anonime sono gratis, ed è una decisione.**
         *
         * ⚠️ L'alternativa sarebbe contare per indirizzo IP: gonfiabile da
         * chiunque con un telefono in tethering, e vorrebbe dire conservare gli
         * IP di chi consulta un elenco — un dato personale raccolto **per
         * fatturare**.
         */
        $campagna = $this->accendi($this->schedaB, budgetEuro: 10);

        $this->getJson('/api/v1/catalogo')->assertOk();

        $this->assertSame(0, Visualizzazione::where('campagna_id', $campagna->id)->count());
        $this->assertSame(0, $campagna->fresh()->speso_mese_cent);
    }

    #[Test]
    public function una_scheda_no_n_sponsorizzata_non_conta_niente(): void
    {
        Sanctum::actingAs($this->chiGuarda);
        $this->getJson('/api/v1/catalogo')->assertOk();

        $this->assertSame(0, Visualizzazione::count());
    }

    #[Test]
    public function si_conta_solo_quello_che_e_stato_mostrato(): void
    {
        /*
         * 🚨 M5.3. ⚠️ Contare prima di applicare il limite vorrebbe dire
         * fatturare schede che non sono state restituite: una ricerca che ne
         * trova trenta e ne mostra venti farebbe pagare anche le dieci che
         * nessuno ha visto.
         */
        $questaNo = $this->scheda($this->creaPalestra('Nu', 'nu', 'NUNU2345'), 'Nu Fitness');
        $campagnaNascosta = $this->accendi($questaNo, budgetEuro: 10);
        $campagnaVisibile = $this->accendi($this->schedaB, budgetEuro: 10);

        Sanctum::actingAs($this->chiGuarda);

        // Un solo risultato: la seconda sponsorizzata resta fuori.
        $this->getJson('/api/v1/catalogo?limite=1')->assertOk()->assertJsonCount(1, 'data');

        $contate = Visualizzazione::whereIn('campagna_id', [$campagnaNascosta->id, $campagnaVisibile->id])->count();

        $this->assertSame(1, $contate, 'Si deve contare solo la scheda restituita.');
    }

    // ────────────────── il tetto di spesa ──────────────────

    #[Test]
    public function raggiunto_il_tetto_la_campagna_si_spegne_da_sola(): void
    {
        /*
         * 🚨 **Il tetto non è facoltativo** (§4.3.2). ⚠️ Senza, un pagamento a
         * evento è un modo per mandare a qualcuno una fattura da quattromila
         * euro per un difetto nostro.
         */
        $campagna = $this->accendi($this->schedaB, budgetEuro: 10);

        // 4 centesimi di budget = due visualizzazioni.
        $campagna->update(['budget_mensile_cent' => 4]);

        foreach (['uno@e.it', 'due@e.it', 'tre@e.it'] as $email) {
            Sanctum::actingAs($this->creaUtente($this->palestraA, UserRole::Member, $email));
            $this->getJson('/api/v1/catalogo')->assertOk();
        }

        $dopo = $campagna->fresh();

        $this->assertFalse($dopo->attiva, 'Doveva spegnersi da sola.');
        $this->assertNotNull($dopo->esaurita_il, 'La data di spegnimento serve a rispondere «perché non compaio più».');
        $this->assertSame(4, $dopo->speso_mese_cent, 'Non deve spendere oltre il tetto.');
    }

    #[Test]
    public function una_campagna_esaurita_non_conta_piu_niente(): void
    {
        $campagna = $this->accendi($this->schedaB, budgetEuro: 10);
        $campagna->update([
            'attiva' => false,
            'mese' => Campagna::meseCorrente(),
            'speso_mese_cent' => 1000,
        ]);

        Sanctum::actingAs($this->chiGuarda);
        $this->getJson('/api/v1/catalogo')->assertOk();

        $this->assertSame(1000, $campagna->fresh()->speso_mese_cent);
        $this->assertSame(0, Visualizzazione::count());
    }

    #[Test]
    public function con_un_residuo_insufficiente_non_si_addebita(): void
    {
        /*
         * ⚠️ Con 1 centesimo residuo e un costo di 2, senza questo controllo la
         * campagna comparirebbe all'infinito senza mai riuscire a pagarsi.
         */
        $campagna = $this->accendi($this->schedaB, budgetEuro: 10);
        $campagna->update([
            'budget_mensile_cent' => 5,
            'mese' => Campagna::meseCorrente(),
            'speso_mese_cent' => 4,
        ]);

        Sanctum::actingAs($this->chiGuarda);
        $this->getJson('/api/v1/catalogo')->assertOk();

        $this->assertSame(4, $campagna->fresh()->speso_mese_cent);
    }

    // ────────────────── il prezzo fotografato ──────────────────

    #[Test]
    public function il_costo_e_quello_dell_attivazione_non_quello_di_oggi(): void
    {
        /*
         * 🚨 Un aumento di listino non deve cambiare il prezzo di una campagna
         * **già in corso**: chi l'ha accesa ha accettato quella cifra.
         */
        $campagna = $this->accendi($this->schedaB, budgetEuro: 10);
        $this->assertSame(2, $campagna->costo_visualizzazione_cent);

        config()->set('listino.pubblicita.costo_visualizzazione_cent', 50);

        Sanctum::actingAs($this->chiGuarda);
        $this->getJson('/api/v1/catalogo')->assertOk();

        $this->assertSame(2, $campagna->fresh()->speso_mese_cent, 'Doveva pagare il prezzo di quando ha acceso.');
    }

    // ────────────────── aiutanti ──────────────────

    private function scheda(Tenant $palestra, string $titolo): ProfiloPubblico
    {
        return ProfiloPubblico::create([
            'tenant_id' => $palestra->id,
            'comune_id' => $this->rimini->id,
            'titolo' => $titolo,
            'visibile' => true,
        ]);
    }

    private function accendi(ProfiloPubblico $scheda, int $budgetEuro): Campagna
    {
        $campagna = Campagna::create([
            'tenant_id' => $scheda->tenant_id,
            'user_id' => $scheda->user_id,
            'attiva' => true,
            'budget_mensile_cent' => $budgetEuro * 100,
            'costo_visualizzazione_cent' => (int) config('listino.pubblicita.costo_visualizzazione_cent', 2),
        ]);

        $scheda->update(['campagna_id' => $campagna->id]);

        return $campagna;
    }
}
