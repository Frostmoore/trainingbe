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

        /*
         * ── 🚨 I gettoni servono DAVVERO, da U.6 (28/08/2026) ──────────────
         *
         * 📌 *«devono essere proprio GETTONI, non si puo' usare la quota flat»*.
         *
         * ⛔ **Prima di oggi questa riga non c'era, e sei test passavano lo
         * stesso**: l'importazione ricadeva sulla quota inclusa, che nei test
         * e' sempre intatta. Il commento in testa a questa classe diceva
         * «cinquanta gettoni si scalano solo se la trascrizione riesce», ma la
         * strada dei gettoni non veniva percorsa **mai**.
         *
         * 💡 E' il difetto tipico di qui: nessun errore, tutto verde, e una
         * regola commerciale che nella realta' passava da un'altra parte. Il
         * fatto che togliendo questa riga sei test diventino 402 e' adesso la
         * prova che la regola nuova e' viva.
         */
        $this->palestra->forceFill(['ai_credits' => 500])->save();
        $this->iscritto->refresh()->load('tenant');

        /*
         * ── 🔴 E il CONSENSO, che prima del 03/09/2026 non serviva ─────────
         *
         * ⛔ `POST importazioni-piani` manda un PDF ad Anthropic e **non aveva
         * `ai.consent`**: dal 19/08 chi non aveva mai acconsentito — o chi
         * aveva revocato — poteva vedersi trasferire il proprio piano
         * alimentare negli Stati Uniti.
         *
         * 🚨 **E tutti i test di questo file giravano senza consenso, verdi.**
         * E' la prova che il buco c'era: nessuno di loro ha mai dovuto darlo,
         * perche' nessuno glielo chiedeva.
         *
         * 💡 Adesso serve, e `senza_consenso_non_si_carica_niente()` sta qui
         * sotto a difenderlo.
         */
        $this->iscritto->accendiLAi();
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
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
        ]);

        $risposta->assertStatus(202)
            ->assertJsonPath('data.stato', ImportazionePiano::IN_CODA)
            ->assertJsonPath('data.nome_file', 'piano.pdf');

        Queue::assertPushed(TrascriviPianoAlimentare::class);

        $riga = ImportazionePiano::withoutGlobalScopes()->firstOrFail();

        $this->assertSame((int) $this->iscritto->id, (int) $riga->user_id);
        $this->assertNotNull($riga->dichiarato_il, 'La dichiarazione va registrata con la data.');
        // 💡 Uno per documento: da K1 possono essere fino a cinque.
        foreach ($riga->percorsi() as $percorso) {
            Storage::disk('local')->assertExists($percorso);
        }
    }

    /**
     * 🚨 `accepted` e non `boolean`: deve arrivare **vera**, non presente.
     */
    #[Test]
    public function senza_dichiarazione_non_si_importa(): void
    {
        Queue::fake();

        foreach ([['file' => [$this->unPdf()]], ['file' => [$this->unPdf()], 'dichiarazione' => false]] as $corpo) {
            $this->actingAs($this->iscritto)
                ->postJson('/api/v1/importazioni-piani', $corpo)
                ->assertStatus(422)
                ->assertJsonValidationErrors('dichiarazione');
        }

        $this->assertSame(0, ImportazionePiano::withoutGlobalScopes()->count());
        Queue::assertNothingPushed();
    }

    /**
     * 🆕 **Anche le fotografie passano** — K1, 03/09/2026.
     *
     * ⛔ Questo test si chiamava `solo_i_pdf_passano` e provava che un `.jpg`
     * prendesse un 422. 📌 Il committente: *«deve funzionare anche con le
     * immagini»*.
     *
     * 💡 Quello che resta vero e' il **tipo dichiarato**: l'importazione sa di
     * essere «da immagini», ed e' cio' che fa comparire l'avvertenza giusta —
     * *«l'analisi delle immagini e' generalmente meno accurata di quella dei
     * PDF»*.
     */
    #[Test]
    public function anche_le_fotografie_passano(): void
    {
        Queue::fake();

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/importazioni-piani', [
                'file' => [UploadedFile::fake()->image('pagina.jpg')],
                'dichiarazione' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.tipo', ImportazionePiano::TIPO_IMMAGINI);
    }

    /**
     * ⛔ **`heic` no**, e non e' una dimenticanza.
     *
     * 🚨 Anthropic non lo accetta: lasciarlo passare darebbe un rifiuto del
     * fornitore che a chi guarda arriva come *«l'AI non e' disponibile»*, cioe'
     * un guasto nostro per un file che non abbiamo mai potuto leggere.
     * 💡 Il telefono lo converte prima di caricare.
     */
    #[Test]
    public function un_formato_che_il_modello_non_legge_si_rifiuta_subito(): void
    {
        Queue::fake();

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/importazioni-piani', [
                'file' => [UploadedFile::fake()->create('pagina.heic', 40, 'image/heic')],
                'dichiarazione' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file.0');
    }

    /**
     * 🚨 **Piu' pagine in un'importazione sola, nell'ordine in cui arrivano.**
     *
     * 📌 Una scheda su carta sono spesso due o tre pagine fotografate: ⛔
     * accettarne una sola vorrebbe dire che chi ne fotografa una **perde il
     * resto senza accorgersene** — la bozza esce con meta' scheda, plausibile e
     * incompleta.
     *
     * ⚠️ E l'ordine e' l'informazione principale: la seconda pagina letta per
     * prima da' una scheda che comincia da meta'.
     */
    #[Test]
    public function piu_pagine_stanno_in_una_importazione_sola(): void
    {
        Queue::fake();

        $risposta = $this->actingAs($this->iscritto)
            ->postJson('/api/v1/importazioni-piani', [
                'file' => [
                    UploadedFile::fake()->image('uno.jpg'),
                    UploadedFile::fake()->image('due.jpg'),
                    UploadedFile::fake()->image('tre.jpg'),
                ],
                'dichiarazione' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.quanti_documenti', 3);

        $riga = ImportazionePiano::query()->findOrFail($risposta->json('data.id'));

        $this->assertSame(
            ['uno.jpg', 'due.jpg', 'tre.jpg'],
            array_column($riga->documenti, 'nome'),
            'L\'ordine delle pagine non e\' stato conservato.',
        );

        // 💡 E il nome mostrato non e' quello del primo file: direbbe che e' tutto li'.
        $this->assertSame('3 immagini', $riga->nome_file);
    }

    /**
     * ⛔ **Oltre cinque non si accettano.**
     *
     * 💡 Ogni pagina in piu' e' un'immagine intera dentro un prompt pagato a
     * token, e oltre le cinque pagine il documento giusto da caricare e' un PDF.
     */
    #[Test]
    public function oltre_cinque_documenti_si_rifiuta(): void
    {
        Queue::fake();

        $troppe = [];

        for ($i = 0; $i < ImportazionePiano::AL_MASSIMO + 1; $i++) {
            $troppe[] = UploadedFile::fake()->image("pagina{$i}.jpg");
        }

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/importazioni-piani', [
                'file' => $troppe,
                'dichiarazione' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, ImportazionePiano::query()->count());
    }

    /**
     * 🚨 **Un PDF fra le fotografie fa dell'importazione una «da PDF».**
     *
     * 💡 Il tipo serve a decidere quale avvertenza mostrare, e quella sulle
     * immagini — *«generalmente meno accurata»* — ha senso solo quando **tutto**
     * e' una fotografia.
     */
    #[Test]
    public function basta_un_pdf_perche_non_sia_un_import_da_foto(): void
    {
        Queue::fake();

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/importazioni-piani', [
                'file' => [UploadedFile::fake()->image('pagina.jpg'), $this->unPdf()],
                'dichiarazione' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.tipo', ImportazionePiano::TIPO_PDF);
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
            'file' => [$this->unPdf()],
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
            'file' => [$this->unPdf()],
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
            'file' => [$this->unPdf()],
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
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
        ])->assertStatus(202);

        $riga = ImportazionePiano::withoutGlobalScopes()->firstOrFail();
        $percorsi = $riga->percorsi();

        $this->actingAs($this->iscritto)
            ->deleteJson('/api/v1/importazioni-piani/'.$riga->id)
            ->assertOk()
            ->assertJsonPath('data.chiusa', true);

        $this->assertNull(ImportazionePiano::withoutGlobalScopes()->find($riga->id));

        foreach ($percorsi as $percorso) {
            Storage::disk('local')->assertMissing($percorso);
        }
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
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
        ])->assertStatus(202);

        $riga = ImportazionePiano::withoutGlobalScopes()->firstOrFail();
        $riga->forceFill(['scade_il' => now()->subMinute()])->save();

        $this->actingAs($this->iscritto)
            ->getJson('/api/v1/importazioni-piani/'.$riga->id)
            ->assertStatus(404);

        $this->artisan('piani:pota-importazioni')->assertSuccessful();

        $this->assertNull(ImportazionePiano::withoutGlobalScopes()->find($riga->id));
        foreach ($riga->percorsi() as $percorso) {
            Storage::disk('local')->assertMissing($percorso);
        }
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

    /**
     * 🔴 **Senza consenso non si carica niente** — buco chiuso il 03/09/2026.
     *
     * ══ 🚨 IL DIFETTO, E PERCHE' NESSUNO SE N'ERA ACCORTO ═════════════════
     *
     * La rotta aveva `throttle:importazioni-piani` e basta. ⛔ Mandava un PDF ad
     * **Anthropic** — un piano alimentare, cioe' art. 9 — senza che nessuno
     * avesse mai chiesto il consenso a chi lo caricava.
     *
     * 💡 Ed e' **parola per parola** il difetto che il docblock di
     * `RequireAiConsent` descrive: *«deve valere su ogni rotta AI, comprese
     * quelle che non esistono ancora. Il prossimo endpoint AI che qualcuno
     * aggiungera' partira' scoperto, e non se ne accorgera' nessuno — la
     * chiamata funzionerebbe benissimo»*. E' successo con N20, il 19/08.
     *
     * ⚠️ **E vale anche per la revoca**, che e' il caso piu' importante: chi
     * toglie il consenso non deve poter mandare altro. Un consenso che non si
     * puo' ritirare non e' un consenso.
     */
    #[Test]
    public function senza_consenso_non_si_carica_niente(): void
    {
        $senzaConsenso = $this->creaUtente(
            $this->palestra,
            UserRole::Member,
            'bruno@olimpo.it',
        );

        $this->actingAs($senzaConsenso)
            ->postJson('/api/v1/importazioni-piani', [
                'file' => [UploadedFile::fake()->create('piano.pdf', 40, 'application/pdf')],
                'dichiarazione' => true,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'ai_consent_required');

        $this->assertSame(0, ImportazionePiano::query()->count());

        /*
         * 🚨 **E nemmeno sul disco.** Il cancello sta prima della scrittura del
         * file: aprire l'importazione e poi rifiutarla vorrebbe dire un PDF con
         * dentro la dieta di qualcuno depositato sul nostro disco per niente.
         */
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    /**
     * ⚠️ **Chi REVOCA non puo' caricare piu' niente.**
     *
     * 💡 E' lo stesso cancello, ma il caso che conta davvero: il consenso dato
     * una volta e mai piu' ritirabile non sarebbe un consenso.
     */
    #[Test]
    public function chi_revoca_il_consenso_non_carica_piu(): void
    {
        $this->iscritto->forceFill(['ai_consent_at' => null])->save();

        $this->actingAs($this->iscritto->fresh())
            ->postJson('/api/v1/importazioni-piani', [
                'file' => [UploadedFile::fake()->create('piano.pdf', 40, 'application/pdf')],
                'dichiarazione' => true,
            ])
            ->assertForbidden();

        $this->assertSame(0, ImportazionePiano::query()->count());
    }
}
