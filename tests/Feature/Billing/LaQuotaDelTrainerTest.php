<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\AiFeature;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Quota\MemberAiQuota;
use App\Services\Ai\Quota\QuotaDelTrainer;
use App\Services\Billing\PianoAttivo;
use App\Services\Tenancy\CreaTenantPersonale;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🎯 La quota AI che un trainer riserva ai suoi allievi — U.2 e U.3.
 *
 * ══ 📌 LE DUE RICHIESTE ═══════════════════════════════════════════════════
 *
 * *«all'allievo arriva la stessa quota che gli arriverebbe se si abbonasse.
 * Quindi 150 chiamate»*.
 *
 * *«Quando a un trainer scade l'abbonamento, gli allievi mantengono l'uso della
 * quota ai riservata a loro dal trainer fino a un mese dopo il giorno in cui gli
 * e' stata assegnata»* — e, per sciogliere l'ambiguita': *«se la sua quota e'
 * stata messa il 12 febbraio e il trainer smette di pagare il 25 ottobre,
 * all'allievo resta fino al 12 novembre»*.
 *
 * ══ 🚨 IL DIFETTO CHE QUESTA CLASSE INCHIODA ══════════════════════════════
 *
 * `MemberAiQuota` aveva un **livello 3** che leggeva il tetto del trainer, con
 * un test verde che lo provava. ⛔ Non e' mai servito a niente: `hasQuotaLeft()`
 * comincia con `if (! haLaAi($utente)) return false;`, e l'allievo di un trainer
 * indipendente sta su un piano `free` che l'AI non ce l'ha.
 *
 * 💡 Il numero era giusto e nessuno lo raggiungeva. Per questo qui si prova
 * `hasQuotaLeft()` e non solo `capFor()`: e' l'unica domanda che passa da
 * entrambe le porte.
 */
final class LaQuotaDelTrainerTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);
    }

    // ───────────────────────── l'impianto ─────────────────────────

    private function trainerAbbonato(?Carbon $scadenza = null): User
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Trainer', 'trainer@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $this->abbonaIlTrainer($trainer, $scadenza);

        return $trainer->fresh();
    }

    private function abbonaIlTrainer(User $trainer, ?Carbon $scadenza = null): void
    {
        $piano = Plan::query()->where('code', Plan::TRAINER_PRO)->firstOrFail();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => $piano->id,
            'starts_at' => Carbon::now()->subYears(2),
            'ends_at' => $scadenza,
        ]);
    }

    /**
     * Sposta il `PLUS` su numeri che non somigliano a nient'altro.
     *
     * 🚨 **Serve, e non e' pignoleria.** Il `PLUS` vale 150 chiamate e 15 foto,
     * e `config('ai.quota.default_monthly_calls_per_user')` vale **anche lui**
     * 150 e 15. ⛔ Un test che legge 150 non sa dire se la quota e' arrivata dal
     * trainer o se e' semplicemente ricaduta in fondo alla catena — sarebbe
     * verde anche con il livello 3 completamente muto, che e' precisamente il
     * difetto che questa classe esiste per non far tornare.
     */
    private function plusRiconoscibile(): Plan
    {
        $plus = Plan::query()->where('code', Plan::PLUS)->firstOrFail();

        $plus->forceFill([
            'ai_monthly_calls_per_member' => 4242,
            'ai_monthly_photo_calls_per_member' => 77,
        ])->save();

        return $plus->fresh();
    }

    private function allievoDi(User $trainer, ?Carbon $assegnataIl = null): User
    {
        $allievo = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        $allievo->assignedTrainers()->attach($trainer->id, [
            'tenant_id' => $trainer->tenant_id,
            'assigned_at' => $assegnataIl ?? Carbon::now(),
            'quota_assegnata_il' => $assegnataIl,
        ]);

        return $allievo->fresh();
    }

    // ───────────────── U.2: la quota arriva davvero ─────────────────

    /**
     * 🚨 **Il test che il livello 3 non aveva.**
     *
     * ⛔ `capFor()` da solo era verde anche prima, con un numero che il cancello
     * non raggiungeva mai. Qui si chiede a `hasQuotaLeft()`, che passa da
     * `haLaAi()` — cioe' dalla porta che era chiusa.
     */
    #[Test]
    public function l_allievo_di_un_trainer_abbonato_ha_davvero_l_ai(): void
    {
        $allievo = $this->allievoDi($this->trainerAbbonato());

        $this->assertTrue(
            app(PianoAttivo::class)->haLaAi($allievo),
            'L\'allievo di un trainer abbonato non ha l\'AI: il livello 3 e\' di nuovo muto.',
        );

        $this->assertTrue(
            app(MemberAiQuota::class)->hasQuotaLeft($allievo, AiFeature::DailyAdvice),
            'Il cancello dice di no a chi il trainer sta pagando.',
        );
    }

    /**
     * 🔢 **150 e 15, cioe' esattamente il `PLUS`.**
     *
     * 📌 *«la stessa quota che gli arriverebbe se si abbonasse»*.
     *
     * ⚠️ I numeri si leggono **dal listino**, non da una costante di questo
     * test: il giorno che il `PLUS` cambia, questo test deve seguire da solo
     * invece di diventare rosso per una ragione che non c'entra.
     */
    #[Test]
    public function la_quota_e_quella_del_piano_plus(): void
    {
        $plus = $this->plusRiconoscibile();
        $allievo = $this->allievoDi($this->trainerAbbonato());

        $quota = app(MemberAiQuota::class);

        $this->assertSame(4242, $quota->capFor($allievo));
        $this->assertSame(77, $quota->capFor($allievo, conFoto: true));

        // 💡 E i due numeri vengono davvero **dal listino**, non da qui: il
        // giorno che il `PLUS` cambia, gli allievi dei trainer seguono da soli.
        $this->assertSame($plus->ai_monthly_calls_per_member, $quota->capFor($allievo));
    }

    /**
     * ⛔ **Il tetto del trainer non c'entra piu' niente.**
     *
     * 🚨 Prima si leggeva `ai_monthly_call_cap` del trainer: un trainer con
     * l'illimitato l'avrebbe regalato a cinquanta persone. Adesso quel campo
     * resta **suo** e non tocca nessun allievo.
     */
    #[Test]
    public function il_tetto_personale_del_trainer_non_arriva_all_allievo(): void
    {
        $trainer = $this->trainerAbbonato();
        $trainer->forceFill(['ai_monthly_call_cap' => 9999])->save();

        $this->plusRiconoscibile();
        $allievo = $this->allievoDi($trainer->fresh());

        $this->assertSame(
            4242,
            app(MemberAiQuota::class)->capFor($allievo),
            'L\'allievo ha ereditato il tetto personale del trainer.',
        );
    }

    /**
     * ⛔ **Un trainer che non si e' mai abbonato non copre nessuno.**
     *
     * 🚨 Senza questa regola, `free_trainer` sarebbe il modo piu' economico per
     * avere quaranta account `plus`: basta invitare quaranta persone.
     */
    #[Test]
    public function un_trainer_mai_abbonato_non_da_niente_a_nessuno(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Trainer', 'trainer@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $allievo = $this->allievoDi($trainer->fresh());

        $this->assertFalse(app(QuotaDelTrainer::class)->copre($allievo));
        $this->assertFalse(app(PianoAttivo::class)->haLaAi($allievo));
    }

    /**
     * ⛔ **E nemmeno un trainer sulla fascia GRATUITA.**
     *
     * ── 🚨 Il buco che il test qui sopra non copriva ───────────────────────
     *
     * «Mai abbonato» e «abbonato al piano che costa zero» sembrano lo stesso
     * caso e non lo sono. `trainer_free` e' un `plan_subscriptions` vero, con
     * una riga in tabella: la prima versione di `QuotaDelTrainer` escludeva solo
     * `Plan::FREE` e lasciava passare questo.
     *
     * 💡 Risultato: tre allievi con la quota `PLUS` a costo zero, e il test
     * accanto verde perche' provava l'altro caso. ⚠️ **Due situazioni diverse
     * hanno bisogno di due test**, anche quando la frase che le descrive e' la
     * stessa.
     *
     * 🎯 Il criterio buono e' `ai_enabled`: chi l'AI non ce l'ha non puo'
     * regalarla.
     */
    #[Test]
    public function un_trainer_sulla_fascia_gratuita_non_copre_nessuno(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Trainer', 'trainer@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $gratuita = Plan::query()->where('code', Plan::TRAINER_FREE)->firstOrFail();

        // 🔎 La premessa, dichiarata: questa fascia non comprende l'AI e non
        // costa niente. Se un giorno cambiasse, questo test va riletto — non
        // aggiustato.
        $this->assertFalse((bool) $gratuita->ai_enabled);
        $this->assertSame(0, (int) $gratuita->price_cents);

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => $gratuita->id,
            'starts_at' => Carbon::now()->subYear(),
        ]);

        $allievo = $this->allievoDi($trainer->fresh());

        $this->assertFalse(
            app(QuotaDelTrainer::class)->copre($allievo),
            'La fascia trainer gratuita sta regalando la quota PLUS.',
        );
        $this->assertFalse(app(PianoAttivo::class)->haLaAi($allievo));
    }

    /**
     * ⚖️ **D3: la palestra viene prima del trainer.**
     *
     * Un iscritto di una palestra che si fa seguire **anche** da un trainer
     * indipendente non deve drenare quello che il trainer paga per i **suoi**.
     */
    #[Test]
    public function un_iscritto_in_palestra_non_prende_la_quota_del_trainer(): void
    {
        $palestra = $this->creaPalestra('Alfa', 'alfa', 'ALFA2345');
        $palestra->update(['ai_monthly_calls_per_member' => 111]);

        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@alfa.test');
        $trainer = $this->trainerAbbonato();

        $iscritto->assignedTrainers()->attach($trainer->id, [
            'tenant_id' => $trainer->tenant_id,
            'assigned_at' => Carbon::now(),
        ]);

        $this->assertFalse(app(QuotaDelTrainer::class)->copre($iscritto->fresh()));
        $this->assertSame(111, app(MemberAiQuota::class)->capFor($iscritto->fresh()));
    }

    /**
     * ⛔ **Un rapporto disattivato non copre piu'** — D5.
     *
     * 💡 La riga di `trainer_member` non si cancella mai («il legame resta, la
     * storia si conserva, il canale si chiude»), quindi la disattivazione e'
     * l'unico segno che il rapporto non e' piu' in piedi. Leggerla e'
     * obbligatorio: senza, un allievo cacciato terrebbe la quota per sempre.
     */
    #[Test]
    public function un_rapporto_disattivato_non_copre_piu(): void
    {
        $trainer = $this->trainerAbbonato();
        $allievo = $this->allievoDi($trainer);

        $allievo->assignedTrainers()->updateExistingPivot($trainer->id, [
            'disattivato_il' => Carbon::now(),
        ]);

        $this->assertFalse(app(QuotaDelTrainer::class)->copre($allievo->fresh()));
    }

    /**
     * 🚨 **Lo spegnimento esplicito vince anche sul trainer.**
     *
     * Chi ha spento l'AI a una persona dal pannello non deve vedersela
     * riaccendere perche' quella persona si e' fatta seguire da un trainer. E'
     * la stessa regola gia' scritta per i gettoni: *«un interruttore che si
     * riaccende da solo non e' un interruttore»*.
     */
    #[Test]
    public function l_interruttore_spento_vince_sul_trainer(): void
    {
        $allievo = $this->allievoDi($this->trainerAbbonato());
        $allievo->forceFill(['ai_enabled_override' => false])->save();

        $this->assertFalse(app(PianoAttivo::class)->haLaAi($allievo->fresh()));
    }

    // ───────────────── U.3: la scadenza ─────────────────

    /**
     * 🎯 **L'esempio del committente, parola per parola.**
     *
     * 📌 *«se la sua quota e' stata messa il 12 febbraio e il trainer smette di
     * pagare il 25 ottobre, all'allievo resta fino al 12 novembre»*.
     *
     * ⚠️ **Il 12 e' compreso, il 13 no.** E' il bordo su cui la regola si
     * decide, e provarlo da un lato solo lascerebbe passare sia «un giorno in
     * meno» sia «un mese in piu'».
     */
    #[Test]
    public function l_esempio_del_committente_per_intero(): void
    {
        $assegnata = Carbon::create(2026, 2, 12);
        $scadenza = Carbon::create(2026, 10, 25);

        $fine = QuotaDelTrainer::valeFinoA($assegnata, $scadenza);

        $this->assertSame('2026-11-12', $fine->toDateString());

        // ── E adesso lo stesso, guardato dal calendario vero ──
        $trainer = $this->trainerAbbonato($scadenza);
        $allievo = $this->allievoDi($trainer, $assegnata);

        Carbon::setTestNow(Carbon::create(2026, 11, 12, 9, 0));
        $this->assertTrue(
            app(QuotaDelTrainer::class)->copre($allievo->fresh()),
            'Il 12 novembre la quota deve valere ancora: e\' il giorno detto.',
        );

        Carbon::setTestNow(Carbon::create(2026, 11, 13, 9, 0));
        $this->assertFalse(
            app(QuotaDelTrainer::class)->copre($allievo->fresh()),
            'Il 13 novembre la quota non c\'e\' piu\'.',
        );

        Carbon::setTestNow();
    }

    /**
     * ⚠️ **La trappola dei mesi corti, che questo progetto ha gia' pagato.**
     *
     * ⛔ `Carbon::addMonth()` sul 31 gennaio da' **3 marzo**, non il 28. Una
     * quota assegnata il 31 non ha un anniversario a febbraio, e il mese corto
     * va **accorciato**, non superato.
     *
     * 💡 E il mese dopo l'anniversario torna al 31: sommare mesi anche con
     * `addMonthNoOverflow()` lo farebbe derivare per sempre (31 → 28 → 28).
     */
    #[Test]
    public function una_quota_assegnata_il_trentuno_non_salta_febbraio(): void
    {
        $assegnata = Carbon::create(2026, 1, 31);

        $this->assertSame(
            '2026-02-28',
            QuotaDelTrainer::valeFinoA($assegnata, Carbon::create(2026, 2, 10))->toDateString(),
            'Febbraio e\' stato saltato, o traboccato in marzo.',
        );

        $this->assertSame(
            '2026-03-31',
            QuotaDelTrainer::valeFinoA($assegnata, Carbon::create(2026, 3, 5))->toDateString(),
            'Il giorno e\' derivato: dopo un mese corto deve tornare al 31.',
        );

        // 💡 Il 2028 e' bisestile: il 29 esiste, e va usato.
        $this->assertSame(
            '2028-02-29',
            QuotaDelTrainer::valeFinoA($assegnata, Carbon::create(2028, 2, 3))->toDateString(),
        );
    }

    /**
     * ⏰ **Scaduto e poi rinnovato non azzera niente** — U.3.3.
     *
     * 💡 La regola si applica **in lettura**: non esiste nessun job che alla
     * scadenza cancelli qualcosa. Per questo il rinnovo rimette il rapporto in
     * piedi da solo — non c'e' niente da ripristinare perche' non e' stato
     * distrutto niente.
     */
    #[Test]
    public function scaduto_e_poi_rinnovato_rimette_tutto_a_posto(): void
    {
        $trainer = $this->trainerAbbonato(Carbon::create(2026, 10, 25));
        $allievo = $this->allievoDi($trainer, Carbon::create(2026, 2, 12));

        Carbon::setTestNow(Carbon::create(2026, 12, 1));

        $this->assertFalse(app(QuotaDelTrainer::class)->copre($allievo->fresh()));

        // Il trainer rinnova.
        $this->abbonaIlTrainer($trainer, null);

        $this->assertTrue(
            app(QuotaDelTrainer::class)->copre($allievo->fresh()),
            'Il rinnovo non ha rimesso in piedi il rapporto: qualcosa e\' stato cancellato.',
        );

        Carbon::setTestNow();
    }

    /**
     * ⛔ **E dopo la scadenza si scende, non si precipita** — U.3.4.
     *
     * 🚨 L'allievo non deve accorgersene con un errore che parla di un
     * abbonamento che non e' il suo: torna semplicemente al livello successivo
     * della catena, cioe' al proprio piano.
     */
    #[Test]
    public function finita_la_grazia_si_torna_al_proprio_piano(): void
    {
        $trainer = $this->trainerAbbonato(Carbon::create(2026, 10, 25));
        $allievo = $this->allievoDi($trainer, Carbon::create(2026, 2, 12));

        Carbon::setTestNow(Carbon::create(2026, 12, 1));

        $fresco = $allievo->fresh();

        $this->assertFalse(app(PianoAttivo::class)->haLaAi($fresco));
        $this->assertFalse(
            app(MemberAiQuota::class)->hasQuotaLeft($fresco, AiFeature::DailyAdvice),
        );

        Carbon::setTestNow();
    }

    /**
     * 💡 **Chi non ha `quota_assegnata_il` ricade su `assigned_at`.**
     *
     * ⚠️ La migrazione lascia la colonna vuota per tutti i rapporti gia'
     * esistenti: riempirla con `now()` avrebbe spostato l'anniversario di gente
     * che il trainer ce l'ha da mesi. La data di nascita del rapporto **e'**,
     * per loro, il giorno in cui ha cominciato a dare la quota.
     */
    #[Test]
    public function senza_la_data_nuova_vale_quella_del_rapporto(): void
    {
        $trainer = $this->trainerAbbonato(Carbon::create(2026, 10, 25));

        $allievo = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::FAKE_PASSWORD,
        ]);

        // ⚠️ Come una riga scritta prima di U.2: `quota_assegnata_il` e' vuota.
        $allievo->assignedTrainers()->attach($trainer->id, [
            'tenant_id' => $trainer->tenant_id,
            'assigned_at' => Carbon::create(2026, 2, 12),
        ]);

        Carbon::setTestNow(Carbon::create(2026, 11, 12, 9, 0));
        $this->assertTrue(app(QuotaDelTrainer::class)->copre($allievo->fresh()));

        Carbon::setTestNow(Carbon::create(2026, 11, 13, 9, 0));
        $this->assertFalse(app(QuotaDelTrainer::class)->copre($allievo->fresh()));

        Carbon::setTestNow();
    }

    /** 🔎 Un tenant senza `PLUS` nel listino nega, non inventa un numero. */
    #[Test]
    public function senza_listino_non_si_inventa_una_quota(): void
    {
        $trainer = $this->trainerAbbonato();
        $allievo = $this->allievoDi($trainer);

        Plan::withoutGlobalScopes()->where('code', Plan::PLUS)->delete();

        $this->assertNull(app(QuotaDelTrainer::class)->tetto($allievo->fresh(), conFoto: false));
    }

    // ───────────────── U.4: «mai limiti di schede» ─────────────────

    /**
     * 🔓 **Un trainer su una fascia a pagamento è «abbonato»** — U.4.1.
     *
     * 📌 *«non ha mai limiti di schede da vedere, creare o usare»*.
     *
     * ── 🚨 Perché è un test e non un ragionamento ──────────────────────────
     *
     * Il limite delle schede, nell'app, passa da
     * `limiti_delle_schede.dart → senzaLimiti({abbonato, illimitata})`, e quel
     * flag `abbonato` è **esattamente** questa riga di server. ⛔ Il piano di
     * U.4.1 dice di provarlo invece di darlo per buono, perché la catena è
     * lunga — piano → `eAbbonato` → `/me` → app — e basta un anello perché il
     * trainer si ritrovi con tre schede come chiunque altro.
     *
     * 💡 Non c'è niente da scrivere: **era già vero**. Questo test serve a
     * fermarlo lì.
     */
    #[Test]
    public function un_trainer_su_una_fascia_a_pagamento_e_abbonato(): void
    {
        $trainer = $this->trainerAbbonato();

        $this->assertTrue(
            app(PianoAttivo::class)->eAbbonato($trainer),
            'Un trainer che paga risulta non abbonato: nell\'app si ritroverebbe '
            .'il limite delle schede.',
        );

        $this->assertSame(Plan::TRAINER_PRO, app(PianoAttivo::class)->livello($trainer));
    }

    /**
     * ⏰ **E alla scadenza torna limitato come tutti** — U.4.2.
     *
     * 📌 *«può solo agire come un utente normale»*. ⚠️ Che non vuol dire
     * perdere le schede: quelle restano, ed è la protezione già scritta in
     * `limiti_delle_schede.dart` — **una scheda mandata dal trainer non
     * sparisce mai**, perché quel trainer l'ha scritta per quella persona.
     */
    #[Test]
    public function un_trainer_scaduto_non_e_piu_abbonato(): void
    {
        $trainer = $this->trainerAbbonato(Carbon::now()->subMonth());

        $this->assertFalse(app(PianoAttivo::class)->eAbbonato($trainer->fresh()));
    }

    /**
     * ⏳ **DOMANDA APERTA, non un difetto deciso** — U.4, 29/08/2026.
     *
     * 🚨 `eAbbonato()` risponde *«il codice del piano non è `free`»*, e
     * `trainer_free` non è `free`: quindi **un trainer sulla fascia gratuita
     * risulta abbonato**, e nell'app non ha limiti di schede né il gate.
     *
     * ⚠️ Può essere giusto — è un tier vero, da tre allievi, e togliergli le
     * schede renderebbe la prova del prodotto inutile — o può essere un buco:
     * chiunque si dichiari trainer si prende le funzioni a pagamento senza
     * pagare.
     *
     * ⛔ **Non si decide qui.** Questo test **fotografa** il comportamento
     * attuale invece di lasciarlo scoprire per caso: il giorno che il
     * committente decide, diventa rosso e dice esattamente cosa cambiare.
     */
    #[Test]
    public function per_ora_anche_la_fascia_trainer_gratuita_risulta_abbonata(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Trainer', 'trainer@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $gratuita = Plan::query()->where('code', Plan::TRAINER_FREE)->firstOrFail();

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => $gratuita->id,
            'starts_at' => Carbon::now()->subYear(),
        ]);

        $this->assertTrue(
            app(PianoAttivo::class)->eAbbonato($trainer->fresh()),
            'Il comportamento è cambiato: rileggere U.4 e chiedere al committente.',
        );

        // 💡 Ma l'AI no: quella la decide `ai_enabled`, che su questa fascia è
        // spento. Le due domande sono separate, ed è giusto che lo siano.
        $this->assertFalse(app(PianoAttivo::class)->haLaAi($trainer->fresh()));
    }

    /** 💡 Il contorno: un `Tenant` di prova e' quello che si crede. */
    #[Test]
    public function il_tenant_dell_allievo_e_personale(): void
    {
        $allievo = $this->allievoDi($this->trainerAbbonato());

        $this->assertInstanceOf(Tenant::class, $allievo->tenant);
        $this->assertTrue($allievo->tenant->ePersonale());
    }
}
