<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Billing\PianoAttivo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 📱 **Sull'app, chi ha lo spazio sospeso torna non abbonato** — 63.C, 27/09/2026.
 *
 * ══ 📌 LA DECISIONE ═══════════════════════════════════════════════════════
 *
 * 📌 Il committente, 27/09: *«chi non ha pagato … sull'app vede l'interfaccia dei
 * non abbonati»*.
 *
 * ══ 🚨 IL DISACCORDO CHE QUESTI TEST CHIUDONO ═════════════════════════════
 *
 * ⛔ Prima `PianoAttivo::per()` guardava **solo l'abbonamento**, non lo stato dello
 * spazio. 🚨 Quindi una persona con la palestra sospesa **risultava abbonata**:
 * l'app le mostrava le carte accese e i vantaggi, e poi ogni chiamata finanziata
 * dalla palestra le rispondeva **403 `tenant_inactive`**.
 *
 * ⚠️ L'app prometteva e il server rifiutava. 💡 È il difetto più fastidioso di
 * tutti, perché **sembra un guasto** e non una sospensione — e chi lo vede
 * telefona invece di pagare.
 *
 * 🔑 La correzione non aggiunge nessuna schermata: toglie il pagante sospeso, e
 * il piano ricade sul gratuito **da sé**. ⛔ Una seconda interfaccia «quasi
 * uguale» a quella dei non abbonati divergerebbe alla prima modifica.
 */
class UnoSpazioSospesoNonPagaPerNessunoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🎯 **A6 della matrice**: l'iscritto di una palestra sospesa non è abbonato.
     *
     * ⚠️ «Non abbonato» vuol dire **senza i vantaggi che la palestra pagava**, non
     * «fuori»: entra, tiene allenamenti, diario, progressi e backup. 💡 Quello lo
     * prova la fase F1 dal lato dell'accesso; qui si prova che l'app **lo dica**.
     */
    #[Test]
    public function liscritto_di_una_palestra_sospesa_non_risulta_abbonato(): void
    {
        $palestra = $this->creaPalestra('Palestra Ferma', 'palestra-ferma');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@palestra-ferma.test');

        /* ✅ Il controllo: finché la palestra è attiva, l'AI c'è. */
        self::assertTrue(
            app(PianoAttivo::class)->per($iscritto->fresh())->ai_enabled,
            'Il presupposto del test: con la palestra attiva l\'iscritto ha i vantaggi.',
        );

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        self::assertFalse(
            (bool) app(PianoAttivo::class)->per($iscritto->fresh())->ai_enabled,
            '🚨 L\'app gli mostra i vantaggi di una palestra sospesa, e poi il server '
            .'gli risponde 403. ⛔ Sembra un guasto, non una sospensione.',
        );
    }

    /**
     * 🚨🚨 **A13 DELLA MATRICE — il caso più importante, e nessun test lo copriva.**
     *
     * ══ ⛔ COSA SAREBBE ANDATO STORTO ══════════════════════════════════════
     *
     * Chi paga è il **trainer**, e la palestra sospesa **non deve portargli via
     * niente**. ⛔ Se il filtro sullo stato fosse stato scritto *per persona*
     * invece che *per pagante* — «hai uno spazio sospeso fra i tuoi? allora
     * niente» — questo allievo perderebbe i vantaggi **per un debito che non è né
     * suo né del suo trainer**.
     *
     * 🔑 È esattamente il difetto che il committente temeva: *«al massimo non mi
     * dà i privilegi dell'abbonamento»* riferito a **chi non paga**, non a chi sta
     * accanto a lui.
     */
    #[Test]
    public function con_la_palestra_sospesa_ma_un_trainer_che_paga_resta_abbonato(): void
    {
        $palestra = $this->creaPalestra('Palestra Morosa', 'palestra-morosa');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'allievo@palestra-morosa.test');

        /* 💪 Un trainer indipendente, col suo spazio, che paga. */
        $spazioDelTrainer = $this->creaPalestra('Trainer Che Paga', 'trainer-che-paga');
        $trainer = $this->creaUtente($spazioDelTrainer, UserRole::FreeTrainer, 'paga@trainer.test');

        $this->conPianoDaTrainer($trainer, Plan::TRAINER_30);
        $this->dagliLaiAlPiano(Plan::TRAINER_30);

        /* 🔗 Segue l'allievo nel proprio spazio. */
        $trainer->assignedMembers()->attach($iscritto->getKey(), [
            'tenant_id' => $spazioDelTrainer->getKey(),
            'assigned_at' => now(),
        ]);

        /* ⛔ E la palestra smette di pagare. */
        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        self::assertTrue(
            (bool) app(PianoAttivo::class)->per($iscritto->fresh())->ai_enabled,
            '🚨 La palestra sospesa ha portato via all\'allievo quello che paga il suo '
            .'trainer. ⛔ Punito per un debito che non è né suo né del suo trainer.',
        );
    }

    /**
     * ⚖️ **E se anche il trainer è sospeso, allora sì: niente.**
     *
     * 💡 È il rovescio del test qui sopra, e serve a dire che il filtro **funziona**
     * e non è stato neutralizzato: ⛔ senza, `con_la_palestra_sospesa_ma_un_trainer_
     * che_paga_resta_abbonato` passerebbe anche con il controllo tolto del tutto.
     */
    #[Test]
    public function se_sono_sospesi_tutti_non_resta_niente(): void
    {
        $palestra = $this->creaPalestra('Ferma Uno', 'ferma-uno');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'allievo@ferma-uno.test');

        $spazioDelTrainer = $this->creaPalestra('Ferma Due', 'ferma-due');
        $trainer = $this->creaUtente($spazioDelTrainer, UserRole::FreeTrainer, 'trainer@ferma-due.test');

        $this->conPianoDaTrainer($trainer, Plan::TRAINER_30);
        $this->dagliLaiAlPiano(Plan::TRAINER_30);

        $trainer->assignedMembers()->attach($iscritto->getKey(), [
            'tenant_id' => $spazioDelTrainer->getKey(),
            'assigned_at' => now(),
        ]);

        Tenant::withoutGlobalScopes()
            ->whereIn('id', [$palestra->getKey(), $spazioDelTrainer->getKey()])
            ->update(['status' => TenantStatus::Suspended->value]);

        self::assertFalse(
            (bool) app(PianoAttivo::class)->per($iscritto->fresh())->ai_enabled,
            '⛔ Tutti sospesi e i vantaggi restano accesi: il filtro non filtra.',
        );
    }

    /**
     * 🔑 **E il titolare di uno spazio sospeso non è abbonato nemmeno lui.**
     *
     * ⚠️ Non è una ripetizione dei casi sopra: lui è **dentro** lo spazio che non
     * paga, non accanto. 💡 Sull'app deve vedere quello che vede chi non ha mai
     * pagato — mentre nel pannello trova l'avviso e il tasto (§162).
     */
    #[Test]
    public function anche_il_titolare_non_e_abbonato_se_il_suo_spazio_e_sospeso(): void
    {
        $palestra = $this->creaPalestra('Mia Ferma', 'mia-ferma');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@mia-ferma.test');

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        self::assertFalse(
            (bool) app(PianoAttivo::class)->per($capo->fresh())->ai_enabled,
        );
    }

    /**
     * 🚨 **E l'APP lo sente dire dall'API, non da un metodo.**
     *
     * ══ ⛔ PERCHE' QUESTO TEST ESISTE OLTRE AI QUATTRO SOPRA ═══════════════
     *
     * 🔑 L'app decide cosa mostrare leggendo `abbonato` da `GET
     * /api/v1/billing/listino`. ⚠️ I test qui sopra interrogano `PianoAttivo` a
     * mano — ⛔ ed è **esattamente** il tipo di prova che ha fatto sembrare
     * funzionante la `v36.29.0`: il metodo risponde giusto e la risposta vera
     * dice un'altra cosa.
     *
     * 💡 Qui si chiede al server quello che chiede l'app, e si guarda il campo
     * che l'app guarda. 🎯 `abbonato: false` **è** l'interfaccia dei non abbonati:
     * non c'è nessuna schermata nuova da scrivere, ed è per questo che la
     * decisione del committente si realizza con una riga in `PianoAttivo`.
     */
    #[Test]
    public function lapi_dice_allapp_che_non_e_abbonato(): void
    {
        $palestra = $this->creaPalestra('Api Ferma', 'api-ferma');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@api-ferma.test');

        /* ✅ Il controllo: con la palestra attiva l'app lo vede abbonato. */
        $this->actingAs($iscritto->fresh(), 'sanctum')
            ->getJson('/api/v1/billing/listino')
            ->assertOk()
            ->assertJsonPath('data.abbonato', true);

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->actingAs($iscritto->fresh(), 'sanctum')
            ->getJson('/api/v1/billing/listino')
            ->assertOk()
            ->assertJsonPath('data.abbonato', false);
    }

    /**
     * ⚠️ **Accende l'AI su un piano da trainer.**
     *
     * ⛔ `conPianoDaTrainer()` crea il piano con `ai_enabled = false` se non
     * esiste: senza questa riga i test in positivo qui sopra fallirebbero per il
     * motivo sbagliato — non perché il filtro toglie il pagante, ma perché quel
     * piano non ha l'AI da dare.
     */
    private function dagliLaiAlPiano(string $codice): void
    {
        Plan::query()->where('code', $codice)->update(['ai_enabled' => true]);
    }
}
