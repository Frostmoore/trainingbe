<?php

declare(strict_types=1);

namespace Tests\Feature\Promozioni;

use App\Enums\AzioneDellAvviso;
use App\Enums\TipoBersaglio;
use App\Enums\UserRole;
use App\Http\Controllers\NienteOfferteController;
use App\Mail\BenvenutoMail;
use App\Mail\PromozionePerTeMail;
use App\Models\Avviso;
use App\Models\AvvisoDecisione;
use App\Models\Promozione;
use App\Models\PromozioneBersaglio;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Avvisi\QualeAvvisoMostrare;
use App\Services\Promozioni\AvvisaIDestinatari;
use App\Services\Promozioni\ChiLaRiceve;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🔕 **«Non mandarmi offerte»** — voce 43, DPIA **R16**, 25/09/2026.
 *
 * ══ ⛔ IL RESIDUO CHE QUESTI TEST CHIUDONO ══════════════════════════════════
 *
 * 📌 La DPIA: *«Non c'è ancora un modo, nel prodotto, per dire "non mandarmi
 * offerte": oggi ci si oppone scrivendo»*. 🚨 Opporsi scrivendo una email non è
 * un modo di opporsi: bisogna sapere che il diritto esiste, trovare
 * l'indirizzo, scrivere, e sperare che qualcuno legga.
 *
 * ══ 🚨 COSA GUARDANO, E PERCHE' NON BASTA «l'interruttore funziona» ════════
 *
 * ⛔ Il marketing esce da **due strade** (le promozioni e gli avvisi in-app), e
 * spegnerne una sola è peggio che non spegnerne nessuna: dà alla persona la
 * **certezza** di essere a posto mentre continua a ricevere offerte.
 *
 * ⚠️ E la metà più importante di questi test difende il **verso opposto**: che
 * l'interruttore **non** spenga quello che non è pubblicità. 🚨 Un diritto che
 * si esercita perdendo l'avviso che l'abbonamento sta per scadere non è un
 * diritto, è una trappola.
 */
final class NonMandarmiOfferteTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $tizio;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $this->tizio = $this->creaUtente($this->palestra, UserRole::Member, 'tizio@olimpo.it');

        Mail::fake();
    }

    /** Lo dice, come lo direbbe dall'app. */
    private function spegneLeOfferte(User $chi): void
    {
        $this->actingAs($chi, 'sanctum')
            ->patchJson('/api/v1/account/consents', ['niente_offerte' => true])
            ->assertOk();

        $chi->refresh();
    }

    private function unaPromozione(): Promozione
    {
        $promo = Promozione::withoutGlobalScopes()->create([
            'codice' => 'REGALO',
            'nome' => 'Un regalo',
            'tipo' => 'gettoni',
            'quantita' => 100,
            'attiva' => true,
            'avviso' => ['app', 'email'],
        ]);

        PromozioneBersaglio::withoutGlobalScopes()->create([
            'promozione_id' => $promo->getKey(),
            'tipo' => TipoBersaglio::Tutti,
        ]);

        return $promo;
    }

    // ───────────────── 1. Le promozioni ─────────────────

    /**
     * 🚨 **Il cuore della voce**: chi ha detto di no non riceve l'annuncio.
     */
    #[Test]
    public function chi_ha_spento_le_offerte_non_riceve_lannuncio_di_una_promozione(): void
    {
        $this->spegneLeOfferte($this->tizio);

        app(AvvisaIDestinatari::class)->aTutti($this->unaPromozione());

        Mail::assertNothingSent();
    }

    /**
     * ⚖️ **E chi non l'ha spento continua a riceverlo.**
     *
     * ⛔ Senza questo test, una modifica che spegne le offerte **a tutti**
     * passerebbe: il primo test da solo non distingue «rispetta il rifiuto» da
     * «non manda niente a nessuno».
     */
    #[Test]
    public function chi_non_lo_ha_spento_riceve_lannuncio_come_prima(): void
    {
        app(AvvisaIDestinatari::class)->aTutti($this->unaPromozione());

        Mail::assertSent(PromozionePerTeMail::class);
    }

    /**
     * 🚨 **Si ferma l'annuncio, NON la promozione.**
     *
     * ⛔ Chi ha spento le offerte e ne riceve una la trova lo stesso nel saldo:
     * quello a cui si è opposto è la **pubblicità**, non il regalo. ⚠️
     * Togliergli anche quello sarebbe punirlo per aver esercitato un diritto.
     */
    #[Test]
    public function la_promozione_arriva_lo_stesso_e_solo_lannuncio_che_non_parte(): void
    {
        $this->spegneLeOfferte($this->tizio);

        $promo = $this->unaPromozione();

        $this->assertTrue(
            app(ChiLaRiceve::class)->prende($promo, $this->tizio),
            'Spegnere gli avvisi ha tolto la promozione: è il difetto opposto.',
        );
    }

    // ───────────────── 2. Gli avvisi in-app ─────────────────

    private function unAvviso(array $extra = []): Avviso
    {
        $avviso = Avviso::withoutGlobalScopes()->create(array_merge([
            'titolo' => 'Un avviso',
            'testo' => 'Il testo.',
            'azione' => AzioneDellAvviso::Dismiss,
            'attivo' => true,
        ], $extra));

        PromozioneBersaglio::withoutGlobalScopes()->create([
            'pubblico_type' => $avviso->getMorphClass(),
            'pubblico_id' => $avviso->getKey(),
            'tipo' => TipoBersaglio::Tutti,
        ]);

        return $avviso;
    }

    #[Test]
    public function un_avviso_commerciale_non_si_mostra_a_chi_ha_spento_le_offerte(): void
    {
        $this->spegneLeOfferte($this->tizio);

        $this->unAvviso(['commerciale' => true]);

        $this->assertNull(app(QualeAvvisoMostrare::class)->per($this->tizio));
    }

    /**
     * ⛔ **E uno normale si mostra lo stesso**: l'interruttore riguarda la
     * pubblicità, non gli avvisi.
     */
    #[Test]
    public function un_avviso_normale_si_mostra_anche_a_chi_ha_spento_le_offerte(): void
    {
        $this->spegneLeOfferte($this->tizio);

        $this->unAvviso(['commerciale' => false]);

        $this->assertNotNull(app(QualeAvvisoMostrare::class)->per($this->tizio));
    }

    /**
     * 🚨 **L'urgente passa sempre**, anche se commerciale.
     *
     * ⚠️ Ed è il motivo per cui `urgente` va usato con parsimonia: dal pannello
     * è una casella, e una casella che scavalca un rifiuto va meritata.
     */
    #[Test]
    public function un_avviso_urgente_passa_anche_se_commerciale(): void
    {
        $this->spegneLeOfferte($this->tizio);

        $this->unAvviso(['commerciale' => true, 'urgente' => true]);

        $this->assertNotNull(app(QualeAvvisoMostrare::class)->per($this->tizio));
    }

    /**
     * 🚨 **Un avviso saltato non consuma il tetto.**
     *
     * ⛔ Il difetto che questo test rende impossibile: segnare «mostrato» un
     * avviso che la persona non vedrà mai. ⚠️ Chi rifiuta la pubblicità si
     * mangerebbe i quattro avvisi del mese in avvisi **invisibili**, e non
     * riceverebbe quelli veri.
     */
    #[Test]
    public function un_avviso_saltato_non_consuma_il_tetto(): void
    {
        $this->spegneLeOfferte($this->tizio);

        $this->unAvviso(['commerciale' => true]);
        $buono = $this->unAvviso(['titolo' => 'Questo si deve vedere']);

        $mostrato = app(QualeAvvisoMostrare::class)->per($this->tizio);

        $this->assertSame($buono->getKey(), $mostrato?->getKey());
        $this->assertSame(
            0,
            AvvisoDecisione::query()
                ->where('user_id', $this->tizio->getKey())
                ->whereNot('avviso_id', $buono->getKey())
                ->count(),
            'Un avviso mai mostrato ha lasciato una riga: consumerebbe il tetto.',
        );
    }

    // ───────────────── 3. La guardia sul pannello ─────────────────

    /**
     * 🚨 **Un avviso che attiva una promozione E' pubblicità, e non si può
     * dichiarare il contrario.**
     *
     * ⛔ La casella nel pannello la spunta una persona, di fretta, mesi dopo
     * che qualcuno ha scritto questa regola. Lasciarla libera vorrebbe dire che
     * un'offerta può essere mandata **dichiarandola non commerciale** — e chi
     * l'ha rifiutata la riceve lo stesso, senza che nessuno abbia mentito.
     */
    #[Test]
    public function un_avviso_con_azione_promo_e_commerciale_per_forza(): void
    {
        $promo = $this->unaPromozione();

        $avviso = Avviso::withoutGlobalScopes()->create([
            'titolo' => 'Ti regalo qualcosa',
            'testo' => 'Testo.',
            'azione' => AzioneDellAvviso::Promo,
            'promozione_id' => $promo->getKey(),
            'commerciale' => false,   // ⛔ ci si prova, e non deve servire
            'attivo' => true,
        ]);

        $this->assertTrue(
            $avviso->refresh()->commerciale,
            'Un avviso che regala una promozione si è dichiarato non commerciale.',
        );
    }

    // ───────────────── 4. L'interruttore ─────────────────

    #[Test]
    public function linterruttore_si_accende_e_si_spegne_dalla_stessa_chiamata(): void
    {
        $dati = $this->actingAs($this->tizio, 'sanctum')
            ->patchJson('/api/v1/account/consents', ['niente_offerte' => true])
            ->assertOk()
            ->json('data');

        $this->assertTrue($dati['niente_offerte']);
        $this->assertNotNull($dati['niente_offerte_dal'], 'Manca da quando vale.');

        $dati = $this->actingAs($this->tizio, 'sanctum')
            ->patchJson('/api/v1/account/consents', ['niente_offerte' => false])
            ->assertOk()
            ->json('data');

        $this->assertFalse($dati['niente_offerte']);
        $this->assertNull($dati['niente_offerte_dal'], 'La data è rimasta su un rifiuto ritirato.');
    }

    /**
     * ⚖️ **Di serie le offerte arrivano.**
     *
     * 🚨 È un **opt-out**, non un consenso: chi non ha mai toccato niente non
     * deve risultare oppostо. ⛔ Il verso sbagliato spegnerebbe le promozioni a
     * tutti, in silenzio.
     */
    #[Test]
    public function di_serie_le_offerte_arrivano(): void
    {
        $dati = $this->actingAs($this->tizio, 'sanctum')
            ->getJson('/api/v1/account/consents')
            ->assertOk()
            ->json('data');

        $this->assertFalse($dati['niente_offerte']);
        $this->assertNull($dati['niente_offerte_dal']);
    }

    /**
     * 🚨 **La data non si riscrive se lo stato non cambia.**
     *
     * ⛔ Serve a dimostrare **da quando** vale l'opposizione (art. 21): una
     * prova che si riscrive da sola a ogni `PATCH` non è una prova.
     */
    #[Test]
    public function ripetere_la_stessa_scelta_non_sposta_la_data(): void
    {
        $this->spegneLeOfferte($this->tizio);
        $prima = $this->tizio->fresh()?->niente_offerte_dal;

        $this->travel(2)->days();

        $this->actingAs($this->tizio, 'sanctum')
            ->patchJson('/api/v1/account/consents', ['niente_offerte' => true])
            ->assertOk();

        $this->assertEquals($prima, $this->tizio->fresh()?->niente_offerte_dal);
    }

    // ───────────────── 5. Il collegamento nelle email ─────────────────

    /**
     * 🚨 **Ogni email promozionale porta il modo di non riceverne più** — art.
     * 130, c. 4, Codice privacy. 📌 Il committente, 25/09: *«in ogni email
     * promozionale per disiscriversi dalle email promozionali»*.
     */
    #[Test]
    public function lemail_di_una_promozione_porta_il_collegamento(): void
    {
        $mail = new PromozionePerTeMail($this->unaPromozione(), $this->tizio, ricevuta: false);

        $reso = $mail->render();

        $this->assertStringContainsString('/niente-offerte/'.$this->tizio->getKey(), $reso);
        $this->assertStringContainsString('signature=', $reso, 'Il collegamento non è firmato.');
    }

    /**
     * 🔑 **E l'header che fa comparire il pulsante nel client di posta** (RFC
     * 8058). 💡 Un collegamento in fondo lo trova chi scorre fino in fondo;
     * questo lo vede chiunque, prima ancora di aprire il messaggio.
     */
    #[Test]
    public function lemail_dichiara_lheader_standard_della_disiscrizione(): void
    {
        $mail = new PromozionePerTeMail($this->unaPromozione(), $this->tizio, ricevuta: false);

        $header = $mail->headers()->text;

        $this->assertArrayHasKey('List-Unsubscribe', $header);
        $this->assertStringContainsString('/niente-offerte/', $header['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $header['List-Unsubscribe-Post']);
    }

    /**
     * ⛔ **Le email transazionali NON lo hanno, ed è la metà che conta.**
     *
     * 📌 Il committente: *«quelle transazionali o urgenti restano»*. 🚨 Offrire
     * di disiscriversi da una ricevuta o da un invito vorrebbe dire promettere
     * qualcosa che non possiamo mantenere: quelle email le mandiamo comunque,
     * perché senza non si usa il servizio.
     */
    #[Test]
    public function le_email_di_servizio_non_offrono_di_disiscriversi(): void
    {
        $utente = User::withoutGlobalScopes()->findOrFail($this->tizio->getKey());

        $benvenuto = (new BenvenutoMail($utente))->render();

        $this->assertStringNotContainsString('niente-offerte', $benvenuto);
        $this->assertStringNotContainsString('disiscriv', mb_strtolower($benvenuto));
    }

    // ───────────────── 6. La pagina pubblica ─────────────────

    /**
     * 🚨 **Il `GET` non spegne niente.**
     *
     * ⛔ I filtri antispam e le anteprime dei client di posta **aprono i link da
     * soli**: un `GET` che eseguisse vorrebbe dire persone disiscritte da un
     * antivirus, senza aver toccato niente. ⚠️ E la prima volta che succede non
     * lo capisce nessuno.
     */
    #[Test]
    public function aprire_il_collegamento_non_spegne_ancora_niente(): void
    {
        $this->get(NienteOfferteController::indirizzo($this->tizio->getKey()))
            ->assertOk()
            ->assertSee('Non ricevere più offerte');

        $this->assertFalse((bool) $this->tizio->fresh()?->niente_offerte);
    }

    /** ✅ È il `POST` che spegne, ed è quello che chiama anche il client di posta. */
    #[Test]
    public function il_bottone_spegne_le_offerte(): void
    {
        $this->post(NienteOfferteController::indirizzoDelPost($this->tizio->getKey()))
            ->assertRedirect();

        $this->assertTrue((bool) $this->tizio->fresh()?->niente_offerte);
        $this->assertNotNull($this->tizio->fresh()?->niente_offerte_dal);
    }

    /**
     * 🔑 **Senza firma non si disiscrive nessuno.**
     *
     * ⛔ La rotta è pubblica e porta un id nell'indirizzo: senza la firma,
     * quell'id basterebbe a disiscrivere chiunque tirando a indovinare un
     * numero.
     */
    #[Test]
    public function senza_firma_il_collegamento_non_vale(): void
    {
        $this->post('/niente-offerte/'.$this->tizio->getKey())->assertForbidden();
        $this->get('/niente-offerte/'.$this->tizio->getKey())->assertForbidden();

        $this->assertFalse((bool) $this->tizio->fresh()?->niente_offerte);
    }

    /**
     * 🚨 **Non si può usare per sapere se una persona è iscritta.**
     *
     * ⛔ Rispondere «questo account non esiste» a un indirizzo pubblico
     * trasformerebbe il collegamento in un modo per verificare se un id
     * corrisponde a qualcuno. 💡 Chi arriva con un id che non c'è più vede la
     * stessa pagina degli altri.
     */
    #[Test]
    public function un_id_che_non_esiste_non_lo_dichiara(): void
    {
        $this->get(NienteOfferteController::indirizzo(999999))
            ->assertOk()
            ->assertSee('Non ricevere più offerte');
    }

    /** ⚠️ Chi l'ha già fatto lo legge, invece di premere un bottone inutile. */
    #[Test]
    public function chi_lo_ha_gia_fatto_se_lo_sente_dire(): void
    {
        $this->spegneLeOfferte($this->tizio);

        $this->get(NienteOfferteController::indirizzo($this->tizio->getKey()))
            ->assertOk()
            ->assertSee('È fatto.');
    }
}
