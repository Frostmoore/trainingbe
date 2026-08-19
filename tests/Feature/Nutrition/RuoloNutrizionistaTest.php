<?php

declare(strict_types=1);

namespace Tests\Feature\Nutrition;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il nutrizionista: predisposto, non attivo — N22.
 *
 * ── 🚨 Cosa difendono questi test ──────────────────────────────────────────
 *
 * Che i due mestieri restino **separati** — il trainer fa schede e consigli, il
 * nutrizionista fa piani alimentari — e che il ruolo, finche' non lo si decide,
 * **non sia assegnabile da nessun percorso reale**.
 */
class RuoloNutrizionistaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $nutrizionista;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $this->nutrizionista = $this->creaUtente(
            $this->palestra,
            UserRole::Nutrizionista,
            'bio@olimpo.it',
        );
    }

    // ───────────────────── i due mestieri restano separati ─────────────────

    #[Test]
    public function un_nutrizionista_non_e_un_trainer(): void
    {
        /*
         * 🚨 **La riga da cui dipende tutto il resto.**
         *
         * ⚠️ Se `isTrainer()` rispondesse `true` — la tentazione e' forte,
         * «in fondo allena anche lui» — il nutrizionista potrebbe comporre
         * schede di allenamento, e tutta la separazione di N19 salterebbe da un
         * punto che non c'entra niente con l'alimentazione.
         *
         * 💡 E' la stessa regola gia' scritta per `isFreeTrainer()`, e qui vale
         * ancora di piu': quelli sono due modi di fare lo stesso mestiere,
         * questi sono due mestieri diversi.
         */
        $this->assertTrue($this->nutrizionista->isNutrizionista());
        $this->assertFalse($this->nutrizionista->isTrainer());
        $this->assertFalse($this->nutrizionista->isGymAdmin());
    }

    #[Test]
    public function un_nutrizionista_non_arriva_alle_schede_di_allenamento(): void
    {
        // ⚠️ Non serve un divieto nuovo: il cancello delle schede chiede
        // `isTrainer() || isGymAdmin()`, e lui non e' nessuno dei due. Questo
        // test esiste per accorgersi se un giorno quel cancello si allargasse.
        $this->actingAs($this->nutrizionista)
            ->getJson('/api/v1/workout-plans/templates')
            ->assertForbidden();
    }

    #[Test]
    public function un_trainer_resta_un_trainer(): void
    {
        $trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'coach@olimpo.it');

        $this->assertTrue($trainer->isTrainer());
        $this->assertFalse($trainer->isNutrizionista());
    }

    // ─────────────────── predisposto, ma non assegnabile ───────────────────

    #[Test]
    public function il_ruolo_non_e_assegnabile(): void
    {
        /*
         * 🚨 **«Predisposto» e «attivo» sono due cose diverse.**
         *
         * ⚠️ Un ruolo a meta' che si puo' gia' dare a qualcuno e' un ruolo **in
         * produzione**, e i confini si scoprono rotti quando qualcuno ci
         * finisce dentro.
         *
         * 💡 Il giorno dell'attivazione questo test si rompe, e costringe chi
         * la fa a deciderlo di proposito invece che per distrazione.
         */
        $this->assertFalse(UserRole::Nutrizionista->assegnabile());
        $this->assertFalse(UserRole::FreeNutrizionista->assegnabile());
    }

    #[Test]
    public function tutti_gli_altri_ruoli_lo_sono(): void
    {
        foreach ([
            UserRole::SuperAdmin,
            UserRole::GymAdmin,
            UserRole::Trainer,
            UserRole::Member,
            UserRole::FreeTrainer,
            UserRole::FreeUser,
        ] as $ruolo) {
            $this->assertTrue(
                $ruolo->assegnabile(),
                "{$ruolo->value} dovrebbe essere assegnabile",
            );
        }
    }

    #[Test]
    public function lelenco_degli_assegnabili_non_li_contiene(): void
    {
        $valori = array_map(
            static fn (UserRole $r): string => $r->value,
            UserRole::assegnabili(),
        );

        $this->assertNotContains('nutrizionista', $valori);
        $this->assertNotContains('free_nutrizionista', $valori);
        $this->assertContains('trainer', $valori);
    }

    // ─────────────────────────── l'albo ────────────────────────────────────

    #[Test]
    public function lalbo_si_dichiara_e_resta_scritto(): void
    {
        $this->nutrizionista->forceFill([
            'albo' => 'onb',
            'albo_numero' => 'AA_123456',
            'albo_dichiarato_il' => now(),
        ])->save();

        $this->assertTrue($this->nutrizionista->fresh()->haDichiaratoLAlbo());
    }

    #[Test]
    public function una_dichiarazione_a_meta_non_conta(): void
    {
        /*
         * ⚠️ Numero senza albo, o albo senza data: non e' una dichiarazione.
         * 💡 La data conta piu' del resto — se un giorno qualcuno contestasse,
         * la domanda sara' «cosa aveva dichiarato, e a che data».
         */
        foreach ([
            ['albo' => 'onb', 'albo_numero' => null, 'albo_dichiarato_il' => now()],
            ['albo' => null, 'albo_numero' => 'X1', 'albo_dichiarato_il' => now()],
            ['albo' => 'onb', 'albo_numero' => 'X1', 'albo_dichiarato_il' => null],
        ] as $meta) {
            $this->nutrizionista->forceFill($meta)->save();

            $this->assertFalse(
                $this->nutrizionista->fresh()->haDichiaratoLAlbo(),
                'una dichiarazione a meta e passata: '.json_encode($meta),
            );
        }
    }

    #[Test]
    public function di_serie_nessuno_ha_dichiarato_niente(): void
    {
        $this->assertFalse($this->nutrizionista->haDichiaratoLAlbo());
    }
}
