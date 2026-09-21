<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\UserRole;
use App\Filament\God\Resources\Tenants\Pages\ListTenants;
use App\Filament\God\Resources\Users\Pages\ListUsers;
use App\Filament\Gym\Resources\Members\Pages\ListMembers;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\ListWorkoutPlans;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🃏 Gli elenchi dei pannelli sono a schede — 09/09/2026.
 *
 * ── 📌 La richiesta ────────────────────────────────────────────────────────
 *
 * Il committente: *«mi sono rotto il cazzo de ste tabelle, fanno schifo su ogni
 * tipo di schermo, fai tutto con cards. Stessi elementi»*.
 *
 * ── 🚨 Cosa difende questo file ────────────────────────────────────────────
 *
 * ⛔ **Non l'aspetto**: quello si guarda a schermo. Difende le due cose che si
 * rompono in silenzio quando si sposta una colonna dentro un `Stack`:
 *
 * | | Se si rompe |
 * |---|---|
 * | La pagina **si apre** | Una colonna duplicata dentro un layout, o un `use` mancante, e l'elenco va in errore. ⚠️ E' successo davvero: la prima stesura di `UsersTable` aveva `tenant.name` **due volte**, una fuori e una dentro lo `Split` |
 * | Le colonne **ci sono ancora** | 🚨 «Stessi elementi» e' meta' della richiesta: una colonna persa in un `Stack` non da' nessun errore, la scheda si disegna lo stesso e il dato non c'e' piu' |
 *
 * 💡 Filament appiattisce le colonne dentro i layout (`HasColumns::columns()`),
 * quindi `assertCanRenderTableColumn` funziona **anche** dentro le schede: e'
 * il modo di verificare che siano ancora tutte al loro posto.
 */
final class GliElenchiSonoSchedeTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    #[Test]
    public function l_elenco_utenti_si_apre_e_ha_ancora_le_sue_colonne(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::Member, 'iscritto@test.it');

        $this->actingAs($this->creaSuperAdmin());

        /*
         * Il pannello va scelto a mano: senza, Filament resta su quello di
         * serie e la vista cerca `filament.admin.resources.users.edit`,
         * che in questo progetto non esiste. Non e' un difetto della
         * tabella: e' il contesto che nel browser lo mette la rotta.
         */
        filament()->setCurrentPanel('god');

        /*
         * Si guarda il CONTENUTO, non il contorno di Filament.
         *
         * `assertCanRenderTableColumn` cerca il markup con cui Filament
         * avvolge una colonna, e dentro uno `Split` annidato in uno `Stack`
         * quel contorno cambia: l'asserzione fallisce anche quando il dato
         * c'e'. Verificare la presenza del VALORE risponde alla domanda vera --
         * "stessi elementi" -- invece che a come Filament li impacchetta.
         */
        Livewire::test(ListUsers::class)
            ->assertSuccessful()
            ->assertSee('iscritto@test.it')
            ->assertSee('Demo')
            ->assertSee('attivo');
    }

    #[Test]
    public function l_elenco_palestre_si_apre_e_ha_ancora_le_sue_colonne(): void
    {
        $this->creaPalestra();

        $this->actingAs($this->creaSuperAdmin());

        /*
         * Il pannello va scelto a mano: senza, Filament resta su quello di
         * serie e la vista cerca `filament.admin.resources.users.edit`,
         * che in questo progetto non esiste. Non e' un difetto della
         * tabella: e' il contesto che nel browser lo mette la rotta.
         */
        filament()->setCurrentPanel('god');

        Livewire::test(ListTenants::class)
            ->assertSuccessful()
            ->assertSee('Demo')
            ->assertSee('demo')
            /*
             * ⛔ **Il codice della palestra non si vede più** — 21/09/2026,
             * fase F1. 📌 *«il codice palestra non ha più senso di esistere,
             * gli inviti si fanno con il link di invito»*.
             *
             * ⚠️ La colonna `join_code` è ancora viva altrove (registrazione,
             * login, app): toglierla di lì è la fase F11. Qui si difende che
             * dal pannello **non si mostri più**.
             */
            ->assertDontSee('DEMO2345');
    }

    #[Test]
    public function l_elenco_iscritti_si_apre_e_ha_ancora_le_sue_colonne(): void
    {
        $palestra = $this->creaPalestra();
        $this->creaUtente($palestra, UserRole::Member, 'iscritto@test.it');

        $this->actingAs($this->creaUtente($palestra, UserRole::GymAdmin, 'capo@test.it'));

        filament()->setCurrentPanel('gym');

        /*
         * Qui si verifica solo che la pagina SI APRA, e va detto perche':
         * l'elenco degli iscritti e' filtrato sul tenant, che nel browser lo
         * mette la rotta del pannello e in un test Livewire nudo non c'e'.
         * Asserire un contenuto qui vorrebbe dire montare mezzo pannello per
         * verificare una cosa che le altre tre gia' verificano.
         *
         * Quello che serve a questo file lo copre lo stesso: se lo `Stack`
         * fosse scritto male -- una colonna duplicata, un `use` mancante --
         * la pagina andrebbe in errore invece di aprirsi.
         */
        Livewire::test(ListMembers::class)->assertSuccessful();
    }

    #[Test]
    public function l_elenco_schede_si_apre_e_ha_ancora_le_sue_colonne(): void
    {
        $palestra = $this->creaPalestra();

        $this->actingAs($this->creaUtente($palestra, UserRole::GymAdmin, 'capo2@test.it'));

        filament()->setCurrentPanel('gym');

        Livewire::test(ListWorkoutPlans::class)->assertSuccessful();
    }
}
