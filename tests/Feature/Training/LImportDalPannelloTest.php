<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\AiFeature;
use App\Enums\MuscleGroup;
use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Filament\Gym\Resources\PlanImports\Pages\ListPlanImports;
use App\Filament\Gym\Resources\PlanImports\Pages\ReviewPlanImport;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\EditWorkoutPlan;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WorkoutPlan;
use App\Models\WorkoutPlanImport;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\Contracts\AiProvider;
use App\Services\Ai\Data\ParsedWorkoutPlan;
use App\Services\Ai\Exceptions\AiUnavailableException;
use App\Services\Ai\Prompts;
use App\Services\Ai\Providers\OpenAiProvider;
use App\Services\Training\ExerciseMatcher;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * F7 — l'import dal pannello, dal documento alla scheda nel builder.
 *
 * 📌 Il committente: *«il builder deve funzionare alla fine, e deve avere la
 * scheda vicino (visualizzatore pdf o immagine) per poterla confrontare con il
 * risultato dell'import»*.
 *
 * 💡 Quasi tutto quello che si prova qui l'ha trovato il **giro vero** del
 * 23/09/2026: nove schede (cinque fatte da noi, quattro prese dal web) lette
 * dal modello vero e pubblicate. I test non toccano la rete: fissano quello
 * che il giro ha insegnato.
 */
final class LImportDalPannelloTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private Tenant $beta;

    private User $gestore;

    private User $altroGestore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->beta = $this->creaPalestra('Beta', 'beta', 'BETA2345');
        $this->gestore = $this->creaUtente($this->alfa, UserRole::GymAdmin, 'capo@alfa.test');
        $this->altroGestore = $this->creaUtente($this->beta, UserRole::GymAdmin, 'capo@beta.test');

        filament()->setCurrentPanel('admin');
    }

    // ─────────────────────── F7, il disco privato ───────────────────────

    /**
     * 🚨 **La scheda di una persona non sta sul disco pubblico.**
     *
     * ⛔ Prima medialibrary la metteva su `public`, raggiungibile per URL con
     * id sequenziali, e il commento nel codice diceva il contrario.
     */
    #[Test]
    public function il_documento_importato_sta_sul_disco_privato(): void
    {
        $import = $this->importCon('scheda.pdf', '%PDF-1.4 finto');

        $this->assertSame('local', $import->documento()?->disk);
        $this->assertFalse($import->eUnaImmagine());
    }

    /** 📷 Una foto si accetta, e si riconosce come foto (per il visualizzatore). */
    #[Test]
    public function una_foto_si_accetta_e_si_riconosce(): void
    {
        $this->assertContains('image/png', WorkoutPlanImport::TIPI_ACCETTATI);
        $this->assertContains('application/pdf', WorkoutPlanImport::TIPI_ACCETTATI);

        $import = $this->importCon('scheda.png', self::PNG_1X1);

        $this->assertTrue($import->eUnaImmagine());
    }

    /** 🔒 Il documento si legge dalla rotta del pannello, e solo dalla palestra sua. */
    #[Test]
    public function il_documento_si_legge_solo_dalla_sua_palestra(): void
    {
        $import = $this->importCon('scheda di prova.pdf', '%PDF-1.4 finto');
        $url = route('filament.admin.documento-import', ['import' => $import->getKey()]);

        $this->entra($this->gestore);
        $risposta = $this->get($url);
        $risposta->assertOk();
        $this->assertStringStartsWith('inline;', (string) $risposta->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $risposta->headers->get('Cache-Control'));

        // ⛔ Il gestore di un'altra palestra non lo vede: 404, non 403, per
        // non dire nemmeno che esiste.
        $this->entra($this->altroGestore);
        $this->get($url)->assertNotFound();
    }

    /** 🚪 E senza login non si legge affatto. */
    #[Test]
    public function senza_login_il_documento_non_si_legge(): void
    {
        $import = $this->importCon('scheda.pdf', '%PDF-1.4 finto');

        $this->get(route('filament.admin.documento-import', ['import' => $import->getKey()]))
            ->assertRedirect();
    }

    // ─────────────────────── F7.4, le pagine si aprono ───────────────────────

    /**
     * 🚨 **La revisione si apre davvero, da un browser.**
     *
     * ⛔ Non si apriva mai: `public WorkoutPlanImport $record` faceva scattare
     * il binding implicito di Livewire, e `mount()` rifaceva `findOrFail()` con
     * l'oggetto intero come chiave → 404. Il test Livewire passava il record
     * a mano e non se ne accorgeva: qui si passa **dalla rotta**.
     */
    #[Test]
    public function la_revisione_si_apre_dalla_rotta(): void
    {
        $import = $this->importCon('scheda.pdf', '%PDF-1.4 finto');
        $import->storeDraft(ParsedWorkoutPlan::fromArray([
            'name' => 'Letta', 'exercises' => [$this->riga('Panca piana', 1)], 'confidence' => 0.9,
        ]), 'claude-sonnet-5');

        $this->entra($this->gestore);
        $this->get(ReviewPlanImport::getUrl(['record' => $import]))
            ->assertOk()
            ->assertSee('Il documento originale')
            ->assertSee('I giorni');

        // ⛔ E quella di un'altra palestra resta un 404.
        $this->entra($this->altroGestore);
        $this->get(ReviewPlanImport::getUrl(['record' => $import]))->assertNotFound();
    }

    /**
     * 🚨 **L'elenco degli import si apre.** Dava 500: `fn (ImportStatus $s)`
     * in una colonna, e Filament — che inietta per nome — provava a costruire
     * l'enum dal container.
     */
    #[Test]
    public function l_elenco_degli_import_si_apre(): void
    {
        $import = $this->importCon('scheda.pdf', '%PDF-1.4 finto');
        $import->storeDraft(ParsedWorkoutPlan::fromArray([
            'name' => 'Scheda di Giulia', 'exercises' => [$this->riga('Panca piana', 1)], 'confidence' => 0.9,
        ]), 'claude-sonnet-5');

        $this->entra($this->gestore);

        Livewire::test(ListPlanImports::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$import])
            ->assertSee('Scheda di Giulia')
            ->assertSee('Da rivedere');
    }

    // ─────────────────────── F7.4, i giorni ───────────────────────

    /**
     * 📅 **Una scheda di tre giorni resta di tre giorni.**
     *
     * 🚨 Il difetto vero trovato dal giro: tutti gli esercizi finivano in un
     * giorno solo, e la scheda A/B/C da diciannove esercizi diventava un
     * allenamento da diciannove esercizi.
     */
    #[Test]
    public function i_giorni_letti_arrivano_nella_scheda(): void
    {
        $import = $this->importCon('scheda.pdf', '%PDF-1.4 finto');

        $import->storeDraft(ParsedWorkoutPlan::fromArray([
            'name' => 'A/B/C',
            'day_names' => ['Giorno A - Petto', 'Giorno B - Dorso', ''],
            'exercises' => [
                $this->riga('Panca piana', 1),
                $this->riga('Croci ai cavi', 1),
                $this->riga('Trazioni', 2),
                $this->riga('Squat', 3),
            ],
            'confidence' => 0.9,
        ]), 'claude-sonnet-5');

        $piano = $this->runAs(fn () => $import->publishAsPlan(null, $this->gestore));
        $giorni = $piano->days()->orderBy('position')->withCount('exercises')->get();

        $this->assertSame(PlanStatus::Published, $piano->status);
        $this->assertSame(['Giorno A - Petto', 'Giorno B - Dorso', 'Giorno 3'], $giorni->pluck('name')->all());
        $this->assertSame([2, 1, 1], $giorni->pluck('exercises_count')->all());
    }

    /**
     * 💡 Un giorno saltato resta **vuoto**, non sparisce: chi apre il builder
     * vede che manca qualcosa invece di non accorgersene.
     */
    #[Test]
    public function un_giorno_saltato_resta_vuoto(): void
    {
        $import = $this->importCon('scheda.pdf', '%PDF-1.4 finto');

        $piano = $this->runAs(fn () => $import->publishAsPlan([
            $this->riga('Panca piana', 1),
            $this->riga('Squat', 3),
        ], $this->gestore));

        $this->assertSame([1, 0, 1], $piano->days()->orderBy('position')->withCount('exercises')->pluck('exercises_count')->all());
    }

    /** Un giorno solo senza nome resta senza nome, come lo scrive il builder. */
    #[Test]
    public function una_scheda_di_un_giorno_non_prende_un_nome_inventato(): void
    {
        $import = $this->importCon('scheda.pdf', '%PDF-1.4 finto');

        $piano = $this->runAs(fn () => $import->publishAsPlan([$this->riga('Panca piana', 1)], $this->gestore));

        $this->assertSame(1, $piano->days()->count());
        $this->assertNull($piano->days()->first()->name);
    }

    /** ✏️ La revisione corregge i giorni: nome e giorno della riga. */
    #[Test]
    public function la_revisione_corregge_i_giorni(): void
    {
        $import = $this->importCon('scheda.pdf', '%PDF-1.4 finto');

        $import->storeDraft(ParsedWorkoutPlan::fromArray([
            'name' => 'Letta',
            'day_names' => ['Lunedi', 'Giovedi'],
            'exercises' => [$this->riga('Panca piana', 1), $this->riga('Squat', 2)],
            'confidence' => 0.9,
        ]), 'claude-sonnet-5');

        $this->entra($this->gestore);

        $pagina = Livewire::test(ReviewPlanImport::class, ['record' => $import->getKey()])
            ->assertSuccessful()
            ->assertSee('I giorni')
            ->assertSee('Il documento originale');

        $stato = $pagina->get('data');
        $stato['day_names'] = array_combine(array_keys($stato['day_names']), [['nome' => 'Push'], ['nome' => 'Legs']]); // lo stato interno del repeater semplice
        $righe = array_keys($stato['exercises']);
        $stato['exercises'][$righe[1]]['day'] = 1;

        $pagina->set('data', $stato)->callAction('pubblica')->assertHasNoErrors();

        $piano = $this->runAs(fn () => WorkoutPlan::query()->findOrFail($import->refresh()->workout_plan_id));

        // Tutti e due gli esercizi sul giorno 1: resta un giorno solo, col nome corretto.
        $this->assertSame(['Push'], $piano->days()->pluck('name')->all());
        $this->assertSame(2, $piano->exercises()->count());
    }

    // ─────────────────── F7.2, il documento accanto al builder ───────────────────

    /** 📄 Il builder di una scheda importata mostra il documento; le altre no. */
    #[Test]
    public function il_builder_mostra_il_documento_solo_alle_schede_importate(): void
    {
        $import = $this->importCon('scheda.pdf', '%PDF-1.4 finto');
        $importata = $this->runAs(fn () => $import->publishAsPlan([$this->riga('Panca piana', 1)], $this->gestore));

        $scritta = $this->runAs(fn () => WorkoutPlan::create([
            'name' => 'Scritta a mano',
            'status' => PlanStatus::Published,
            'created_by' => $this->gestore->getKey(),
        ]));

        $this->entra($this->gestore);

        Livewire::test(EditWorkoutPlan::class, ['record' => $importata->getKey()])
            ->assertSuccessful()
            ->assertSee('Il documento originale')
            ->assertSee(route('filament.admin.documento-import', ['import' => $import->getKey()]), false);

        Livewire::test(EditWorkoutPlan::class, ['record' => $scritta->getKey()])
            ->assertSuccessful()
            ->assertDontSee('Il documento originale');
    }

    // ─────────────────── F7.4, la riconciliazione degli esercizi ───────────────────

    /**
     * 🚨 **Tre abbinamenti falsi trovati dal giro vero**, uno per riga.
     *
     * ⛔ Il contenimento guardava dentro il nome in qualunque punto: l'attrezzo
     * e la parola in fondo alla frase vincevano sull'esercizio.
     */
    #[Test]
    public function il_contenimento_guarda_la_testa_del_nome(): void
    {
        $corda = $this->globale('Corda', MuscleGroup::Cardio);
        $inclinata = $this->globale('Panca inclinata', MuscleGroup::Chest);
        $piana = $this->globale('Panca piana', MuscleGroup::Chest);
        $this->globale('Panca inclinata con manubri', MuscleGroup::Chest);

        $m = app(ExerciseMatcher::class);

        $tricipiti = $m->match('tricipiti ai cavi con la corda', $this->alfa->id, null, MuscleGroup::Triceps, ['forearms']);
        $this->assertNotSame($corda->id, $tricipiti->id, '«…con la corda» è finito sul salto con la corda.');

        $crunch = $m->match('CRUNCH INVERSI SU PANCA INCLINATA', $this->alfa->id, null, MuscleGroup::Abs, []);
        $this->assertNotSame($inclinata->id, $crunch->id, 'Un crunch è finito su «Panca inclinata».');

        $this->assertSame($piana->id, $m->match('PANCA', $this->alfa->id)->id, '«Panca» deve essere la meno specifica.');
        $this->assertSame($inclinata->id, $m->match('Panca inclinata manubri', $this->alfa->id)->id);
    }

    /**
     * 💪 Una somiglianza nel nome con un **altro muscolo** non basta.
     *
     * 📌 «Corda al cavo basso» (bicipiti) comincia con «corda».
     */
    #[Test]
    public function la_somiglianza_con_un_altro_muscolo_non_basta(): void
    {
        $corda = $this->globale('Corda', MuscleGroup::Cardio);
        $m = app(ExerciseMatcher::class);

        $curl = $m->match('Corda al cavo basso', $this->alfa->id, null, MuscleGroup::Biceps, ['forearms']);
        $this->assertNotSame($corda->id, $curl->id);

        // ⚠️ Ma senza muscolo dichiarato il contenimento vale ancora.
        $this->assertSame($corda->id, $m->match('Corda veloce', $this->alfa->id)->id);
    }

    /** 🔡 Un esercizio nuovo scritto in maiuscolo nasce con un nome normale. */
    #[Test]
    public function un_nome_tutto_maiuscolo_nasce_normale(): void
    {
        $m = app(ExerciseMatcher::class);

        $this->assertSame('Panca orizzontale', $m->match('PANCA ORIZZONTALE', $this->alfa->id, null, MuscleGroup::Chest, [])->name);
        $this->assertSame('Curl alla panca Scott', $m->match('Curl alla panca Scott', $this->alfa->id, null, MuscleGroup::Biceps, [])->name);
    }

    // ─────────────────── il resto trovato per strada ───────────────────

    /**
     * ⚖️ **I carichi in libbre si convertono.** Il giro vero ha letto «135 lb»
     * come 135 kg: il doppio, senza nessun errore. La regola sta nel prompt.
     */
    #[Test]
    public function il_prompt_chiede_i_chili(): void
    {
        $this->assertStringContainsString('CARICHI SEMPRE IN CHILOGRAMMI', Prompts::PDF_SYSTEM);
        $this->assertStringContainsString('0,4536', Prompts::PDF_SYSTEM);
    }

    /**
     * 🚨 **`OpenAiProvider` si carica.** La sua firma era rimasta indietro
     * rispetto all'interfaccia (K1), e PHP rifiutava la classe con un errore
     * fatale: `AI_DRIVER=openai` faceva cadere tutto invece di dire
     * «serve Anthropic».
     */
    #[Test]
    public function il_provider_openai_si_carica_e_rifiuta_chiaro(): void
    {
        $provider = app(OpenAiProvider::class);
        $this->assertInstanceOf(AiProvider::class, $provider);

        $this->expectException(AiUnavailableException::class);
        $provider->parseWorkoutPdf(['/non/esiste.pdf'], new AiCallContext($this->alfa->id, 0, AiFeature::PdfImport));
    }

    // ───────────────────────── aiuti ─────────────────────────

    /** Un PNG di un pixel, valido. */
    private const PNG_1X1 = "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR\x00\x00\x00\x01\x00\x00\x00\x01\x08\x06\x00\x00\x00\x1f\x15\xc4\x89\x00\x00\x00\rIDATx\x9cc\xf8\x0f\x00\x00\x01\x01\x00\x05\x18\xd8N\x00\x00\x00\x00IEND\xaeB`\x82";

    private function importCon(string $nome, string $contenuto): WorkoutPlanImport
    {
        return $this->runAs(function () use ($nome, $contenuto): WorkoutPlanImport {
            $import = WorkoutPlanImport::create(['uploaded_by' => $this->gestore->id, 'member_id' => null]);

            $tmp = tempnam(sys_get_temp_dir(), 'imp');
            file_put_contents($tmp, $contenuto);

            $import->addMedia($tmp)->usingFileName($nome)->toMediaCollection(WorkoutPlanImport::COLLECTION);

            return $import->refresh();
        });
    }

    /** @return array<string, mixed> */
    private function riga(string $nome, int $giorno): array
    {
        return [
            'name' => $nome, 'sets' => 3, 'reps' => '10', 'day' => $giorno,
            'muscle_group' => 'chest', 'secondary_muscles' => [], 'confidence' => 0.9,
        ];
    }

    private function globale(string $nome, MuscleGroup $muscolo): Exercise
    {
        return app(TenantContext::class)->runWithoutTenant(fn () => Exercise::create([
            'name' => $nome,
            'muscle_group' => $muscolo,
            'secondary_muscles' => [],
        ]));
    }

    private function runAs(callable $fn): mixed
    {
        return app(TenantContext::class)->runAs($this->alfa, $fn);
    }

    private function entra(User $utente): void
    {
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs($utente);

        app(TenantContext::class)->set($utente->tenant);
    }
}
