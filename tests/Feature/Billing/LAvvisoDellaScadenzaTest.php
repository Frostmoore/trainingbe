<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\PlanKind;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\DeviceToken;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Services\Billing\LAvvisoDellaScadenza;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * ⏳ **«Il tuo abbonamento sta per scadere»** — voce 62, 27/09/2026.
 *
 * ══ 📌 COSA PROVA QUESTO FILE ═════════════════════════════════════════════
 *
 * 🔑 Le **quattro esclusioni** di `LAvvisoDellaScadenza::daAvvisare()`, una per
 * test, e la memoria che impedisce la ripetizione. ⛔ Sono esclusioni scritte in
 * una query: 🚨 un `where` sbagliato non dà nessun errore, dà solo notifiche
 * mandate a chi non doveva riceverle — o non mandate a chi le aspettava.
 *
 * ══ ⚠️ PERCHE' OGNI CASO E' UN TEST E NON UN CICLO ════════════════════════
 *
 * 💡 Un ciclo su un elenco di situazioni fa **una** diagnosi per **cinque**
 * cause: quando si rompe, dice «qualcosa fra queste cinque». ⛔ Ed è già
 * succeduto in questo progetto (il test del marchio di Google, 27/09): quattordici
 * asserzioni che dimostravano una cosa sola.
 */
class LAvvisoDellaScadenzaTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * ✅ **Il caso buono**: scade fra tre giorni, non si rinnova, nessuno gliel'ha
     * detto. Deve essere avvisato, e la colonna deve ricordarselo.
     */
    #[Test]
    public function chi_scade_fra_pochi_giorni_viene_avvisato(): void
    {
        [$spazio, $abbonamento] = $this->unoCheScade(fraGiorni: 3);

        $fatti = $this->macchina()->giro(scrivi: true);

        self::assertSame([(int) $abbonamento->getKey()], $fatti);

        self::assertNotNull(
            $abbonamento->fresh()->scadenza_avvisata_il,
            '🚨 Avvisato ma non segnato: la notte dopo lo riavviserebbe.',
        );
    }

    /**
     * 🚨 **E non lo riavvisa la notte dopo.**
     *
     * ⛔ È il difetto che la colonna esiste per impedire: la finestra è di sette
     * giorni, quindi senza memoria lo stesso abbonamento rientrerebbe nella
     * selezione **per sette notti di fila**. 💡 Sette notifiche identiche sono il
     * modo più rapido di far spegnere le notifiche a qualcuno.
     */
    #[Test]
    public function e_la_notte_dopo_non_lo_riavvisa(): void
    {
        $this->unoCheScade(fraGiorni: 3);

        $primo = $this->macchina()->giro(scrivi: true);
        $secondo = $this->macchina()->giro(scrivi: true);

        self::assertCount(1, $primo);
        self::assertSame([], $secondo, '🚨 Avvisato due volte.');
    }

    /**
     * 🔁 **Chi si rinnova da sé non sta scadendo.**
     *
     * ⛔ Dirgli «sta per scadere» lo manda a cercare un pulsante che non deve
     * premere. 💡 Se poi la carta fallisse, quella è `IncassoFallito`: un'altra
     * notifica, con un altro testo e un'altra pagina.
     */
    #[Test]
    public function chi_si_rinnova_da_se_non_viene_avvisato(): void
    {
        $this->unoCheScade(fraGiorni: 3, rinnova: true);

        self::assertSame([], $this->macchina()->giro(scrivi: true));
    }

    /**
     * ⏸️ **Un abbonamento in pausa riprenderà.**
     *
     * 🚨 `ends_at` **non viene toccato** dalla pausa (vedi
     * `LaPausaDellAbbonamento`), quindi una scadenza in pausa *sembra* vicina e
     * non lo è: il conto riparte quando la pausa finisce. ⛔ Avvisare qui vuol
     * dire annunciare una scadenza che non arriverà in quella data.
     */
    #[Test]
    public function chi_e_in_pausa_non_viene_avvisato(): void
    {
        [, $abbonamento] = $this->unoCheScade(fraGiorni: 3);

        $abbonamento->forceFill(['in_pausa_dal' => Carbon::now()->subDay()])->save();

        self::assertSame([], $this->macchina()->giro(scrivi: true));
    }

    /**
     * ⛔ **Chi è già sospeso ha un altro problema.**
     *
     * 💡 Per lui la notizia non è che sta per scadere: è che il servizio è **già**
     * spento, e gliel'ha detto `IncassoFallito`. 🚨 Un «sta per scadere» in coda a
     * quello parla al passato, e fa sembrare il sistema scollegato da sé stesso.
     *
     * ⚠️ Nota: l'esclusione è in `avvisa()`, non nella query, quindi `giro()` lo
     * **elenca** — e il test guarda la colonna, che è la prova che la notifica non
     * è partita.
     */
    #[Test]
    public function chi_e_gia_sospeso_non_viene_avvisato(): void
    {
        [$spazio, $abbonamento] = $this->unoCheScade(fraGiorni: 3);

        $spazio->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->macchina()->giro(scrivi: true);

        self::assertNull(
            $abbonamento->fresh()->scadenza_avvisata_il,
            '🚨 Segnato come avvisato: la notifica è partita a uno spazio già sospeso.',
        );
    }

    /** ⏰ Chi scade **oltre** la finestra non si avvisa: è presto. */
    #[Test]
    public function chi_scade_fra_un_mese_non_viene_avvisato_adesso(): void
    {
        $this->unoCheScade(fraGiorni: LAvvisoDellaScadenza::GIORNI_PRIMA + 5);

        self::assertSame([], $this->macchina()->giro(scrivi: true));
    }

    /** ⛔ E chi è **già** scaduto neanche: per lui c'è la sospensione. */
    #[Test]
    public function chi_e_gia_scaduto_non_viene_avvisato(): void
    {
        $this->unoCheScade(fraGiorni: -2);

        self::assertSame([], $this->macchina()->giro(scrivi: true));
    }

    /**
     * 🚨 **Senza `--scrivi` non parte niente**, e la colonna resta vuota.
     *
     * ⚖️ È la convenzione dei comandi che toccano i clienti. ⛔ Qui pesa perché
     * una prova fatta per sbaglio **consumerebbe** l'avviso: la colonna resterebbe
     * segnata, e quello vero non partirebbe mai.
     */
    #[Test]
    public function senza_scrivi_guarda_e_non_tocca(): void
    {
        [, $abbonamento] = $this->unoCheScade(fraGiorni: 3);

        $fatti = $this->macchina()->giro(scrivi: false);

        self::assertCount(1, $fatti, 'ℹ️ Lo elenca: serve a rispondere a «chi verrebbe avvisato?».');

        self::assertNull(
            $abbonamento->fresh()->scadenza_avvisata_il,
            '🚨 Ha scritto senza --scrivi: l\'avviso vero non partirà più.',
        );
    }

    /**
     * 🔄 **Se la scadenza si allontana, l'avviso si dimentica** — `booted()` del
     * modello.
     *
     * ══ 📌 IL CASO VERO CHE QUESTO CHIUDE ═════════════════════════════════
     *
     * 🚨 Gli abbonamenti **regalati** hanno `rinnova = false` (vedi
     * `ConsegnaDeiPremi`) e vengono allungati ogni mese da
     * `RinnovaGliAbbonamentiRegalati`. ⛔ Senza azzeramento, il primo mese
     * l'avviso parte e **tutti i mesi dopo no**: un difetto visibile una volta al
     * mese, che nessuno collegherebbe a una colonna.
     */
    #[Test]
    public function se_la_scadenza_si_allontana_lavviso_si_dimentica(): void
    {
        [, $abbonamento] = $this->unoCheScade(fraGiorni: 3);

        $this->macchina()->giro(scrivi: true);
        self::assertNotNull($abbonamento->fresh()->scadenza_avvisata_il);

        /* 🎁 Il rinnovo del regalo: un mese in più. */
        $abbonamento->update(['ends_at' => $abbonamento->ends_at->addMonth()]);

        self::assertNull(
            $abbonamento->fresh()->scadenza_avvisata_il,
            '🚨 La memoria è rimasta: la prossima scadenza passerebbe in silenzio.',
        );
    }

    /**
     * ⚠️ **E se si avvicina, invece, la memoria si tiene.**
     *
     * 💡 Una disdetta o una revoca anticipano `ends_at`: è la **stessa** scadenza
     * di cui si è già parlato, o una più vicina. ⛔ Riavvisare vorrebbe dire due
     * notifiche per un fatto solo.
     */
    #[Test]
    public function ma_se_si_avvicina_la_memoria_si_tiene(): void
    {
        [, $abbonamento] = $this->unoCheScade(fraGiorni: 5);

        $this->macchina()->giro(scrivi: true);

        $abbonamento->update(['ends_at' => Carbon::now()->addDays(2)]);

        self::assertNotNull(
            $abbonamento->fresh()->scadenza_avvisata_il,
            '🚨 Memoria azzerata per una scadenza anticipata: riavviserebbe.',
        );
    }

    private function macchina(): LAvvisoDellaScadenza
    {
        return app(LAvvisoDellaScadenza::class);
    }

    /**
     * 🏗️ Una palestra con un proprietario, un telefono registrato e un
     * abbonamento che scade quando si vuole.
     *
     * @return array{0: Tenant, 1: PlanSubscription}
     */
    private function unoCheScade(int $fraGiorni, bool $rinnova = false): array
    {
        $spazio = $this->creaPalestra('Palestra Scadenza', 'scadenza');
        $capo = $this->creaUtente($spazio, UserRole::GymAdmin, 'capo@scadenza.test');

        $spazio->forceFill(['proprietario_id' => $capo->getKey()])->save();

        DeviceToken::query()->create([
            'user_id' => $capo->getKey(),
            'tenant_id' => $spazio->getKey(),
            'token' => 'tok-scadenza',
            'platform' => 'android',
        ]);

        $piano = Plan::query()->where('code', 'prova-scadenza')->first()
            ?? Plan::create([
                'code' => 'prova-scadenza',
                'name' => 'Prova scadenza',
                'kind' => PlanKind::Gym,
                'ai_enabled' => false,
                'max_members' => 50,
                'price_cents' => 1000,
                'is_public' => true,
            ]);

        $abbonamento = PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $spazio->getKey(),
            'plan_id' => $piano->getKey(),
            'starts_at' => Carbon::now()->subMonth(),
            'ends_at' => Carbon::now()->addDays($fraGiorni),
            'rinnova' => $rinnova,
        ]);

        return [$spazio, $abbonamento];
    }
}
