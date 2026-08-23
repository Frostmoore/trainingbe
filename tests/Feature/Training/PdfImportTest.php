<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\ImportStatus;
use App\Enums\MuscleGroup;
use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Exceptions\MuscoliNonDecisiException;
use App\Jobs\ParseWorkoutPdf;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlanImport;
use App\Services\Ai\AiManager;
use App\Services\Ai\Data\ParsedWorkoutPlan;
use App\Services\Ai\Exceptions\AiUnavailableException;
use App\Services\Training\ExerciseMatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * B7 — l'import delle schede da PDF.
 *
 * 🚨 Nessun test tocca la rete: il PDF e' un file finto e il modello e' il
 * doppio. Quello che si prova qui e' **nostro**: la riconciliazione dei nomi,
 * l'escalation, e soprattutto che niente arrivi a un iscritto senza revisione.
 */
class PdfImportTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $alfa;

    private User $trainer;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->trainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'anna@alfa.test');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

    // ───────────────────────── il riconoscitore ─────────────────────────

    /**
     * 🚨 Il caso per cui `ExerciseMatcher` esiste.
     *
     * Senza, «Panca piana», «panca piana bilanciere» e «Bench press»
     * diventerebbero tre righe diverse, e il progresso sulla panca risulterebbe
     * diviso su tre esercizi.
     */
    #[Test]
    public function it_recognises_the_same_exercise_written_in_different_ways(): void
    {
        $panca = $this->esercizioGlobale('Panca piana');

        $matcher = app(ExerciseMatcher::class);

        foreach (['Panca piana', 'panca  piana', 'PANCA PIANA!', 'Panca piana bilanciere', 'Bench press'] as $scritto) {
            $this->assertSame(
                $panca->id,
                $matcher->match($scritto, $this->alfa->id)->id,
                "«{$scritto}» non e' stato riconosciuto come «Panca piana».",
            );
        }
    }

    /** Un esercizio della palestra vince su uno globale con lo stesso nome. */
    #[Test]
    public function the_gym_own_exercise_wins_over_the_global_one(): void
    {
        $globale = $this->esercizioGlobale('Squat');

        $suo = $this->ctx()->runAs($this->alfa, fn () => Exercise::create(['name' => 'Squat']));

        $this->assertSame(
            $suo->id,
            app(ExerciseMatcher::class)->match('squat', $this->alfa->id)->id,
        );

        unset($globale);
    }

    /**
     * Un nome sconosciuto crea un esercizio **marcato**.
     *
     * `is_custom` e' cio' che permette di ritrovare a fine mese tutto quello che
     * l'import non ha riconosciuto, invece di lasciarlo sedimentare.
     *
     * 🆕 **E da 3b-A.3.5 nasce anche con i muscoli.** ⛔ Prima questa chiamata
     * scriveva una riga muta: `is_custom` diceva «guardami», ma nessuno
     * guardava, e in staging se n'erano accumulate sette.
     */
    #[Test]
    public function an_unknown_name_creates_a_flagged_exercise(): void
    {
        $nuovo = app(ExerciseMatcher::class)->match(
            'Pulley basso presa stretta',
            $this->alfa->id,
            null,
            MuscleGroup::Back,
            ['biceps'],
        );

        $this->assertTrue($nuovo->is_custom);
        $this->assertSame($this->alfa->id, $nuovo->tenant_id);
        $this->assertSame('pulley basso presa stretta', $nuovo->slug_normalized);
        $this->assertSame(['biceps'], $nuovo->secondary_muscles);
    }

    /**
     * ⛔ E senza muscoli **non nasce affatto** — 3b-A.3.5.
     *
     * 🚨 E' la stessa porta di sopra, guardata dall'altro lato: quello che
     * `is_custom` segnalava a posteriori adesso viene impedito prima.
     */
    #[Test]
    public function but_an_unknown_name_without_muscles_is_refused(): void
    {
        $this->expectException(MuscoliNonDecisiException::class);

        app(ExerciseMatcher::class)->match('Attrezzo che nessuno conosce', $this->alfa->id);
    }

    /** Non si riconosce due volte lo stesso nome creando due righe. */
    #[Test]
    public function the_same_unknown_name_is_created_only_once(): void
    {
        $matcher = app(ExerciseMatcher::class);

        // ⚠️ I muscoli servono solo al primo: al secondo giro l'esercizio
        // esiste gia', e la guardia di A.3.5 non si applica. E' anche il test
        // che dimostra che la regola non da' fastidio a chi non crea niente.
        $a = $matcher->match('Macchina strana', $this->alfa->id, null, MuscleGroup::Chest, []);
        $b = $matcher->match('macchina  strana', $this->alfa->id);

        $this->assertSame($a->id, $b->id);
    }

    // ───────────────────────── il job ─────────────────────────

    #[Test]
    public function it_produces_a_draft_to_review_and_not_a_plan(): void
    {
        $this->aiFinta();

        $import = $this->importConPdf();

        (new ParseWorkoutPdf($import->id))->handle(
            app(AiManager::class),
            app(TenantContext::class),
        );

        $import->refresh();

        $this->assertSame(ImportStatus::Review, $import->status);
        $this->assertNotNull($import->parsed_payload);
        $this->assertNull(
            $import->workout_plan_id,
            'Un import ha creato una scheda senza che nessuno l\'abbia guardata.',
        );
    }

    /**
     * 🚨 Sotto soglia si sale di modello **una volta sola**.
     *
     * La maggioranza dei PDF e' testo pulito e non ha bisogno del modello
     * migliore: pagarlo per tutti per coprire il caso difficile e' la
     * definizione di spreco. Ritentare all'infinito lo sarebbe di piu'.
     */
    #[Test]
    public function a_low_confidence_result_escalates_once(): void
    {
        $finta = $this->aiFinta()->willReturnPlan(ParsedWorkoutPlan::fromArray([
            'name' => 'Scheda illeggibile',
            'exercises' => [['name' => 'Qualcosa', 'confidence' => 0.2]],
            'confidence' => 0.2,
        ]));

        $import = $this->importConPdf();

        (new ParseWorkoutPdf($import->id))->handle(
            app(AiManager::class),
            app(TenantContext::class),
        );

        $import->refresh();

        $this->assertTrue($import->escalated, 'Sotto soglia non e\' scattata l\'escalation.');
        $this->assertSame(
            2,
            count(array_filter($finta->calls, static fn (array $c): bool => $c['method'] === 'parseWorkoutPdf')),
            'Il modello e\' stato chiamato piu\' di due volte: l\'escalation deve fermarsi al secondo tentativo.',
        );
    }

    #[Test]
    public function a_pdf_that_cannot_be_read_fails_cleanly(): void
    {
        $this->aiFinta()->willThrow(new AiUnavailableException);

        $import = $this->importConPdf();

        (new ParseWorkoutPdf($import->id))->handle(
            app(AiManager::class),
            app(TenantContext::class),
        );

        $import->refresh();

        $this->assertSame(ImportStatus::Failed, $import->status);
        $this->assertNotNull($import->error);
        $this->assertNull($import->workout_plan_id);
    }

    // ───────────────────────── la pubblicazione ─────────────────────────

    #[Test]
    public function publishing_the_draft_creates_a_draft_plan_with_reconciled_exercises(): void
    {
        $panca = $this->esercizioGlobale('Panca piana');

        $import = $this->importConPdf();

        $import->storeDraft(ParsedWorkoutPlan::fromArray([
            'name' => 'Scheda A',
            'exercises' => [
                ['name' => 'Bench press', 'sets' => 4, 'reps' => '8-12', 'rest_sec' => 90, 'confidence' => 0.9],
                // 🆕 3b-A.3.5: la riga che crea un esercizio nuovo porta i
                // muscoli, che dal 24/08/2026 il modello legge insieme al resto.
                [
                    'name' => 'Esercizio mai visto', 'sets' => 3, 'reps' => '10',
                    'muscle_group' => 'back', 'secondary_muscles' => ['biceps'],
                    'confidence' => 0.6,
                ],
            ],
            'confidence' => 0.8,
        ]), 'claude-sonnet-5');

        $piano = $this->ctx()->runAs($this->alfa,
            fn () => $import->publishAsPlan(null, $this->trainer));

        $this->assertSame(PlanStatus::Draft, $piano->status, 'La scheda importata e\' nata pubblicata.');
        $this->assertSame(PlanSource::PdfImport, $piano->source);
        $this->assertSame($this->iscritto->id, $piano->member_id);
        $this->assertSame(2, $piano->exercises()->count());

        // Il primo e' stato riconciliato sul globale, il secondo creato custom.
        $righe = $piano->exercises()->with('exercise')->get();

        $this->assertSame($panca->id, $righe[0]->exercise_id);
        $this->assertSame('8-12', $righe[0]->reps);
        $this->assertTrue($righe[1]->exercise->is_custom);

        $this->assertSame(ImportStatus::Done, $import->refresh()->status);
        $this->assertSame($piano->id, $import->workout_plan_id);
    }

    /** Le correzioni fatte a mano nella revisione vincono sulla bozza. */
    #[Test]
    public function the_manual_corrections_win_over_what_the_model_read(): void
    {
        $import = $this->importConPdf();

        $import->storeDraft(ParsedWorkoutPlan::fromArray([
            'name' => 'Scheda',
            'exercises' => [['name' => 'Sbagliato', 'sets' => 1, 'confidence' => 0.3]],
            'confidence' => 0.3,
        ]), 'claude-sonnet-5');

        $piano = $this->ctx()->runAs($this->alfa, fn () => $import->publishAsPlan([
            [
                'name' => 'Panca piana', 'sets' => 4, 'reps' => '10',
                // 🆕 3b-A.3.5 — «Panca piana» qui non e' in libreria (questo
                // test non semina il catalogo), quindi la riga la crea, quindi
                // i muscoli servono. E' esattamente quello che vede chi
                // corregge a mano nella pagina di revisione.
                'muscle_group' => 'chest', 'secondary_muscles' => ['triceps'],
            ],
        ], $this->trainer));

        $this->assertSame(1, $piano->exercises()->count());
        $this->assertSame(4, $piano->exercises()->first()->sets);
        $this->assertSame('Panca piana', $piano->exercises()->first()->exercise->name);
    }

    // ───────────────────────── aiuti ─────────────────────────

    private function esercizioGlobale(string $nome): Exercise
    {
        return $this->ctx()->runWithoutTenant(fn () => Exercise::create(['name' => $nome]));
    }

    /**
     * Un import con un file allegato.
     *
     * Il contenuto non conta: il modello e' finto e non lo legge. Conta che il
     * percorso esista, perche' il job controlla proprio quello.
     */
    private function importConPdf(): WorkoutPlanImport
    {
        return $this->ctx()->runAs($this->alfa, function (): WorkoutPlanImport {
            $import = WorkoutPlanImport::create([
                'uploaded_by' => $this->trainer->id,
                'member_id' => $this->iscritto->id,
            ]);

            $tmp = tempnam(sys_get_temp_dir(), 'pdf').'.pdf';
            file_put_contents($tmp, '%PDF-1.4 finto');

            $import->addMedia($tmp)->usingFileName('scheda.pdf')
                ->toMediaCollection(WorkoutPlanImport::COLLECTION);

            return $import->refresh();
        });
    }
}
