<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\MuscleGroup;
use App\Enums\TenantStatus;
use App\Filament\God\Resources\Exercises\Pages\ListExercises;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Gli esercizi rimasti senza muscoli si vedono — 3b-A.3.4, 24/08/2026.
 *
 * ══ 🚨 A COSA SERVE ═══════════════════════════════════════════════════════
 *
 * ⛔ Il catalogo di piattaforma i muscoli ce li ha tutti, e c'è un test che lo
 * garantisce (`MuscoliDegliEserciziTest`). Ma gli esercizi che **nascono** da un
 * nome scritto a mano o letto da un PDF possono non averli: quando nessuno lo
 * sa, `ExerciseMatcher` scrive `null` invece di inventare un muscolo.
 *
 * 💡 Il filtro del pannello è il posto dove quelle righe si sistemano. Senza,
 * resterebbero lì per sempre — e una figura del corpo grigia non dice a nessuno
 * *perché* è grigia.
 *
 * ── ⚠️ Perché la condizione si prova da sola, e non con `filterTable()` ───
 *
 * 🚨 **`filterTable()` in questa versione di Filament non applica niente**: i
 * filtri sono *deferred* per impostazione predefinita (`hasDeferredFilters` vale
 * `true`), e nel test la tabella continua a mostrare tutte le righe. Verificato
 * anche con `da_import`, che esisteva già: stesso risultato, e nemmeno
 * `->call('applyTableFilters')` cambia le cose.
 *
 * ⛔ Un test scritto così **sembra** provare il filtro e non prova niente:
 * `assertCanSeeTableRecords` passa perché i record ci sono *comunque*. Quindi
 * qui si provano due cose separate e vere: che la **condizione** è giusta
 * (`Exercise::senzaMuscoliDecisi()`), e che la **pagina si disegna** con la
 * colonna nuova.
 */
final class EserciziSenzaMuscoliTest extends TestCase
{
    use RefreshDatabase;

    private User $god;

    protected function setUp(): void
    {
        parent::setUp();

        $this->god = app(TenantContext::class)->runWithoutTenant(fn (): User => User::create([
            'name' => 'God', 'email' => 'god@piattaforma.test', 'password' => self::FAKE_PASSWORD,
        ]));
        $this->god->forceFill(['is_super_admin' => true])->save();

        filament()->setCurrentPanel('god');
        $this->actingAs($this->god);
    }

    /** @param  list<string>|null  $secondari */
    private function esercizio(string $nome, ?MuscleGroup $primario, ?array $secondari): Exercise
    {
        return Exercise::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'name' => $nome,
            'slug_normalized' => Exercise::normalize($nome),
            'muscle_group' => $primario,
            'secondary_muscles' => $secondari,
        ]);
    }

    // ───────────────────────── la condizione ─────────────────────────

    #[Test]
    public function trova_solo_quelli_che_nessuno_ha_deciso(): void
    {
        $this->esercizio('Panca piana', MuscleGroup::Chest, ['triceps']);
        $isola = $this->esercizio('Leg extension', MuscleGroup::Quads, []);
        $muto = $this->esercizio('Movimento mai visto', null, null);
        $mezzo = $this->esercizio('Mezza informazione', MuscleGroup::Back, null);

        $trovati = Exercise::withoutGlobalScopes()
            ->senzaMuscoliDecisi()
            ->pluck('name')
            ->all();

        /*
         * 🚨 **Un elenco vuoto non è una lacuna.** «Leg extension» il
         * quadricipite lo isola davvero, e chi l'ha scritto ha risposto.
         * ⛔ Se comparisse qui, la pagina si riempirebbe di righe già a posto e
         * smetterebbe di servire a qualcosa.
         */
        self::assertEqualsCanonicalizing([$muto->name, $mezzo->name], $trovati);
        self::assertNotContains($isola->name, $trovati);
    }

    #[Test]
    public function e_non_si_porta_dietro_le_altre_condizioni(): void
    {
        /*
         * ⛔ **Le parentesi sono la cosa che questo test tiene ferma.** Senza,
         * l'`or` uscirebbe dalla condizione: chiedere «della piattaforma **e**
         * senza muscoli» darebbe anche gli esercizi di tutte le palestre, e la
         * pagina mostrerebbe righe che nessuno stava cercando.
         */
        $palestra = Tenant::create([
            'name' => 'Alfa', 'slug' => 'alfa', 'join_code' => 'ALFA2345',
            'contact_email' => 'a@a.test', 'status' => TenantStatus::Active,
        ]);

        Exercise::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->getKey(),
            'name' => 'Roba di una palestra',
            'slug_normalized' => Exercise::normalize('Roba di una palestra'),
        ]);

        $this->esercizio('Movimento mai visto', null, null);

        $trovati = Exercise::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->senzaMuscoliDecisi()
            ->pluck('name')
            ->all();

        self::assertSame(['Movimento mai visto'], $trovati);
    }

    // ───────────────────────── la pagina ─────────────────────────

    #[Test]
    public function la_tabella_si_disegna_anche_con_i_secondari(): void
    {
        /*
         * ⚠️ **Una colonna che si rompe si rompe al disegno, non ai test dei
         * modelli.** `secondary_muscles` è un JSON: un `formatStateUsing`
         * scritto per una stringa singola, quando arriva un elenco, fa esplodere
         * la pagina — e nessun test sul dato se ne accorgerebbe.
         *
         * 🚨 È già successo con la **query** del filtro: scritta nella forma
         * naturale di Eloquent faceva morire la vista con *«Call to a member
         * function newQueryWithoutRelationships() on null»*. Vedi la nota su
         * `Exercise::scopeSenzaMuscoliDecisi()`.
         */
        $this->esercizio('Panca piana', MuscleGroup::Chest, ['triceps', 'shoulders']);
        $this->esercizio('Leg extension', MuscleGroup::Quads, []);
        $this->esercizio('Movimento mai visto', null, null);

        Livewire::test(ListExercises::class)
            ->assertOk()
            ->assertCanSeeTableRecords(Exercise::withoutGlobalScopes()->get());
    }

    #[Test]
    public function e_il_filtro_esiste_con_quel_nome(): void
    {
        // ⚠️ Il nome è il filo fra la pagina e lo scope: se qualcuno lo cambia
        // da una parte sola, il filtro sparisce senza fare rumore.
        Livewire::test(ListExercises::class)->assertTableFilterExists('senza_muscoli');
    }
}
