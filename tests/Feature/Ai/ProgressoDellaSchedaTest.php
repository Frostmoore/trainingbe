<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Prompts;
use App\Services\Billing\PortafoglioGettoni;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * 3b-I.A — l'analisi della progressione degli esercizi.
 *
 * ══ ⚖️ COSA PROVA DAVVERO QUESTA CLASSE ══════════════════════════════════
 *
 * Due cose, e nessuna delle due e' «l'AI risponde bene»:
 *
 * 1. **Il gate**: chi non e' abbonato non entra, *nemmeno avendo i gettoni*.
 *    E' l'unico test in tutta la suite in cui la distinzione fra le due monete
 *    — i gettoni comprano le chiamate, l'abbonamento compra le funzioni — si
 *    vede in azione invece di essere solo scritta in un commento.
 * 2. **Il setaccio**: una riga che prescrive non arriva al telefono. 🚨 E'
 *    l'obbligo legale (*«serve un medico»*), quindi e' un test che non si
 *    cancella per far passare qualcos'altro.
 */
class ProgressoDellaSchedaTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $palestra;

    private User $abbonato;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->aiFinta();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->abbonato = $this->creaUtente($this->palestra, UserRole::Member, 'mario@alfa.test');
        $this->abbonato->registraConsenso('ai_consent_at', true);
    }

    /**
     * Il corpo della richiesta: due esercizi con la loro storia.
     *
     * @return array<string, mixed>
     */
    private function scheda(): array
    {
        return ['esercizi' => [
            ['id' => 7, 'nome' => 'Panca piana', 'sedute' => [
                ['data' => '2026-08-01', 'carico' => 60.0, 'ripetizioni' => 8],
                ['data' => '2026-08-08', 'carico' => 62.5, 'ripetizioni' => 8],
            ]],
            ['id' => 9, 'nome' => 'Lat machine', 'sedute' => [
                ['data' => '2026-08-01', 'carico' => 50.0, 'ripetizioni' => 10],
            ]],
        ]];
    }

    #[Test]
    public function un_abbonato_riceve_una_riga_per_esercizio(): void
    {
        $this->aiFinta()->willReturnProgresso([
            ['id' => 7, 'andamento' => 'in_salita', 'riga' => 'Hai chiuso tutte le serie due volte di fila.'],
            ['id' => 9, 'andamento' => 'poco_storico', 'riga' => ''],
        ]);

        $this->comeApp($this->abbonato)
            ->postJson('/api/v1/ai/scheda/progresso', $this->scheda())
            ->assertOk()
            ->assertJsonCount(2, 'data.esercizi')
            ->assertJsonPath('data.esercizi.0.id', 7)
            ->assertJsonPath('data.esercizi.0.andamento', 'in_salita')
            ->assertJsonPath('data.esercizi.1.andamento', 'poco_storico');
    }

    /**
     * 🚨 **Il test che non si cancella.**
     *
     * ⛔ *«MAI un numero riferito al futuro»*: se un giorno questa asserzione
     * dara' fastidio, la risposta non e' toglierla — e' capire perche' la riga
     * proibita e' passata.
     *
     * 💡 E l'andamento **resta**: la sparkline continua a colorarsi. Buttare
     * tutta la risposta farebbe perdere un gettone per una frase scritta male.
     */
    #[Test]
    public function una_riga_che_prescrive_non_arriva_al_telefono(): void
    {
        $this->aiFinta()->willReturnProgresso([
            ['id' => 7, 'andamento' => 'in_salita', 'riga' => 'La prossima volta metti 65 kg.'],
        ]);

        $this->comeApp($this->abbonato)
            ->postJson('/api/v1/ai/scheda/progresso', $this->scheda())
            ->assertOk()
            ->assertJsonPath('data.esercizi.0.riga', '')
            ->assertJsonPath('data.esercizi.0.andamento', 'in_salita');
    }

    /** Il setaccio, riga per riga, senza passare dall'HTTP. */
    #[Test]
    public function il_setaccio_riconosce_le_forme_del_consiglio(): void
    {
        foreach ([
            'Prova a salire di due chili.',
            'Dovresti riposare di piu\' fra le serie.',
            'La prossima volta aumenta il carico.',
            'Ti conviene passare a otto ripetizioni.',
        ] as $proibita) {
            $this->assertTrue(Prompts::rigaProibita($proibita), $proibita);
        }

        foreach ([
            'Hai chiuso tutte le serie tre volte di fila.',
            'Il carico e\' fermo da un mese.',
            'Le ripetizioni sono scese nelle ultime due sedute.',
        ] as $lecita) {
            $this->assertFalse(Prompts::rigaProibita($lecita), $lecita);
        }
    }

    /**
     * 🗣️ Il riassunto e' la sola cosa che guarda gli esercizi INSIEME.
     *
     * 📌 *«Mi deve dire qualcosa di utile, sennò che cazzo lo pago a fare?»* —
     * una riga sotto un esercizio, per costruzione, non puo' dire dove la scheda
     * si muove e dove no.
     */
    #[Test]
    public function la_risposta_porta_anche_il_riassunto_della_scheda(): void
    {
        $this->aiFinta()->willReturnRiassunto(
            'Cresci sulle spinte, fermo sulle trazioni da un mese.',
        );

        $this->comeApp($this->abbonato)
            ->postJson('/api/v1/ai/scheda/progresso', $this->scheda())
            ->assertOk()
            ->assertJsonPath(
                'data.riassunto',
                'Cresci sulle spinte, fermo sulle trazioni da un mese.',
            );
    }

    /**
     * 🚨 **Il riassunto passa dallo stesso setaccio delle righe.**
     *
     * ⚠️ E' la frase che parla di **tutta** la scheda, quindi la piu' tentata di
     * concludere con un consiglio: lasciarla uscire senza controllo perche' «e'
     * solo un riassunto» vorrebbe dire lasciare aperta la porta piu' larga.
     */
    #[Test]
    public function un_riassunto_che_prescrive_non_arriva_al_telefono(): void
    {
        $this->aiFinta()->willReturnRiassunto(
            'Sei fermo ovunque: dovresti aumentare i carichi.',
        );

        $this->comeApp($this->abbonato)
            ->postJson('/api/v1/ai/scheda/progresso', $this->scheda())
            ->assertOk()
            ->assertJsonPath('data.riassunto', '');
    }

    /**
     * 🏆 I primati li calcola il telefono, e il server li lascia passare.
     *
     * ⛔ Il modello non deve ricalcolarli: sbaglia i confronti fra numeri, e un
     * «e' il tuo record» falso e' una bugia detta con entusiasmo.
     */
    #[Test]
    public function i_primati_calcolati_dal_telefono_sono_accettati(): void
    {
        $corpo = $this->scheda();

        $corpo['esercizi'][0]['primati'] = [
            'sedute_totali' => 24,
            'dal' => '2026-02-01',
            'carico_massimo' => 62.5,
            'quando_il_massimo' => '2026-08-08',
            'sedute_allo_stesso_carico' => 9,
        ];

        $this->comeApp($this->abbonato)
            ->postJson('/api/v1/ai/scheda/progresso', $corpo)
            ->assertOk();
    }

    /**
     * ⚖️ **I gettoni non comprano le funzioni.**
     *
     * 🚨 Questo utente ha l'AI utilizzabile — il credito lo fa passare da
     * `ai.plan` — e viene fermato lo stesso. Se un giorno questo test iniziasse
     * a rispondere `200`, vorrebbe dire che il gate dell'abbonamento e' saltato
     * senza che nessuna riga di codice lo dichiarasse.
     */
    #[Test]
    public function chi_non_e_abbonato_non_entra_nemmeno_con_i_gettoni(): void
    {
        $libero = $this->utenteSulPianoGratuito();

        $this->assertGreaterThan(0, app(PortafoglioGettoni::class)->saldo($libero));

        $this->comeApp($libero)
            ->postJson('/api/v1/ai/scheda/progresso', $this->scheda())
            ->assertStatus(403);

        // ⚠️ E non ha pagato: un rifiuto che scala il credito e' un furto.
        $this->assertGreaterThan(0, app(PortafoglioGettoni::class)->saldo($libero->fresh()));
    }

    /**
     * ⛔ Un id inventato dal modello non deve comparire sotto nessun esercizio:
     * sarebbe una riga **plausibile** attaccata alla cosa sbagliata, cioe' del
     * tipo che non si scopre guardando.
     */
    #[Test]
    public function un_id_che_non_era_stato_chiesto_viene_scartato(): void
    {
        $this->aiFinta()->willReturnProgresso([
            ['id' => 7, 'andamento' => 'fermo', 'riga' => 'Il carico e\' lo stesso da tre sedute.'],
            ['id' => 999, 'andamento' => 'in_salita', 'riga' => 'Inventato di sana pianta.'],
        ]);

        $this->comeApp($this->abbonato)
            ->postJson('/api/v1/ai/scheda/progresso', $this->scheda())
            ->assertOk()
            ->assertJsonCount(1, 'data.esercizi')
            ->assertJsonPath('data.esercizi.0.id', 7);
    }

    /** 💡 Il tetto sta nella validazione: senza, la fattura la scrive il client. */
    #[Test]
    public function una_scheda_smisurata_viene_rifiutata(): void
    {
        $esercizi = [];

        for ($i = 0; $i < 41; $i++) {
            $esercizi[] = ['id' => $i, 'nome' => 'Esercizio '.$i, 'sedute' => [
                ['data' => '2026-08-01', 'carico' => 10.0, 'ripetizioni' => 10],
            ]];
        }

        $this->comeApp($this->abbonato)
            ->postJson('/api/v1/ai/scheda/progresso', ['esercizi' => $esercizi])
            ->assertStatus(422);
    }

    /**
     * Una palestra sul piano gratuito, con dei gettoni comprati.
     *
     * 💡 Si smonta l'abbonamento di `creaPalestra()` invece di crearne una
     * senza: quel metodo ne mette uno apposta, e ottenere il caso «gratuito»
     * per omissione — non seminando il listino — proverebbe un'altra cosa.
     */
    private function utenteSulPianoGratuito(): User
    {
        $tenant = $this->creaPalestra('Beta', 'beta', 'BETA2345');

        PlanSubscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->delete();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => Plan::query()->where('code', Plan::FREE)->firstOrFail()->id,
            'starts_at' => now()->subYears(5),
        ]);

        $tenant->forceFill(['ai_credits' => 50])->save();

        $utente = $this->creaUtente($tenant, UserRole::Member, 'libero@beta.test');
        $utente->registraConsenso('ai_consent_at', true);

        return $utente->fresh();
    }
}
