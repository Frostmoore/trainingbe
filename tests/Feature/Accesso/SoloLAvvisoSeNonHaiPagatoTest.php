<?php

declare(strict_types=1);

namespace Tests\Feature\Accesso;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Filament\Gym\Pages\Cruscotto;
use App\Filament\Gym\Pages\IlTuoPiano;
use App\Filament\Gym\Resources\Members\MemberResource;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🔒 **Con il servizio sospeso: solo l'avviso, e nient'altro** — 63.D, 27/09/2026.
 *
 * ══ 📌 LA DECISIONE ═══════════════════════════════════════════════════════
 *
 * 📌 Il committente, 27/09: *«chi non ha pagato … sul pannello vede solo l'avviso
 * e non può fare nulla»*.
 *
 * ══ 🚨 LA META' DEI TEST CHE CONTA DI PIU' ════════════════════════════════
 *
 * ⛔ Questo file prova due cose **opposte**, e la seconda è quella pericolosa:
 *
 * | Cosa | Se si rompe |
 * |---|---|
 * | il blocco **blocca** | chi non paga continua a lavorare gratis |
 * | il blocco **non chiude fuori** | 🚨 **il cliente non può pagare**, e non incassiamo mai |
 *
 * 🔑 Per questo il middleware **non risponde mai 403**: rimanda al cruscotto.
 * ⚖️ L'errore peggiore che può fare è mandarti **sulla pagina in cui paghi**.
 */
class SoloLAvvisoSeNonHaiPagatoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $capo;

    protected function setUp(): void
    {
        parent::setUp();

        $this->palestra = $this->creaPalestra('Sospesa Srl', 'sospesa-srl');
        $this->capo = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'capo@sospesa-srl.test');
    }

    private function sospendi(): void
    {
        $this->palestra->forceFill(['status' => TenantStatus::Suspended])->save();
    }

    // ───────────── quello che DEVE restare raggiungibile ─────────────

    /**
     * 🚨 **Il cruscotto si apre, ed è lì che sta l'avviso.**
     *
     * ⛔ Se questo cadesse, il rimando del middleware girerebbe a vuoto e la
     * persona finirebbe in un ciclo di redirect: chiusa fuori, senza un 403 e
     * senza capire perché.
     */
    #[Test]
    public function il_cruscotto_si_apre_e_mostra_lavviso(): void
    {
        $this->sospendi();

        $this->actingAs($this->capo->fresh())
            ->get(Cruscotto::getUrl(panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('il servizio è sospeso', escape: false)
            ->assertSee('Riattiva il servizio', escape: false);
    }

    /**
     * 🔒 **E sul cruscotto NON c'è nient'altro: solo l'avviso.**
     *
     * ══ 🚨 PERCHE' QUESTO TEST DEVE ESISTERE ══════════════════════════════
     *
     * 📌 *«vede **solo** l'avviso»*. ⛔ Senza questo test, `Cruscotto::getWidgets()`
     * potrebbe tornare a mostrare tutto e nessuno se ne accorgerebbe: l'avviso
     * comparirebbe comunque, in cima, **in mezzo ai numeri della palestra**.
     *
     * ⚠️ Cioè una pagina che dice *«è tutto sospeso»* mentre mostra i posti
     * occupati e il giro della settimana — un messaggio che contraddice se stesso.
     *
     * ── ⚠️ Perché si guarda il PUNTO DI INNESTO e non il testo del riquadro ──
     *
     * 🚨 Il primo giro cercava «Iscritti attivi», il titolo di un dato di
     * `GymOverview`, **e cadeva sul controllo**: quel riquadro è **pigro**, quindi
     * il suo contenuto non è nella pagina — arriva con una seconda richiesta.
     * ⛔ Asserire su un testo che non c'è mai sarebbe un test verde per sempre,
     * in entrambe le direzioni.
     *
     * 💡 Nella pagina c'è però il suo **punto di innesto** Livewire, che porta il
     * nome della classe (`wire:key="...GymOverview-0"`). 🔑 È la prova che il
     * riquadro è **montato**, che è esattamente la domanda: *il cruscotto lo
     * mostra o no?*
     */
    #[Test]
    public function sul_cruscotto_sospeso_non_ci_sono_gli_altri_riquadri(): void
    {
        /* ✅ Prima il controllo: a servizio attivo quel riquadro è montato. */
        $this->actingAs($this->capo->fresh())
            ->get(Cruscotto::getUrl(panel: 'admin'))
            ->assertSee('GymOverview', escape: false);

        $this->sospendi();

        $this->actingAs($this->capo->fresh())
            ->get(Cruscotto::getUrl(panel: 'admin'))
            ->assertSuccessful()
            ->assertSee('AvvisoDaPagare', escape: false)
            ->assertDontSee('GymOverview', escape: false);
    }

    /**
     * 💳 **E la pagina da cui si paga si apre.**
     *
     * 🚨 È il test che protegge l'incasso: ⛔ bloccare anche questa renderebbe il
     * blocco **definitivo** — *«se uno non può entrare, non mi può pagare»*.
     */
    #[Test]
    public function la_pagina_del_piano_si_apre_ed_e_da_li_che_paga(): void
    {
        $this->sospendi();

        $this->actingAs($this->capo->fresh())
            ->get(IlTuoPiano::getUrl(panel: 'admin'))
            ->assertSuccessful();
    }

    // ───────────── quello che NON deve essere raggiungibile ─────────────

    /**
     * 🔒 **Ogni altra pagina rimanda al cruscotto.**
     *
     * ⚠️ Si prova su una risorsa vera del pannello, presa dalle rotte
     * registrate: ⛔ un test su un percorso inventato proverebbe solo che il 404
     * funziona.
     */
    #[Test]
    public function le_altre_pagine_rimandano_al_cruscotto(): void
    {
        $this->sospendi();

        $altrove = $this->unaPaginaQualsiasiDelPannello();

        $this->actingAs($this->capo->fresh())
            ->get($altrove)
            ->assertRedirect(Cruscotto::getUrl(panel: 'admin'));
    }

    /**
     * ⛔ **E non risponde 403, mai.**
     *
     * 🚨 Un 403 è un vicolo cieco: la persona non sa dove andare. ⚖️ Il rimando
     * la porta dove c'è il tasto per pagare. 💡 La differenza non è cosmetica: è
     * fra un cliente che paga e uno che telefona incazzato.
     */
    #[Test]
    public function non_risponde_mai_403(): void
    {
        $this->sospendi();

        $risposta = $this->actingAs($this->capo->fresh())
            ->get($this->unaPaginaQualsiasiDelPannello());

        self::assertNotSame(403, $risposta->getStatusCode());
    }

    // ───────────── e quando è tutto in regola, niente cambia ─────────────

    /**
     * ✅ **Il controllo.** Con il servizio attivo tutto si apre come sempre.
     *
     * ⚠️ Senza questo, i test qui sopra passerebbero anche con il pannello
     * **rotto per tutti**: direbbero «chi non paga è bloccato» mentre la verità
     * sarebbe «nessuno passa».
     */
    #[Test]
    public function con_il_servizio_attivo_non_cambia_niente(): void
    {
        $this->actingAs($this->capo->fresh())
            ->get($this->unaPaginaQualsiasiDelPannello())
            ->assertSuccessful();
    }

    /** ⚖️ E il cruscotto mostra i suoi riquadri, non solo l'avviso. */
    #[Test]
    public function con_il_servizio_attivo_lavviso_non_compare(): void
    {
        $this->actingAs($this->capo->fresh())
            ->get(Cruscotto::getUrl(panel: 'admin'))
            ->assertSuccessful()
            ->assertDontSee('il servizio è sospeso', escape: false);
    }

    /**
     * 🦸 **E il super admin non viene bloccato da questo middleware.**
     *
     * ⚠️ Lui entra nel pannello di una palestra per assistenza, e se il blocco lo
     * prendesse non potrebbe **guardare** proprio lo spazio che ha un problema.
     */
    #[Test]
    public function il_super_admin_non_viene_bloccato(): void
    {
        $this->sospendi();

        $god = $this->creaSuperAdmin('god@blocco.test');

        $risposta = $this->actingAs($god)->get($this->unaPaginaQualsiasiDelPannello());

        self::assertNotSame(
            302,
            $risposta->getStatusCode(),
            '⛔ Il super admin è stato rimandato al cruscotto di una palestra sospesa.',
        );
    }

    // ───────────────────────── aiuti ─────────────────────────

    /**
     * 🔎 **La pagina di lavoro del pannello**: l'elenco degli iscritti.
     *
     * ══ ⚠️ PERCHE' UNA PAGINA SCELTA A MANO E NON PESCATA DALLE ROTTE ══════
     *
     * ⛔ Il primo giro di questo test pescava **la prima rotta GET del
     * pannello**, per non dipendere da un nome. 🚨 Pescava «Cambia spazio», che
     * rimanda **per conto suo** quando di spazi ce n'è uno solo: i due test di
     * controllo cadevano con un 302 che non aveva niente a che fare con la
     * sospensione.
     *
     * 💡 Ed era una falsa accusa nella direzione peggiore: sembrava che il blocco
     * scattasse anche a servizio attivo.
     *
     * 🔑 `MemberResource` è la pagina che una palestra apre per lavorare, ed è
     * quella che deve smettere di aprirsi. ⚠️ Riferita per **classe**: se un
     * giorno cambiasse nome, questo test si romperebbe subito e a voce alta,
     * invece di provare silenziosamente qualcos'altro.
     */
    private function unaPaginaQualsiasiDelPannello(): string
    {
        return MemberResource::getUrl(panel: 'admin');
    }
}
