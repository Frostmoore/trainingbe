<?php

declare(strict_types=1);

namespace Tests\Feature\Nutrition;

use App\Enums\AiFeature;
use App\Enums\UserRole;
use App\Jobs\TrascriviIlDocumento;
use App\Models\ImportazioneDaDocumento;
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
class ImportazioneDaDocumentoTest extends TestCase
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
         * ⛔ `POST importazioni` manda un PDF ad Anthropic e **non aveva
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

        $risposta = $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni', [
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
            'consenso_documento' => true,
        ]);

        $risposta->assertStatus(202)
            ->assertJsonPath('data.stato', ImportazioneDaDocumento::IN_CODA)
            ->assertJsonPath('data.nome_file', 'piano.pdf');

        Queue::assertPushed(TrascriviIlDocumento::class);

        $riga = ImportazioneDaDocumento::withoutGlobalScopes()->firstOrFail();

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
                ->postJson('/api/v1/importazioni', $corpo)
                ->assertStatus(422)
                ->assertJsonValidationErrors('dichiarazione');
        }

        $this->assertSame(0, ImportazioneDaDocumento::withoutGlobalScopes()->count());
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
            ->postJson('/api/v1/importazioni', [
                'file' => [UploadedFile::fake()->image('pagina.jpg')],
                'dichiarazione' => true,
                'consenso_documento' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.tipo', ImportazioneDaDocumento::TIPO_IMMAGINI);
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
            ->postJson('/api/v1/importazioni', [
                'file' => [UploadedFile::fake()->create('pagina.heic', 40, 'image/heic')],
                'dichiarazione' => true,
                'consenso_documento' => true,
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
            ->postJson('/api/v1/importazioni', [
                'file' => [
                    UploadedFile::fake()->image('uno.jpg'),
                    UploadedFile::fake()->image('due.jpg'),
                    UploadedFile::fake()->image('tre.jpg'),
                ],
                'dichiarazione' => true,
                'consenso_documento' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.quanti_documenti', 3);

        $riga = ImportazioneDaDocumento::query()->findOrFail($risposta->json('data.id'));

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

        for ($i = 0; $i < ImportazioneDaDocumento::AL_MASSIMO + 1; $i++) {
            $troppe[] = UploadedFile::fake()->image("pagina{$i}.jpg");
        }

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/importazioni', [
                'file' => $troppe,
                'dichiarazione' => true,
                'consenso_documento' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, ImportazioneDaDocumento::query()->count());
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
            ->postJson('/api/v1/importazioni', [
                'file' => [UploadedFile::fake()->image('pagina.jpg'), $this->unPdf()],
                'dichiarazione' => true,
                'consenso_documento' => true,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.tipo', ImportazioneDaDocumento::TIPO_PDF);
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

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni', [
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
            'consenso_documento' => true,
        ])->assertStatus(202);

        $riga = ImportazioneDaDocumento::withoutGlobalScopes()->firstOrFail();

        $estranei = [
            'trainer' => $this->creaUtente($this->palestra, UserRole::Trainer, 'coach@olimpo.it'),
            'palestra' => $this->creaUtente($this->palestra, UserRole::GymAdmin, 'capo@olimpo.it'),
            'compagno' => $this->creaUtente($this->palestra, UserRole::Member, 'bruno@olimpo.it'),
        ];

        foreach ($estranei as $chi => $utente) {
            // ⚠️ La coda `/pdf` non c'e' piu' da provare: quella rotta e'
            // sparita con K1-bis.
            $this->actingAs($utente)
                ->getJson('/api/v1/importazioni/'.$riga->id)
                ->assertStatus(404, "«{$chi}» non deve nemmeno sapere che esiste.");

            $this->actingAs($utente)
                ->deleteJson('/api/v1/importazioni/'.$riga->id)
                ->assertStatus(404);
        }

        // E la riga e' ancora li': nessuno l'ha buttata per conto suo.
        $this->assertNotNull(ImportazioneDaDocumento::withoutGlobalScopes()->find($riga->id));
    }

    /**
     * ⚠️ **Si chiamava `il_job_trascrive_e_l_originale_resta_consultabile`.**
     *
     * ⛔ Da K1-bis l'originale **non resta**: se ne va appena il job ha finito, e
     * la rotta che lo riconsegnava non esiste piu'. 🚨 Quella ragione — *«senza
     * l'originale accanto la revisione non e' una revisione»* — resta vera, ma
     * l'originale ce l'ha il telefono, che se l'e' copiato quando l'ha scelto.
     *
     * 💡 Quello che questo test prova adesso e' la meta' che il server fa ancora:
     * **la bozza esiste**.
     */
    #[Test]
    public function il_job_trascrive_e_la_bozza_arriva(): void
    {
        $this->aiFinta();

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni', [
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
            'consenso_documento' => true,
        ])->assertStatus(202);

        $riga = ImportazioneDaDocumento::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(ImportazioneDaDocumento::PRONTA, $riga->refresh()->stato);
        $this->assertSame('Piano importato', $riga->bozza['nome']);

        /*
         * 🚨 I **dubbi** arrivano fino all'app: sono la parte piu' utile della
         * risposta, perche' portano chi controlla dritto sulle righe che
         * contano. Se si perdessero per strada, la revisione diventerebbe un
         * elenco di trenta voci tutte uguali.
         */
        $this->assertNotEmpty($riga->bozza['dubbi']);

        $risposta = $this->actingAs($this->iscritto)
            ->getJson('/api/v1/importazioni/'.$riga->id)
            ->assertOk();

        $risposta->assertJsonPath('data.stato', ImportazioneDaDocumento::PRONTA);
        $risposta->assertJsonPath('data.righe', 2);

        /*
         * ⛔ **E l'originale, sul server, non c'e' piu'** — K1-bis.
         *
         * 🚨 Qui si chiedeva `/pdf` e si pretendeva un 200: N20.4 diceva che
         * l'originale doveva restare consultabile accanto alla bozza. 💡 Quella
         * ragione resta vera, ma l'originale ce l'ha **il telefono**, che se
         * l'e' copiato quando l'ha scelto.
         *
         * ⚠️ Il documento se ne va appena il job ha finito: e' cio' che prova
         * `finita_la_trascrizione_il_documento_non_c_e_piu`.
         */
        $this->assertSame([], $riga->refresh()->percorsi());
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

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni', [
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
            'consenso_documento' => true,
        ])->assertStatus(202);

        $riga = ImportazioneDaDocumento::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(ImportazioneDaDocumento::FALLITA, $riga->stato);
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

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni', [
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
            'consenso_documento' => true,
        ])->assertStatus(202);

        $riga = ImportazioneDaDocumento::withoutGlobalScopes()->firstOrFail();
        $percorsi = $riga->percorsi();

        $this->actingAs($this->iscritto)
            ->deleteJson('/api/v1/importazioni/'.$riga->id)
            ->assertOk()
            ->assertJsonPath('data.chiusa', true);

        $this->assertNull(ImportazioneDaDocumento::withoutGlobalScopes()->find($riga->id));

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

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni', [
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
            'consenso_documento' => true,
        ])->assertStatus(202);

        $riga = ImportazioneDaDocumento::withoutGlobalScopes()->firstOrFail();
        $riga->forceFill(['scade_il' => now()->subMinute()])->save();

        $this->actingAs($this->iscritto)
            ->getJson('/api/v1/importazioni/'.$riga->id)
            ->assertStatus(404);

        $this->artisan('piani:pota-importazioni')->assertSuccessful();

        $this->assertNull(ImportazioneDaDocumento::withoutGlobalScopes()->find($riga->id));
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
     * La rotta aveva `throttle:importazioni` e basta. ⛔ Mandava un PDF ad
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
            ->postJson('/api/v1/importazioni', [
                'file' => [UploadedFile::fake()->create('piano.pdf', 40, 'application/pdf')],
                'dichiarazione' => true,
                'consenso_documento' => true,
            ])
            ->assertForbidden()
            ->assertJsonPath('code', 'ai_consent_required');

        $this->assertSame(0, ImportazioneDaDocumento::query()->count());

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
            ->postJson('/api/v1/importazioni', [
                'file' => [UploadedFile::fake()->create('piano.pdf', 40, 'application/pdf')],
                'dichiarazione' => true,
                'consenso_documento' => true,
            ])
            ->assertForbidden();

        $this->assertSame(0, ImportazioneDaDocumento::query()->count());
    }

    // ───────────── K1-bis: sul server non resta niente ─────────────

    /**
     * 🚨 **Finita la trascrizione, il documento se ne va.**
     *
     * 📌 Il committente: *«niente deve stare più sul server»*.
     *
     * ⛔ Prima restava **sette giorni**, perche' la revisione doveva poter
     * riaprire l'originale. 💡 E quei sette giorni non servivano a niente: il
     * documento ce l'ha gia' il telefono, che e' chi l'ha scelto e caricato.
     */
    #[Test]
    public function finita_la_trascrizione_il_documento_non_c_e_piu(): void
    {
        /*
         * ⚠️ **Niente `Queue::fake()` qui**: nei test la coda e' `sync`, quindi
         * la trascrizione gira dentro la POST — che e' esattamente lo scenario
         * da provare. 🚨 E fingerla renderebbe inerte anche `dispatchSync`.
         */
        $this->aiFinta();

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni', [
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
            'consenso_documento' => true,
        ])->assertStatus(202);

        $riga = ImportazioneDaDocumento::withoutGlobalScopes()->firstOrFail();

        // 🎉 La bozza c'e'…
        $this->assertSame(ImportazioneDaDocumento::PRONTA, $riga->stato);

        /*
         * ⛔ …e sul disco non e' rimasto **niente**.
         *
         * 💡 Si guarda il disco e non l'elenco dei percorsi: «del documento non
         * resta niente» non si prova elencando nomi, si prova **non trovando
         * file**. Un elenco svuotato con i file ancora li' passerebbe il primo
         * controllo e fallirebbe questo.
         */
        $this->assertEmpty(Storage::disk('local')->allFiles());
        $this->assertSame([], $riga->percorsi());
    }

    /**
     * ⚠️ **Anche quando fallisce**, ed e' il caso che si dimentica.
     *
     * 🚨 Un job morto lascerebbe sul disco proprio il documento che non siamo
     * nemmeno riusciti a leggere. E' la stessa regola di `StimaCibo::fallisce()`.
     */
    #[Test]
    public function anche_se_fallisce_il_documento_non_resta(): void
    {
        $finta = $this->aiFinta();
        $finta->prossimoErrore = new \RuntimeException('Il fornitore non risponde.');

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni', [
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
            'consenso_documento' => true,
        ])->assertStatus(202);

        $riga = ImportazioneDaDocumento::withoutGlobalScopes()->firstOrFail();

        $this->assertSame(ImportazioneDaDocumento::FALLITA, $riga->stato);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    // ───────────── K1-ter: il consenso a questo documento ─────────────

    /**
     * 🔴 **Senza il consenso a mandare QUESTO documento non si carica.**
     *
     * 📌 Il committente: *«si deve richiedere il consenso specifico a mandare
     * quei dati all'AI»*.
     *
     * ⚠️ E' **diverso** da `ai_consent_at`, che questa persona ha: quello dice
     * «puoi usare l'AI», questo dice «puoi mandare **questo file**». 🚨 Si chiede
     * ogni volta, perche' il file e' diverso ogni volta.
     */
    #[Test]
    public function senza_il_consenso_al_documento_non_si_carica(): void
    {
        Queue::fake();

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/importazioni', [
                'file' => [$this->unPdf()],
                'dichiarazione' => true,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('consenso_documento');

        $this->assertSame(0, ImportazioneDaDocumento::query()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    /**
     * ⛔ **`false` non e' un consenso**, ed e' il caso che un `boolean`
     * lascerebbe passare.
     */
    #[Test]
    public function un_consenso_negato_non_vale_come_dato(): void
    {
        Queue::fake();

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/importazioni', [
                'file' => [$this->unPdf()],
                'dichiarazione' => true,
                'consenso_documento' => false,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('consenso_documento');
    }

    /**
     * 💡 **E quando c'e', si registra QUANDO** — non cosa.
     *
     * ⛔ Il documento e il suo contenuto non si conservano «per provare il
     * consenso»: sarebbe tenere proprio cio' che K1-bis toglie, con la scusa
     * migliore che ci sia.
     */
    #[Test]
    public function il_consenso_si_registra_con_la_sua_ora(): void
    {
        Queue::fake();

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni', [
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
            'consenso_documento' => true,
        ])->assertStatus(202);

        $riga = ImportazioneDaDocumento::withoutGlobalScopes()->firstOrFail();

        $this->assertNotNull($riga->consenso_documento_il);
    }

    // ───────────── K2: si importa anche una scheda ─────────────

    /**
     * 🆕 **Anche una scheda**, dalla stessa rotta — K2, 03/09/2026.
     *
     * ⛔ Una classe gemella sarebbe stata due implementazioni della stessa cosa.
     * 💡 Cambia il `genere`, e con lui la funzione AI che paga e il prompt che
     * legge.
     */
    #[Test]
    public function si_importa_anche_una_scheda(): void
    {
        Queue::fake();

        $this->actingAs($this->iscritto)
            ->postJson('/api/v1/importazioni', [
                'file' => [$this->unPdf('scheda.pdf')],
                'dichiarazione' => true,
                'consenso_documento' => true,
                'genere' => ImportazioneDaDocumento::GENERE_SCHEDA,
            ])
            ->assertStatus(202)
            ->assertJsonPath('data.genere', ImportazioneDaDocumento::GENERE_SCHEDA);

        $riga = ImportazioneDaDocumento::withoutGlobalScopes()->firstOrFail();

        $this->assertTrue($riga->eUnaScheda());
        $this->assertSame(AiFeature::PdfImport, $riga->funzione());
    }

    /**
     * ⚠️ **Senza `genere` si importa un piano**, che e' cio' che questa rotta ha
     * sempre fatto.
     *
     * 💡 Un `required` avrebbe rotto l'app installata per un campo che ha un
     * valore ovvio.
     */
    #[Test]
    public function senza_genere_e_un_piano(): void
    {
        Queue::fake();

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni', [
            'file' => [$this->unPdf()],
            'dichiarazione' => true,
            'consenso_documento' => true,
        ])->assertStatus(202);

        $riga = ImportazioneDaDocumento::withoutGlobalScopes()->firstOrFail();

        $this->assertFalse($riga->eUnaScheda());
        $this->assertSame(AiFeature::NutritionPdfImport, $riga->funzione());
    }

    /**
     * 🚨 **Le righe di una scheda si contano sugli esercizi.**
     *
     * ⛔ Fino a K il conteggio scendeva **sempre** dentro `giorni -> pasti ->
     * alimenti`, che e' la forma di un piano alimentare. Su una scheda quelle
     * chiavi non esistono, e il risultato sarebbe stato **zero** — senza nessun
     * errore da nessuna parte.
     *
     * ⚠️ E zero non e' un numero neutro: la barra della revisione avrebbe
     * scritto *«0 righe da controllare»* su una scheda che ne ha due, cioe'
     * avrebbe detto a chi deve confrontare che non c'e' niente da confrontare.
     */
    #[Test]
    public function le_righe_di_una_scheda_si_contano_sugli_esercizi(): void
    {
        $this->aiFinta();

        $this->actingAs($this->iscritto)->postJson('/api/v1/importazioni', [
            'file' => [$this->unPdf('scheda.pdf')],
            'dichiarazione' => true,
            'consenso_documento' => true,
            'genere' => ImportazioneDaDocumento::GENERE_SCHEDA,
        ])->assertStatus(202);

        $riga = ImportazioneDaDocumento::withoutGlobalScopes()->firstOrFail();

        $attese = count((array) ($riga->refresh()->bozza['exercises'] ?? []));

        // 💡 Il doppio finto risponde con una scheda vera: se un giorno non lo
        // facesse piu', questo test lo direbbe invece di passare a vuoto.
        $this->assertGreaterThan(0, $attese);

        $this->actingAs($this->iscritto)
            ->getJson('/api/v1/importazioni/'.$riga->id)
            ->assertOk()
            ->assertJsonPath('data.righe', $attese);
    }
}
