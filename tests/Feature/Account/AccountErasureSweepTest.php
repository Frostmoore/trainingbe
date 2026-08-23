<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Enums\MealType;
use App\Enums\UserRole;
use App\Models\AiAdvice;
use App\Models\ChatKey;
use App\Models\Conversation;
use App\Models\DeviceToken;
use App\Models\FoodEntry;
use App\Models\FoodFavorite;
use App\Models\RecoveryKey;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Account\AccountEraser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\ScriveBusteCifrate;
use Tests\TestCase;

/**
 * S9.3 — la rassegna di tutte le tabelle con `user_id`.
 *
 * ── 🚨 Perché questo test esiste, raccontato per esteso ────────────────────
 *
 * `AccountEraser` **non cancellava `health_readings`**. HRV e battito a riposo
 * — categorie particolari ai sensi dell'art. 9 — **sopravvivevano alla
 * cancellazione dell'account**, cioè una violazione del diritto alla
 * cancellazione. Ed è rimasta invisibile per settimane, per un motivo che vale
 * la pena capire: **la tabella era nata DOPO quel metodo**, e nessuno aveva più
 * riaperto l'eraser.
 *
 * 🚨 **La lezione non è «ricordarsi di aggiornare l'eraser»: è che una regola
 * affidata alla memoria è già rotta.** Nessun test falliva, nessuna schermata
 * si rompeva, nessun utente se ne lamentava — perché il difetto si manifesta
 * solo nei dati di qualcuno che se n'è già andato.
 *
 * ── Come funziona, e perché fallisce «al contrario» ────────────────────────
 *
 * Enumera le tabelle **dallo schema reale**, non da un elenco scritto a mano:
 * un elenco andrebbe aggiornato, e chi si dimentica di aggiornare l'eraser si
 * dimenticherebbe anche dell'elenco.
 *
 * ⚠️ **Chi aggiunge una tabella con `user_id` fa fallire questo test**, e deve
 * scegliere: o la cancella nell'eraser, o la mette in [self::CONSERVATE] con
 * scritto **perché**. È deliberato: la scelta va **presa**, non ereditata dal
 * silenzio.
 *
 * È lo stesso modo di ragionare di `TenantIsolationTest`, che si accorge da solo
 * quando qualcuno aggiunge un modello con `tenant_id` e dimentica il trait.
 */
class AccountErasureSweepTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use ScriveBusteCifrate;

    /**
     * Le tabelle che **devono** conservare righe, con il motivo.
     *
     * 🚨 Ogni voce qui dentro è una decisione, non un'eccezione di comodo.
     *
     * @var array<string, string>
     */
    private const CONSERVATE = [
        'users' => 'La riga resta anonimizzata e in soft delete: `messages.sender_id` e '
            .'`audit_logs.user_id` ci puntano, e una cancellazione a cascata porterebbe via '
            .'il registro di controllo — cioè esattamente cio\' che serve il giorno in cui '
            .'qualcuno contesta una cancellazione.',

        'audit_logs' => 'E\' il registro di controllo. Cancellarlo insieme alla persona '
            .'significherebbe non poter piu\' dimostrare cosa e\' successo, compresa la '
            .'cancellazione stessa.',

        'ai_usage_logs' => 'Contiene conteggi di token, non contenuti: e\' la base della quota '
            .'e della fatturazione. Contare non e\' conservare.',

    ];

    private Tenant $alfa;

    private User $iscritto;

    private User $trainer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->trainer = $this->creaUtente($this->alfa, UserRole::Trainer, 'anna@alfa.test');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');

        $this->trainer->assignedMembers()->attach($this->iscritto->id, [
            'tenant_id' => $this->alfa->id, 'assigned_at' => now(),
        ]);
    }

    /**
     * 🚨 **Il test che avrebbe trovato il difetto di S1.7.**
     *
     * Riempie **ogni** tabella raggiungibile con dati di questa persona, cancella
     * l'account, e pretende che non resti niente — tranne cio' che e' scritto in
     * [self::CONSERVATE] con la sua ragione.
     */
    #[Test]
    public function nothing_of_a_deleted_person_survives_in_any_table(): void
    {
        $this->riempiTutto();

        $id = $this->iscritto->getKey();
        $tabelle = $this->tabelleConUserId();

        // Prima: la persona c'è davvero in giro. Senza questo controllo il test
        // passerebbe anche se `riempiTutto()` non scrivesse niente — cioè
        // dimostrerebbe che «non resta niente» senza che ci fosse mai stato
        // niente.
        $prima = $this->righePerTabella($tabelle, $id);
        $this->assertGreaterThanOrEqual(
            6,
            count(array_filter($prima)),
            'Il test non ha popolato abbastanza tabelle per dimostrare qualcosa.',
        );

        app(AccountEraser::class)->erase($this->iscritto);

        $sopravvissute = [];

        foreach ($this->righePerTabella($tabelle, $id) as $tabella => $righe) {
            if ($righe > 0 && ! array_key_exists($tabella, self::CONSERVATE)) {
                $sopravvissute[] = "{$tabella} ({$righe} righe)";
            }
        }

        $this->assertSame([], $sopravvissute, implode("\n", [
            '🚨 Dati di un utente cancellato sono sopravvissuti in: '
                .implode(', ', $sopravvissute),
            '',
            'Delle due l\'una:',
            '  1. la tabella va cancellata in AccountEraser::cancellaDatiPersonali(), oppure',
            '  2. c\'è un motivo perché resti, e va scritto in self::CONSERVATE.',
            '',
            'Non lasciarla così: è il difetto di health_readings, che è rimasto',
            'invisibile per settimane perché la tabella era nata dopo l\'eraser.',
        ]));
    }

    /**
     * ⚠️ **E le tabelle raggiunte per una colonna che non si chiama `user_id`.**
     *
     * La rassegna sopra guarda `user_id`, ma i dati di una persona si raggiungono
     * anche per `member_id` e `sender_id`. Sono pochi casi e sono elencati qui a
     * mano — 💡 ma ognuno ha un comportamento **voluto e diverso**, che è la
     * ragione per cui non stanno insieme agli altri.
     */
    #[Test]
    public function the_tables_reached_by_another_column_behave_as_decided(): void
    {
        $this->riempiTutto();

        $id = $this->iscritto->getKey();

        app(AccountEraser::class)->erase($this->iscritto);

        // I messaggi RESTANO, ed è deliberato: cancellarli svuoterebbe la
        // conversazione dal lato del trainer, che si ritroverebbe le proprie
        // risposte senza le domande. Dopo S6 sono comunque buste cifrate: senza
        // la chiave — che è sparita col telefono — non identificano nessuno.
        $this->assertGreaterThan(
            0,
            DB::table('messages')->where('sender_id', $id)->count(),
            'La conversazione del trainer è stata svuotata.',
        );

        // La conversazione resta per lo stesso motivo.
        $this->assertGreaterThan(
            0,
            DB::table('conversations')->where('member_id', $id)->count(),
        );

        // Le schede assegnate **che si è scritto da solo** spariscono; quelle
        // prescritte dal trainer restano, perché sono lavoro suo.
        $this->assertSame(
            0,
            DB::table('workout_plans')
                ->where('member_id', $id)
                ->where('created_by', $id)
                ->count(),
        );
    }

    /**
     * 🚨 **Il legame con Google e Apple si spezza — difetto trovato da questo
     * test, non da una prova a mano.**
     *
     * `users` resta in **soft delete**, quindi la cascade della foreign key
     * **non scatta**: la riga di `social_identities` sopravviveva alla
     * cancellazione dell'account.
     *
     * ⚠️ **Il sintomo non è quello che verrebbe da pensare.** Non si rientra
     * nell'account cancellato: `SocialIdentity::user()` è una `belongsTo` verso
     * un modello con `SoftDeletes`, quindi restituisce `null` e
     * `accediConIdentitaNota()` risponde *«Non è stato possibile completare
     * l'accesso»*.
     *
     * 🚨 Il vero danno è peggiore e silenzioso: **quella persona non può più
     * registrarsi, mai**, con quell'account Google. L'identità risulta presa da
     * un utente che non esiste più, e il messaggio d'errore non dice niente di
     * utile — né a lei, né a chi le risponde in assistenza.
     */
    #[Test]
    public function the_social_link_is_broken_so_the_person_can_sign_up_again(): void
    {
        DB::table('social_identities')->insert([
            'user_id' => $this->iscritto->getKey(),
            'provider' => 'google',
            'provider_user_id' => 'sub-di-prova-123',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(AccountEraser::class)->erase($this->iscritto);

        $this->assertSame(
            0,
            DB::table('social_identities')
                ->where('user_id', $this->iscritto->getKey())
                ->count(),
            'L\'identità social sopravvive: quella persona non potrà mai più '
            .'registrarsi con quell\'account Google.',
        );
    }

    // ───────────────────────── interni ─────────────────────────

    /**
     * Le tabelle con una colonna `user_id`, lette **dallo schema reale**.
     *
     * ⚠️ Si saltano quelle dell'infrastruttura di Laravel (code, cache,
     * sessioni): non sono dati personali conservati da noi, e `jobs` contiene
     * roba che si consuma da sola in minuti.
     *
     * @return list<string>
     */
    private function tabelleConUserId(): array
    {
        $infrastruttura = [
            'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs',
            'password_reset_tokens', 'sessions', 'personal_access_tokens',
        ];

        $trovate = [];

        foreach (Schema::getTableListing() as $tabella) {
            $nome = str_contains($tabella, '.')
                ? substr($tabella, (int) strrpos($tabella, '.') + 1)
                : $tabella;

            if (in_array($nome, $infrastruttura, true)) {
                continue;
            }

            if (Schema::hasColumn($nome, 'user_id')) {
                $trovate[] = $nome;
            }
        }

        sort($trovate);

        return $trovate;
    }

    /**
     * @param  list<string>  $tabelle
     * @return array<string, int>
     */
    private function righePerTabella(array $tabelle, int $userId): array
    {
        $conteggi = [];

        foreach ($tabelle as $t) {
            $conteggi[$t] = DB::table($t)->where('user_id', $userId)->count();
        }

        // `users` si conta per chiave primaria, non per `user_id`.
        $conteggi['users'] = DB::table('users')->where('id', $userId)->count();

        return $conteggi;
    }

    /** Dati di questa persona in ogni tabella che ne può contenere. */
    private function riempiTutto(): void
    {
        $id = $this->iscritto->getKey();

        $this->ctx()->runAs($this->alfa, function () use ($id): void {
            FoodEntry::create([
                'user_id' => $id, 'eaten_at' => Carbon::now(),
                'meal' => MealType::Lunch, 'description' => 'Pasto', 'kcal' => 700,
            ]);

            FoodFavorite::create([
                'user_id' => $id, 'description' => 'Yogurt', 'kcal' => 120,
            ]);

            /*
             * 'daily_burns' e 'workout_sessions' non si seminano piu': le
             * tabelle sono cadute con la FASE 11.6.3 (23/08/2026) e gli
             * allenamenti stanno sul telefono. La spazzata le trovava
             * sopravvissute perche' AccountEraser non le cancellava piu' —
             * giustamente, visto che non esistono.
             */

            AiAdvice::create([
                'user_id' => $id, 'date' => Carbon::today(),
                'context_hash' => 'abc', 'body' => 'Bevi acqua.',
            ]);

            DeviceToken::create([
                'tenant_id' => $this->alfa->id, 'user_id' => $id,
                'token' => 'fcm-di-prova', 'platform' => 'android',
            ]);

            $conversazione = Conversation::between($this->trainer, $this->iscritto);
            $conversazione->messages()->create([
                'sender_id' => $id,
                ...$this->busta('Ho un problema al ginocchio'),
            ]);
        });

        RecoveryKey::create([
            'user_id' => $id, 'version' => 1, 'kdf' => 'argon2id13',
            'ops_limit' => 3, 'mem_limit' => 64 * 1024 * 1024,
            'salt' => base64_encode(str_repeat('s', 16)),
            'nonce' => base64_encode(str_repeat('n', 24)),
            'wrapped_key' => base64_encode(str_repeat('k', 48)),
        ]);

        ChatKey::create([
            'user_id' => $id,
            'public_key' => base64_encode(str_repeat('p', 32)),
        ]);
    }
}
