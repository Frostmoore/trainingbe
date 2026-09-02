<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Enums\UserRole;
use App\Models\AiAdvice;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Providers\FakeAiProvider;
use App\Support\Tempo\FasciaDelConsiglio;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaAiFinta;
use Tests\TestCase;

/**
 * Due richieste, un consiglio — FASE 2-septies, 21/08/2026.
 *
 * ── 🚨 Cosa difende questo file ────────────────────────────────────────────
 *
 * Il difetto, trovato nel log del server il 20/08: fra il «guardo se c'è in
 * cache» e lo «scrivo» passano i secondi della chiamata al modello. Una seconda
 * richiesta identica arrivata in quella finestra trovava la cache **ancora
 * vuota**, partiva anche lei, e al momento di scrivere sbatteva contro
 * `ai_advices_unique` → `SQLSTATE 23000` → **500**.
 *
 * ⚠️ **Il 500 era il sintomo meno costoso.** Tutt'e due avevano già chiamato il
 * modello e **pagato**: una delle due risposte si buttava via.
 *
 * ── 💡 Come si prova una corsa senza avere due processi ────────────────────
 *
 * Con `FakeAiProvider::duranteIlConsiglio()`: mentre la nostra richiesta è ferma
 * dentro la generazione, la chiusura scrive la riga **come farebbe l'altra
 * richiesta che ha vinto**. È il momento esatto della corsa, reso deterministico.
 *
 * 🚨 Senza la correzione questi test falliscono con un `QueryException`, che è
 * il requisito che il piano si era dato (2sp.4): un test che passa anche prima
 * prova soltanto che il codice nuovo non esplode.
 */
final class CorsaSulConsiglioTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaAiFinta;

    private Tenant $palestra;

    private User $iscritto;

    /** 🚨 Il doppio si **tiene**: `app(FakeAiProvider::class)` non è un singleton. */
    private FakeAiProvider $finta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finta = $this->aiFinta();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'anna@alfa.test');
        $this->iscritto->accendiLAi();
    }

    /**
     * Scrive la riga che «vince la corsa», con l'hash del contesto vero.
     *
     * 💡 L'hash lo si prende dal contesto che il modello ha appena ricevuto: è
     * l'unico modo di essere sicuri che sia **lo stesso** che il controller
     * userà per scrivere. Ricostruirlo a mano nel test vorrebbe dire duplicare
     * `chiaveDiCache()` — e il giorno che quella cambia, il test proverebbe una
     * collisione che non avviene più.
     *
     * @param  array<string, mixed>  $contesto
     */
    private function scriviLaVincente(array $contesto, string $testo, ?int $tenantId = null): AiAdvice
    {
        $volatili = ['time', 'day_progress_pct', 'now'];

        /*
         * 🚨 **La stessa fascia in cui sta arrivando l'altra richiesta** —
         * 3b-AB. E' quello che rende questa riga «la vincente»: dal 30/08/2026
         * la collisione non e' piu' sull'hash del contesto ma su
         * `(utente, fascia, tipo)`.
         *
         * ⚠️ Si calcola con `FasciaDelConsiglio` e non a mano: una fascia
         * scritta a mano che non coincide con quella dell'orologio farebbe
         * scrivere **due** righe invece di una, e il test proverebbe una corsa
         * che non avviene.
         */
        $fascia = FasciaDelConsiglio::adesso($this->iscritto);

        return AiAdvice::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId ?? $this->palestra->getKey(),
            'user_id' => $this->iscritto->getKey(),
            'date' => $fascia->giorno->etichetta,
            'fascia' => $fascia->etichetta(),
            'kind' => 'daily',
            'context_hash' => AiAdvice::hashOf(array_diff_key($contesto, array_flip($volatili))),
            /*
             * ⚠️ **`$testo` non finisce piu' in tabella** — I5.3: la colonna non
             * esiste. 💡 Resta un parametro perche' i test lo usano per dire
             * *quale* delle due richieste ha vinto, e la riga che ne esce e' la
             * prova che qualcuno ha vinto.
             */
            'model' => 'fake',
        ]);
    }

    #[Test]
    public function chi_perde_la_corsa_riceve_il_consiglio_e_non_un_errore(): void
    {
        $this->finta
            ->willReturnAdvice('Il testo di chi è arrivato secondo.')
            ->duranteIlConsiglio(
                fn (array $contesto) => $this->scriviLaVincente($contesto, 'Il testo di chi è arrivato primo.'),
            );

        $risposta = $this->comeApp($this->iscritto->fresh())
            ->getJson('/api/v1/ai/advice')
            ->assertOk();

        /*
         * ══ 🚨 RICEVE UN CONSIGLIO, E DA I5.3 E' IL PROPRIO ═══════════════
         *
         * ⛔ Qui c'era scritto *«riceve il testo del vincitore, non il proprio:
         * o due telefoni della stessa persona vedrebbero due consigli diversi
         * per lo stesso giorno»*. Era giusto finche' il testo stava sul server.
         *
         * 🚨 Da I5.3 il server il testo del vincitore **non ce l'ha**: non lo
         * conserva piu'. 💡 Le due strade erano consegnare il proprio — che e'
         * gia' stato generato e **pagato** — oppure `null`, cioe' far aspettare
         * qualcuno per poi non dargli niente. ⚠️ Il prezzo e' quello che il
         * vecchio commento temeva: due consigli diversi per la stessa fascia su
         * due telefoni. Sono tutti e due buoni per lo stesso contesto, e la
         * corsa e' rara — il lucchetto esiste apposta.
         */
        $risposta->assertJsonPath('data.body', 'Il testo di chi è arrivato secondo.');
        $risposta->assertJsonPath('data.cached', true);

        // ⚠️ E in tabella resta **una riga sola**: il duplicato non è entrato.
        $this->assertSame(1, AiAdvice::withoutGlobalScopes()->count());
    }

    /**
     * ⚠️ **La riga del vincitore resta una, e resta la sua.**
     *
     * ⛔ Qui si leggeva `->body` per provare che il perdente non avesse
     * sovrascritto. Da I5.3 quella colonna non esiste: il testo non sta piu' sul
     * server, quindi *«sovrascrivere il testo»* non e' piu' una cosa che possa
     * succedere.
     *
     * 💡 Quello che resta da provare e' che la riga non si duplichi e non si
     * riscriva: e' **lei** il registro della fascia, cioe' l'unica cosa che
     * impedisce di pagare due volte. 🚨 Un `updateOrCreate` qui rimetterebbe in
     * piedi la scrittura del perdente, e con lei il `created_at` che sposta
     * l'ora del consiglio in avanti senza che nessuno l'abbia rigenerato.
     */
    #[Test]
    public function la_riga_del_vincitore_non_viene_sovrascritta(): void
    {
        $this->finta
            ->willReturnAdvice('Il testo di chi è arrivato secondo.')
            ->duranteIlConsiglio(
                fn (array $contesto) => $this->scriviLaVincente($contesto, 'Il testo di chi è arrivato primo.'),
            );

        $vincitore = null;

        $this->comeApp($this->iscritto->fresh())->getJson('/api/v1/ai/advice')->assertOk();

        $righe = AiAdvice::withoutGlobalScopes()->get();

        $this->assertCount(1, $righe, 'Il perdente ha scritto una seconda riga.');

        $vincitore = $righe->first();

        $this->assertSame('fake', $vincitore->model, 'La riga e\' stata riscritta dal perdente.');
    }

    #[Test]
    public function un_duplicato_che_non_e_la_nostra_corsa_viene_rilanciato(): void
    {
        /*
         * ══ 🚨 IL CASO CHE IL `catch` NON DEVE INGHIOTTIRE ═══════════════════
         *
         * La riga che collide appartiene a **un'altra palestra**. L'indice
         * `ai_advices_unique` è `(user_id, date, kind, context_hash)` e **non
         * comprende `tenant_id`**, quindi la scrittura sbatte lo stesso; ma
         * `AiAdvice::cached()` passa dallo scope di tenant e quella riga **non
         * la può vedere**.
         *
         * ⚠️ La risposta giusta è **rilanciare**, non consegnare il consiglio di
         * un'altra palestra a chi non ha il diritto di leggerlo. 🚨 Ed è il
         * motivo per cui il `catch` non si accontenta del codice `23000`: la
         * prova che sia la nostra corsa è che **la riga adesso si veda**.
         */
        $altra = $this->creaPalestra('Beta', 'beta', 'BETA2345');

        $this->finta->duranteIlConsiglio(
            fn (array $contesto) => $this->scriviLaVincente(
                $contesto,
                'Consiglio di un altro tenant.',
                $altra->getKey(),
            ),
        );

        $this->withoutExceptionHandling();
        $this->expectException(QueryException::class);

        $this->comeApp($this->iscritto->fresh())->getJson('/api/v1/ai/advice');
    }

    #[Test]
    public function chi_trova_la_cache_non_chiama_il_modello(): void
    {
        $utente = $this->iscritto->fresh();

        $this->comeApp($utente)->getJson('/api/v1/ai/advice')->assertOk();
        $this->comeApp($utente)->getJson('/api/v1/ai/advice')->assertJsonPath('data.cached', true);

        // 💡 È la difesa **economica**: una chiamata sola, un consiglio solo.
        $this->assertSame(1, $this->quanteChiamate());
        $this->assertSame(1, AiAdvice::withoutGlobalScopes()->count());
    }

    private function quanteChiamate(): int
    {
        return count(array_filter(
            $this->finta->calls,
            static fn (array $c): bool => $c['method'] === 'dailyAdvice',
        ));
    }
}
