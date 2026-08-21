<?php

declare(strict_types=1);

namespace Tests\Feature\Panels;

use App\Enums\UserRole;
use App\Filament\Gym\Pages\SchedaPubblica;
use App\Models\Comune;
use App\Models\ProfiloPubblico;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Scoperta\ChiaveComune;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * La scheda pubblica dal pannello della palestra — M2.2, 18/08/2026.
 *
 * 🚨 **Perche' questi test esistono.** Senza questa pagina `profili_pubblici` si
 * riempirebbe solo da console, e il catalogo resterebbe vuoto per sempre: un
 * endpoint pubblico perfettamente funzionante che non ha niente da mostrare.
 *
 * ⚠️ E' una lacuna che **nessun test del catalogo avrebbe trovato**, perche'
 * quelli si scrivono le righe da soli.
 */
class SchedaPubblicaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $proprietario;

    private Comune $rimini;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $this->proprietario = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'capo@olimpo.it');

        $this->rimini = Comune::create([
            'codice' => '099014',
            'nome' => 'Rimini',
            'chiave' => app(ChiaveComune::class)->da('Rimini'),
            'provincia' => 'RN',
            'provincia_nome' => 'Rimini',
            'regione' => 'Emilia-Romagna',
            'popolazione' => 150_951,
            'lat' => 44.060249,
            'lng' => 12.565599,
            'attivo' => true,
        ]);
    }

    #[Test]
    public function il_proprietario_puo_creare_la_propria_scheda(): void
    {
        $this->attivaContesto();

        Livewire::actingAs($this->proprietario)
            ->test(SchedaPubblica::class)
            ->fillForm([
                'titolo' => 'Palestra Olimpo',
                'comune_id' => $this->rimini->id,
                'descrizione' => 'Sala pesi e functional',
                'visibile' => true,
            ])
            ->call('save')
            ->assertHasNoErrors();

        $scheda = ProfiloPubblico::where('tenant_id', $this->palestra->id)->first();

        $this->assertNotNull($scheda);
        $this->assertSame('Palestra Olimpo', $scheda->titolo);
        $this->assertSame($this->rimini->id, $scheda->comune_id);
        $this->assertTrue($scheda->visibile);
    }

    #[Test]
    public function salvare_due_volte_aggiorna_e_non_duplica(): void
    {
        /*
         * ⚠️ Il vincolo di unicita' sul database farebbe fallire un secondo
         * inserimento con un errore SQL nudo — che nel pannello sarebbe un 500.
         * `updateOrCreate` e' quello che rende la pagina rientrabile.
         */
        $this->attivaContesto();

        foreach (['Primo nome', 'Secondo nome'] as $titolo) {
            Livewire::actingAs($this->proprietario)
                ->test(SchedaPubblica::class)
                ->fillForm(['titolo' => $titolo, 'comune_id' => $this->rimini->id, 'visibile' => true])
                ->call('save')
                ->assertHasNoErrors();
        }

        $schede = ProfiloPubblico::where('tenant_id', $this->palestra->id)->get();

        $this->assertCount(1, $schede);
        $this->assertSame('Secondo nome', $schede->first()->titolo);
    }

    #[Test]
    public function la_scheda_nasce_spenta(): void
    {
        /*
         * 🚨 Comparire in un catalogo pubblico e' una decisione commerciale del
         * titolare, non l'effetto collaterale di aver compilato un modulo.
         */
        $this->attivaContesto();

        Livewire::actingAs($this->proprietario)
            ->test(SchedaPubblica::class)
            ->assertFormSet(['visibile' => false]);
    }

    #[Test]
    public function il_titolo_e_il_comune_sono_obbligatori(): void
    {
        /*
         * ⚠️ Una scheda senza comune non comparirebbe in nessuna ricerca per
         * vicinanza: sarebbe invisibile pur essendo «pubblicata». Meglio
         * impedirla che lasciare qualcuno a chiedersi perche' non lo trova
         * nessuno.
         */
        $this->attivaContesto();

        Livewire::actingAs($this->proprietario)
            ->test(SchedaPubblica::class)
            ->fillForm(['titolo' => '', 'comune_id' => null])
            ->call('save')
            ->assertHasFormErrors(['titolo', 'comune_id']);
    }

    #[Test]
    public function un_trainer_dipendente_no_n_puo_aprire_la_pagina(): void
    {
        /*
         * 🚨 Chi risponde ai messaggi che arrivano dal catalogo e' il
         * proprietario, e i messaggi sono cifrati **per lui**. Lasciare la
         * pagina allo staff vorrebbe dire far pubblicare a un dipendente una
         * casella che poi non e' la sua.
         */
        $dipendente = $this->creaUtente($this->palestra, UserRole::Trainer, 'tizio@olimpo.it');

        $this->actingAs($dipendente);

        $this->assertFalse(SchedaPubblica::canAccess());
    }

    #[Test]
    public function la_pagina_avverte_che_i_messaggi_non_seguono_il_cambio_di_proprietario(): void
    {
        /*
         * 🚨 **L'avvertenza e' M2.2, non un abbellimento.** Chi pubblica sta per
         * aprire una casella su cui riceverà messaggi cifrati per se': se un
         * giorno la palestra cambiasse proprietario, quei messaggi non li
         * leggerebbe nessun altro. Va saputo **prima**, non il giorno del
         * passaggio di consegne.
         */
        $this->attivaContesto();

        $pagina = Livewire::actingAs($this->proprietario)->test(SchedaPubblica::class);

        $sottotitolo = $pagina->instance()->getSubheading();

        $this->assertStringContainsString('cifrat', (string) $sottotitolo);
        $this->assertStringContainsString('illeggibili', (string) $sottotitolo);
    }

    // ───────────────────── il trainer indipendente ─────────────────────

    #[Test]
    public function un_trainer_indipendente_pubblica_una_scheda_su_a_e_non_della_palestra(): void
    {
        /*
         * 🚨 **Il test che difende la distinzione piu' facile da sbagliare.**
         *
         * ⚠️ Un trainer indipendente ha un tenant **personale** tutto suo.
         * Scrivendo la sua scheda con `tenant_id` — che e' la cosa naturale in
         * un pannello costruito attorno alle palestre — comparirebbe nel
         * catalogo **come palestra**, e i messaggi verrebbero recapitati cercando
         * un `GymAdmin` che nel suo tenant non esiste: scheda «non contattabile»,
         * nessun errore da nessuna parte.
         *
         * 💡 Il vincolo XOR sul database impedisce di scriverli entrambi, ma non
         * puo' sapere **quale dei due** fosse quello giusto. Lo dice questo test.
         */
        $suo = $this->creaPalestra('Studio Tizio', 'tizio', 'TIZI2345');
        $trainer = $this->creaUtente($suo, UserRole::FreeTrainer, 'tizio@esempio.it');

        app(TenantContext::class)->set($suo);

        Livewire::actingAs($trainer)
            ->test(SchedaPubblica::class)
            ->fillForm([
                'titolo' => 'Tizio, personal trainer',
                'comune_id' => $this->rimini->id,
                'visibile' => true,
            ])
            ->call('save')
            ->assertHasNoErrors();

        $scheda = ProfiloPubblico::where('user_id', $trainer->id)->first();

        $this->assertNotNull($scheda, 'La scheda del trainer non e\' stata scritta su user_id.');
        $this->assertNull($scheda->tenant_id, 'La scheda del trainer NON deve avere tenant_id.');
        $this->assertFalse($scheda->ePalestra());
        $this->assertTrue($trainer->is($scheda->destinatario()));
    }

    #[Test]
    public function al_trainer_indipendente_non_si_parla_di_cambio_di_proprietario(): void
    {
        /*
         * 💡 Un trainer indipendente non passa la sua attivita' a nessuno.
         * Scrivergli l'avvertenza delle palestre sarebbe rumore — e il rumore fa
         * smettere di leggere anche gli avvisi che contano.
         */
        $suo = $this->creaPalestra('Studio Tizio', 'tizio', 'TIZI2345');
        $trainer = $this->creaUtente($suo, UserRole::FreeTrainer, 'tizio@esempio.it');

        app(TenantContext::class)->set($suo);

        $sottotitolo = (string) Livewire::actingAs($trainer)
            ->test(SchedaPubblica::class)
            ->instance()
            ->getSubheading();

        $this->assertStringContainsString('cifrat', $sottotitolo);
        $this->assertStringNotContainsString('proprietario', $sottotitolo);
    }

    /**
     * 💡 La pagina legge la palestra dal `TenantContext`, come tutto il pannello:
     * senza contesto non saprebbe di chi e' la scheda.
     */
    private function attivaContesto(): void
    {
        app(TenantContext::class)->set($this->palestra);
    }
}
