<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Filament\God\Resources\Fatture\Pages\ListFatture;
use App\Filament\Gym\Pages\IlTuoPiano;
use App\Mail\RichiestaDalPannello;
use App\Models\Fattura;
use App\Models\PagamentoIncassato;
use App\Models\RichiestaDiContatto;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\RegistroDeiPagamenti;
use App\Services\Fatturazione\EmissioneDelleFatture;
use App\Support\Fatturazione\TestoLatino;
use App\Support\Tenancy\TenantContext;
use Database\Seeders\PlanSeeder;
use DOMDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\Concerns\UsaStripeFinto;
use Tests\TestCase;

/**
 * 🧾 F8 — le fatture: chi le riceve, come sono fatte, chi le scarica.
 *
 * 🚨 **Ogni XML si valida contro lo schema ufficiale** (`Schema_VFPR12_v1.2.3.xsd`,
 * scaricato com'è da fatturapa.gov.it in `tests/fixtures/fatturapa/`). ⛔ Lo
 * SDI scarta il file intero per un elemento fuori ordine o un carattere fuori
 * da Latin-1: «sembra giusto» non basta.
 */
final class LeFattureTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;
    use UsaStripeFinto;

    private Tenant $palestra;

    private User $titolare;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
        $this->stripeFinto();

        Carbon::setTestNow('2026-09-23 10:00:00');

        $this->palestra = $this->creaPalestra('Olimpia', 'olimpia', 'OLIM2345');
        $this->titolare = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'capo@olimpia.test');
        $this->palestra->forceFill(['proprietario_id' => $this->titolare->getKey()])->save();

        $this->conIDatiFiscali($this->palestra);

        filament()->setCurrentPanel('admin');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ───────────────────────── a chi, e quando ─────────────────────────

    /** 🧾 Il canone di una palestra diventa una fattura emessa, con un XML valido. */
    #[Test]
    public function il_canone_della_palestra_diventa_una_fattura_valida(): void
    {
        $f = $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30');

        $this->assertNotNull($f);
        $this->assertTrue($f->eEmessa());
        $this->assertSame('1/TC', $f->numeroPerEsteso());
        $this->assertSame(200, $f->bollo_cent, 'Sopra 77,47 € il bollo virtuale è dovuto.');
        $this->assertSame(13000, $f->totale_cent, 'Il bollo lo paghiamo noi: non entra nel totale.');
        $this->assertXmlValido($f->xml);

        $xml = (string) $f->xml;
        $this->assertStringContainsString('<RegimeFiscale>RF19</RegimeFiscale>', $xml);
        $this->assertStringContainsString('<Natura>N2.2</Natura>', $xml);
        $this->assertStringContainsString('<BolloVirtuale>SI</BolloVirtuale>', $xml);
        $this->assertStringContainsString('<IdCodice>02288080563</IdCodice>', $xml);
    }

    /** Sotto la soglia, niente bollo — e niente `DatiBollo` nell'XML. */
    #[Test]
    public function sotto_la_soglia_niente_bollo(): void
    {
        $f = $this->incassa(PagamentoIncassato::CANONE, 6000, 'gym_10');

        $this->assertSame(0, $f->bollo_cent);
        $this->assertStringNotContainsString('DatiBollo', (string) $f->xml);
        $this->assertXmlValido($f->xml);
    }

    /**
     * 🚨 **Solo quello che paga la palestra.** Il Plus personale di un
     * iscritto finisce sotto il tenant della palestra, ma non è suo.
     */
    #[Test]
    public function il_plus_di_un_iscritto_non_si_fattura_alla_palestra(): void
    {
        $iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'mario@olimpia.test');

        $this->assertNull($this->incassa(PagamentoIncassato::CANONE, 499, 'plus', $iscritto));
    }

    /** I gettoni: del titolare sì (senza il nome dell'iscritto regalato), di un iscritto no. */
    #[Test]
    public function i_gettoni_si_fatturano_solo_se_li_paga_il_titolare(): void
    {
        $iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'mario@olimpia.test');

        $this->assertNull($this->incassa(PagamentoIncassato::GETTONI, 1000, '500 gettoni', $iscritto));

        $f = $this->incassa(PagamentoIncassato::GETTONI, 1000, '500 gettoni per Mario Rossi', $this->titolare);

        $this->assertNotNull($f);
        $this->assertStringContainsString('500 gettoni', $f->descrizione);
        $this->assertStringContainsString('regalati a un iscritto', $f->descrizione);
        $this->assertStringNotContainsString('Mario', $f->descrizione, 'Il nome di un iscritto non va sulla fattura.');
    }

    /** Il consumo mensile si fattura, col mese per esteso. */
    #[Test]
    public function il_consumo_si_fattura(): void
    {
        $f = $this->incassa(PagamentoIncassato::CONSUMO, 4500, 'consumo 2026-08');

        $this->assertStringContainsString('agosto 2026', $f->descrizione);
        $this->assertXmlValido($f->xml);
    }

    /** Lo spazio personale di un trainer non riceve fatture da qui (debito dichiarato). */
    #[Test]
    public function lo_spazio_di_un_trainer_non_si_fattura(): void
    {
        $spazio = $this->creaPalestra('Spazio di Anna', 'anna', 'ANNA2345');
        $spazio->forceFill(['kind' => 'personal'])->save();

        $riga = app(RegistroDeiPagamenti::class)->segna($spazio, PagamentoIncassato::CANONE, 1500, 'cs_trainer', cosa: 'trainer_5');

        $this->assertSame(0, Fattura::withoutGlobalScopes()->where('pagamento_incassato_id', $riga->getKey())->count());
    }

    /** 💡 Un webhook doppio non fa due fatture. */
    #[Test]
    public function un_incasso_ha_una_fattura_sola(): void
    {
        $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30', stripeId: 'in_doppio');
        $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30', stripeId: 'in_doppio');

        $this->assertSame(1, Fattura::withoutGlobalScopes()->count());
    }

    /** 🔢 La serie è progressiva, e riparte a ogni anno. */
    #[Test]
    public function la_numerazione_e_progressiva_e_riparte_ogni_anno(): void
    {
        $this->assertSame('1/TC', $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30')->numeroPerEsteso());
        $this->assertSame('2/TC', $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30')->numeroPerEsteso());

        Carbon::setTestNow('2027-01-02 09:00:00');

        $nuovo = $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30');
        $this->assertSame('1/TC', $nuovo->numeroPerEsteso());
        $this->assertSame(2027, $nuovo->anno);
    }

    // ───────────────────────── i dati che mancano ─────────────────────────

    /**
     * ⏳ Senza dati fiscali la fattura **aspetta**, senza numero; quando la
     * palestra li salva, parte da sola.
     */
    #[Test]
    public function senza_dati_la_fattura_aspetta_e_parte_quando_arrivano(): void
    {
        $this->palestra->forceFill(['fatt_partita_iva' => null, 'fatt_codice_fiscale' => null])->save();

        $f = $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30');

        $this->assertFalse($f->eEmessa());
        $this->assertNull($f->numero, 'Un numero dato a una fattura che non esiste lascerebbe un buco.');
        $this->assertNull($f->xml);

        $this->conIDatiFiscali($this->palestra->refresh());

        $this->assertSame(1, app(EmissioneDelleFatture::class)->emettiQuelleInAttesa($this->palestra->refresh()));
        $this->assertTrue($f->refresh()->eEmessa());
        $this->assertXmlValido($f->xml);
    }

    /** 📸 Il cliente è fotografato: cambiare la ragione sociale non riscrive le fatture vecchie. */
    #[Test]
    public function il_cliente_e_una_fotografia(): void
    {
        $f = $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30');

        $this->palestra->forceFill(['fatt_ragione_sociale' => 'Nome Nuovo S.r.l.'])->save();

        $this->assertSame('Olimpia S.r.l.', $f->refresh()->cessionario['ragione_sociale']);
        $this->assertStringContainsString('Olimpia S.r.l.', (string) $f->xml);
    }

    // ───────────────────────── l'XML nei casi scomodi ─────────────────────────

    /** ✍️ «—», «€», «×», le virgolette curve: ripuliti, e l'XML resta valido. */
    #[Test]
    public function i_caratteri_fuori_da_latin1_si_ripuliscono(): void
    {
        $this->assertSame('Palestra - "centro" x 2 EUR', TestoLatino::di("Palestra — “centro” × 2 €\n", 100));
        $this->assertSame('Città', TestoLatino::di('Città 🏋️', 100), 'Le accentate italiane restano.');

        $this->palestra->forceFill(['fatt_ragione_sociale' => 'Olimpia — “Centro” S.r.l. 🏋️'])->save();

        $this->assertXmlValido($this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30')->xml);
    }

    /** 📬 Con la PEC: codice destinatario `0000000` e `PECDestinatario`. */
    #[Test]
    public function con_la_pec_il_codice_e_sette_zeri(): void
    {
        $this->palestra->forceFill(['fatt_codice_sdi' => null, 'fatt_pec' => 'olimpia@pec.it'])->save();

        $xml = (string) $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30')->xml;

        $this->assertStringContainsString('<CodiceDestinatario>0000000</CodiceDestinatario>', $xml);
        $this->assertStringContainsString('<PECDestinatario>olimpia@pec.it</PECDestinatario>', $xml);
        $this->assertXmlValido($xml);
    }

    /** 🌍 Un cliente estero: `XXXXXXX`, CAP `00000`, niente provincia — e valido. */
    #[Test]
    public function un_cliente_estero_segue_le_regole_dello_sdi(): void
    {
        $this->palestra->forceFill([
            'fatt_nazione' => 'DE', 'fatt_partita_iva' => '123456789', 'fatt_codice_fiscale' => null,
            'fatt_cap' => '10115', 'fatt_comune' => 'Berlin', 'fatt_provincia' => null,
            'fatt_codice_sdi' => null, 'fatt_pec' => 'fit@beispiel.de',
        ])->save();

        $xml = (string) $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30')->xml;

        $this->assertStringContainsString('<CodiceDestinatario>XXXXXXX</CodiceDestinatario>', $xml);
        $this->assertStringContainsString('<CAP>00000</CAP>', $xml);
        $this->assertXmlValido($xml);
    }

    // ───────────────────────── chi scarica cosa ─────────────────────────

    /** 📥 Il titolare scarica il PDF delle sue fatture; l'XML dal suo pannello non esiste. */
    #[Test]
    public function il_titolare_scarica_il_pdf_e_non_l_xml(): void
    {
        $f = $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30');

        $this->entra($this->titolare);

        $risposta = $this->get(route('filament.admin.fattura-pdf', ['fattura' => $f->getKey()]));
        $risposta->assertOk();
        $this->assertSame('application/pdf', $risposta->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', (string) $risposta->getContent());

        // ⛔ L'XML solo al super admin (D2).
        $this->get('/god/fatture/'.$f->getKey().'/xml')->assertStatus(403);
    }

    /** 🔒 Un'altra palestra, o un trainer della stessa, non vedono niente: 404. */
    #[Test]
    public function gli_altri_non_scaricano_le_fatture(): void
    {
        $f = $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30');
        $url = route('filament.admin.fattura-pdf', ['fattura' => $f->getKey()]);

        $altra = $this->creaPalestra('Beta', 'beta', 'BETA2345');
        $altroTitolare = $this->creaUtente($altra, UserRole::GymAdmin, 'capo@beta.test');
        $altra->forceFill(['proprietario_id' => $altroTitolare->getKey()])->save();

        $this->entra($altroTitolare);
        $this->get($url)->assertNotFound();

        $this->entra($this->creaUtente($this->palestra, UserRole::Trainer, 'anna@olimpia.test'));
        $this->get($url)->assertNotFound();
    }

    /** 🏛️ Il super admin scarica XML e PDF, e vede l'elenco. */
    #[Test]
    public function il_super_admin_scarica_xml_e_pdf(): void
    {
        $f = $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30');
        $god = $this->creaSuperAdmin();

        $this->entra($god);
        filament()->setCurrentPanel('god');

        $xml = $this->get(route('filament.god.fattura-xml', ['fattura' => $f->getKey()]));
        $xml->assertOk();
        $this->assertStringContainsString('IT02288080563_', (string) $xml->headers->get('Content-Disposition'));
        $this->assertXmlValido((string) $xml->getContent());

        $this->get(route('filament.god.fattura-pdf', ['fattura' => $f->getKey()]))->assertOk();

        Livewire::test(ListFatture::class)
            ->assertSuccessful()
            ->assertCanSeeTableRecords([$f])
            ->assertSee('1/TC');
    }

    // ───────────────────────── «Il tuo piano» ─────────────────────────

    /** 💳 Le fatture al posto del portale di Stripe, e i dati che mancano detti chiari. */
    #[Test]
    public function il_tuo_piano_mostra_le_fatture_e_non_il_portale(): void
    {
        $this->incassa(PagamentoIncassato::CANONE, 13000, 'gym_30');
        $this->entra($this->titolare);

        Livewire::test(IlTuoPiano::class)
            ->assertSuccessful()
            ->assertSee('Le tue fatture')
            ->assertSee('1/TC')
            ->assertDontSee('Carte, ricevute e disdetta');

        $this->palestra->forceFill(['fatt_pec' => null, 'fatt_codice_sdi' => null])->save();
        $this->entra($this->titolare->refresh());

        Livewire::test(IlTuoPiano::class)->assertSee('il codice SDI o la PEC');
    }

    /**
     * 📬 Il piano su misura delle palestre si **chiede**: la richiesta resta nel
     * database e parte una mail al titolare della piattaforma.
     */
    #[Test]
    public function il_su_misura_manda_una_richiesta(): void
    {
        Mail::fake();
        $this->entra($this->titolare);

        Livewire::test(IlTuoPiano::class)
            ->assertSee('Scrivici')
            ->callAction('suMisura', ['iscritti' => 400, 'trainer' => 12, 'messaggio' => 'Abbiamo tre sedi e vorremmo un prezzo unico.'])
            ->assertHasNoActionErrors();

        $richiesta = RichiestaDiContatto::query()->sole();
        $this->assertSame('Olimpia', $richiesta->organizzazione);
        $this->assertSame(400, $richiesta->iscritti);

        Mail::assertSent(RichiestaDalPannello::class, fn (RichiestaDalPannello $m): bool => $m->hasTo('info@smp-digital.it')
            && $m->hasReplyTo('capo@olimpia.test'));
    }

    /** ⛔ E «scegli» sul su misura non apre nessun pagamento, nemmeno chiamato a mano. */
    #[Test]
    public function scegliere_il_su_misura_non_apre_un_pagamento(): void
    {
        $stripe = $this->stripeFinto();
        $this->entra($this->titolare);

        Livewire::test(IlTuoPiano::class)->call('scegli', 'gym_consumo')->assertNoRedirect();

        $this->assertSame([], $stripe->chiamateA('checkout.sessions.create'));
    }

    // ───────────────────────── aiuti ─────────────────────────

    private function incassa(string $tipo, int $cent, string $cosa, ?User $chi = null, ?string $stripeId = null): ?Fattura
    {
        $riga = app(RegistroDeiPagamenti::class)->segna(
            tenant: $this->palestra,
            tipo: $tipo,
            importoCent: $cent,
            stripeId: $stripeId ?? 'in_'.uniqid(),
            utente: $chi,
            cosa: $cosa,
        );

        // La coda nei test è sincrona: il job è già girato.
        return app(TenantContext::class)->runWithoutTenant(
            fn (): ?Fattura => Fattura::query()->where('pagamento_incassato_id', $riga->getKey())->first(),
        );
    }

    private function conIDatiFiscali(Tenant $palestra): void
    {
        $palestra->forceFill([
            'fatt_ragione_sociale' => 'Olimpia S.r.l.',
            'fatt_partita_iva' => '01234567897',
            'fatt_codice_fiscale' => '01234567897',
            'fatt_indirizzo' => 'Via Roma 12',
            'fatt_cap' => '00100',
            'fatt_comune' => 'Roma',
            'fatt_provincia' => 'RM',
            'fatt_nazione' => 'IT',
            'fatt_codice_sdi' => 'M5UXCR1',
            'fatt_pec' => null,
        ])->save();
    }

    private function assertXmlValido(?string $xml): void
    {
        $this->assertNotNull($xml);

        $doc = new DOMDocument;
        $doc->loadXML((string) $xml);

        libxml_use_internal_errors(true);
        libxml_clear_errors();

        $valido = $doc->schemaValidate(base_path('tests/fixtures/fatturapa/Schema_VFPR12_v1.2.3.xsd'));
        $errori = array_map(fn ($e): string => trim($e->message).' (riga '.$e->line.')', libxml_get_errors());

        libxml_clear_errors();

        $this->assertTrue($valido, "L'XML non passa lo schema FatturaPA:\n".implode("\n", $errori));
    }

    private function entra(User $utente): void
    {
        app('auth')->forgetGuards();
        $this->flushSession();
        $this->actingAs($utente);

        app(TenantContext::class)->set($utente->tenant);
    }
}
