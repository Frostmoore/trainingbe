<?php

declare(strict_types=1);

namespace Tests\Feature\Training;

use App\Enums\MuscleGroup;
use App\Enums\UserRole;
use App\Exceptions\MuscoliNonDecisiException;
use App\Models\Exercise;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Training\ExerciseMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Da dove entrano davvero i muscoli — 3b-A.3.4, 23/08/2026.
 *
 * ══ 🚨 IL CATALOGO NON È IL PROBLEMA ══════════════════════════════════════
 *
 * 📌 *«Ovviamente questo va fatto anche dove vengono creati gli esercizi,
 * quindi anche sul server e sul builder delle schede»*.
 *
 * ⛔ I 314 esercizi in libreria sono la parte facile: si riempiono una volta e
 * restano. **Gli esercizi veri nascono altrove** — da un nome scritto a mano in
 * una scheda, o letto da un PDF — e passano tutti da `ExerciseMatcher::match()`,
 * che fino a ieri li creava muti.
 *
 * 💡 Questi test guardano quella porta, non la libreria.
 */
final class IMuscoliEntranoNelCatalogoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'iscritto@alfa.test');
    }

    private function matcher(): ExerciseMatcher
    {
        return app(ExerciseMatcher::class);
    }

    // ─────────────────── un esercizio che nasce ───────────────────

    #[Test]
    public function un_esercizio_nuovo_nasce_con_i_muscoli_che_gli_sono_stati_detti(): void
    {
        $e = $this->matcher()->match(
            'Spinte strane al cavo',
            $this->palestra->getKey(),
            $this->iscritto,
            MuscleGroup::Chest,
            ['triceps', 'shoulders'],
        );

        self::assertSame(MuscleGroup::Chest, $e->muscle_group);
        self::assertSame(['triceps', 'shoulders'], $e->secondary_muscles);
    }

    #[Test]
    public function e_se_nessuno_glieli_dice_non_si_crea_affatto(): void
    {
        /*
         * ⛔ **3b-A.3.5.** Fino al 24/08/2026 questo scriveva una riga muta, e
         * in staging ce n'erano già **sette** — tutte nate da qui mentre
         * qualcuno salvava una scheda, tutte senza che nessuno se ne accorgesse.
         *
         * 🚨 Il danno non si vede dove nasce: si vede mesi dopo, su una figura
         * del corpo che resta grigia. A quel punto nessuno collega la zona
         * spenta al momento in cui l'esercizio è stato scritto.
         */
        $this->expectException(MuscoliNonDecisiException::class);

        $this->matcher()->match('Esercizio senza nome noto', $this->palestra->getKey(), $this->iscritto);
    }

    #[Test]
    public function e_i_secondari_vanno_dichiarati_anche_quando_sono_nessuno(): void
    {
        /*
         * ⛔ **Il primario da solo non basta.** `[]` dice «questo esercizio
         * isola davvero» ed è una decisione; `null` dice «non ci ho pensato».
         * 🚨 Accettare `null` sui secondari vorrebbe dire richiudere dalla
         * finestra la porta appena chiusa: nascerebbero esercizi mezzi muti,
         * che il filtro del pannello troverebbe comunque — ma che nessuno
         * andrebbe più a completare.
         */
        $this->expectException(MuscoliNonDecisiException::class);

        $this->matcher()->match(
            'Esercizio con mezza risposta',
            $this->palestra->getKey(),
            $this->iscritto,
            MuscleGroup::Chest,
        );
    }

    #[Test]
    public function ma_un_esercizio_che_esiste_gia_non_chiede_niente(): void
    {
        /*
         * 💡 **La guardia scatta solo alla creazione**, ed è la differenza fra
         * una regola e una scocciatura: i muscoli di un esercizio in libreria
         * il server li sa già, e pretenderli a ogni riga vorrebbe dire far
         * ricompilare un campo la cui risposta viene buttata via.
         */
        $gia = Exercise::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->getKey(),
            'name' => 'Esercizio che c\'era',
            'slug_normalized' => Exercise::normalize('Esercizio che c\'era'),
            'muscle_group' => MuscleGroup::Back,
            'secondary_muscles' => ['biceps'],
        ]);

        $trovato = $this->matcher()->match(
            'Esercizio che c\'era',
            $this->palestra->getKey(),
            $this->iscritto,
        );

        self::assertSame($gia->getKey(), $trovato->getKey());
    }

    // / ══ ⛔ IL REMATORE CHE DIVENTAVA UN SALTO CON LA CORDA ════════════════
    // /
    // / 🚨 Difetto vero, trovato il 24/08/2026 creando le schede del
    // / committente: **«Rematore corda» finiva su «Corda»** — il saltare la
    // / corda, categoria `cardio`. Il riconoscimento per **contenimento** aveva
    // / visto la parola «corda» dentro il nome.
    // /
    // / ⚠️ E il danno non era il nome: quell'esercizio avrebbe colorato
    // / **polpacci e spalle** invece di schiena e bicipiti, e le calorie
    // / sarebbero state stimate con il MET del salto della corda.
    // /
    // / 💡 Un nome che sta in `MuscoliDegliEsercizi` è un esercizio **a sé**: va
    // / creato, non accoppiato per somiglianza a qualcos'altro.
    #[Test]
    public function un_nome_che_conosciamo_non_diventa_l_alias_di_un_altro(): void
    {
        // Il salto con la corda esiste già in libreria: è l'esca.
        Exercise::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'name' => 'Corda',
            'slug_normalized' => Exercise::normalize('Corda'),
            'muscle_group' => MuscleGroup::Cardio,
            'secondary_muscles' => ['calves', 'shoulders'],
        ]);

        $trovato = $this->matcher()->match(
            'Rematore corda',
            $this->palestra->getKey(),
            $this->iscritto,
            MuscleGroup::Back,
            ['biceps', 'shoulders'],
        );

        self::assertSame('Rematore corda', $trovato->name);
        self::assertSame(MuscleGroup::Back, $trovato->muscle_group);
    }

    // / 💡 E il contenimento **continua a funzionare** per i nomi che non
    // / conosciamo: è quello che impedisce a «Panca piana bilanciere» di
    // / diventare una riga nuova accanto a «Panca piana».
    #[Test]
    public function ma_per_un_nome_sconosciuto_il_contenimento_resta(): void
    {
        $panca = Exercise::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'name' => 'Panca piana',
            'slug_normalized' => Exercise::normalize('Panca piana'),
            'muscle_group' => MuscleGroup::Chest,
            'secondary_muscles' => ['triceps'],
        ]);

        $trovato = $this->matcher()->match(
            'Panca piana con bilanciere olimpico',
            $this->palestra->getKey(),
            $this->iscritto,
        );

        self::assertSame($panca->getKey(), $trovato->getKey());
    }

    // ─────────────────── un esercizio che guarisce ───────────────────

    #[Test]
    public function un_esercizio_incompleto_della_palestra_si_completa(): void
    {
        $prima = Exercise::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->getKey(),
            'name' => 'Spinte al cavo basso',
            'slug_normalized' => Exercise::normalize('Spinte al cavo basso'),
            'is_custom' => true,
        ]);

        $dopo = $this->matcher()->match(
            'Spinte al cavo basso',
            $this->palestra->getKey(),
            $this->iscritto,
            MuscleGroup::Chest,
            ['triceps'],
        );

        self::assertSame($prima->getKey(), $dopo->getKey(), 'ne ha creato uno nuovo invece di riusare quello');
        self::assertSame(MuscleGroup::Chest, $dopo->fresh()->muscle_group);
        self::assertSame(['triceps'], $dopo->fresh()->secondary_muscles);
    }

    #[Test]
    public function ma_quello_che_i_muscoli_ce_li_ha_gia_non_si_tocca(): void
    {
        /*
         * ⛔ Chi scrive una scheda sta descrivendo **il suo allenamento**, non
         * correggendo la libreria. Se scrive «Panca piana → gambe» per sbaglio,
         * quello non deve diventare il dato di tutti.
         */
        $prima = Exercise::withoutGlobalScopes()->create([
            'tenant_id' => $this->palestra->getKey(),
            'name' => 'Croci strane',
            'slug_normalized' => Exercise::normalize('Croci strane'),
            'muscle_group' => MuscleGroup::Chest,
            'secondary_muscles' => ['shoulders'],
        ]);

        $this->matcher()->match(
            'Croci strane',
            $this->palestra->getKey(),
            $this->iscritto,
            MuscleGroup::Quads,
            ['calves'],
        );

        self::assertSame(MuscleGroup::Chest, $prima->fresh()->muscle_group);
        self::assertSame(['shoulders'], $prima->fresh()->secondary_muscles);
    }

    #[Test]
    public function e_il_catalogo_di_piattaforma_non_si_tocca_mai(): void
    {
        /*
         * 🚨 **Questo è un confine fra palestre, non una raffinatezza.**
         *
         * ⛔ Gli esercizi con `tenant_id` nullo li vedono **tutte** le palestre.
         * Lasciare che il nome scritto a mano da un iscritto ne completi uno
         * vorrebbe dire far scrivere una persona sul dato di tutti gli altri —
         * e nessuno se ne accorgerebbe, perché il salvataggio riesce.
         *
         * 💡 Le lacune del catalogo comune si sistemano dal pannello, dove c'è
         * qualcuno che risponde di quello che scrive.
         */
        $comune = Exercise::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'name' => 'Esercizio di piattaforma',
            'slug_normalized' => Exercise::normalize('Esercizio di piattaforma'),
        ]);

        $trovato = $this->matcher()->match(
            'Esercizio di piattaforma',
            $this->palestra->getKey(),
            $this->iscritto,
            MuscleGroup::Chest,
            ['triceps'],
        );

        self::assertSame($comune->getKey(), $trovato->getKey());
        self::assertNull($comune->fresh()->muscle_group, 'una palestra ha scritto sul catalogo di tutti');
        self::assertNull($comune->fresh()->secondary_muscles);
    }

    // ─────────────────── dalla scheda scritta nell'app ───────────────────

    #[Test]
    public function il_compositore_puo_dichiarare_i_muscoli_salvando_la_scheda(): void
    {
        $this->actingAs($this->iscritto, 'sanctum')
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Scheda con muscoli',
                'days' => [[
                    'exercises' => [[
                        'name' => 'Spinta obliqua alla macchina strana',
                        'sets' => 4,
                        'muscle_group' => 'chest',
                        'secondary_muscles' => ['triceps', 'shoulders'],
                    ]],
                ]],
            ])
            ->assertCreated();

        $e = Exercise::withoutGlobalScopes()
            ->where('name', 'Spinta obliqua alla macchina strana')
            ->firstOrFail();

        self::assertSame(MuscleGroup::Chest, $e->muscle_group);
        self::assertSame(['triceps', 'shoulders'], $e->secondary_muscles);
    }

    #[Test]
    public function una_scheda_di_soli_esercizi_noti_si_salva_senza_dire_niente(): void
    {
        /*
         * 💡 **È il caso normale**, ed è quello che tiene la regola vivibile:
         * una scheda fatta di esercizi del catalogo non chiede niente a
         * nessuno, perché non sta creando niente.
         */
        Exercise::withoutGlobalScopes()->create([
            'tenant_id' => null,
            'name' => 'Panca piana',
            'slug_normalized' => Exercise::normalize('Panca piana'),
            'muscle_group' => MuscleGroup::Chest,
            'secondary_muscles' => ['triceps'],
        ]);

        $this->actingAs($this->iscritto, 'sanctum')
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Solo roba nota',
                'exercises' => [['name' => 'Panca piana', 'sets' => 3]],
            ])
            ->assertCreated();
    }

    #[Test]
    public function ma_una_scheda_con_un_esercizio_nuovo_e_muto_viene_rifiutata(): void
    {
        /*
         * ⛔ **3b-A.3.5.** Prima passava e nasceva una riga muta in libreria.
         *
         * ⚠️ La scheda **non nasce a metà**: la scrittura sta in una
         * transazione, quindi o entra tutta o non entra. Una scheda con tre
         * esercizi su venti sarebbe peggio di nessuna scheda, perché sembra
         * completa.
         */
        $this->actingAs($this->iscritto, 'sanctum')
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Con dentro una cosa mai vista',
                'exercises' => [['name' => 'Movimento mai visto prima', 'sets' => 3]],
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'muscoli_non_decisi');

        self::assertDatabaseMissing('exercises', ['name' => 'Movimento mai visto prima']);
        self::assertDatabaseMissing('workout_plans', ['name' => 'Con dentro una cosa mai vista']);
    }

    #[Test]
    public function un_muscolo_inventato_ferma_il_salvataggio(): void
    {
        // ⚠️ Meglio un 422 che una libreria con dentro `pettorine`: quel valore
        // non colora niente e non filtra niente, e resta lì per sempre.
        $this->actingAs($this->iscritto, 'sanctum')
            ->postJson('/api/v1/workout-plans', [
                'name' => 'Scheda con muscolo finto',
                'days' => [['exercises' => [[
                    'name' => 'Esercizio X',
                    'secondary_muscles' => ['pettorine'],
                ]]]],
            ])
            ->assertStatus(422);
    }
}
