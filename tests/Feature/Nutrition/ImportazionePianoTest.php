<?php

declare(strict_types=1);

namespace Tests\Feature\Nutrition;

use App\Enums\AiFeature;
use App\Enums\UserRole;
use App\Jobs\TrascriviPianoAlimentare;
use App\Models\ImportazionePiano;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * L'importazione di un piano alimentare da PDF — N20.
 *
 * ── 🚨 Cosa difendono questi test ──────────────────────────────────────────
 *
 * Tre cose, in ordine di gravita':
 *
 *   1. **Il piano e' della persona e di nessun altro.** Nessun trainer, nessuna
 *      palestra, nessun amministratore deve poter leggere una di queste righe.
 *      A chi non e' il proprietario si risponde 404 e non 403, perche' su un
 *      piano alimentare anche solo l'esistenza dice qualcosa sulla salute.
 *   2. **La dichiarazione non e' facoltativa.** Chi importa dichiara che il
 *      piano l'ha redatto un professionista abilitato: noi non elaboriamo
 *      niente, ricopiamo un documento che qualcun altro ha firmato.
 *   3. **Cinquanta gettoni si scalano solo se la trascrizione riesce**, e mai
 *      due volte.
 */
class ImportazionePianoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $palestra;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'anna@olimpo.it');
    }

    private function unPdf(string $nome = 'piano.pdf'): UploadedFile
    {
        return UploadedFile::fake()->create($nome, 40, 'application/pdf');
    }

    #[Test]
    public function carica_il_pdf_e_mette_in_coda_la_trascrizione(): void
    {
        Queue::fake();

        $risposta = $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni-piani', [
            'file' => $this->unPdf(),
            'dichiarazione' => true,
        ]);

        $risposta->assertStatus(202)
            ->assertJsonPath('data.stato', ImportazionePiano::IN_CODA)
            ->assertJsonPath('data.nome_file', 'piano.pdf');

        Queue::assertPushed(TrascriviPianoAlimentare::class);

        $riga = ImportazionePiano::withoutGlobalScopes()->firstOrFail();

        $this->assertSame((int) $this->iscritto->id, (int) $riga->user_id);
        $this->assertNotNull($riga->dichiarato_il, 'La dichiarazione va registrata con la data.');
        Storage::disk('local')->assertExists($riga->percorso());
    }

    /**
     * 🚨 `accepted` e non `boolean`: deve arrivare **vera**, non presente.
     */
    #[Test]
    public function senza_dichiarazione_non_si_importa(): void
    {
        Queue::fake();

        foreach ([['file' => $this->unPdf()], ['file' => $this->unPdf(), 'dichiarazione' => false]] as $corpo) {
            $this->actingAs($this->iscritto)
                ->postJson('/api/v1/importazioni-piani', $corpo)
                ->assertStatus(422)
                ->assertJsonValidationErrors('dichiarazione');
        }

        $this->assertSame(0, ImportazionePiano::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    #[Test]
    public function solo_i_pdf_passano(): void
    {
        Queue::fake();

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/importazioni-piani', [
                'file' => UploadedFile::fake()->create('piano.jpg', 40, 'image/jpeg'),
                'dichiarazione' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    /**
     * 🚨 **Il test che conta**: il piano e' suo e di nessun altro.
     *
     * ⚠️ 404 e non 403. Un 403 direbbe *«quell'importazione esiste, ma non e'
     * tua»* — e su un piano alimentare l'esistenza e' gia' un'informazione di
     * salute.
     */
    #[Test]
    public function nessun_altro_vede_l_importazione_nemmeno_il_trainer(): void
    {
        Queue::fake();

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni-piani', [
            'file' => $this->unPdf(),
            'dichiarazione' => true,
        ])->assertStatus(202);

        $riga = ImportazionePiano::withoutGlobalScopes()->firstOrFail();

        $estranei = [
            'trainer' => $this->creaUtente($this->palestra, UserRole::Trainer, 'coach@olimpo.it'),
            'palestra' => $this->creaUtente($this->palestra, UserRole::GymAdmin, 'capo@olimpo.it'),
            'compagno' => $this->creaUtente($this->palestra, UserRole::Member, 'bruno@olimpo.it'),
        ];

        foreach ($estranei as $chi => $utente) {
            foreach (['', '/pdf'] as $coda) {
                $this->actingAs($utente)
                    ->getJson('/api/v1/importazioni-piani/'.$riga->id.$coda)
                    ->assertStatus(404, "«{$chi}» non deve nemmeno sapere che esiste.");
            }

            $this->actingAs($utente)
                ->deleteJson('/api/v1/importazioni-piani/'.$riga->id)
                ->assertStatus(404);
        }

        // E la riga e' ancora li': nessuno l'ha buttata per conto suo.
        $this->assertNotNull(ImportazionePiano::withoutGlobalScopes()->find($riga->id));
    }

    #[Test]
    public function il_job_trascrive_e_l_originale_resta_consultabile(): void
    {
        $this->aiFinta();

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni-piani', [
            'file' => $this->unPdf(),
            'dichiarazione' => true,
        ])->assertStatus(202);

        $riga = ImportazionePiano::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(ImportazionePiano::PRONTA, $riga->refresh()->stato);
        $this->assertSame('Piano importato', $riga->bozza['nome']);

        /*
         * 🚨 I **dubbi** arrivano fino all'app: sono la parte piu' utile della
         * risposta, perche' portano chi controlla dritto sulle righe che
         * contano. Se si perdessero per strada, la revisione diventerebbe un
         * elenco di trenta voci tutte uguali.
         */
        $this->assertNotEmpty($riga->bozza['dubbi']);

        $risposta = $this->actingAs($this->iscritto)
            ->getJson('/api/v1/importazioni-piani/'.$riga->id)
            ->assertOk();

        $risposta->assertJsonPath('data.stato', ImportazionePiano::PRONTA);
        $risposta->assertJsonPath('data.righe', 2);

        // ⚠️ N20.4: l'originale si deve poter guardare accanto alla bozza.
        $this->actingAs($this->iscritto)
            ->get('/api/v1/importazioni-piani/'.$riga->id.'/pdf')
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    /**
     * 🚨 Fallita = non pagata.
     *
     * ⚠️ Far pagare 50 gettoni per un guasto del fornitore vuol dire far pagare
     * al cliente un problema nostro.
     */
    #[Test]
    public function se_la_trascrizione_fallisce_l_importazione_lo_dice(): void
    {
        $finta = $this->aiFinta();
        $finta->prossimoErrore = new \RuntimeException('Il fornitore ha detto no.');

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni-piani', [
            'file' => $this->unPdf(),
            'dichiarazione' => true,
        ])->assertStatus(202);

        $riga = ImportazionePiano::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(ImportazionePiano::FALLITA, $riga->stato);
        $this->assertStringContainsString('fornitore', (string) $riga->errore);
        $this->assertNull($riga->bozza);
    }

    /**
     * 💡 Chiudere significa **buttare**, che si sia confermato o scartato: il
     * piano confermato se lo porta via il telefono e non resta qui.
     */
    #[Test]
    public function chiudere_l_importazione_porta_via_anche_il_pdf(): void
    {
        Queue::fake();

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni-piani', [
            'file' => $this->unPdf(),
            'dichiarazione' => true,
        ])->assertStatus(202);

        $riga = ImportazionePiano::withoutGlobalScopes()->firstOrFail();
        $percorso = $riga->percorso();

        $this->actingAs($this->iscritto)
            ->deleteJson('/api/v1/importazioni-piani/'.$riga->id)
            ->assertOk()
            ->assertJsonPath('data.chiusa', true);

        $this->assertNull(ImportazionePiano::withoutGlobalScopes()->find($riga->id));
        Storage::disk('local')->assertMissing($percorso);
    }

    /**
     * 🚨 Un'importazione scaduta non si consegna, nemmeno al proprietario e
     * nemmeno se il comando notturno non e' ancora passato.
     */
    #[Test]
    public function un_importazione_scaduta_non_esiste_piu(): void
    {
        Queue::fake();

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni-piani', [
            'file' => $this->unPdf(),
            'dichiarazione' => true,
        ])->assertStatus(202);

        $riga = ImportazionePiano::withoutGlobalScopes()->firstOrFail();
        $riga->forceFill(['scade_il' => now()->subMinute()])->save();

        $this->actingAs($this->iscritto)
            ->getJson('/api/v1/importazioni-piani/'.$riga->id)
            ->assertStatus(404);

        $this->artisan('piani:pota-importazioni')->assertSuccessful();

        $this->assertNull(ImportazionePiano::withoutGlobalScopes()->find($riga->id));
        Storage::disk('local')->assertMissing($riga->percorso());
    }

    /**
     * 🚨 Cinquanta, e non sette come una foto.
     *
     * ⚠️ Il costo sta scritto una volta sola (`AiFeature::costoInGettoni()`) e
     * questo test lo tiene fermo: e' un numero che qualcuno cambierebbe senza
     * accorgersi di aver toccato il listino.
     */
    #[Test]
    public function costa_cinquanta_gettoni(): void
    {
        $this->assertSame(50, AiFeature::NutritionPdfImport->costoInGettoni());
        $this->assertSame(10, AiFeature::FoodPhoto->costoInGettoni());
        $this->assertSame(1, AiFeature::FoodText->costoInGettoni());
    }
}
