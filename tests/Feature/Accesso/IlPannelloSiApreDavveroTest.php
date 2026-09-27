<?php

declare(strict_types=1);

namespace Tests\Feature\Accesso;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Http\Middleware\SpazioDelPannello;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Privacy\LeLiberatorie;
use App\Services\Tenancy\AllenareInUnaPalestra;
use App\Services\Tenancy\CreaTenantPersonale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🚨 **Il pannello si apre DAVVERO?** — 63.A, 27/09/2026.
 *
 * ══ ⛔ PERCHE' QUESTO FILE ESISTE, E PERCHE' NON BASTAVA QUELLO CHE C'ERA ══
 *
 * `ChiEntraNeiPannelliTest` prova `canAccessPanel()` e `AvvisoDaPagare::canView()`
 * **chiamandoli a mano**. ⛔ Sono due metodi, non due porte: in mezzo c'e' la
 * catena dei middleware del pannello, e quella nessuno la percorreva.
 *
 * 🚨 **Risultato**: `be v36.29.0` ha aperto `canAccessPanel()` a chi non paga, i
 * test sono diventati verdi, e il pannello ha continuato a rispondere **403 JSON**
 * perche' `EnsureTenantActive` sta in `authMiddleware` dell'`AdminPanelProvider` e
 * gira **dopo** `Authenticate`. ⛔ Cioe' la cosa chiesta dal committente — *«nel
 * pannello devono avere qualcosa che gli comunichi che devono pagare, con un modo
 * per permettere loro di pagare»* — non funzionava, e niente lo diceva.
 *
 * 💡 Questi test fanno **richieste HTTP vere**. E' l'unico modo di provare una
 * porta: chiedere di entrare, non chiedere al portiere se ti farebbe entrare.
 *
 * ── ⚠️ La regola che questo file protegge ────────────────────────────────────
 *
 * 📌 *«chiunque deve poter accedere e pagarmi … Se uno non puo' entrare, non mi
 * puo' pagare»*. 🔑 **Si entra sempre**; quello che si vede dipende da chi sei e
 * da dove sei.
 */
class IlPannelloSiApreDavveroTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * ✍️ **La liberatoria dell'art. 28, firmata come nella vita vera.**
     *
     * ⚠️ `creaUtente()` la firma da sé; `CreaTenantPersonale` no, perché chi
     * arriva da lì si è appena iscritto e la firma la dà al primo ingresso. ⛔ Nei
     * test del **doppio cappello** serve a mano, altrimenti il pannello risponde
     * **302 verso `/admin/liberatoria`** — che è il comportamento **giusto**, ma
     * non è quello che questi test stanno provando.
     *
     * 💡 Vale la pena scriverlo: quel 302 mi ha fatto credere per un momento a un
     * difetto dell'accesso, e non lo era. Un test che non distingue «porta chiusa»
     * da «carta da firmare» mente in entrambe le direzioni.
     */
    private function firmaLeLiberatorie(User $persona): void
    {
        $spazio = Tenant::withoutGlobalScopes()->findOrFail($persona->tenant_id);

        $this->ctx()->runAs($spazio, function () use ($persona): void {
            foreach ([LeLiberatorie::PALESTRA, LeLiberatorie::TRAINER] as $tipo) {
                if (app(LeLiberatorie::class)->riguarda($persona, $tipo)) {
                    app(LeLiberatorie::class)->firma($persona, $tipo);
                }
            }
        });
    }

    /**
     * 🎯 **Il caso di riferimento**: chi ha pagato entra. ⚠️ Serve come
     * controllo — se anche questo fosse rosso, i test qui sotto non direbbero
     * niente sullo stato dell'abbonamento, ma solo che il pannello e' rotto.
     */
    #[Test]
    public function il_titolare_di_una_palestra_che_paga_apre_il_pannello(): void
    {
        $palestra = $this->creaPalestra('In Regola', 'in-regola');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@in-regola.test');

        $this->actingAs($capo)
            ->get('/admin')
            ->assertSuccessful();
    }

    /**
     * 🚨 **IL TEST CHE CONTA**: la palestra sospesa apre il pannello.
     *
     * ⛔ Se questo e' rosso, il committente non incassa: il cliente che voleva
     * riattivare non ha trovato la porta.
     */
    #[Test]
    public function il_titolare_di_una_palestra_sospesa_apre_il_pannello(): void
    {
        $palestra = $this->creaPalestra('Morosa', 'morosa');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@morosa.test');

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        $risposta = $this->actingAs($capo->fresh())->get('/admin');

        self::assertNotSame(
            403,
            $risposta->getStatusCode(),
            '🚨 Il pannello ha risposto 403 a chi non ha pagato. E\' esattamente la porta chiusa '
            .'che il committente ha vietato: «se uno non puo\' entrare, non mi puo\' pagare».',
        );

        $risposta->assertSuccessful();
    }

    /**
     * 💳 **E dentro trova l'avviso, con il tasto per pagare.**
     *
     * ⚠️ Si cerca una **frase della vista**, non il nome della classe: il
     * committente legge parole, non nomi di widget. ⛔ Un test che cercasse
     * `AvvisoDaPagare` passerebbe anche se la vista fosse vuota.
     */
    #[Test]
    public function e_dentro_il_pannello_trova_come_pagare(): void
    {
        $palestra = $this->creaPalestra('Morosa Due', 'morosa-due');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@morosa-due.test');

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->actingAs($capo->fresh())
            ->get('/admin')
            ->assertSuccessful()
            ->assertSee('Non siamo riusciti a incassare', escape: false)
            ->assertSee('Modifica il metodo di pagamento', escape: false);
    }

    /**
     * 🔑 **E il trainer della palestra sospesa entra anche lui.**
     *
     * ⚠️ Non e' lo stesso caso del titolare: il trainer **non puo' pagare** e
     * non deve credere di doverlo fare. Ma chiudergli la porta vuol dire che il
     * titolare, quando riattiva, si ritrova i suoi trainer fuori — e chiama.
     */
    #[Test]
    public function anche_il_trainer_di_una_palestra_sospesa_apre_il_pannello(): void
    {
        $palestra = $this->creaPalestra('Morosa Tre', 'morosa-tre');
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@morosa-tre.test');
        $trainer = $this->creaUtente($palestra, UserRole::Trainer, 'trainer@morosa-tre.test');

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        $this->actingAs($trainer->fresh())
            ->get('/admin')
            ->assertSuccessful();
    }

    /**
     * 🚨 **IL DOPPIO CAPPELLO: il trainer indipendente che lavora anche in una
     * palestra sospesa deve poter ENTRARE NELLO SPAZIO DELLA PALESTRA.**
     *
     * ══ ⛔ PERCHE' QUESTO E' IL CASO CHE SI DIMENTICA ═══════════════════════
     *
     * Il suo spazio lo paga lui, e funziona. ⛔ Ma quando sceglie «Cambia
     * spazio» e prende la palestra, `SpazioDelPannello` **si dimentica la
     * scelta** se la palestra non è attiva, e lo riporta a casa sua senza dire
     * niente.
     *
     * 🚨 L'effetto non è un errore: è **peggio**. Il titolare gli dice *«guarda
     * l'avviso nel pannello della palestra»*, lui clicca, si ritrova nel proprio
     * spazio dove tutto funziona, e conclude che l'avviso non esiste.
     *
     * 💡 La ragione per cui quel rifiuto era stato scritto era vera: prima
     * `EnsureTenantActive` girava dopo, e con la palestra in mano restava
     * **chiuso fuori da tutto**. ⚠️ Tolto quello, il rifiuto ha perso il suo
     * motivo — e ne è rimasto solo il danno.
     */
    #[Test]
    public function chi_ha_due_spazi_entra_anche_in_quello_della_palestra_sospesa(): void
    {
        $palestra = $this->creaPalestra('Palestra Ferma', 'palestra-ferma');
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@palestra-ferma.test');

        /* 🏠 Un trainer indipendente col suo spazio, che PAGA. */
        $suo = app(CreaTenantPersonale::class)(
            'Trainer Suo',
            'doppio@cappello.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        /* 💼 E che lavora anche nella palestra. */
        app(AllenareInUnaPalestra::class)->assumi($suo, $palestra);

        $this->firmaLeLiberatorie($suo);

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        $risposta = $this->actingAs($suo->fresh())
            ->withSession([SpazioDelPannello::CHIAVE => $palestra->getKey()])
            ->get('/admin');

        $risposta->assertSuccessful();

        $risposta->assertSee('Non siamo riusciti a incassare', escape: false);

        /*
         * 🔑 **E deve NOMINARE la palestra.** ⚠️ Chi porta due cappelli ha due
         * spazi: un avviso che non dice **di quale** sta parlando lo manda a
         * controllare il metodo di pagamento sbagliato.
         *
         * ⛔ Riscrivendo il testo il 27/09 questa asserzione si era indebolita da
         * sé: cercava la frase intera «Palestra Ferma: il servizio è sospeso», e
         * sostituendo la frase era rimasto solo il pezzo senza il nome.
         * 💡 Cambiare un testo può **togliere una garanzia** senza far diventare
         * rosso niente.
         */
        $risposta->assertSee('Palestra Ferma', escape: false);
    }

    /**
     * ⚖️ **E nel PROPRIO spazio, che è pagato, l'avviso NON deve comparire.**
     *
     * 🚨 È la metà che smaschera il difetto vero: `AvvisoDaPagare` legge il
     * **contesto**, non la casa di chi guarda. ⛔ Se leggesse la casa, questa
     * persona si vedrebbe dire che deve pagare mentre ha pagato — oppure, nel
     * caso simmetrico, non lo vedrebbe dove serve.
     *
     * 💡 Insieme al test qui sopra, i due provano che l'avviso parla **dello
     * spazio che si ha davanti**.
     */
    #[Test]
    public function e_nel_proprio_spazio_pagato_l_avviso_non_compare(): void
    {
        $palestra = $this->creaPalestra('Palestra Ferma Due', 'palestra-ferma-due');
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@palestra-ferma-due.test');

        $suo = app(CreaTenantPersonale::class)(
            'Trainer Suo Due',
            'doppio2@cappello.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        app(AllenareInUnaPalestra::class)->assumi($suo, $palestra);

        $this->firmaLeLiberatorie($suo);

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        /* ⚠️ Nessuna scelta in sessione: è a casa sua. */
        $this->actingAs($suo->fresh())
            ->get('/admin')
            ->assertSuccessful()
            ->assertDontSee('Non siamo riusciti a incassare', escape: false);
    }

    /**
     * 🚨 **CHI NON HA NESSUN'ALTRA STRADA: il trainer che a casa non ha niente e
     * lavora SOLO in una palestra sospesa.**
     *
     * ══ ⛔ PERCHE' ERA IL PEGGIORE DEI DUE ════════════════════════════════
     *
     * Questa persona non ha un secondo spazio in cui rifugiarsi: il suo è uno
     * spazio personale **vuoto**, senza ruoli da pannello. ⛔ Con la palestra
     * sospesa, `palestraDiChiNonHaNienteACasa()` rispondeva `null`, il contesto
     * restava quello di casa, e lei apriva un pannello **senza niente dentro** —
     * e senza l'avviso che spiegava perché.
     *
     * 🚨 Dal di fuori è indistinguibile da *«mi ha cancellato il lavoro»*. È
     * proprio la telefonata che il committente ha detto di non voler mai
     * ricevere.
     *
     * 💡 Il test guarda il **titolo della pagina**, che porta il nome dello
     * spazio: è il modo più diretto di chiedere *«dove sono atterrato?»*.
     */
    #[Test]
    public function il_trainer_che_ha_solo_una_palestra_sospesa_ci_atterra_comunque(): void
    {
        $palestra = $this->creaPalestra('Unica Palestra', 'unica-palestra');
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@unica-palestra.test');

        /* 🏠 Casa sua è uno spazio personale senza ruoli da pannello. */
        $trainer = app(CreaTenantPersonale::class)(
            'Solo Qui',
            'soloqui@cappello.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeUser,
        );

        app(AllenareInUnaPalestra::class)->assumi($trainer, $palestra);

        $this->firmaLeLiberatorie($trainer);

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        $risposta = $this->actingAs($trainer->fresh())->get('/admin');

        $risposta->assertSuccessful();

        $risposta->assertSee('Unica Palestra', escape: false);

        $risposta->assertSee('Non siamo riusciti a incassare', escape: false);
    }

    /**
     * ⚠️ **E chi non ha pagato NON entra in `/god`.**
     *
     * 🚨 Sta qui e non fra i test dei permessi per una ragione: aprire una porta
     * e' il momento in cui si aprono anche quelle accanto. ⛔ Questo test cade se
     * la correzione dell'accesso e' stata fatta allargando troppo.
     */
    #[Test]
    public function chi_non_ha_pagato_non_entra_nel_pannello_god(): void
    {
        $palestra = $this->creaPalestra('Morosa Quattro', 'morosa-quattro');
        $capo = $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@morosa-quattro.test');

        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        $risposta = $this->actingAs($capo->fresh())->get('/god');

        self::assertNotSame(
            200,
            $risposta->getStatusCode(),
            '🚨 Un titolare di palestra ha aperto il pannello di piattaforma.',
        );
    }
}
