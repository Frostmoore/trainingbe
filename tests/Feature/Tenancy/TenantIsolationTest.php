<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\BelongsToTenantOrGlobal;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
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
            "Questi modelli hanno la colonna tenant_id ma NON usano BelongsToTenant "
            ."né BelongsToTenantOrGlobal:\n  - %s\n"
            ."Senza il trait le loro query NON sono limitate alla palestra: "
            .'ogni utente vedrebbe i dati di tutti i clienti.',
            implode("\n  - ", $senzaTrait),
        ));
    }

    #[Test]
    public function it_hides_other_tenants_records(): void
    {
        $this->context()->runAs($this->alfa, fn () => User::create([
            'name' => 'Anna', 'email' => 'anna@alfa.test', 'password' => 'segretissima1',
        ]));
        $this->context()->runAs($this->beta, fn () => User::create([
            'name' => 'Bruno', 'email' => 'bruno@beta.test', 'password' => 'segretissima1',
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
            'name' => 'Senza tenant esplicito', 'email' => 'x@alfa.test', 'password' => 'segretissima1',
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
            $u = User::create(['name' => 'Tina', 'email' => 'tina@alfa.test', 'password' => 'segretissima1']);
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
            'name' => 'God', 'email' => 'god@piattaforma.test', 'password' => 'segretissima1',
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
            'name' => 'Mario', 'email' => 'mario@esempio.test', 'password' => 'segretissima1',
        ]));
        $b = $this->context()->runAs($this->beta, fn () => User::create([
            'name' => 'Mario', 'email' => 'mario@esempio.test', 'password' => 'segretissima1',
        ]));

        $this->assertNotSame($a->id, $b->id);
    }

    #[Test]
    public function it_rejects_the_same_email_twice_in_one_tenant(): void
    {
        $this->context()->runAs($this->alfa, fn () => User::create([
            'name' => 'Mario', 'email' => 'mario@esempio.test', 'password' => 'segretissima1',
        ]));

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        $this->context()->runAs($this->alfa, fn () => User::create([
            'name' => 'Doppione', 'email' => 'mario@esempio.test', 'password' => 'segretissima1',
        ]));
    }

    #[Test]
    public function it_does_not_filter_without_context(): void
    {
        $this->context()->runAs($this->alfa, fn () => User::create([
            'name' => 'A', 'email' => 'a@alfa.test', 'password' => 'segretissima1',
        ]));
        $this->context()->runAs($this->beta, fn () => User::create([
            'name' => 'B', 'email' => 'b@beta.test', 'password' => 'segretissima1',
        ]));

        // Comportamento VOLUTO, non una svista: seeder, migration e il pannello
        // /god girano legittimamente senza palestra. La sicurezza sta nel fatto
        // che ogni richiesta HTTP autenticata imposta sempre il contesto.
        $this->assertSame(2, $this->context()->runWithoutTenant(fn () => User::count()));
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
