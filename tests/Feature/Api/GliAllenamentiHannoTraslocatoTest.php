<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Gli allenamenti hanno traslocato — FASE 11.6, 21/08/2026.
 *
 * == 🚨 UN TEST CHE DIFENDE UN'ASSENZA ======================================
 *
 * 📌 Il committente: *«Nessun allenamento deve risiedere sul server, devono
 * stare tutti nell'app»*. E il 16/08, sulle conseguenze: *«Niente, nemmeno se
 * si allena»*.
 *
 * ⚠️ Qui non si prova che qualcosa funzioni: si prova che **non c'e' piu'**. E'
 * il genere di test che sembra inutile finche' qualcuno, fra sei mesi, riapre
 * una rotta «solo per il pannello» — e rimette sul server un dato che questa
 * fase ha tolto apposta.
 *
 * 🚨 Le regole che quelle rotte facevano rispettare **non sono sparite**: si
 * sono spostate sul telefono, e hanno i loro test la':
 *
 * | Regola | Dove sta adesso |
 * |---|---|
 * | `MET x kg x ore`, e il MET per esercizio | `test/calorie_allenamento_test.dart` |
 * | Il valore a mano vince e non si somma | idem |
 * | Quello scritto non si sovrascrive | `test/seduta_dallarchivio_test.dart` |
 * | Il target comprende le bruciate | `test/target_del_giorno_test.dart` |
 */
class GliAllenamentiHannoTraslocatoTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $alfa;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alfa = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->alfa, UserRole::Member, 'mario@alfa.test');
    }

    /**
     * ⛔ Le sette rotte delle sedute non rispondono piu'.
     *
     * ⚠️ **404 e non 401**: se rispondessero «non autenticato» vorrebbe dire che
     * esistono ancora e sono solo protette — un'altra cosa.
     */
    #[Test]
    public function the_workout_session_routes_are_gone(): void
    {
        $app = $this->comeApp($this->iscritto);

        $app->getJson('/api/v1/workout-sessions')->assertNotFound();
        $app->postJson('/api/v1/workout-sessions')->assertNotFound();
        $app->getJson('/api/v1/workout-sessions/1')->assertNotFound();
        $app->postJson('/api/v1/workout-sessions/1/sets')->assertNotFound();
        $app->postJson('/api/v1/workout-sessions/1/finish')->assertNotFound();
        $app->patchJson('/api/v1/workout-sessions/1/kcal')->assertNotFound();
        $app->deleteJson('/api/v1/workout-sessions/1')->assertNotFound();
    }

    #[Test]
    public function the_manual_burn_route_is_gone(): void
    {
        // 💡 La dichiarazione «oggi ho bruciato 800» si scrive in
        // `BruciateDichiarate`, sull'archivio del telefono.
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/daily-burn', ['date' => '2026-08-21', 'kcal' => 800])
            ->assertNotFound();
    }

    /**
     * 🚨 **Il trasloco invece c'e', ed e' l'unica via rimasta.**
     *
     * ⚠️ Se questo test fallisse mentre passano i due sopra, vorrebbe dire aver
     * tolto le rotte **senza** lasciare a nessuno il modo di portarsi via i
     * propri dati: la peggiore delle combinazioni.
     */
    #[Test]
    public function the_move_is_still_the_way_out(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/migrazione/allenamenti/stato')
            ->assertOk()
            ->assertJsonPath('data.migrated', false);
    }

    /**
     * ⛔ Il riepilogo e le serie non parlano piu' di allenamento.
     *
     * 💡 I campi sono **usciti dalla risposta** invece di valere zero: cosi' chi
     * li cercasse trova `null` — «non lo so» — e non uno zero che afferma
     * qualcosa di falso.
     */
    #[Test]
    public function nothing_about_training_leaks_from_the_summary(): void
    {
        $riepilogo = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/dashboard')
            ->assertOk();

        $this->assertNull($riepilogo->json('data.training'));
        $this->assertNull($riepilogo->json('data.nutrition.burned'));

        $serie = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/series?metric=calories&days=7')
            ->assertOk();

        $this->assertNull($serie->json('data.burned'));
        $this->assertNull($serie->json('data.averages.burned'));

        $diario = $this->comeApp($this->iscritto)
            ->getJson('/api/v1/diary')
            ->assertOk();

        $this->assertNull($diario->json('data.burned'));
    }
}
