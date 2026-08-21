<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Campagna;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\BelongsToTenantOrGlobal;
use App\Models\ProfiloPubblico;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Il gate della fase B1.
 *
 * Il primo test è il più importante di tutta la codebase: **si accorge da solo**
 * quando qualcuno aggiunge un modello con `tenant_id` e si dimentica il trait.
 * Senza, l'isolamento fra palestre dipenderebbe dalla memoria di chi scrive, e
 * la dimenticanza non darebbe nessun errore — solo dati di altri clienti.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 🚨 **Le eccezioni, e ognuna deve avere una ragione scritta QUI.**
     *
     * ── ⚠️ Perché un elenco, in un test che esiste per non averne ──────────
     *
     * Il valore di questo file è accorgersi da solo di un modello dimenticato.
     * Un elenco di eccezioni è esattamente il modo in cui una guardia del genere
     * smette di guardare: basta aggiungerci un nome quando dà fastidio.
     *
     * 💡 Quindi la regola è **una sola e severa**: si aggiunge un nome qui solo
     * se la riga di spiegazione qui sotto regge da sola. Se la spiegazione è
     * «per far passare il test», il modello va sistemato, non elencato.
     *
     * @var array<class-string, string>
     */
    private const ECCEZIONI_MOTIVATE = [
        /*
         * 🚨 `profili_pubblici` esiste **apposta** per essere visto da fuori.
         *
         * Il `TenantScope` filtra per la palestra di chi guarda: applicato qui,
         * ogni palestra vedrebbe soltanto la propria scheda — cioè il catalogo
         * pubblico non mostrerebbe niente a nessuno, che è l'unica cosa che
         * quella tabella deve fare (M2).
         *
         * ⚠️ **L'isolamento qui lo fa `visibile`**, non lo scope: una scheda
         * spenta non compare, e non c'è nessuna rete sotto. Ogni query che legge
         * questa tabella per mostrarla a qualcuno **deve** filtrarlo, e c'è un
         * test che lo verifica (`CatalogoPubblicoTest`).
         *
         * 💡 E non è un dato sensibile: sono il nome e la città di un'attività
         * commerciale che ha **chiesto** di comparire.
         */
        ProfiloPubblico::class => 'Catalogo pubblico (M2): deve essere visibile fuori dal proprio tenant. Isolamento tramite `visibile`.',

        /*
         * 🚨 `campagne` viene letta **mentre si risponde a chiunque**.
         *
         * L'ordinamento del catalogo fa una `JOIN` su questa tabella per sapere
         * chi sta pagando, e quella query gira anche per un visitatore anonimo:
         * col `TenantScope` non troverebbe mai nessuna campagna, e la pubblicità
         * semplicemente non funzionerebbe — senza nessun errore.
         *
         * ⚠️ **L'isolamento in scrittura c'è ed è altrove**: una campagna si
         * modifica solo dalla propria pagina del pannello, che la cerca con
         * `tenant_id` o `user_id` **esplicito** e mai dal contesto. E i due
         * indici unici impediscono che qualcuno ne abbia due.
         *
         * 💡 In lettura non c'è niente da proteggere: il fatto che una palestra
         * si stia facendo pubblicità è, per definizione, quello che vuole far
         * sapere. Gli importi restano nel pannello, non escono dall'API.
         */
        Campagna::class => 'Pubblicità nel catalogo (M5): la legge il catalogo pubblico, anche per gli anonimi. Scrittura solo dalla propria pagina, con chiave esplicita.',
    ];

    private Tenant $alfa;

    private Tenant $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = Tenant::create(['name' => 'Alfa', 'slug' => 'alfa',
            'join_code' => 'ALFA2345', 'contact_email' => 'a@a.test']);
        $this->beta = Tenant::create(['name' => 'Beta', 'slug' => 'beta',
            'join_code' => 'BETA2345', 'contact_email' => 'b@b.test']);

        foreach ([$this->alfa, $this->beta] as $t) {
            $this->context()->runAs($t, fn () => Role::create([
                'name' => 'member', 'guard_name' => 'web', 'tenant_id' => $t->id,
            ]));
        }
    }

    private function context(): TenantContext
    {
        return app(TenantContext::class);
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Ogni modello con `tenant_id` deve usare uno dei due trait.
     *
     * Enumera i file in `app/Models`, non un elenco scritto a mano: un elenco
     * andrebbe aggiornato, e nessuno aggiorna un elenco quando aggiunge un
     * modello di fretta. Fallisce **nominando la classe**, così il messaggio
     * dice cosa fare invece di far partire una caccia.
     */
    #[Test]
    public function it_scopes_every_tenant_owned_model(): void
    {
        $senzaTrait = [];
        $controllati = 0;

        foreach ($this->modelliApplicativi() as $classe) {
            $model = new $classe;
            $tabella = $model->getTable();

            if (! Schema::hasTable($tabella) || ! Schema::hasColumn($tabella, 'tenant_id')) {
                continue;
            }

            if (array_key_exists($classe, self::ECCEZIONI_MOTIVATE)) {
                continue;
            }

            $controllati++;
            $trait = class_uses_recursive($classe);

            $haTrait = in_array(BelongsToTenant::class, $trait, true)
                || in_array(BelongsToTenantOrGlobal::class, $trait, true);

            if (! $haTrait) {
                $senzaTrait[] = $classe;

                continue;
            }

            // BelongsToTenantOrGlobal è ammesso SOLO dove la colonna è davvero
            // nullable: se diventasse NOT NULL, quel trait smetterebbe di avere
            // senso e il modello dovrebbe tornare a BelongsToTenant.
            if (in_array(BelongsToTenantOrGlobal::class, $trait, true)) {
                $colonna = Schema::getColumnListing($tabella);
                $this->assertContains('tenant_id', $colonna);

                $nullable = collect(Schema::getColumns($tabella))
                    ->firstWhere('name', 'tenant_id')['nullable'] ?? false;

                $this->assertTrue(
                    $nullable,
                    "{$classe} usa BelongsToTenantOrGlobal ma {$tabella}.tenant_id NON è nullable: "
                    .'senza righe globali quel trait è solo un filtro più debole. Usare BelongsToTenant.',
                );
            }
        }

        $this->assertGreaterThan(0, $controllati, 'Nessun modello con tenant_id: il test non sta verificando nulla.');

        $this->assertSame([], $senzaTrait, sprintf(
            'Questi modelli hanno la colonna tenant_id ma NON usano BelongsToTenant '
            ."né BelongsToTenantOrGlobal:\n  - %s\n"
            .'Senza il trait le loro query NON sono limitate alla palestra: '
            .'ogni utente vedrebbe i dati di tutti i clienti.',
            implode("\n  - ", $senzaTrait),
        ));
    }

    /**
     * 🚨 Ogni modello scopato deve **davvero** filtrare, non solo avere il trait.
     *
     * ── Perché non basta il test qui sopra ──────────────────────────────────
     *
     * `it_scopes_every_tenant_owned_model()` verifica che il trait ci sia. È il
     * 90% dei casi, ma controlla una **dichiarazione**, non un effetto: un
     * modello che riscrivesse `booted()` senza chiamare `parent::booted()`, o
     * che aggiungesse `withoutGlobalScopes()` in una `newQuery()` propria,
     * avrebbe il trait e non filtrerebbe niente. Il primo test direbbe che è
     * tutto a posto.
     *
     * ── 💡 Come fa a funzionare senza factory ───────────────────────────────
     *
     * Al 13/08/2026 esiste **una sola** factory (`UserFactory`), quindi «crea una
     * riga per ogni modello in due palestre e confronta» non è scrivibile senza
     * prima scrivere quindici factory. Ma la riga non serve: si guarda l'**SQL
     * che il modello genera**.
     *
     * Il criterio è che la stessa query, compilata dentro due palestre diverse,
     * **deve venire diversa**. Se viene identica, non c'è nessun filtro sul
     * tenant — qualunque sia il motivo, e senza che il test debba conoscere la
     * forma della `WHERE` (`= ?` per `BelongsToTenant`, `= ? OR IS NULL` per
     * `BelongsToTenantOrGlobal`).
     *
     * 🚨 **Ed è il punto di tutta la fase F1**: un modello aggiunto in F4 (i
     * piani) o in F6 (gli inviti) con lo scope sbagliato fa fallire un test che
     * nessuno ha scritto per lui.
     */
    #[Test]
    public function it_filters_every_scoped_model_by_the_current_tenant(): void
    {
        $senzaFiltro = [];
        $controllati = 0;

        foreach ($this->modelliApplicativi() as $classe) {
            $model = new $classe;
            $tabella = $model->getTable();

            if (! Schema::hasTable($tabella) || ! Schema::hasColumn($tabella, 'tenant_id')) {
                continue;
            }

            if (array_key_exists($classe, self::ECCEZIONI_MOTIVATE)) {
                continue;
            }

            $controllati++;

            $inAlfa = $this->context()->runAs($this->alfa, fn (): string => $classe::query()->toRawSql());
            $inBeta = $this->context()->runAs($this->beta, fn (): string => $classe::query()->toRawSql());

            if ($inAlfa === $inBeta || ! str_contains($inAlfa, 'tenant_id')) {
                $senzaFiltro[] = $classe;
            }
        }

        $this->assertGreaterThan(0, $controllati, 'Nessun modello con tenant_id: il test non sta verificando nulla.');

        $this->assertSame([], $senzaFiltro, sprintf(
            "Questi modelli hanno `tenant_id` ma la loro query NON cambia fra due palestre:\n  - %s\n"
            .'Il trait potrebbe esserci lo stesso: quello che manca è il filtro. '
            .'Ogni utente vedrebbe le righe di tutti i clienti.',
            implode("\n  - ", $senzaFiltro),
        ));
    }

    #[Test]
    public function it_hides_other_tenants_records(): void
    {
        $this->context()->runAs($this->alfa, fn () => User::create([
            'name' => 'Anna', 'email' => 'anna@alfa.test', 'password' => self::FAKE_PASSWORD,
        ]));
        $this->context()->runAs($this->beta, fn () => User::create([
            'name' => 'Bruno', 'email' => 'bruno@beta.test', 'password' => self::FAKE_PASSWORD,
        ]));

        $this->assertSame(1, $this->context()->runAs($this->alfa, fn () => User::count()));
        $this->assertSame(1, $this->context()->runAs($this->beta, fn () => User::count()));

        $this->assertNull(
            $this->context()->runAs($this->beta, fn () => User::where('email', 'anna@alfa.test')->first()),
            'Beta è riuscita a leggere un utente di Alfa.',
        );
    }

    #[Test]
    public function it_auto_fills_tenant_id_on_create(): void
    {
        $u = $this->context()->runAs($this->alfa, fn () => User::create([
            'name' => 'Senza tenant esplicito', 'email' => 'x@alfa.test', 'password' => self::FAKE_PASSWORD,
        ]));

        $this->assertSame($this->alfa->id, $u->tenant_id);
    }

    #[Test]
    public function it_restores_context_after_exception_in_run_as(): void
    {
        $this->context()->runAs($this->alfa, function (): void {
            try {
                $this->context()->runAs($this->beta, function (): void {
                    throw new RuntimeException('scoppia');
                });
            } catch (RuntimeException) {
                // atteso
            }

            $this->assertSame(
                $this->alfa->id,
                $this->context()->id(),
                "Dopo un'eccezione dentro runAs il contesto è rimasto su Beta: "
                .'un job che fallisce lascerebbe il tenant sbagliato al giro successivo.',
            );
        });

        $this->assertNull($this->context()->id());
    }

    #[Test]
    public function it_scopes_roles_per_tenant(): void
    {
        $u = $this->context()->runAs($this->alfa, function (): User {
            $u = User::create(['name' => 'Tina', 'email' => 'tina@alfa.test', 'password' => self::FAKE_PASSWORD]);
            $u->assignRole('member');

            return $u;
        });

        $inAlfa = $this->context()->runAs($this->alfa, function () use ($u): bool {
            $f = User::find($u->id);
            $f->unsetRelation('roles');

            return $f->hasRole('member');
        });

        $inBeta = $this->context()->runAs($this->beta, function () use ($u): bool {
            $f = User::withoutGlobalScopes()->find($u->id);
            $f->unsetRelation('roles');

            return $f->hasRole('member');
        });

        $this->assertTrue($inAlfa);
        $this->assertFalse($inBeta, 'Il ruolo assegnato in Alfa vale anche in Beta: la modalità teams non sta funzionando.');
    }

    #[Test]
    public function it_keeps_super_admin_across_tenants(): void
    {
        $god = $this->context()->runWithoutTenant(fn () => User::create([
            'name' => 'God', 'email' => 'god@piattaforma.test', 'password' => self::FAKE_PASSWORD,
        ]));
        $god->forceFill(['is_super_admin' => true])->save();

        $this->assertTrue($this->context()->runWithoutTenant(
            fn () => User::withoutGlobalScopes()->find($god->id)->isSuperAdmin(),
        ));

        $this->assertTrue(
            $this->context()->runAs($this->alfa,
                fn () => User::withoutGlobalScopes()->find($god->id)->isSuperAdmin()),
            'Il super admin perde i poteri dentro una palestra: sarebbe inutile proprio dove serve.',
        );
    }

    #[Test]
    public function it_allows_the_same_email_in_different_tenants(): void
    {
        $a = $this->context()->runAs($this->alfa, fn () => User::create([
            'name' => 'Mario', 'email' => 'mario@esempio.test', 'password' => self::FAKE_PASSWORD,
        ]));
        $b = $this->context()->runAs($this->beta, fn () => User::create([
            'name' => 'Mario', 'email' => 'mario@esempio.test', 'password' => self::FAKE_PASSWORD,
        ]));

        $this->assertNotSame($a->id, $b->id);
    }

    #[Test]
    public function it_rejects_the_same_email_twice_in_one_tenant(): void
    {
        $this->context()->runAs($this->alfa, fn () => User::create([
            'name' => 'Mario', 'email' => 'mario@esempio.test', 'password' => self::FAKE_PASSWORD,
        ]));

        $this->expectException(UniqueConstraintViolationException::class);

        $this->context()->runAs($this->alfa, fn () => User::create([
            'name' => 'Doppione', 'email' => 'mario@esempio.test', 'password' => self::FAKE_PASSWORD,
        ]));
    }

    #[Test]
    public function it_does_not_filter_without_context(): void
    {
        $this->context()->runAs($this->alfa, fn () => User::create([
            'name' => 'A', 'email' => 'a@alfa.test', 'password' => self::FAKE_PASSWORD,
        ]));
        $this->context()->runAs($this->beta, fn () => User::create([
            'name' => 'B', 'email' => 'b@beta.test', 'password' => self::FAKE_PASSWORD,
        ]));

        // Comportamento VOLUTO, non una svista: seeder, migration e il pannello
        // /god girano legittimamente senza palestra. La sicurezza sta nel fatto
        // che ogni richiesta HTTP autenticata imposta sempre il contesto.
        $this->assertSame(2, $this->context()->runWithoutTenant(fn () => User::count()));
    }

    /**
     * 🚨 **La guardia sulla guardia: l'elenco delle eccezioni non deve marcire.**
     *
     * ── Perché serve ────────────────────────────────────────────────────────
     *
     * Un elenco di eccezioni è il punto debole di ogni controllo automatico:
     * ⚠️ una voce che resta lì dopo che il modello è stato rinominato, o dopo
     * che ha smesso di avere `tenant_id`, non fa rumore — semplicemente smette
     * di escludere qualcosa e nessuno se ne accorge. E se un giorno un modello
     * nuovo prendesse per caso quel nome, entrerebbe esente senza che nessuno
     * l'abbia deciso.
     *
     * 💡 Quindi ogni eccezione deve **esistere**, **avere davvero `tenant_id`**
     * (altrimenti è inutile e va tolta) e portare una spiegazione non vuota.
     */
    #[Test]
    public function ogni_eccezione_all_isolamento_e_ancora_giustificata(): void
    {
        foreach (self::ECCEZIONI_MOTIVATE as $classe => $perche) {
            $this->assertTrue(
                class_exists($classe),
                "L'eccezione {$classe} non esiste più: va tolta dall'elenco.",
            );

            $tabella = (new $classe)->getTable();

            $this->assertTrue(
                Schema::hasTable($tabella) && Schema::hasColumn($tabella, 'tenant_id'),
                "{$classe} è elencata come eccezione ma {$tabella} non ha `tenant_id`: "
                .'l\'eccezione non serve a niente e va tolta.',
            );

            $this->assertNotSame('', trim($perche), "L'eccezione {$classe} non ha una ragione scritta.");
        }
    }

    // ─────────────────────────────────────────────────────────────────────

    /**
     * Tutti i modelli in `app/Models`, ricavati dai file.
     *
     * @return list<class-string<Model>>
     */
    private function modelliApplicativi(): array
    {
        $base = app_path('Models');
        $classi = [];

        $iter = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base));

        foreach ($iter as $file) {
            if ($file->isDir() || $file->getExtension() !== 'php') {
                continue;
            }

            $relativo = str_replace([$base.DIRECTORY_SEPARATOR, '.php'], '', $file->getPathname());
            $classe = 'App\\Models\\'.str_replace(DIRECTORY_SEPARATOR, '\\', $relativo);

            if (! class_exists($classe)) {
                continue;
            }

            $r = new ReflectionClass($classe);

            if ($r->isAbstract() || ! $r->isSubclassOf(Model::class)) {
                continue;
            }

            $classi[] = $classe;
        }

        return $classi;
    }
}
