<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use Database\Seeders\PacchettiGettoniSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\ChiamaComeApp;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 3b-H — il listino e l'apertura del pagamento.
 *
 * ── 🚨 COSA DIFENDONO QUESTI TEST ─────────────────────────────────────────
 *
 * ⛔ Il difetto peggiore possibile qui non e' un errore: e' **un prezzo che il
 * client decide**. Se il taglio arrivasse col suo importo, chiunque potrebbe
 * comprare 2.000 gettoni per un centesimo cambiando una riga della richiesta.
 *
 * ⚠️ E il secondo e' vendere due volte la stessa cosa: un abbonato che si
 * riabbona paga due volte e non se ne accorge finche' non guarda l'estratto.
 */
class BillingApiTest extends TestCase
{
    use ChiamaComeApp;
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $iscritto;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * 🆕 🎟️ **I tagli di gettoni si seminano** — 22/09/2026, fase F2:
         * dal 22/09 vivono nel database, e senza il seme il listino che l'app
         * riceve è vuoto.
         */
        $this->seed(PacchettiGettoniSeeder::class);

        $this->palestra = $this->creaPalestra('Alfa', 'alfa');
        $this->iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'io@alfa.test');
    }

    #[Test]
    public function il_listino_dice_cosa_si_compra(): void
    {
        $risposta = $this->comeApp($this->iscritto)->getJson('/api/v1/billing/listino');

        $risposta->assertOk()
            ->assertJsonStructure(['data' => [
                'abbonato',
                'livello',
                'abbonamento' => ['prezzo_cent', 'chiamate_mensili'],
                'pacchetti' => [['gettoni', 'prezzo_cent', 'nota']],
                'gettoni_disponibili',
            ]]);
    }

    /**
     * 📌 *«non va bene 300 chiamate per gli abbonati, facciamo 150»*.
     *
     * 🚨 E il numero che l'app mostra deve essere **lo stesso** che il piano
     * concede: erano 300 raccontate e 450 vere, e nessuno se n'era accorto.
     */
    #[Test]
    public function le_chiamate_incluse_sono_centocinquanta(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/billing/listino')
            ->assertJsonPath('data.abbonamento.chiamate_mensili', 150);

        $this->assertSame(
            150,
            Plan::where('code', Plan::PLUS)->value('ai_monthly_calls_per_member'),
            'il piano `plus` deve concedere quello che il listino promette',
        );
    }

    /**
     * ⛔ **Il taglio lo sceglie il client, il prezzo no.** Un taglio che non e'
     * a listino si rifiuta **prima** di parlare con Stripe: aprire una sessione
     * e poi scoprirlo vorrebbe dire sessioni fantasma nel loro pannello.
     */
    #[Test]
    public function un_taglio_che_non_esiste_si_rifiuta(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/billing/checkout', [
                'tipo' => 'gettoni',
                'gettoni' => 999999,
            ])
            ->assertStatus(422);
    }

    /** ⚠️ Chiedere gettoni senza dire quanti e' una richiesta incompleta. */
    #[Test]
    public function i_gettoni_senza_taglio_non_passano(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/billing/checkout', ['tipo' => 'gettoni'])
            ->assertStatus(422);
    }

    #[Test]
    public function un_tipo_inventato_non_passa(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/billing/checkout', ['tipo' => 'una_villa'])
            ->assertStatus(422);
    }

    /**
     * Qualcuno che l'abbonamento lo paga DAVVERO: un utente con il suo spazio.
     *
     * 🚨 **Serve dal 10/09**, da quando esiste `Cassa::loPuoGestire()`: un
     * iscritto di palestra non gestisce niente, e ogni prova che riguarda
     * l'acquisto va fatta con chi paga.
     *
     * ⛔ **Prima questo test usava `$this->iscritto` e non passava piu'.** Era
     * rosso da due giorni senza che nessuno se ne accorgesse, perche'
     * `tests/Feature/Api` non veniva lanciata: verificava il comportamento che
     * il 10/09 e' stato chiuso come falla.
     */
    private function chiPaga(string $email): User
    {
        return app(CreaTenantPersonale::class)('Paga', $email, [
            'password' => TestCase::FAKE_PASSWORD,
        ]);
    }

    /** 🚨 Non si vende due volte la stessa cosa. */
    #[Test]
    public function chi_e_gia_abbonato_non_si_riabbona(): void
    {
        $piano = Plan::where('code', Plan::PLUS)->firstOrFail();
        $paga = $this->chiPaga('paga@esempio.test');

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $paga->tenant->getKey(),
            'plan_id' => $piano->getKey(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addMonth(),
        ]);

        $this->comeApp($paga)
            ->postJson('/api/v1/billing/checkout', ['tipo' => 'abbonamento'])
            ->assertStatus(409);
    }

    /**
     * ⛔ E un iscritto di palestra non arriva nemmeno a quella domanda: gli si
     * risponde **403**, perche' l'abbonamento della palestra lo gestisce la
     * palestra.
     *
     * 💡 Sta qui accanto apposta: le due risposte si somigliano — «non puoi
     * comprare» — e vengono da due ragioni diverse. Chi cambia l'ordine dei due
     * controlli nel `BillingController` rompe una delle due, e senza questa
     * coppia non se ne accorgerebbe.
     */
    #[Test]
    public function ma_un_iscritto_non_puo_comprare_per_la_sua_palestra(): void
    {
        $this->comeApp($this->iscritto)
            ->postJson('/api/v1/billing/checkout', ['tipo' => 'abbonamento'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'non_lo_gestisci_tu');
    }

    /**
     * ⛔ **Le due rotte stanno FUORI dal cancello dell'AI**, ed e' il punto:
     * servono proprio a chi il piano non ce l'ha. 🚨 Se finissero dietro
     * `ai.plan`, per comprare l'abbonamento bisognerebbe gia' averlo.
     */
    #[Test]
    public function chi_non_ha_lai_puo_comunque_vedere_il_listino(): void
    {
        /*
         * ⚠️ **La palestra di prova nasce abbonata**, quindi un suo iscritto
         * risulta gia' coperto: per provare il caso «senza AI» l'abbonamento si
         * toglie, ed e' la strada esplicita suggerita da `CreaAmbiente`.
         *
         * 💡 E' anche un dato di prodotto che vale la pena vedere scritto:
         * l'iscritto di una palestra che paga **non deve** vedersi offrire un
         * abbonamento personale, perche' ce l'ha gia' tramite la palestra.
         */
        PlanSubscription::withoutGlobalScopes()->delete();

        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/billing/listino')
            ->assertOk()
            ->assertJsonPath('data.abbonato', false);
    }

    /** 🚨 E l'iscritto di una palestra che paga risulta gia' coperto. */
    #[Test]
    public function liscritto_di_una_palestra_abbonata_risulta_coperto(): void
    {
        $this->comeApp($this->iscritto)
            ->getJson('/api/v1/billing/listino')
            ->assertJsonPath('data.abbonato', true);
    }

    #[Test]
    public function e_senza_accesso_non_si_compra_niente(): void
    {
        $this->postJson('/api/v1/billing/checkout', ['tipo' => 'abbonamento'])
            ->assertStatus(401);
    }
}
