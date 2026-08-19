<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Enums\TipoConversazione;
use App\Enums\UserRole;
use App\Models\AllegatoCifrato;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Le foto della chat, in transito — N14.
 *
 * 🚨 **Quello che questi test difendono** e' che il server resti una cassetta
 * cieca con una scadenza: riceve byte che non sa aprire, li consegna una volta
 * sola a chi ha diritto, e non li tiene oltre le ventiquattro ore.
 */
class AllegatiCifratiTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $trainer;

    private User $iscritto;

    private Conversation $filo;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $this->trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'coach@olimpo.it');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'atleta@olimpo.it');

        $this->filo = Conversation::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->id,
            'trainer_id' => $this->trainer->id,
            'member_id' => $this->iscritto->id,
            'tipo' => TipoConversazione::Iscritto,
        ]);
    }

    /** I byte «cifrati»: al server sono opachi comunque. */
    private function byteFinti(int $quanti = 2048): string
    {
        return random_bytes($quanti);
    }

    private function deposita(User $chi, ?string $byte = null): string
    {
        $byte ??= $this->byteFinti();

        return $this->actingAs($chi)
            ->post("/api/v1/conversations/{$this->filo->id}/allegati", [
                'file' => UploadedFile::fake()->createWithContent('foto.bin', $byte),
            ])
            ->assertCreated()
            ->json('token');
    }

    // ─────────────────────────── il deposito ───────────────────────────

    #[Test]
    public function chi_sta_nella_conversazione_deposita_e_ottiene_un_token(): void
    {
        $token = $this->deposita($this->iscritto);

        $this->assertSame(48, strlen($token));
        $this->assertDatabaseHas('allegati_cifrati', ['token' => $token]);
    }

    #[Test]
    public function la_scadenza_e_ventiquattro_ore(): void
    {
        $token = $this->deposita($this->trainer);

        $allegato = AllegatoCifrato::query()->where('token', $token)->firstOrFail();

        // ⚠️ Una tolleranza di un minuto: fra la richiesta e l'asserzione passa
        // del tempo, e un confronto esatto sarebbe un test che fallisce a caso.
        $this->assertEqualsWithDelta(
            now()->addHours(24)->timestamp,
            $allegato->scade_il->timestamp,
            60,
        );
    }

    #[Test]
    public function un_estraneo_non_puo_depositare_in_un_filo_non_suo(): void
    {
        $estraneo = $this->creaUtente($this->palestra, UserRole::Member, 'altro@olimpo.it');

        $this->actingAs($estraneo)
            ->post("/api/v1/conversations/{$this->filo->id}/allegati", [
                'file' => UploadedFile::fake()->createWithContent('x.bin', $this->byteFinti()),
            ])
            ->assertNotFound();

        $this->assertDatabaseCount('allegati_cifrati', 0);
    }

    #[Test]
    public function oltre_il_tetto_non_passa(): void
    {
        /*
         * 💡 Il tetto e' sulla **misura** e su niente altro: arriva un flusso
         * cifrato, e qualunque controllo su estensione o MIME direbbe soltanto
         * che non e' un'immagine — cosa che sappiamo gia'.
         */
        $this->actingAs($this->iscritto)
            ->post("/api/v1/conversations/{$this->filo->id}/allegati", [
                'file' => UploadedFile::fake()->create('grosso.bin', 12 * 1024), // 12 MB
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function un_pdf_da_otto_megabyte_passa(): void
    {
        /*
         * 🚨 **N21.2** — il tetto era 2 MB, tarato sulle foto. Un piano
         * alimentare scansionato ne pesa 5-10, e sarebbe stato respinto senza
         * che nessuno avesse mai deciso di respingerlo.
         */
        $this->actingAs($this->iscritto)
            ->post("/api/v1/conversations/{$this->filo->id}/allegati", [
                'file' => UploadedFile::fake()->create('piano.bin', 8 * 1024),
            ])
            ->assertCreated();
    }

    #[Test]
    public function oltre_il_budget_si_riceve_un_no_diverso(): void
    {
        /*
         * 🚨 **Il limite di frequenza non protegge lo spazio.**
         *
         * ⚠️ Dice quanto **spesso** si puo' scrivere, non quanto si puo'
         * **occupare**: con il tetto a 10 MB, venti al minuto sono 200 MB al
         * minuto su una macchina condivisa con i domini di altri clienti.
         *
         * 💡 413 e non 429: non e' «troppo in fretta», e' «troppo in tutto».
         * Un 429 farebbe aspettare e riprovare, che qui non servirebbe.
         */
        AllegatoCifrato::create([
            'conversation_id' => $this->filo->id,
            'sender_id' => $this->iscritto->id,
            'token' => str_repeat('z', 48),
            'byte_totali' => AllegatoCifrato::BUDGET_BYTE,
            'scade_il' => now()->addHours(24),
        ]);

        $this->actingAs($this->iscritto)
            ->post("/api/v1/conversations/{$this->filo->id}/allegati", [
                'file' => UploadedFile::fake()->createWithContent('x.bin', $this->byteFinti()),
            ])
            ->assertStatus(413)
            ->assertJsonPath('code', 'budget_allegati_esaurito');
    }

    #[Test]
    public function il_budget_non_conta_quelli_gia_scaduti(): void
    {
        /*
         * 💡 Sono spazzatura che il comando orario portera' via: farli pesare
         * punirebbe qualcuno per un file che non esiste piu' se non nel senso
         * piu' tecnico.
         */
        AllegatoCifrato::create([
            'conversation_id' => $this->filo->id,
            'sender_id' => $this->iscritto->id,
            'token' => str_repeat('y', 48),
            'byte_totali' => AllegatoCifrato::BUDGET_BYTE,
            'scade_il' => now()->subHour(),
        ]);

        $this->assertSame(0, AllegatoCifrato::byteInGiroDi((int) $this->iscritto->id));

        $this->deposita($this->iscritto);
    }

    #[Test]
    public function il_budget_e_di_chi_manda_non_della_conversazione(): void
    {
        // ⚠️ Altrimenti due persone che si scrivono si mangerebbero il budget a
        // vicenda, e la seconda pagherebbe per la prima.
        AllegatoCifrato::create([
            'conversation_id' => $this->filo->id,
            'sender_id' => $this->iscritto->id,
            'token' => str_repeat('w', 48),
            'byte_totali' => AllegatoCifrato::BUDGET_BYTE,
            'scade_il' => now()->addHours(24),
        ]);

        // Il trainer, nello stesso filo, deposita senza problemi.
        $this->deposita($this->trainer);
    }

    // ─────────────────────────── lo scarico ────────────────────────────

    #[Test]
    public function laltra_persona_scarica_gli_stessi_identici_byte(): void
    {
        /*
         * 🚨 **Identici**: il server non tocca niente. Se un giorno qualcuno ci
         * infilasse una ricompressione o una conversione, quei byte non si
         * decifrerebbero piu' — e il guasto si vedrebbe sul telefono di
         * qualcuno, non qui.
         */
        $byte = $this->byteFinti();
        $token = $this->deposita($this->iscritto, $byte);

        $risposta = $this->actingAs($this->trainer)->get("/api/v1/allegati/{$token}");

        $risposta->assertOk();
        $this->assertSame($byte, $risposta->streamedContent());
    }

    #[Test]
    public function scaricato_una_volta_non_ce_piu(): void
    {
        // ⚠️ E' la ritenzione promessa: consegnata la foto, il server non ha
        // piu' nessuna ragione di tenerla.
        $token = $this->deposita($this->iscritto);
        $allegato = AllegatoCifrato::query()->where('token', $token)->firstOrFail();
        $percorso = $allegato->percorso();

        $this->actingAs($this->trainer)->get("/api/v1/allegati/{$token}")->assertOk();

        $this->assertDatabaseCount('allegati_cifrati', 0);
        Storage::disk('local')->assertMissing($percorso);

        $this->actingAs($this->trainer)->get("/api/v1/allegati/{$token}")->assertNotFound();
    }

    #[Test]
    public function un_estraneo_non_scarica_e_non_scopre_nemmeno_che_esiste(): void
    {
        $estraneo = $this->creaUtente($this->palestra, UserRole::Member, 'ficcanaso@olimpo.it');
        $token = $this->deposita($this->iscritto);

        /*
         * 🚨 **404 e non 403.** Un 403 confermerebbe che il token e' valido,
         * cioe' direbbe a un estraneo qualcosa che non gli serve sapere.
         */
        $this->actingAs($estraneo)->get("/api/v1/allegati/{$token}")->assertNotFound();

        // ⚠️ E soprattutto: il tentativo fallito NON deve cancellare niente.
        $this->assertDatabaseCount('allegati_cifrati', 1);
    }

    #[Test]
    public function un_allegato_scaduto_non_si_scarica_e_viene_buttato(): void
    {
        /*
         * ⚠️ Fra una passata di pulizia e l'altra passa un'ora: senza questo
         * controllo, in quella finestra un allegato scaduto verrebbe consegnato
         * lo stesso, e le ventiquattro ore diventerebbero venticinque.
         */
        $token = $this->deposita($this->iscritto);

        AllegatoCifrato::query()->where('token', $token)
            ->update(['scade_il' => now()->subMinute()]);

        $this->actingAs($this->trainer)->get("/api/v1/allegati/{$token}")->assertNotFound();

        $this->assertDatabaseCount('allegati_cifrati', 0);
    }

    #[Test]
    public function un_token_inventato_non_dice_niente(): void
    {
        $this->actingAs($this->trainer)
            ->get('/api/v1/allegati/'.str_repeat('a', 48))
            ->assertNotFound();
    }

    // ─────────────────────────── la potatura ───────────────────────────

    #[Test]
    public function il_comando_butta_gli_scaduti_e_lascia_i_vivi(): void
    {
        $vivo = $this->deposita($this->iscritto);
        $morto = $this->deposita($this->iscritto);

        AllegatoCifrato::query()->where('token', $morto)
            ->update(['scade_il' => now()->subHour()]);

        $this->artisan('chat:pota-allegati')->assertSuccessful();

        $this->assertDatabaseHas('allegati_cifrati', ['token' => $vivo]);
        $this->assertDatabaseMissing('allegati_cifrati', ['token' => $morto]);
        Storage::disk('local')->assertMissing(AllegatoCifrato::CARTELLA.'/'.$morto);
        Storage::disk('local')->assertExists(AllegatoCifrato::CARTELLA.'/'.$vivo);
    }

    #[Test]
    public function il_comando_raccoglie_anche_i_file_orfani(): void
    {
        /*
         * 💡 `deposita()` scrive **prima il file e poi la riga**, di proposito:
         * nell'ordine inverso esisterebbe un istante in cui la riga promette un
         * file che non c'e'. Il prezzo e' che un guasto fra le due lascia un
         * file senza riga, e questo comando e' l'unico che se ne accorge.
         */
        Storage::disk('local')->put(AllegatoCifrato::CARTELLA.'/orfano', 'byte');

        // ⚠️ Deve essere abbastanza vecchio: un file appena scritto potrebbe
        // essere un deposito in corso, e buttarlo sarebbe una gara persa.
        touch(
            Storage::disk('local')->path(AllegatoCifrato::CARTELLA.'/orfano'),
            now()->subHours(3)->timestamp,
        );

        $this->artisan('chat:pota-allegati')->assertSuccessful();

        Storage::disk('local')->assertMissing(AllegatoCifrato::CARTELLA.'/orfano');
    }

    #[Test]
    public function il_comando_non_tocca_un_file_appena_scritto_senza_riga(): void
    {
        // 🚨 La corsa: fra la scrittura del file e la creazione della riga passa
        // un istante. Buttandolo li', si perderebbe un allegato valido.
        Storage::disk('local')->put(AllegatoCifrato::CARTELLA.'/appena', 'byte');

        $this->artisan('chat:pota-allegati')->assertSuccessful();

        Storage::disk('local')->assertExists(AllegatoCifrato::CARTELLA.'/appena');
    }

    #[Test]
    public function la_prova_a_vuoto_non_butta_niente(): void
    {
        $token = $this->deposita($this->iscritto);

        AllegatoCifrato::query()->where('token', $token)
            ->update(['scade_il' => now()->subHour()]);

        $this->artisan('chat:pota-allegati', ['--dry-run' => true])->assertSuccessful();

        $this->assertDatabaseHas('allegati_cifrati', ['token' => $token]);
    }
}
