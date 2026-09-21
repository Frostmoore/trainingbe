<?php

declare(strict_types=1);

namespace Tests\Feature\Pannello;

use App\Enums\UserRole;
use App\Filament\Gym\Pages\GymSettings;
use App\Models\Comune;
use App\Models\ProfiloPubblico;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Scoperta\ChiaveComune;
use App\Services\Tenancy\InvitiInPalestra;
use App\Support\Fatturazione\ControlliFiscali;
use App\Support\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🧾 📍 **Le impostazioni e la scheda che si vede nell'invito** — 23/09/2026, F6.
 *
 * ══ 📌 COSA DIMOSTRA ═════════════════════════════════════════════════════
 *
 * | Fase | Cosa |
 * |---|---|
 * | **F6.4** | i dati per le fatture si salvano, e una partita IVA sbagliata si ferma **mentre si scrive** |
 * | **F6.4** | ⛔ «rigenera codice d'invito» non c'è più |
 * | **F6.2** | testata e indirizzo arrivano all'app, nella pagina dell'**invito** |
 */
final class LeImpostazioniELaSchedaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Olimpia', 'olimpia');
        $this->gestore = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'capo@olimpia.test');

        filament()->setCurrentPanel('admin');
    }

    private function entra(): void
    {
        $this->actingAs($this->gestore);
        app(TenantContext::class)->set($this->palestra);
    }

    // ───────────────────────── F6.4, i dati per le fatture ─────────────────────────

    /**
     * 🧾 **I dati si salvano, puliti** — punti e spazi tolti dalla partita IVA,
     * la provincia in maiuscolo.
     *
     * 📌 *«servono tutte le info per la fatturazione (p.iva, pec, codice SDI,
     * nome legale, sede legale)»*.
     */
    #[Test]
    public function i_dati_per_le_fatture_si_salvano(): void
    {
        $this->entra();

        Livewire::test(GymSettings::class)
            ->fillForm([
                'name' => 'Olimpia',
                'fatt_ragione_sociale' => 'Olimpia Sport SSD a r.l.',
                'fatt_partita_iva' => 'IT 022.880.805.63',
                'fatt_indirizzo' => 'Via degli Orti, 426',
                'fatt_cap' => '01036',
                'fatt_comune' => 'Nepi',
                'fatt_provincia' => 'vt',
                'fatt_nazione' => 'IT',
                'fatt_codice_sdi' => 'm5uxcr1',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $palestra = $this->palestra->refresh();

        $this->assertSame('02288080563', $palestra->fatt_partita_iva);
        $this->assertSame('VT', $palestra->fatt_provincia);
        $this->assertSame('M5UXCR1', $palestra->fatt_codice_sdi);
        $this->assertSame([], $palestra->datiDiFatturazioneMancanti());
    }

    /**
     * 🚨 **Una partita IVA sbagliata si ferma qui**, non allo scarto.
     *
     * ⛔ Noi non trasmettiamo allo SDI (D2): una cifra invertita si
     * scoprirebbe settimane dopo, e costerebbe una nota di credito.
     */
    #[Test]
    public function una_partita_iva_sbagliata_si_ferma_subito(): void
    {
        $this->entra();

        Livewire::test(GymSettings::class)
            ->fillForm(['name' => 'Olimpia', 'fatt_partita_iva' => '02288080564', 'fatt_nazione' => 'IT'])
            ->call('save')
            ->assertHasFormErrors(['fatt_partita_iva']);
    }

    /** 📬 Col codice `0000000` serve la PEC: è l'unico posto dove può arrivare la fattura. */
    #[Test]
    public function senza_codice_sdi_serve_la_pec(): void
    {
        $this->entra();

        Livewire::test(GymSettings::class)
            ->fillForm(['name' => 'Olimpia', 'fatt_codice_sdi' => '0000000', 'fatt_pec' => ''])
            ->call('save')
            ->assertHasFormErrors(['fatt_pec']);
    }

    /** ⛔ «Rigenera codice d'invito» non c'è più, e nemmeno il codice nel sottotitolo. */
    #[Test]
    public function il_codice_d_invito_non_c_e_piu(): void
    {
        $this->entra();

        Livewire::test(GymSettings::class)
            ->assertActionDoesNotExist('rigenera_codice');
    }

    /** 📐 La cifra di controllo, sul numero vero del committente. */
    #[Test]
    public function la_cifra_di_controllo_e_quella_del_ministero(): void
    {
        $this->assertTrue(ControlliFiscali::partitaIvaItaliana('02288080563'));
        $this->assertFalse(ControlliFiscali::partitaIvaItaliana('02288080564'));
        $this->assertFalse(ControlliFiscali::partitaIvaItaliana('00000000000'));

        // 🌍 Fuori dall'Italia si controlla la forma, non l'algoritmo italiano.
        $this->assertTrue(ControlliFiscali::partitaIva('DE123456789', 'DE'));

        $this->assertTrue(ControlliFiscali::codiceFiscale('RNCRCR88T16H501M'));
        $this->assertTrue(ControlliFiscali::codiceSdi('0000000'));
        $this->assertFalse(ControlliFiscali::codiceSdi('ABC'));
    }

    // ───────────────────────── F6.2, cosa arriva all'app ─────────────────────────

    /**
     * 🖼️ 📍 **L'invito porta testata, indirizzo e mappa** — F6.2.
     *
     * 💡 È lì che l'app li mostra: il catalogo non esiste ancora.
     */
    #[Test]
    public function l_anteprima_dell_invito_porta_indirizzo_e_mappa(): void
    {
        $nepi = Comune::create([
            'codice' => '056040', 'nome' => 'Nepi', 'chiave' => app(ChiaveComune::class)->da('Nepi'),
            'provincia' => 'VT', 'provincia_nome' => 'Viterbo', 'regione' => 'Lazio', 'attivo' => true,
        ]);

        ProfiloPubblico::query()->create([
            'tenant_id' => $this->palestra->getKey(),
            'comune_id' => $nepi->getKey(),
            'titolo' => 'Olimpia',
            'indirizzo' => 'Via degli Orti, 426',
            'cap' => '01036',
        ]);

        $invito = app(InvitiInPalestra::class)->invita($this->palestra, $this->gestore, 'nuovo@esempio.test');

        $anteprima = app(InvitiInPalestra::class)->anteprima($invito->token);

        $this->assertSame('Via degli Orti, 426 · 01036 Nepi (VT)', $anteprima['indirizzo']);
        $this->assertStringStartsWith('https://www.google.com/maps/search/?api=1&query=', (string) $anteprima['mappa']);

        // 💡 Senza testata caricata il campo c'è, ed è vuoto: l'app non disegna niente.
        $this->assertArrayHasKey('immagine_testata', $anteprima);
        $this->assertNull($anteprima['immagine_testata']);
    }
}
