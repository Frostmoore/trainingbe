<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\MuscleGroup;
use App\Enums\UserRole;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\ExerciseLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Un esercizio allena più di un muscolo — 3b-A.3, 23/08/2026.
 *
 * 📌 *«Tutti gli esercizi devono indicare il muscolo o il gruppo muscolare che
 * allenano (anche più di uno)»* · *«Mi devi sistemare tutti gli esercizi in modo
 * che abbiano effettivamente questo campo pieno»*.
 *
 * ⚠️ **Il campo non era vuoto: era povero.** Una panca piana diceva `chest` e
 * taceva su tricipiti e deltoidi — invisibile finché serviva a filtrare un
 * elenco, evidente su una figura colorata per zone.
 */
class MuscoliDegliEserciziTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $mario;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * 🚨 **Il catalogo si semina, o questi test dicono sempre di sì.**
         *
         * ⛔ Al primo giro non lo facevano, e il test che pretende «nessun
         * esercizio senza il dato» girava su un database **vuoto**: zero
         * esercizi, zero senza il dato, verde. È la peggiore delle guardie —
         * quella che conferma qualunque cosa le si metta davanti.
         *
         * 💡 Ed è così che si è scoperto il difetto vero: il catalogo lo crea
         * un **seeder**, non una migrazione. Su un'installazione nuova i 121
         * esercizi sarebbero rinati senza secondari.
         */
        $this->seed(ExerciseLibrarySeeder::class);

        $this->palestra = $this->creaPalestra();
        $this->mario = $this->creaUtente($this->palestra, UserRole::Member, 'mario@demo.test');
    }

    // ───────────────────────── il catalogo ─────────────────────────

    #[Test]
    public function nessun_esercizio_del_catalogo_e_rimasto_senza_il_dato(): void
    {
        /*
         * 🚨 **`NULL` e `[]` sono due cose diverse**, ed è tutta la differenza
         * fra «non ci ho pensato» e «questo esercizio isola davvero».
         *
         * ⛔ Questo test pretende che nessuno sia `NULL`: un esercizio nuovo
         * aggiunto al catalogo senza decidere i suoi muscoli fa diventare rosso
         * questo, non lascia una lacuna che si vede sei mesi dopo su una figura
         * grigia.
         */
        // ⚠️ Prima di contare i buchi, che ci siano delle righe: `0 su 0` è
        // verde e non vuol dire niente. È l'errore che questo test faceva.
        self::assertGreaterThan(100, DB::table('exercises')->count());

        $senzaDato = DB::table('exercises')->whereNull('secondary_muscles')->count();

        self::assertSame(
            0,
            $senzaDato,
            'esercizi senza `secondary_muscles`: decidi i loro muscoli, anche se '
                .'la risposta è «nessuno» — in quel caso scrivi un elenco vuoto',
        );
    }

    #[Test]
    public function e_i_secondari_sono_scritti_uno_per_uno(): void
    {
        // 💡 I casi che una regola automatica sul nome avrebbe sbagliato.
        $attesi = [
            // ⚠️ **L'ordine è quello della fonte**, non uno nostro: da 3b-B.2 i
            // muscoli vengono da `free-exercise-db`, che li elenca in ordine
            // alfabetico. ⛔ Riordinare il dato per far contento un test
            // vorrebbe dire allontanarsi dalla fonte per una cosa che non ha
            // nessun significato.
            'Panca piana' => ['shoulders', 'triceps'],
            // 💡 I **polpacci** ce li ha messi la fonte, e ha ragione: in uno
            // stacco lavorano a stabilizzare. Non li avevo scritti io.
            'Stacco da terra' => [
                'calves',
                'forearms',
                'glutes',
                'hamstrings',
                'quads',
            ],
            'Leg extension' => [],
            'Corsa' => ['quads', 'hamstrings', 'calves', 'glutes'],
        ];

        foreach ($attesi as $nome => $muscoli) {
            $riga = DB::table('exercises')->where('name', $nome)->first();

            self::assertNotNull($riga, "manca l'esercizio «{$nome}»");
            self::assertSame(
                $muscoli,
                json_decode((string) $riga->secondary_muscles, true),
                "i secondari di «{$nome}» non sono quelli decisi",
            );
        }
    }

    // ───────────────────────── i pesi ─────────────────────────

    #[Test]
    public function il_primario_pesa_il_doppio_di_un_secondario(): void
    {
        $panca = Exercise::query()->where('name', 'Panca piana')->firstOrFail();

        self::assertSame(
            ['chest' => 1.0, 'shoulders' => 0.5, 'triceps' => 0.5],
            $panca->muscoliConPeso(),
        );
    }

    #[Test]
    public function cardio_non_e_una_zona_del_corpo(): void
    {
        /*
         * ⛔ **`cardio` non entra nei pesi**, e non è una dimenticanza: non c'è
         * nessuna zona da colorare che si chiami «cardio».
         *
         * 💡 Ma una corsa **colora le gambe lo stesso**, perché le ha fra i
         * secondari. Senza quel accorgimento chi corre e basta avrebbe una
         * figura tutta grigia — che sarebbe falsa.
         */
        $corsa = Exercise::query()->where('name', 'Corsa')->firstOrFail();

        $pesi = $corsa->muscoliConPeso();

        self::assertArrayNotHasKey('cardio', $pesi);
        self::assertSame(
            ['quads' => 0.5, 'hamstrings' => 0.5, 'calves' => 0.5, 'glutes' => 0.5],
            $pesi,
        );
    }

    #[Test]
    public function un_muscolo_ripetuto_conta_una_volta_sola(): void
    {
        // ⚠️ Se un dato sbagliato mettesse lo stesso muscolo fra primario e
        // secondari, non deve valere uno e mezzo.
        $e = Exercise::query()->create([
            'tenant_id' => $this->palestra->getKey(),
            'name' => 'Esercizio strano',
            'muscle_group' => MuscleGroup::Chest,
            'secondary_muscles' => ['chest', 'triceps'],
        ]);

        self::assertSame(['chest' => 1.0, 'triceps' => 0.5], $e->muscoliConPeso());
    }

    // ───────────────────────── l'API ─────────────────────────

    #[Test]
    public function l_app_puo_creare_un_esercizio_con_i_suoi_muscoli(): void
    {
        $this->comeApp($this->mario)
            ->postJson('/api/v1/exercises', [
                'name' => 'Spinte strane al cavo',
                'muscle_group' => 'chest',
                'secondary_muscles' => ['triceps', 'shoulders'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.muscle_group', 'chest')
            // ⚠️ **Qui l'ordine è quello che ha mandato l'app**, non quello
            // della fonte: si sta creando un esercizio nuovo, e quello che
            // arriva si scrive com'è arrivato.
            ->assertJsonPath('data.secondary_muscles', ['triceps', 'shoulders']);
    }

    #[Test]
    public function un_muscolo_inventato_viene_rifiutato(): void
    {
        $this->comeApp($this->mario)
            ->postJson('/api/v1/exercises', [
                'name' => 'Esercizio con muscolo finto',
                'secondary_muscles' => ['pettorine'],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function un_esercizio_gia_in_libreria_non_chiede_i_muscoli(): void
    {
        /*
         * 💡 **La guardia di A.3.5 scatta solo alla creazione.** Chiedere i
         * muscoli di una panca piana a chi ha appena scritto «Panca piana»
         * vorrebbe dire far compilare un campo la cui risposta viene buttata
         * via: il server i suoi muscoli li sa già, e non li sovrascrive.
         */
        $this->comeApp($this->mario)
            ->postJson('/api/v1/exercises', ['name' => 'Panca piana'])
            ->assertOk()
            ->assertJsonPath('data.secondary_muscles', ['shoulders', 'triceps']);
    }

    #[Test]
    public function ma_uno_nuovo_e_muto_non_si_crea(): void
    {
        /*
         * ⛔ **3b-A.3.5.** Fino al 24/08/2026 questa chiamata creava una riga
         * senza muscoli, e in staging se ne erano già accumulate **sette**.
         *
         * 🚨 Il 422 dice **cosa manca e per quale esercizio**: un errore che
         * non nomina la riga costringe a indovinare quale delle venti.
         */
        $this->comeApp($this->mario)
            ->postJson('/api/v1/exercises', ['name' => 'Esercizio senza muscoli'])
            ->assertStatus(422)
            ->assertJsonPath('error', 'muscoli_non_decisi')
            ->assertJsonPath('meta.exercise', 'Esercizio senza muscoli');

        self::assertDatabaseMissing('exercises', ['name' => 'Esercizio senza muscoli']);
    }

    #[Test]
    public function e_nemmeno_con_mezza_risposta(): void
    {
        // ⛔ Il primario da solo non basta: i secondari vanno dichiarati anche
        // quando la risposta è «nessuno». `[]` è una decisione, `null` no.
        $this->comeApp($this->mario)
            ->postJson('/api/v1/exercises', [
                'name' => 'Esercizio con mezza risposta',
                'muscle_group' => 'chest',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'muscoli_non_decisi');
    }

    #[Test]
    public function e_un_esercizio_che_isola_si_crea_con_l_elenco_vuoto(): void
    {
        // 💡 «Nessun secondario» **è** una risposta, e deve bastare.
        $this->comeApp($this->mario)
            ->postJson('/api/v1/exercises', [
                'name' => 'Isolamento strano',
                'muscle_group' => 'quads',
                'secondary_muscles' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.secondary_muscles', []);
    }
}
