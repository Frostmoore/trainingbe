<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\PlanKind;
use App\Filament\God\Resources\Offerte\Pages\ListOfferte;
use App\Filament\God\Resources\Plans\Pages\CreatePlan;
use App\Filament\God\Resources\Plans\Pages\ListPlans;
use App\Filament\God\Resources\RichiesteDiContatto\Pages\ListRichiesteDiContatto;
use App\Models\OffertaDiPiano;
use App\Models\Plan;
use App\Models\RichiestaDiContatto;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * ✂️ **Le due pagine nuove del pannello `/god`** — 21/09/2026.
 *
 * 📌 *«Mettici un modulo di contatto così mi scrivono e creami la possibilità
 * di creare nuovi abbonamenti per palestre con il numero di trainers che
 * voglio io»*: il modulo scrive nel database, e queste sono le due pagine da
 * cui quel database si legge e si risponde.
 *
 * ── 🚨 Cosa difende questo file ───────────────────────────────────────────
 *
 * ⛔ **Non l'aspetto.** Difende il fatto che le pagine **si aprano**: una
 * colonna scritta male, un `use` mancante o un metodo di Filament cambiato di
 * firma fanno andare in errore l'elenco, e un pannello che va in errore è un
 * canale di vendita chiuso senza che nessuno lo sappia.
 *
 * 💡 E difende la conversione **euro → centesimi** del modulo, che è l'unico
 * punto di tutta la fase in cui uno zero di troppo si traduce in soldi.
 */
class IlPannelloDeiPianiSuMisuraTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->actingAs($this->creaSuperAdmin());

        /*
         * ⚠️ Il pannello va scelto a mano: senza, Filament resta su quello di
         * serie e la vista cerca rotte che in questo progetto non esistono.
         * Nel browser lo mette la rotta.
         */
        filament()->setCurrentPanel('god');
    }

    #[Test]
    public function l_elenco_dei_piani_si_apre(): void
    {
        Livewire::test(ListPlans::class)
            ->assertSuccessful()
            ->assertSee('gym_10')
            ->assertSee('Palestra Pro');
    }

    #[Test]
    public function l_elenco_delle_richieste_si_apre(): void
    {
        RichiestaDiContatto::create([
            'tipo' => 'palestra',
            'nome' => 'Giulia Ferri',
            'email' => 'giulia@palestrademo.it',
            'iscritti' => 180,
            'trainer' => 9,
            'messaggio' => 'Ci serve un piano che copra tutti gli istruttori.',
        ]);

        Livewire::test(ListRichiesteDiContatto::class)
            ->assertSuccessful()
            ->assertSee('Giulia Ferri')
            ->assertSee('180 iscritti · 9 trainer');
    }

    /**
     * 🆕 🔗 **Il pulsante che fa il link di pagamento** — 21/09/2026.
     *
     * 📌 *«certo che il piano cucito a mano deve essere fatto con stripe»*.
     *
     * ⚠️ Il test guarda che nasca **l'offerta**, non la notifica: la notifica
     * è dove si legge il link, ma quello che vale è la riga — il link si
     * ritrova, la notifica no.
     */
    #[Test]
    public function dal_piano_si_crea_il_link_di_pagamento(): void
    {
        $palestra = $this->creaPalestra();

        $piano = Plan::create([
            'code' => 'su_misura_link',
            'name' => 'Su misura — link',
            'kind' => PlanKind::Gym,
            'ai_enabled' => true,
            'max_members' => 300,
            'max_trainers' => 25,
            'price_cents' => 70000,
            'is_public' => false,
            'cucito_a_mano' => true,
        ]);

        Livewire::test(ListPlans::class)
            ->callTableAction('manda_il_link', $piano, [
                'tenant_id' => $palestra->getKey(),
                'email' => 'capo@palestrademo.it',
                'scade_il' => now()->addDays(30),
            ])
            ->assertHasNoTableActionErrors();

        $offerta = OffertaDiPiano::withoutGlobalScopes()->sole();

        $this->assertSame($piano->getKey(), $offerta->plan_id);
        $this->assertSame($palestra->getKey(), $offerta->tenant_id);
        $this->assertSame(32, mb_strlen($offerta->token), 'il token non è lungo come quelli degli inviti');
        $this->assertTrue($offerta->eValida());
        $this->assertStringEndsWith('/offerta/'.$offerta->token, $offerta->link());
    }

    /** 💡 E il link si ritrova: è il motivo per cui l'elenco esiste. */
    #[Test]
    public function l_elenco_delle_offerte_si_apre(): void
    {
        $piano = Plan::create([
            'code' => 'su_misura_elenco',
            'name' => 'Su misura per elenco',
            'kind' => PlanKind::Gym,
            'ai_enabled' => true,
            'price_cents' => 50000,
            'is_public' => false,
            'cucito_a_mano' => true,
        ]);

        OffertaDiPiano::create([
            'plan_id' => $piano->getKey(),
            'token' => OffertaDiPiano::nuovoToken(),
            'email' => 'capo@palestrademo.it',
        ]);

        Livewire::test(ListOfferte::class)
            ->assertSuccessful()
            /*
             * ⚠️ Il nome del piano di prova è in ASCII puro, e non è pignoleria:
             * un nome con un trattino lungo si ritrova nella pagina **scritto
             * diverso** da com'è nel database, e `assertSee` fallisce su una
             * pagina giusta. Si è già perso tempo così il 21/09.
             */
            ->assertSee('Su misura per elenco')
            ->assertSee('capo@palestrademo.it');
    }

    /**
     * 🚨 **Il prezzo si scrive in euro e finisce in centesimi.**
     *
     * ⛔ `(int) (2.80 * 100)` fa **279**: è il centesimo che sparisce in
     * virgola mobile, e su un prezzo a iscritto moltiplicato per trecento
     * persone diventano tre euro al mese, per sempre. 💡 Il campo usa
     * `round()`, e questo test è il motivo per cui non tornerà un cast.
     */
    #[Test]
    public function un_piano_si_cuce_scrivendo_i_prezzi_in_euro(): void
    {
        Livewire::test(CreatePlan::class)
            ->fillForm([
                'code' => 'su_misura_powergym',
                'name' => 'PowerGym — accordo 2026',
                'kind' => PlanKind::Gym->value,
                'max_members' => 400,
                'max_trainers' => 30,
                'price_cents' => '0',
                'prezzo_iscritto_cent' => '2.80',
                'canone_cent' => '15.00',
                'minimo_iscritti' => 200,
                'ai_enabled' => true,
                'ai_monthly_calls_per_member' => 150,
                'ai_monthly_photo_calls_per_member' => 15,
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $piano = Plan::query()->where('code', 'su_misura_powergym')->sole();

        $this->assertSame(280, $piano->prezzo_iscritto_cent, 'il centesimo si è perso in virgola mobile');
        $this->assertSame(1500, $piano->canone_cent);
        $this->assertSame(0, $piano->price_cents);
        $this->assertSame(30, $piano->max_trainers);

        // ✂️ Nasce cucito a mano — è la colonna che lo rende modificabile.
        $this->assertTrue($piano->cucito_a_mano);

        /*
         * ⛔ E **non** a listino: un accordo cucito per un cliente che
         * comparisse fra i prezzi del sito diventerebbe il prezzo per tutti.
         */
        $this->assertFalse($piano->is_public);
    }
}
