<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Console\Commands\FondiGliEsercizi;
use App\Enums\MuscleGroup;
use App\Enums\UserRole;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use Database\Seeders\ExerciseLibrarySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Fondere due esercizi senza perdere lo storico — 3b-O, 28/08/2026.
 *
 * 📌 *«vorrei che facessi in modo che gli esercizi che ho io nelle schede siano
 * quelli che abbiamo nel database. Senza farmi perdere nulla, naturalmente»*.
 *
 * ══ 🚨 IL «SENZA PERDERE NULLA» E' TUTTO IN UNA RIGA ══════════════════════
 *
 * ⛔ La cosa ovvia, dopo aver ripuntato le schede, sarebbe **cancellare**
 * l'esercizio vecchio. E sarebbe il modo esatto di perdere quello che il
 * committente ha chiesto di non perdere: lo storico degli allenamenti sta sul
 * telefono e usa **l'id vecchio**.
 *
 * 🚨 Il test `il_vecchio_non_si_cancella_mai` e' quello che tiene in piedi la
 * promessa. Se un giorno qualcuno «pulisce» aggiungendo un `delete()`, quella
 * riga diventa rossa — e senza di lei nessuno se ne accorgerebbe, perche' sul
 * server non si romperebbe niente.
 */
final class FondereSenzaPerdereTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $mario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(ExerciseLibrarySeeder::class);

        $this->palestra = $this->creaPalestra();
        $this->mario = $this->creaUtente($this->palestra, UserRole::Member, 'mario@demo.test');
    }

    /** Un esercizio scritto a mano nella palestra, col nome vecchio. */
    private function suo(string $nome): Exercise
    {
        return Exercise::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->getKey(),
            'name' => $nome,
            'slug_normalized' => Exercise::normalize($nome),
            'is_custom' => true,
            'muscle_group' => MuscleGroup::Chest,
            'secondary_muscles' => [],
        ]);
    }

    private function diPiattaforma(string $nome): Exercise
    {
        return Exercise::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('slug_normalized', Exercise::normalize($nome))
            ->firstOrFail();
    }

    /** Una riga di scheda che punta a quell'esercizio. */
    private function mettiInScheda(Exercise $esercizio): int
    {
        $piano = WorkoutPlan::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->getKey(),
            'member_id' => $this->mario->getKey(),
            'created_by' => $this->mario->getKey(),
            'name' => 'La mia scheda',
        ]);

        $giorno = $piano->days()->create(['position' => 0, 'name' => 'A']);

        return (int) $piano->exercises()->create([
            'workout_plan_day_id' => $giorno->getKey(),
            'exercise_id' => $esercizio->getKey(),
            'position' => 0,
        ])->getKey();
    }

    private function fondi(bool $applica = true): void
    {
        $this->artisan(FondiGliEsercizi::class, array_filter([
            '--tenant' => (string) $this->palestra->getKey(),
            '--applica' => $applica ?: null,
        ]))->assertSuccessful();
    }

    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function la_scheda_passa_all_esercizio_di_piattaforma(): void
    {
        $vecchio = $this->suo('Panca Piana (Bilanciere)');
        $riga = $this->mettiInScheda($vecchio);

        $this->fondi();

        self::assertSame(
            $this->diPiattaforma('Panca piana')->getKey(),
            (int) DB::table('plan_exercises')->where('id', $riga)->value('exercise_id'),
        );
    }

    /**
     * ⚠️ **L'attrezzo non si butta via.** L'automatico proponeva
     * `Panca Piana (Manubrio)` → `Panca piana`, cioe' quella col bilanciere.
     */
    #[Test]
    public function i_manubri_non_diventano_un_bilanciere(): void
    {
        $vecchio = $this->suo('Panca Piana (Manubrio)');
        $riga = $this->mettiInScheda($vecchio);

        $this->fondi();

        self::assertSame(
            $this->diPiattaforma('Panca piana con manubri')->getKey(),
            (int) DB::table('plan_exercises')->where('id', $riga)->value('exercise_id'),
        );
    }

    /**
     * 🚨 **Il difetto gia' visto una volta**: «Rematore corda» finiva su
     * «Corda», cioe' il saltare la corda — polpacci al posto della schiena.
     * 📌 Il committente: *«due corde appese al soffitto… mi traggo su con
     * quelle utilizzando i muscoli della schiena»*.
     */
    #[Test]
    public function il_rematore_alle_corde_non_diventa_saltare_la_corda(): void
    {
        $vecchio = $this->suo('Rematore corda');
        $riga = $this->mettiInScheda($vecchio);

        $this->fondi();

        $finito = (int) DB::table('plan_exercises')->where('id', $riga)->value('exercise_id');

        self::assertSame($this->diPiattaforma('Trazioni orizzontali')->getKey(), $finito);
        self::assertNotSame($this->diPiattaforma('Corda')->getKey(), $finito);
    }

    /**
     * ⚖️ 🚨 **La riga che tiene in piedi «senza perdere nulla».**
     */
    #[Test]
    public function il_vecchio_non_si_cancella_mai(): void
    {
        $vecchio = $this->suo('Panca Piana (Bilanciere)');
        $this->mettiInScheda($vecchio);

        $this->fondi();

        $riletto = Exercise::withoutGlobalScopes()->withTrashed()->find($vecchio->getKey());

        self::assertNotNull(
            $riletto,
            'L\'esercizio vecchio è sparito: lo storico sul telefono non ha più '
            .'nessun modo di ritrovare le proprie serie.'
        );
        self::assertNull($riletto->deleted_at);
        self::assertSame(
            $this->diPiattaforma('Panca piana')->getKey(),
            (int) $riletto->sostituito_da_id,
        );
    }

    #[Test]
    public function senza_applica_non_scrive_niente(): void
    {
        $vecchio = $this->suo('Panca Piana (Bilanciere)');
        $riga = $this->mettiInScheda($vecchio);

        $this->fondi(applica: false);

        self::assertSame(
            $vecchio->getKey(),
            (int) DB::table('plan_exercises')->where('id', $riga)->value('exercise_id'),
        );
        self::assertNull($vecchio->fresh()->sostituito_da_id);
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Quello che l'app riceve
    // ═══════════════════════════════════════════════════════════════════

    #[Test]
    public function il_catalogo_non_mostra_piu_l_esercizio_fuso(): void
    {
        $vecchio = $this->suo('Panca Piana (Bilanciere)');
        $this->mettiInScheda($vecchio);
        $this->fondi();

        $nomi = array_column(
            $this->comeApp($this->mario)->getJson('/api/v1/exercises?limit=1000')
                ->assertOk()->json('data'),
            'name'
        );

        self::assertNotContains('Panca Piana (Bilanciere)', $nomi);
        self::assertContains('Panca piana', $nomi);
    }

    /**
     * 🚨 **Senza questo elenco il telefono perde lo storico.** Le serie gia'
     * registrate usano l'id vecchio: e' l'unica cosa che dice all'app dove
     * sono finite.
     */
    #[Test]
    public function il_rinvio_arriva_all_app(): void
    {
        $vecchio = $this->suo('Panca Piana (Bilanciere)');
        $this->mettiInScheda($vecchio);
        $this->fondi();

        $rinvii = $this->comeApp($this->mario)
            ->getJson('/api/v1/exercises?limit=1000')
            ->assertOk()
            ->json('riconciliazioni');

        self::assertSame([[
            'da' => $vecchio->getKey(),
            'a' => $this->diPiattaforma('Panca piana')->getKey(),
        ]], $rinvii);
    }

    /**
     * ⛔ Al primo giro qui c'era un `withoutGlobalScopes()`, e mandava a ogni
     * telefono i rinvii di **tutte** le palestre.
     */
    #[Test]
    public function i_rinvii_di_un_altra_palestra_non_si_vedono(): void
    {
        $altra = $this->creaPalestra('Altra', 'altra', 'ALTRA234');
        $suoVecchio = Exercise::withoutGlobalScopes()->create([
            'tenant_id' => $altra->getKey(),
            'name' => 'Panca Piana (Bilanciere)',
            'slug_normalized' => Exercise::normalize('Panca Piana (Bilanciere)'),
            'is_custom' => true,
            'muscle_group' => MuscleGroup::Chest,
            'secondary_muscles' => [],
            'sostituito_da_id' => $this->diPiattaforma('Panca piana')->getKey(),
        ]);

        $rinvii = $this->comeApp($this->mario)
            ->getJson('/api/v1/exercises?limit=1000')
            ->assertOk()
            ->json('riconciliazioni');

        self::assertSame([], $rinvii, 'Sono usciti i rinvii di un\'altra palestra.');
        self::assertNotNull($suoVecchio->fresh(), 'La riga dell\'altra palestra non doveva sparire.');
    }
}
