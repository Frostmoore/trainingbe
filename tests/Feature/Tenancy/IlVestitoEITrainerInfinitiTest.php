<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Enums\UserRole;
use App\Filament\Gym\Pages\GymSettings;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 👔 **Il vestito dell'app e i trainer infiniti** — regole 8.1.2, 8.1.3 e 8.1.5,
 * dettate dal committente il 18/09/2026.
 *
 * ══ 📌 LE TRE REGOLE ══════════════════════════════════════════════════════
 *
 * > **8.1.2** — *«Assicurati che un allievo iscritto a una palestra veda sempre
 * > il vestito della palestra nell'app, anche se si iscrive a un free trainer
 * > mantenendo l'iscrizione in palestra.»*
 *
 * > **8.1.3** — *«Assicurati che un trainer non convogli le personalizzazioni
 * > dell'app come le palestre. Cioè, un free trainer non crea un "vestito" per
 * > l'app dei suoi allievi.»*
 *
 * > **8.1.5** — *«Assicurati che un allievo possa essere iscritto a infiniti
 * > trainer. In questo caso, i trainer hanno tutti lo slot occupato.»*
 *
 * ══ ⚠️ PERCHE' SI PROVANO ANCHE QUELLE CHE REGGEVANO GIA' ═════════════════
 *
 * La 8.1.2 e la 8.1.5 reggevano già quando sono state dettate. 🚨 Ma reggono
 * per una **condizione sottile** — la 8.1.2 per un `eDi()` dentro un metodo
 * privato di `InvitiDelTrainer` — e una condizione sottile è esattamente ciò
 * che si rompe senza dare errore, il giorno che qualcuno la «semplifica».
 *
 * ⛔ La 8.1.3 invece **non reggeva**: `Tenant::branding()` azzerava il nome di
 * un tenant personale e lasciava passare logo e colori.
 */
final class IlVestitoEITrainerInfinitiTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    private function trainerIndipendente(string $email): User
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Coach',
            $email,
            ['password' => TestCase::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => Plan::query()->where('code', Plan::TRAINER_30)->firstOrFail()->id,
            'starts_at' => now()->subYear(),
        ]);

        return $trainer->fresh();
    }

    private function accetta(User $trainer, User $allievo): void
    {
        app(InvitiDelTrainer::class)->riscattaPerChiEsiste(
            app(TenantContext::class)->runAs(
                $trainer->tenant,
                fn () => app(InvitiDelTrainer::class)->invita($trainer),
            )->token,
            $allievo->fresh(),
            affianco: true,
        );
    }

    /**
     * 🚨 **8.1.3 — il difetto.** Un free trainer con un logo e tre colori: i suoi
     * allievi ricevono **niente** di tutto questo.
     *
     * ⛔ Prima ricevevano tutto: `branding()` azzerava solo il nome.
     */
    #[Test]
    public function un_free_trainer_non_veste_lapp_dei_suoi_allievi(): void
    {
        $trainer = $this->trainerIndipendente('vestito@esempio.test');

        $trainer->tenant->forceFill([
            'logo_path' => 'loghi/del-trainer.png',
            'color_primary' => '#FF0000',
            'color_secondary' => '#00FF00',
            'color_accent' => '#0000FF',
        ])->save();

        $branding = $trainer->tenant->fresh()->branding();

        $this->assertNull($branding['logo_url'], 'il logo del trainer veste lapp dei suoi allievi');
        $this->assertNull($branding['colors']['primary'], 'il colore del trainer veste lapp');
        $this->assertNull($branding['colors']['secondary']);
        $this->assertNull($branding['colors']['accent']);
        $this->assertNull($branding['name']);
    }

    /**
     * ⚠️ **E per una palestra il vestito resta tutto.** Correggere il difetto
     * togliendo il vestito anche alle palestre sarebbe stato il modo più
     * rapido di far passare il test di sopra.
     */
    #[Test]
    public function una_palestra_veste_lapp_come_prima(): void
    {
        $palestra = $this->creaPalestra();

        $palestra->forceFill([
            'logo_path' => 'loghi/della-palestra.png',
            'color_primary' => '#123456',
        ])->save();

        $branding = $palestra->fresh()->branding();

        $this->assertNotNull($branding['logo_url'], 'la palestra ha perso il suo logo');
        $this->assertSame('#123456', $branding['colors']['primary']);
        $this->assertSame($palestra->name, $branding['name']);
    }

    /**
     * ⚠️ **8.1.3, la metà del pannello**: un free trainer non trova i campi
     * dove si scelgono logo e colori.
     *
     * 💡 Un campo che non ha più effetto sarebbe peggio di un campo che non
     * c'è: farebbe credere di aver vestito l'app.
     *
     * ══ 🆕 COSA E' CAMBIATO IL 23/09/2026, E PERCHE' ═════════════════
     *
     * ⛔ Fino a F10 questo test chiedeva che **tutta la pagina** fosse chiusa
     * (`GymSettings::canAccess()` falso), ed era la difesa giusta con lo
     * strumento grosso: il vestito è un quarto di quella pagina.
     *
     * 📌 Il committente, 23/09: *«un free trainer potrà agire sostanzialmente
     * come una palestra»*. Gli altri tre quarti — nome, contatto, **la sua
     * quota**, i **dati per le fatture** — sono roba sua, e gli servono per
     * lavorare.
     *
     * 🚨 **La 8.1.3 però non si tocca**, e adesso la difendono due cose
     * invece di una: `Tenant::branding()`, che su un tenant personale azzera
     * nome, logo e colori (i test qui sopra), e la sezione «Aspetto nell'app»,
     * che in uno spazio personale **non si mostra**.
     *
     * 💡 Questo test adesso prova **l'intenzione** invece della porta: che i
     * campi del vestito non ci siano. ⚠️ È più forte di prima — una pagina
     * chiusa dice solo *«non entri»*, e non avrebbe impedito a nessuno di
     * rimettere quei campi altrove.
     */
    #[Test]
    public function un_free_trainer_non_trova_i_campi_del_vestito(): void
    {
        $trainer = $this->trainerIndipendente('pannello@esempio.test');

        $this->ctx()->runAs($trainer->tenant, function () use ($trainer): void {
            $this->actingAs($trainer);

            // ✅ La pagina si apre: la sua quota e le sue fatture stanno lì.
            $this->assertTrue(
                GymSettings::canAccess(),
                'un free trainer non raggiunge nemmeno la sua quota e i suoi dati fiscali',
            );

            $campi = Livewire::test(GymSettings::class)
                ->instance()
                ->form
                ->getFlatFields(withHidden: false);

            foreach (['logo_path', 'color_primary', 'color_secondary', 'color_accent'] as $vestito) {
                $this->assertArrayNotHasKey(
                    $vestito,
                    $campi,
                    "\U0001f6a8 Il campo {$vestito} e' tornato nel pannello di un trainer indipendente: "
                    ."gli farebbe credere di aver vestito l'app dei suoi allievi, e la bugia si "
                    ."scoprirebbe solo chiedendo a un allievo cosa vede (regola 8.1.3).",
                );
            }
        });
    }

    /**
     * ✅ **E per una palestra i campi del vestito ci sono ancora.**
     *
     * ⚠️ Il gemello del test di sopra, e serve per la stessa ragione per cui
     * serve `una_palestra_veste_lapp_come_prima`: nascondere la sezione a
     * **tutti** sarebbe il modo più rapido di far passare quell'asserzione.
     */
    #[Test]
    public function una_palestra_invece_i_campi_del_vestito_ce_li_ha(): void
    {
        $palestra = $this->creaPalestra();
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@palestra.test');

        $this->ctx()->runAs($palestra, function () use ($capo): void {
            $this->actingAs($capo);

            $campi = Livewire::test(GymSettings::class)
                ->instance()
                ->form
                ->getFlatFields(withHidden: false);

            $this->assertArrayHasKey('color_primary', $campi, 'la palestra ha perso i campi del vestito');
        });
    }

    /**
     * 🚨 **8.1.2** — un iscritto di palestra che accetta un free trainer **resta
     * vestito della palestra**.
     *
     * 💡 Regge perché `traslocaSeHaUnoSpazioSuo()` sposta l'allievo nello spazio
     * del trainer **solo se lo spazio attuale è suo**: un iscritto di palestra
     * non si sposta, quindi `/auth/me` continua a rispondere col vestito della
     * palestra. ⚠️ È una condizione dentro un metodo privato: questo test la
     * tiene ferma.
     */
    #[Test]
    public function un_iscritto_che_segue_un_free_trainer_resta_vestito_della_palestra(): void
    {
        $palestra = $this->creaPalestra();
        $palestra->forceFill(['color_primary' => '#ABCDEF'])->save();

        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'vestito-palestra@esempio.test');
        $trainer = $this->trainerIndipendente('esterno@esempio.test');

        $this->accetta($trainer, $iscritto);

        $this->assertSame(
            $palestra->getKey(),
            $iscritto->fresh()->tenant_id,
            'seguendo un trainer, liscritto e uscito dalla palestra',
        );

        $risposta = $this->actingAs($iscritto->fresh(), 'sanctum')
            ->getJson('/api/v1/auth/me')
            ->assertOk();

        $this->assertSame(
            '#ABCDEF',
            $risposta->json('data.branding.colors.primary') ?? $risposta->json('branding.colors.primary'),
            'liscritto ha perso il vestito della palestra',
        );
    }

    /**
     * 🚨 **8.1.5** — infiniti trainer, e **ognuno** ha lo slot occupato.
     *
     * 📌 *«In questo caso, i trainer hanno tutti lo slot occupato.»*
     */
    #[Test]
    public function ogni_trainer_di_un_allievo_ha_il_suo_slot_occupato(): void
    {
        $allievo = app(CreaTenantPersonale::class)(
            'Multi',
            'multi@esempio.test',
            ['password' => TestCase::FAKE_PASSWORD],
            UserRole::FreeUser,
        );

        $trainer = [
            $this->trainerIndipendente('t1@esempio.test'),
            $this->trainerIndipendente('t2@esempio.test'),
            $this->trainerIndipendente('t3@esempio.test'),
        ];

        $prima = array_map(
            fn (User $t): ?int => app(InvitiDelTrainer::class)->postiRimasti($t->fresh()),
            $trainer,
        );

        foreach ($trainer as $t) {
            $this->accetta($t, $allievo);
        }

        $this->assertCount(3, $allievo->fresh()->assignedTrainers()->get());

        foreach ($trainer as $i => $t) {
            $dopo = app(InvitiDelTrainer::class)->postiRimasti($t->fresh());

            $this->assertSame(
                $prima[$i] - 1,
                $dopo,
                "il trainer {$t->email} non ha lo slot occupato",
            );
        }
    }
}
