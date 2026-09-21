<?php

declare(strict_types=1);

namespace Tests\Feature\God;

use App\Enums\MuscleGroup;
use App\Enums\PlanKind;
use App\Enums\StatoDellaRichiesta;
use App\Enums\UserRole;
use App\Filament\God\Resources\Exercises\ExerciseResource;
use App\Filament\God\Resources\Plans\Pages\EditPlan;
use App\Filament\God\Resources\Plans\Pages\ListPianiSuMisura;
use App\Filament\God\Resources\Plans\Pages\ListPlans;
use App\Filament\God\Resources\Plans\Tables\PlansTable;
use App\Filament\God\Resources\Plans\Widgets\ChiHaIlPiano;
use App\Filament\God\Resources\RichiesteDiContatto\Pages\ViewRichiestaDiContatto;
use App\Mail\RispostaAllaRichiestaMail;
use App\Models\Exercise;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\RichiestaDiContatto;
use App\Models\RispostaAllaRichiesta;
use App\Models\User;
use App\Services\Billing\Listino;
use App\Services\Billing\Stripe\PianiInVendita;
use App\Services\Training\ExerciseMatcher;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaStripeFinto;
use Tests\TestCase;

/**
 * 🧩 F9 — le pagine nuove di `/god`, una richiesta del committente per test.
 */
final class LePagineDelGodTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaStripeFinto;

    private User $god;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->god = $this->creaSuperAdmin();

        $this->actingAs($this->god);
        filament()->setCurrentPanel('god');
    }

    // ─────────────────────── F9.3 Richieste dal sito ───────────────────────

    /**
     * ✉️ **Si risponde dal pannello**: parte la mail a chi ha scritto, resta la
     * risposta, e la richiesta non è più «nuova».
     */
    #[Test]
    public function a_una_richiesta_si_risponde_dal_pannello(): void
    {
        Mail::fake();

        $richiesta = RichiestaDiContatto::create([
            'tipo' => 'palestra', 'nome' => 'Marta', 'email' => 'marta@esempio.test',
            'messaggio' => "Due sedi, 180 iscritti.\nUn prezzo unico?",
        ]);

        Livewire::test(ViewRichiestaDiContatto::class, ['record' => $richiesta->getKey()])
            ->assertSuccessful()
            ->assertSee('Un prezzo unico?')
            ->callAction('rispondi', ['oggetto' => 'La tua richiesta', 'testo' => 'Sì, ecco la proposta.'])
            ->assertHasNoActionErrors();

        $risposta = RispostaAllaRichiesta::query()->sole();
        $this->assertSame('Sì, ecco la proposta.', $risposta->testo);
        $this->assertSame(StatoDellaRichiesta::Presa, $richiesta->refresh()->stato);

        Mail::assertSent(RispostaAllaRichiestaMail::class, fn (RispostaAllaRichiestaMail $m): bool => $m->hasTo('marta@esempio.test'));
    }

    /** 🚨 La promessa dei 24 mesi vale anche per le risposte: muoiono con la richiesta. */
    #[Test]
    public function le_risposte_muoiono_con_la_richiesta(): void
    {
        $vecchia = RichiestaDiContatto::create(['tipo' => 'altro', 'nome' => 'A', 'email' => 'a@esempio.test', 'messaggio' => 'ciao ciao ciao']);
        DB::table('richieste_di_contatto')->where('id', $vecchia->getKey())->update(['created_at' => now()->subMonths(30)]);
        RispostaAllaRichiesta::create(['richiesta_di_contatto_id' => $vecchia->getKey(), 'oggetto' => 'x', 'testo' => 'y']);

        $this->artisan('registri:pota')->assertSuccessful();

        $this->assertSame(0, RispostaAllaRichiesta::query()->count());
    }

    // ─────────────────────── F9.5 Esercizi ───────────────────────

    /**
     * 🏋️ **Un esercizio della piattaforma riservato ad alcune palestre** lo
     * vedono solo loro; senza palestre lo vedono tutte. E il matcher di
     * un'altra palestra non lo usa.
     */
    #[Test]
    public function un_esercizio_riservato_lo_vedono_solo_le_sue_palestre(): void
    {
        $alfa = $this->creaPalestra('Alfa', 'alfa');
        $beta = $this->creaPalestra('Beta', 'beta');
        $ctx = app(TenantContext::class);

        [$riservato, $perTutti] = $ctx->runWithoutTenant(fn (): array => [
            Exercise::create(['name' => 'Panca speciale Alfa', 'muscle_group' => MuscleGroup::Chest, 'secondary_muscles' => []]),
            Exercise::create(['name' => 'Panca di tutti', 'muscle_group' => MuscleGroup::Chest, 'secondary_muscles' => []]),
        ]);
        $riservato->palestre()->sync([$alfa->getKey()]);

        $inAlfa = $ctx->runAs($alfa, fn () => Exercise::query()->pluck('id')->all());
        $inBeta = $ctx->runAs($beta, fn () => Exercise::query()->pluck('id')->all());

        $this->assertContains($riservato->getKey(), $inAlfa);
        $this->assertNotContains($riservato->getKey(), $inBeta, 'Beta vede un esercizio riservato ad Alfa.');
        $this->assertContains($perTutti->getKey(), $inBeta);

        // Il matcher di Beta non lo prende: ne crea uno suo.
        $trovato = app(ExerciseMatcher::class)->match('Panca speciale Alfa', $beta->getKey(), null, MuscleGroup::Chest, []);
        $this->assertNotSame($riservato->getKey(), $trovato->getKey());
    }

    /**
     * ⚠️ **Salvare senza toccare non regala niente**: l'esercizio di una
     * palestra resta suo; svuotare le palestre lo dà a tutte.
     */
    #[Test]
    public function il_campo_palestre_decide_di_chi_e(): void
    {
        $alfa = $this->creaPalestra('Alfa', 'alfa');
        $beta = $this->creaPalestra('Beta', 'beta');
        $autore = $this->creaUtente($alfa, UserRole::GymAdmin, 'capo@alfa.test');

        $suo = app(TenantContext::class)->runAs($alfa, fn () => Exercise::create([
            'name' => 'Macchina di Alfa', 'muscle_group' => MuscleGroup::Back, 'secondary_muscles' => [], 'created_by' => $autore->getKey(),
        ]));

        ExerciseResource::applicaLePalestre($suo, [$alfa->getKey()]);
        $this->assertSame($alfa->getKey(), (int) $suo->refresh()->tenant_id, 'Salvato così com\'era, non è più di Alfa.');

        // Il «di chi è» dice anche chi l'ha creato.
        $this->assertSame('Alfa ('.$autore->name.')', ExerciseResource::diChiE($suo->load('tenant', 'creator', 'palestre')));

        ExerciseResource::applicaLePalestre($suo, [$alfa->getKey(), $beta->getKey()]);
        $this->assertNull($suo->refresh()->tenant_id);
        $this->assertEqualsCanonicalizing([$alfa->getKey(), $beta->getKey()], $suo->palestre()->pluck('tenants.id')->all());

        ExerciseResource::applicaLePalestre($suo, []);
        $this->assertSame(0, $suo->palestre()->count(), 'Vuoto = di tutte.');
    }

    // ─────────────────────── F9.1 / F9.2 Piani e listino ───────────────────────

    /** 📌 «iscritti deve essere 0, non senza limiti, idem per trainer». */
    #[Test]
    public function i_posti_si_dicono_giusti(): void
    {
        $plus = Plan::query()->where('code', Plan::PLUS)->sole();
        $trainer = Plan::query()->where('kind', PlanKind::Trainer->value)->where('is_public', true)->where('price_cents', '>', 0)->firstOrFail();
        $consumo = Plan::query()->where('code', 'gym_consumo')->sole();

        $this->assertSame('0', PlansTable::posti($plus, 'iscritti'));
        $this->assertSame('0', PlansTable::posti($plus, 'trainer'));
        $this->assertSame('0', PlansTable::posti($trainer, 'trainer'));
        $this->assertSame('senza limiti', PlansTable::posti($consumo, 'iscritti'));
    }

    /**
     * 📅 **La temporalità arriva fino a Stripe**: un piano settimanale si paga
     * ogni settimana, e il prezzo lo dice.
     */
    #[Test]
    public function la_temporalita_arriva_a_stripe(): void
    {
        $stripe = $this->stripeFinto();
        $piano = Plan::query()->where('code', 'gym_10')->sole();
        $piano->forceFill(['intervallo' => 'settimana'])->save();

        $this->assertSame('60,00 € alla settimana', PlansTable::prezzo($piano));
        $this->assertSame('2026-01-08', $piano->finePeriodo(Carbon::parse('2026-01-01'))->toDateString());
        $this->assertSame('2026-02-28', Plan::query()->where('code', 'gym_30')->sole()->finePeriodo(Carbon::parse('2026-01-31'))->toDateString(), 'Dal 31 gennaio, un mese è il 28 febbraio.');

        $palestra = $this->creaPalestra('Alfa', 'alfa');
        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@alfa.test');
        $palestra->forceFill(['proprietario_id' => $titolare->getKey()])->save();

        app(PianiInVendita::class)->apri($titolare->refresh(), 'gym_10', 'http://localhost/admin');

        $sessione = $stripe->ultima('checkout.sessions.create')[0];
        $this->assertSame('week', $sessione['line_items'][0]['price_data']['recurring']['interval']);
    }

    /**
     * ✋ **Un piano di listino modificato dal pannello** resta modificato: il
     * seeder non lo riscrive, e il sito mostra il prezzo nuovo.
     */
    #[Test]
    public function un_piano_modificato_resta_e_il_sito_lo_mostra(): void
    {
        $piano = Plan::query()->where('code', 'gym_10')->sole();

        Livewire::test(EditPlan::class, ['record' => $piano->getKey()])
            ->assertSuccessful()
            ->fillForm(['price_cents' => '65.00'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($piano->refresh()->modificato_a_mano);
        $this->assertSame(6500, $piano->price_cents);

        $this->seed(PlanSeeder::class);
        $this->assertSame(6500, $piano->refresh()->price_cents, 'Il deploy ha rimesso il prezzo del file.');

        $sulSito = collect(app(Listino::class)->piani('palestre'))->firstWhere('codice', 'gym_10');
        $this->assertSame(6500, $sulSito['prezzo'], 'Il sito mostra un prezzo diverso da quello che Stripe addebita.');
    }

    /** ✂️ Listino e su misura sono due pagine, e ognuna vede solo i suoi. */
    #[Test]
    public function listino_e_su_misura_sono_separati(): void
    {
        $suMisura = Plan::create([
            'code' => 'su_misura_prova', 'name' => 'Accordo Prova', 'kind' => PlanKind::Gym->value,
            'price_cents' => 12345, 'is_public' => false, 'cucito_a_mano' => true, 'max_members' => 200,
        ]);
        $listino = Plan::query()->where('code', 'gym_10')->sole();

        Livewire::test(ListPlans::class)
            ->assertCanSeeTableRecords([$listino])
            ->assertCanNotSeeTableRecords([$suMisura]);

        Livewire::test(ListPianiSuMisura::class)
            ->assertCanSeeTableRecords([$suMisura])
            ->assertCanNotSeeTableRecords([$listino]);
    }

    /** 👥 La tabella di chi ce l'ha: solo gli attivi, coi loro rinnovi. */
    #[Test]
    public function la_pagina_del_piano_dice_chi_ce_l_ha(): void
    {
        $piano = Plan::query()->where('code', 'gym_30')->sole();
        $alfa = $this->creaPalestra('Alfa', 'alfa');
        $beta = $this->creaPalestra('Beta', 'beta');

        $attivo = app(TenantContext::class)->runWithoutTenant(fn () => PlanSubscription::create([
            'tenant_id' => $alfa->getKey(), 'plan_id' => $piano->getKey(), 'starts_at' => now()->subMonth(), 'ends_at' => now()->addMonth(), 'rinnova' => true,
        ]));
        $finito = app(TenantContext::class)->runWithoutTenant(fn () => PlanSubscription::create([
            'tenant_id' => $beta->getKey(), 'plan_id' => $piano->getKey(), 'starts_at' => now()->subYear(), 'ends_at' => now()->subMonth(), 'rinnova' => false,
        ]));

        Livewire::test(ChiHaIlPiano::class, ['record' => $piano])
            ->assertCanSeeTableRecords([$attivo])
            ->assertCanNotSeeTableRecords([$finito]);
    }
}
