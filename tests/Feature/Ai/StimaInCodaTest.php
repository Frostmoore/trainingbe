<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\UserRole;
use App\Jobs\StimaIlCibo;
use App\Models\StimaCibo;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Data\FoodEstimate;
use App\Services\Ai\Providers\FakeAiProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * Le stime del cibo in coda — FASE 9, 21/08/2026.
 *
 * ── 🚨 Cosa difende questo file ────────────────────────────────────────────
 *
 * 📌 Il committente: *«se la pubblico e cinque persone all'ora di pranzo
 * scrivono il pranzo si impalla tutto? […] ovvio che ci deve essere un sistema
 * che gli dica "sto pensando" finché non ha fatto»*.
 *
 * ⚠️ Il difetto misurato: `POST /ai/food/text` teneva occupato un processo PHP
 * per **2–8 secondi**, e i processi di questo dominio sono **sei**. A sette
 * persone contemporanee **non si fermava l'AI: si fermava il sito**.
 *
 * 💡 La cosa che questi test guardano, e che è l'unica che conta davvero, è che
 * **la richiesta HTTP non chiami più il modello**. Tutto il resto — lo stato,
 * il risultato, il recupero — è conseguenza.
 */
final class StimaInCodaTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $palestra;

    private User $iscritto;

    private FakeAiProvider $finta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finta = $this->aiFinta();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'anna@alfa.test');
        $this->iscritto->accendiLAi();

        /*
         * 🎟️ **I gettoni per le richieste fatte a mano** — 3b-AE, 31/08/2026.
         *
         * ⛔ Da oggi una stima da testo o da foto non guarda piu' la quota
         * inclusa: si paga a gettoni. Questi test parlano d'altro, e senza
         * credito fallirebbero con un 402 che non c'entra niente.
         *
         * 💡 Che il cancello commerciale funzioni e' provato dove deve esserlo,
         * in `PortafoglioGettoniTest` e `CicloDeiGettoniTest`.
         */
        $this->dagliGettoni($this->palestra);
    }

    #[Test]
    public function il_pasto_sopravvive_alla_cancellazione_della_richiesta(): void
    {
        /*
         * 🚨 **FASE 9.7**: chi riprende una stima dopo aver chiuso l'app deve
         * ritrovare il foglio di conferma **nel pasto giusto**.
         *
         * ⚠️ E la distinzione è quella che regge tutta la privacy di questa
         * tabella: `richiesta` dice **cosa** ha mangiato una persona ed è il dato
         * personale — si cancella; `pasto` dice solo **quando**, ed è
         * un'etichetta. 💡 Cancellarlo insieme all'altro sarebbe stato
         * prudente in modo inutile, e avrebbe costretto a richiedere a chi
         * l'aveva già detto.
         */
        $utente = $this->iscritto->fresh();

        $id = $this->comeApp($utente)
            ->postJson('/api/v1/ai/food/text', ['text' => 'due uova', 'meal' => 'breakfast'])
            ->json('data.id');

        $riga = StimaCibo::withoutGlobalScopes()->findOrFail($id);

        $this->assertNull($riga->richiesta);
        $this->assertSame('breakfast', $riga->pasto);

        $this->comeApp($utente)
            ->getJson('/api/v1/ai/food/stime/'.$id)
            ->assertJsonPath('data.pasto', 'breakfast')
            ->assertJsonPath('data.origine', 'testo');
    }

    #[Test]
    public function le_stime_vecchie_si_potano(): void
    {
        /*
         * ⚠️ `stime_cibo` è una **cache**, non uno storico: vale la regola già
         * imparata su `ai_advices` (§46 dell'atlante). Una stima non confermata
         * dopo 24 ore non interessa più a nessuno, e tenerla vorrebbe dire
         * conservare cosa ha mangiato una persona senza che serva a niente.
         *
         * 🚨 E la potatura gira **senza scope di palestra**, perché un comando
         * schedulato non ne ha uno: con lo scope attivo non troverebbe niente e
         * la tabella crescerebbe **senza dare nessun errore**.
         */
        $utente = $this->iscritto->fresh();

        $id = $this->comeApp($utente)
            ->postJson('/api/v1/ai/food/text', ['text' => 'mela'])
            ->json('data.id');

        StimaCibo::withoutGlobalScopes()
            ->whereKey($id)
            ->update(['created_at' => now()->subHours(StimaCibo::DURATA_ORE + 1)]);

        $this->artisan('model:prune', ['--model' => StimaCibo::class])->assertSuccessful();

        $this->assertNull(StimaCibo::withoutGlobalScopes()->find($id));
    }

    private function quanteChiamate(): int
    {
        return count(array_filter(
            $this->finta->calls,
            static fn (array $c): bool => in_array($c['method'], ['foodFromText', 'foodFromImage'], true),
        ));
    }

    #[Test]
    public function la_richiesta_risponde_subito_e_non_chiama_il_modello(): void
    {
        /*
         * ══ 🚨 IL TEST CHE VALE PER TUTTA LA FASE ════════════════════════════
         *
         * `Queue::fake()` impedisce al lavoro di girare, quindi qui si vede
         * **esattamente cosa fa la richiesta web e nient'altro**: valida, apre il
         * cancello, scrive una riga, accoda. ⚠️ Il modello **non** viene
         * chiamato — che è tutto il punto della fase.
         */
        Queue::fake();

        $risposta = $this->comeApp($this->iscritto->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'pasta al pomodoro', 'meal' => 'lunch'])
            ->assertStatus(202);

        $risposta->assertJsonPath('data.stato', StimaCibo::IN_CODA);
        $this->assertIsInt($risposta->json('data.id'));

        $this->assertSame(0, $this->quanteChiamate(), 'La richiesta web ha chiamato il modello.');

        Queue::assertPushed(StimaIlCibo::class);
    }

    #[Test]
    public function il_lavoro_produce_la_stima_e_l_app_la_legge(): void
    {
        $this->finta->willReturnFood(FoodEstimate::fromArray([
            'items' => [['name' => 'Pasta', 'qty' => 80, 'unit' => 'g', 'grams' => 80, 'kcal' => 280]],
            'confidence' => 0.85,
        ]));

        $utente = $this->iscritto->fresh();

        // 💡 La coda dei test è `sync`: il lavoro gira dentro la `POST`.
        $id = $this->comeApp($utente)
            ->postJson('/api/v1/ai/food/text', ['text' => 'pasta al pomodoro'])
            ->assertStatus(202)
            ->json('data.id');

        $this->comeApp($utente)
            ->getJson('/api/v1/ai/food/stime/'.$id)
            ->assertOk()
            ->assertJsonPath('data.stato', StimaCibo::PRONTA)
            ->assertJsonPath('data.risultato.estimate.confidence', 0.85)
            ->assertJsonPath('data.risultato.estimate.items.0.name', 'Pasta')
            /*
             * 🚨 La forma è **identica** a quella che l'endpoint sincrono dava
             * con `save: false`: l'app la sa già leggere, e non c'è ragione
             * perché impari una seconda forma della stessa cosa.
             */
            ->assertJsonPath('data.risultato.saved', false);

        $this->assertSame(1, $this->quanteChiamate());
    }

    #[Test]
    public function il_pasto_sparisce_appena_la_stima_esiste(): void
    {
        /*
         * ══ 🚨 QUESTO È IL TEST DI PRIVACY DELLA FASE ════════════════════════
         *
         * `richiesta` contiene quello che la persona ha scritto — *«due uova e
         * una fetta di pane»* — cioè il dato più personale che passa di qui.
         * ⚠️ Prima della coda **passava e basta**; adesso sta scritto in una
         * tabella, e la differenza fra «transita» e «è conservato» è esattamente
         * ciò che il registro dei trattamenti deve dichiarare.
         *
         * 💡 Si azzera appena la stima esiste: da quel momento il testo del pasto
         * non serve più a niente.
         */
        $utente = $this->iscritto->fresh();

        $id = $this->comeApp($utente)
            ->postJson('/api/v1/ai/food/text', ['text' => 'due uova e una fetta di pane'])
            ->json('data.id');

        $riga = StimaCibo::withoutGlobalScopes()->findOrFail($id);

        $this->assertSame(StimaCibo::PRONTA, $riga->stato);
        $this->assertNull($riga->richiesta, 'Il pasto è rimasto scritto dopo la stima.');
    }

    #[Test]
    public function la_foto_si_deposita_e_poi_si_butta(): void
    {
        Storage::fake('local');

        $utente = $this->iscritto->fresh();

        $this->comeApp($utente)->post('/api/v1/ai/food/photo', [
            'photo' => UploadedFile::fake()->image('pranzo.jpg', 400, 300),
        ])->assertStatus(202);

        /*
         * ⚠️ **La foto di un pasto a riposo sul nostro disco** è la novità di
         * questa fase, ed è il pezzo che cambia il registro. 🚨 Dura i secondi
         * che separano l'accodamento dal turno del worker, e non un minuto di
         * più: qui si prova che non ne resta traccia.
         */
        $rimaste = Storage::disk('local')->files(StimaCibo::CARTELLA);

        $this->assertSame([], $rimaste, 'La foto del pasto è rimasta sul disco.');
    }

    #[Test]
    public function save_true_viene_rifiutato_e_lo_dice(): void
    {
        /*
         * 🚨 Si rifiuta invece di ignorarlo: un parametro **accettato e non
         * applicato** è peggio di uno rifiutato, perché chi lo manda continua a
         * credere che stia facendo qualcosa.
         *
         * ⚠️ E `save: true` vorrebbe dire un'altra cosa adesso: la stima finisce
         * minuti dopo, magari con l'app chiusa, e scrivere in diario mentre
         * nessuno guarda non è la funzione di prima.
         */
        $this->comeApp($this->iscritto->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'mela', 'save' => true])
            ->assertStatus(422)
            ->assertJsonPath('errors.save.0', 'Le stime si confermano da /ai/food/confirm.');
    }

    #[Test]
    public function la_stima_di_un_altro_non_si_legge(): void
    {
        $altro = $this->creaUtente($this->palestra, UserRole::Member, 'bruno@alfa.test');
        $altro->accendiLAi();

        $id = $this->comeApp($this->iscritto->fresh())
            ->postJson('/api/v1/ai/food/text', ['text' => 'mela'])
            ->json('data.id');

        /*
         * 🚨 **Compagno di palestra, non un'altra palestra.** Lo scope di tenant
         * qui non basterebbe: una stima è cosa ha mangiato una persona, e la
         * regola è l'appartenenza — la stessa già scritta per i messaggi e per
         * il diario.
         */
        $this->comeApp($altro->fresh())
            ->getJson('/api/v1/ai/food/stime/'.$id)
            ->assertStatus(404);
    }

    #[Test]
    public function chi_ha_chiuso_l_app_ritrova_la_stima_in_corso(): void
    {
        /*
         * ══ 🚨 FASE 9.7 — il caso che si dimentica sempre ════════════════════
         *
         * Il lavoro continua sul server: al rientro l'app deve **ritrovarlo**,
         * non ricominciare — che vorrebbe dire una seconda chiamata al modello
         * per lo stesso piatto. ⚠️ E questa rotta è la rete di sicurezza che non
         * dipende dal telefono: chi ha svuotato i dati l'id non ce l'ha.
         */
        Queue::fake();

        $utente = $this->iscritto->fresh();

        $id = $this->comeApp($utente)
            ->postJson('/api/v1/ai/food/text', ['text' => 'minestrone'])
            ->json('data.id');

        $this->comeApp($utente)
            ->getJson('/api/v1/ai/food/stime/in-corso')
            ->assertOk()
            ->assertJsonPath('data.id', $id)
            ->assertJsonPath('data.stato', StimaCibo::IN_CODA);
    }

    #[Test]
    public function quando_non_c_e_niente_in_corso_risponde_null(): void
    {
        // 💡 `null` e non `404`: «non hai niente in sospeso» è una risposta, non
        // un errore. Un 404 farebbe scrivere all'app un `catch` per un caso
        // normalissimo.
        $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/food/stime/in-corso')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    #[Test]
    public function una_stima_finita_non_risulta_piu_in_corso(): void
    {
        $utente = $this->iscritto->fresh();

        $this->comeApp($utente)->postJson('/api/v1/ai/food/text', ['text' => 'mela']);

        $this->comeApp($utente)
            ->getJson('/api/v1/ai/food/stime/in-corso')
            ->assertJsonPath('data', null);
    }
}
