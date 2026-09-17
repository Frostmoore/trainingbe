<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\UserRole;
use App\Filament\Gym\Pages\CosaPaghi;
use App\Models\AddebitoMensile;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Services\Billing\AddebitoDelMese;
use App\Services\Billing\ContoDegliIscritti;
use App\Services\Tenancy\InvitiInPalestra;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 💶 **L'addebito del mese sul piano a consumo** — 3b-AH.6.9, 17/09/2026.
 *
 * ══ 🚨 COSA PROVA DAVVERO QUESTA CLASSE ═══════════════════════════════════
 *
 * Non che la tabella esista: quello lo direbbe la migration. Prova le cose che,
 * sbagliate, **non danno nessun errore** — e sono tutte cose che diventano
 * soldi:
 *
 * 1. che si conti chi c'e' **davvero**, non le righe rimaste in
 *    `model_has_roles` di chi e' stato spento o cancellato;
 * 2. che il minimo si applichi al **conteggio** e non al totale;
 * 3. che lo stesso mese non si possa addebitare **due volte**;
 * 4. che una palestra senza cliente Stripe lasci una riga **visibile** invece
 *    di sparire;
 * 5. che i piani a **canone** non entrino in questo giro per sbaglio.
 */
final class LAddebitoDelMeseTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    /**
     * Mette questa palestra sul piano a consumo, togliendole quello di serie.
     */
    private function aConsumo(Tenant $palestra): Plan
    {
        $piano = Plan::query()->where('code', 'gym_consumo')->firstOrFail();

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->delete();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->getKey(),
            'plan_id' => $piano->getKey(),
            'starts_at' => now()->subYears(2),
        ]);

        return $piano;
    }

    private function iscritti(Tenant $palestra, int $quanti, string $prefisso = 'iscritto'): void
    {
        for ($i = 1; $i <= $quanti; $i++) {
            $this->creaUtente($palestra, UserRole::Member, "{$prefisso}{$i}@esempio.test");
        }
    }

    /**
     * 🚨 **Il difetto che ha fatto nascere `ContoDegliIscritti`.**
     *
     * ⛔ Il conteggio degli iscritti non guardava **mai** la tabella `users`:
     * leggeva `model_has_roles` e basta. E li' dentro la riga di un iscritto
     * **resta** dopo che quella persona e' stata spenta o cancellata —
     * `ChiEsceDallaPalestraOsservatore` chiude i rapporti e i canali, il ruolo
     * no, di proposito.
     *
     * ⚠️ Fino al 17/09 la conseguenza era solo sui posti. 🚨 Da oggi quel numero
     * **e' la fattura**: una palestra che ha cancellato quindici persone le
     * avrebbe pagate tutte, per sempre.
     */
    #[Test]
    public function chi_e_stato_spento_o_cancellato_non_si_conta(): void
    {
        $palestra = $this->creaPalestra();
        $this->iscritti($palestra, 5);

        $conto = app(ContoDegliIscritti::class);

        $this->assertSame(5, $conto->quanti($palestra), 'i cinque iscritti non si contano');

        $spento = $this->creaUtente($palestra, UserRole::Member, 'spento@esempio.test');
        $spento->forceFill(['is_active' => false])->save();

        $cancellato = $this->creaUtente($palestra, UserRole::Member, 'cancellato@esempio.test');
        $cancellato->delete();

        $this->assertSame(
            5,
            $conto->quanti($palestra),
            'uno spento e un cancellato vengono contati: la palestra li paga',
        );

        /*
         * 🔬 **E qui si vede il difetto in faccia**, invece di fidarsi del
         * fatto che adesso il numero e' giusto.
         *
         * ⛔ Questa e' la query di prima — `model_has_roles` senza nessun
         * `join` su `users` — e dice **sette**. 🚨 Le due righe che avanzano
         * sono una persona spenta e una cancellata: nel pannello non ci sono
         * piu', e sulla fattura ci sarebbero state per sempre.
         */
        $senzaGuardareLePersone = DB::table('model_has_roles as mhr')
            ->join('roles as r', function ($join): void {
                $join->on('r.id', '=', 'mhr.role_id')
                    ->on('r.tenant_id', '=', 'mhr.tenant_id');
            })
            ->where('mhr.tenant_id', $palestra->getKey())
            ->where('r.name', UserRole::Member->value)
            ->distinct()
            ->count('mhr.model_id');

        $this->assertSame(
            7,
            $senzaGuardareLePersone,
            'il difetto non c era: allora questo test non prova niente',
        );
    }

    /**
     * ⛔ **E il tetto dei posti deve dire lo stesso numero.**
     *
     * 🚨 Due conteggi in due file divergono sempre, e qui divergere vuol dire
     * che il pannello mostra «5 iscritti» e la fattura ne addebita 7. 💡 Nessuno
     * dei due da' errore: sono due numeri plausibili.
     */
    #[Test]
    public function i_posti_e_la_fattura_contano_la_stessa_cosa(): void
    {
        $palestra = $this->creaPalestra();
        $this->iscritti($palestra, 3);

        $fuori = $this->creaUtente($palestra, UserRole::Member, 'fuori@esempio.test');
        $fuori->delete();

        $piano = Plan::query()->where('code', 'gym_20')->firstOrFail();

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->update(['plan_id' => $piano->getKey()]);

        $rimasti = app(InvitiInPalestra::class)->postiRimasti($palestra->fresh());

        $this->assertSame(
            20 - 3,
            $rimasti,
            'i posti contano anche chi e stato cancellato',
        );
    }

    /**
     * 🚨 **Il minimo si applica al CONTEGGIO, non al totale.**
     *
     * 📌 *«per un minimo di 100»*. Una palestra con 4 iscritti paga **100 ×
     * 5,99**. ⛔ Applicarlo al totale — «almeno tot euro» — sembra la stessa
     * cosa e smette di esserlo appena il prezzo a iscritto cambia.
     */
    #[Test]
    public function sotto_il_minimo_si_paga_il_minimo(): void
    {
        $palestra = $this->creaPalestra();
        $piano = $this->aConsumo($palestra);
        $this->iscritti($palestra, 4);

        $addebito = app(AddebitoDelMese::class)->calcola($palestra, '2026-08');

        $this->assertNotNull($addebito);
        $this->assertSame(4, $addebito->iscritti_contati);
        $this->assertSame(100, $addebito->iscritti_fatturati, 'il minimo non e stato applicato');
        $this->assertSame(100 * 599, $addebito->importo_cent);
        $this->assertTrue($addebito->haPagatoIlMinimo());
        $this->assertSame($piano->getKey(), $addebito->plan_id);
    }

    /**
     * ⚠️ **E sopra il minimo si pagano gli iscritti veri.**
     *
     * 💡 Provato con un numero **sopra** la soglia scritto a mano nel piano, e
     * non con centouno utenti: il test deve restare veloce, e la regola che si
     * verifica e' `max($contati, $minimo)`, non la capacita' di creare righe.
     */
    #[Test]
    public function sopra_il_minimo_si_pagano_gli_iscritti_veri(): void
    {
        $palestra = $this->creaPalestra();
        $piano = $this->aConsumo($palestra);

        $piano->forceFill(['minimo_iscritti' => 3])->save();

        $this->iscritti($palestra, 7);

        $addebito = app(AddebitoDelMese::class)->calcola($palestra, '2026-08');

        $this->assertNotNull($addebito);
        $this->assertSame(7, $addebito->iscritti_fatturati);
        $this->assertSame(7 * 599, $addebito->importo_cent);
        $this->assertFalse($addebito->haPagatoIlMinimo());
    }

    /**
     * ⛔ **I trainer non si fatturano.**
     *
     * 🚨 Contarli vorrebbe dire far pagare a una palestra i propri dipendenti
     * come se fossero clienti — la stessa cosa gia' corretta sui posti in
     * `AH.1-quater`, che qui pero' diventa una cifra su una fattura.
     */
    #[Test]
    public function lo_staff_non_entra_nel_conto(): void
    {
        $palestra = $this->creaPalestra();
        $piano = $this->aConsumo($palestra);
        $piano->forceFill(['minimo_iscritti' => 0])->save();

        $this->iscritti($palestra, 2);
        $this->creaUtente($palestra, UserRole::Trainer, 'coach@esempio.test');
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@esempio.test');

        $addebito = app(AddebitoDelMese::class)->calcola($palestra, '2026-08');

        $this->assertNotNull($addebito);
        $this->assertSame(2, $addebito->iscritti_contati, 'staff contato come iscritti');
    }

    /**
     * 🚨 **Lo stesso mese non si fattura due volte.**
     *
     * ⛔ Un comando schedulato ripartito a mano, un deploy durante il giro, una
     * coda che riprova: senza `unique(tenant_id, periodo)` il secondo giro
     * emette una seconda fattura. 💡 E' la stessa difesa di
     * `stripe_events.event_id`.
     *
     * ⚠️ E si verifica che il **numero non cambi** nel frattempo: il secondo
     * giro trova quattro iscritti in piu' e deve restituire la riga vecchia,
     * non ricalcolare.
     */
    #[Test]
    public function lo_stesso_mese_non_si_addebita_due_volte(): void
    {
        $palestra = $this->creaPalestra();
        $this->aConsumo($palestra);
        $this->iscritti($palestra, 2);

        $servizio = app(AddebitoDelMese::class);

        $primo = $servizio->calcola($palestra, '2026-08');

        $this->iscritti($palestra, 4, 'arrivato');

        $secondo = $servizio->calcola($palestra, '2026-08');

        $this->assertNotNull($primo);
        $this->assertNotNull($secondo);
        $this->assertSame($primo->getKey(), $secondo->getKey(), 'e nata una seconda riga');
        $this->assertSame(2, $secondo->iscritti_contati, 'il conteggio congelato e cambiato');

        $this->assertSame(
            1,
            AddebitoMensile::withoutGlobalScopes()
                ->where('tenant_id', $palestra->getKey())
                ->delPeriodo('2026-08')
                ->count(),
        );
    }

    /**
     * ⛔ **Un piano a canone non entra in questo giro.**
     *
     * 🚨 `Plan::costoDelMese()` torna `null` per i piani a canone, e un `null`
     * usato come importo diventa **zero**: una fattura da 0,00 € emessa a chi
     * paga 199 al mese, senza nessun errore.
     */
    #[Test]
    public function un_piano_a_canone_non_si_addebita_a_consumo(): void
    {
        $palestra = $this->creaPalestra();
        $this->iscritti($palestra, 3);

        $this->assertNull(
            app(AddebitoDelMese::class)->calcola($palestra, '2026-08'),
            'una palestra a canone e finita nel giro del consumo',
        );
    }

    /**
     * ⚠️ **Il giro mensile trova solo le palestre a consumo.**
     */
    #[Test]
    public function il_giro_pesca_solo_chi_e_a_consumo(): void
    {
        $aCanone = $this->creaPalestra('Canone', 'canone', 'CANO2345');
        $aConsumo = $this->creaPalestra('Consumo', 'consumo', 'CONS2345');

        $this->aConsumo($aConsumo);
        $this->iscritti($aConsumo, 3, 'c');
        $this->iscritti($aCanone, 3, 'k');

        $trovate = app(AddebitoDelMese::class)->palestreAConsumo();

        $this->assertTrue($trovate->contains('id', $aConsumo->getKey()));
        $this->assertFalse($trovate->contains('id', $aCanone->getKey()));
    }

    /**
     * 🚨 **Si fattura il piano che c'era IN QUEL MESE, non quello di oggi.**
     *
     * ⛔ Il giro gira il **primo del mese** e fattura il mese **appena chiuso**.
     * Chiedendo «chi e' a consumo adesso», una palestra che ha cambiato piano il
     * 31 non verrebbe fatturata per il mese che ha appena consumato. ⚠️ Nessun
     * errore da nessuna parte: e' solo un cliente che non paga.
     */
    #[Test]
    public function chi_lascia_il_consumo_paga_comunque_il_mese_consumato(): void
    {
        $palestra = $this->creaPalestra();
        $this->aConsumo($palestra);
        $this->iscritti($palestra, 3);

        /*
         * 💡 L'abbonamento a consumo e' finito il 31 agosto: ad agosto c'era
         * tutto il mese, a settembre non c'e' piu'.
         */
        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->update(['ends_at' => '2026-08-31 23:59:59']);

        $servizio = app(AddebitoDelMese::class);

        $this->assertTrue(
            $servizio->palestreAConsumo('2026-08')->contains('id', $palestra->getKey()),
            'ad agosto era a consumo e il giro non la trova: quel mese non lo paga nessuno',
        );

        $this->assertNotNull(
            $servizio->calcola($palestra, '2026-08'),
            'il mese consumato non si riesce ad addebitare',
        );

        $this->assertFalse(
            $servizio->palestreAConsumo('2026-09')->contains('id', $palestra->getKey()),
            'a settembre non c era piu e verrebbe fatturata lo stesso',
        );
    }

    /**
     * ⚠️ **E chi arriva dopo non paga il mese in cui non c'era.**
     */
    #[Test]
    public function chi_arriva_dopo_non_paga_il_mese_prima(): void
    {
        $palestra = $this->creaPalestra();
        $this->aConsumo($palestra);
        $this->iscritti($palestra, 3);

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->update(['starts_at' => '2026-09-01 00:00:00']);

        $servizio = app(AddebitoDelMese::class);

        $this->assertFalse(
            $servizio->palestreAConsumo('2026-08')->contains('id', $palestra->getKey()),
            'ad agosto non era ancora cliente e verrebbe fatturata',
        );

        $this->assertTrue($servizio->palestreAConsumo('2026-09')->contains('id', $palestra->getKey()));
    }

    /**
     * 🚨 **Senza cliente Stripe l'addebito resta scritto, in chiaro.**
     *
     * ⛔ Oggi nessuna palestra ha un `stripe_customer_id`: entrano a mano.
     * Se il mese sparisse in silenzio, si scoprirebbe a fine anno. 💡 Con la
     * riga in stato `senza_cliente` si vede subito, e il giorno che quella
     * palestra un cliente ce l'ha si incassa senza ricostruire niente.
     */
    #[Test]
    public function senza_cliente_stripe_la_riga_resta_e_lo_dice(): void
    {
        $palestra = $this->creaPalestra();
        $this->aConsumo($palestra);
        $this->iscritti($palestra, 3);

        $servizio = app(AddebitoDelMese::class);

        $addebito = $servizio->incassa($servizio->calcola($palestra, '2026-08'));

        $this->assertSame(AddebitoMensile::SENZA_CLIENTE, $addebito->stato);
        $this->assertNull($addebito->stripe_invoice_id);
        $this->assertNotNull($addebito->errore);
        $this->assertSame(100 * 599, $addebito->importo_cent, 'l importo non e stato calcolato lo stesso');
    }

    /**
     * ⚠️ **E il giro completo lo riporta, invece di dire che e andato tutto
     * bene.**
     */
    #[Test]
    public function il_giro_riporta_quelli_che_non_ha_potuto_incassare(): void
    {
        $palestra = $this->creaPalestra();
        $this->aConsumo($palestra);
        $this->iscritti($palestra, 3);

        $esito = app(AddebitoDelMese::class)->giroDelMese('2026-08');

        $this->assertSame(1, $esito['calcolati']);
        $this->assertSame(1, $esito['senza_cliente']);
        $this->assertSame(0, $esito['incassati']);
        $this->assertSame(0, $esito['falliti']);
    }

    /**
     * 🚨 **Una palestra non vede gli addebiti delle altre.**
     *
     * ⛔ L'importo dice quanti iscritti ha quel concorrente: e' l'unico numero
     * che nessuna palestra vuole far sapere in giro. 💡 Lo garantisce
     * `BelongsToTenant`, ed e' proprio per questo che il modello ce l'ha.
     */
    #[Test]
    public function gli_addebiti_non_si_vedono_fra_palestre(): void
    {
        $una = $this->creaPalestra('Una', 'una', 'UNAA2345');
        $altra = $this->creaPalestra('Altra', 'altra', 'ALTR2345');

        $this->aConsumo($una);
        $this->aConsumo($altra);
        $this->iscritti($una, 2, 'u');
        $this->iscritti($altra, 2, 'a');

        $servizio = app(AddebitoDelMese::class);
        $servizio->calcola($una, '2026-08');
        $servizio->calcola($altra, '2026-08');

        $visti = $this->ctx()->runAs($una, fn () => AddebitoMensile::query()->get());

        $this->assertCount(1, $visti);
        $this->assertSame($una->getKey(), $visti->first()->tenant_id);
    }

    /**
     * ⛔ **Il comando rifiuta un periodo storto invece di aggiustarlo.**
     *
     * 🚨 Un `2026-8` normalizzato in `2026-09` sembrerebbe gentile: ma il
     * vincolo di unicita' e' su una **stringa**, e il giorno che la variante non
     * normalizzata entra da un'altra strada lo stesso mese viene fatturato due
     * volte senza violare niente.
     */
    #[Test]
    public function il_comando_rifiuta_un_periodo_storto(): void
    {
        $this->artisan('fattura:il-consumo', ['--periodo' => '2026-8'])
            ->assertFailed();

        $this->artisan('fattura:il-consumo', ['--periodo' => '2026-13'])
            ->assertFailed();
    }

    /**
     * 🚨 **La pagina «Cosa paghi» la vede solo il proprietario.**
     *
     * ⛔ Un trainer della palestra che legge quanto costa il mese sta leggendo
     * **quanti iscritti ha il suo datore di lavoro**, e non e' una cosa che gli
     * abbia dato nessuno. ⚠️ E su un piano a canone la pagina non serve: il
     * prezzo sta gia' in fattura e non dipende da quanti sono.
     */
    #[Test]
    public function cosa_paghi_la_vede_solo_chi_paga(): void
    {
        $palestra = $this->creaPalestra();
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@esempio.test');
        $coach = $this->creaUtente($palestra, UserRole::Trainer, 'coach@esempio.test');

        $this->ctx()->runAs($palestra, function () use ($palestra, $capo, $coach): void {
            $this->actingAs($coach);
            $this->assertFalse(CosaPaghi::canAccess(), 'un trainer vede quanto paga la palestra');

            $this->actingAs($capo);
            $this->assertFalse(
                CosaPaghi::canAccess(),
                'la pagina compare su un piano a canone, dove non dice niente',
            );

            $this->aConsumo($palestra);

            $this->actingAs($capo);
            $this->assertTrue(CosaPaghi::canAccess(), 'il proprietario non vede cosa paga');
        });
    }

    /**
     * ⚠️ **E con `--senza-incasso` calcola senza chiamare Stripe.**
     *
     * 💡 E' il modo di guardare quanto verrebbe fatturato prima di fatturarlo
     * davvero — la cosa che si vuole fare la prima volta, su dati veri.
     */
    #[Test]
    public function senza_incasso_calcola_e_basta(): void
    {
        $palestra = $this->creaPalestra();
        $this->aConsumo($palestra);
        $this->iscritti($palestra, 3);

        $this->artisan('fattura:il-consumo', [
            '--periodo' => '2026-08',
            '--senza-incasso' => true,
        ])->assertSuccessful();

        $riga = AddebitoMensile::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->delPeriodo('2026-08')
            ->firstOrFail();

        $this->assertSame(AddebitoMensile::DA_INCASSARE, $riga->stato);
        $this->assertSame(100 * 599, $riga->importo_cent);
    }
}
