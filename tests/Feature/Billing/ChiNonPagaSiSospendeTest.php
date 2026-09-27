<?php

declare(strict_types=1);

namespace Tests\Feature\Billing;

use App\Enums\PlanKind;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\AddebitoMensile;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Services\Billing\LaSospensioneAutomatica;
use App\Services\Tenancy\CreaTenantPersonale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🤖 **Chi non paga si sospende da sé** — F3, 27/09/2026.
 *
 * ══ 🚨 PERCHE' META' DI QUESTI TEST PROVA CHI **NON** VA SOSPESO ═══════════
 *
 * `LaSospensioneAutomatica` è l'unico pezzo di codice del progetto che **toglie
 * l'accesso senza che una persona lo abbia deciso**. ⛔ Il rischio non è che non
 * sospenda: è che sospenda **qualcuno che non doveva**.
 *
 * 🔑 Quindi i test che contano di più sono quelli in negativo: lo spazio di un
 * iscritto che non ha mai comprato niente, di chi ha appena rinnovato, di chi è
 * stato sospeso **a mano**. ⚠️ Se uno di quelli diventasse rosso, ogni notte alle
 * 4:40 il sistema chiuderebbe fuori dei clienti in regola.
 */
class ChiNonPagaSiSospendeTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    // ───────────────────────── chi SI sospende ─────────────────────────

    /** 🎯 Palestra con l'abbonamento finito da due giorni: si sospende. */
    #[Test]
    public function la_palestra_con_labbonamento_finito_si_sospende(): void
    {
        $palestra = $this->unaPalestraChePagava('Morosa');

        $this->faiFinireLabbonamento($palestra, giorniFa: 2);

        app(LaSospensioneAutomatica::class)->giro();

        $fresca = Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey());

        self::assertSame(
            TenantStatus::Suspended,
            $fresca->status,
            '🚨 Chi non paga resta attivo: l\'avviso «devi pagare» non comparirà mai.',
        );

        self::assertNotNull(
            $fresca->sospensione_automatica_dal,
            '⛔ Sospesa senza segnare che è stata la macchina: non tornerà più su da sé.',
        );
    }

    /**
     * 🔑 **E anche lo spazio di un trainer indipendente che pagava.**
     *
     * ⚠️ È uno spazio `personal`, come quello di un iscritto: ⛔ se la regola
     * guardasse il **tipo** dello spazio invece di *«ha mai pagato un canone»*,
     * questo caso sfuggirebbe e un trainer moroso lavorerebbe gratis per sempre.
     */
    #[Test]
    public function anche_lo_spazio_di_un_trainer_che_pagava_si_sospende(): void
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Trainer Moroso',
            'moroso@trainer.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $spazio = Tenant::withoutGlobalScopes()->findOrFail($trainer->tenant_id);

        $this->abbonaloA($spazio, $this->pianoDaTrainerAPagamento(), finitoGiorniFa: 3);

        app(LaSospensioneAutomatica::class)->giro();

        self::assertSame(
            TenantStatus::Suspended,
            Tenant::withoutGlobalScopes()->findOrFail($spazio->getKey())->status,
        );
    }

    // ───────────────── chi NON si sospende: i test che contano ─────────────────

    /**
     * 🚨 **IL TEST PIU' IMPORTANTE DEL FILE.**
     *
     * Lo spazio personale di una persona che non ha mai comprato niente **non si
     * tocca**. ⛔ Se questo diventasse rosso, il giro notturno sospenderebbe
     * *tutti* — e romperebbe la prima regola del progetto in automatico, ogni
     * notte, su ogni account.
     */
    #[Test]
    public function lo_spazio_di_chi_non_ha_mai_pagato_non_si_tocca_mai(): void
    {
        $persona = app(CreaTenantPersonale::class)(
            'Persona Normale',
            'normale@persona.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeUser,
        );

        app(LaSospensioneAutomatica::class)->giro();

        self::assertSame(
            TenantStatus::Active,
            Tenant::withoutGlobalScopes()->findOrFail($persona->tenant_id)->status,
            '🚨 Sospeso lo spazio di chi non doveva pagare niente. '
            .'«NIENTE AL MONDO DEVE IMPEDIRE A UN UTENTE DI ACCEDERE».',
        );
    }

    /**
     * 🚨🚨 **IL CASO PERICOLOSO VERO: l'abbonamento INDIVIDUALE scaduto.**
     *
     * ══ ⛔ COME L'HO TROVATO, E PERCHE' VA SCRITTO ═════════════════════════
     *
     * Il test qui sopra — *«lo spazio di chi non ha mai pagato non si tocca»* —
     * era **vacuo**: togliendo la guardia `eraUnClientePagante()` restava verde.
     * 🔑 Perché quello spazio non ha **nessun** abbonamento, quindi si salva per
     * la regola sulla **data**, non per la guardia che credevo di provare.
     *
     * 🚨 **Questo è il caso che conta**: una persona che aveva il piano
     * individuale (`plus`) e lo ha lasciato scadere. Ha un abbonamento **finito**,
     * quindi passa la regola sulla data — e **solo** `eraUnClientePagante()` la
     * salva.
     *
     * ⛔ Se quella guardia fosse sbagliata, ogni notte alle 4:40 verrebbe sospeso
     * lo spazio personale di **ogni persona che ha smesso di rinnovare**. Non
     * perderebbe un vantaggio: perderebbe lo **stato attivo del proprio spazio**.
     * 💡 Ed è la situazione più comune che esista in un'app in abbonamento.
     *
     * ⚖️ La differenza in una riga: **l'abbonamento individuale è una cosa che
     * compri per te; il canone è lo slot di un trainer o di una palestra.** Solo
     * il secondo, non pagato, significa «cliente moroso».
     */
    #[Test]
    public function chi_ha_lasciato_scadere_labbonamento_individuale_non_si_sospende(): void
    {
        $persona = app(CreaTenantPersonale::class)(
            'Ex Abbonato',
            'exabbonato@persona.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeUser,
        );

        $spazio = Tenant::withoutGlobalScopes()->findOrFail($persona->tenant_id);

        /* 💳 Aveva il `plus` e l'ha lasciato scadere due mesi fa. */
        $this->abbonaloA($spazio, $this->pianoIndividuale(), finitoGiorniFa: 60);

        app(LaSospensioneAutomatica::class)->giro();

        self::assertSame(
            TenantStatus::Active,
            Tenant::withoutGlobalScopes()->findOrFail($spazio->getKey())->status,
            '🚨 Sospeso lo spazio di una persona che ha solo smesso di rinnovare il '
            .'suo abbonamento individuale. ⛔ Perde i vantaggi, non lo spazio: '
            .'«NIENTE AL MONDO DEVE IMPEDIRE A UN UTENTE DI ACCEDERE».',
        );
    }

    /** ✅ E chi ha l'abbonamento vivo nemmeno, ovviamente. */
    #[Test]
    public function chi_paga_non_si_tocca(): void
    {
        $palestra = $this->unaPalestraChePagava('In Regola');

        app(LaSospensioneAutomatica::class)->giro();

        self::assertSame(
            TenantStatus::Active,
            Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey())->status,
        );
    }

    /**
     * ⏳ **Finito IERI: non ancora.**
     *
     * 💡 Il giorno di attesa copre il ritardo di un webhook: se `invoice.paid`
     * arriva con qualche ora di ritardo e il giro passa nel frattempo, senza
     * margine sospenderemmo **un cliente che ha pagato**. ⛔ È l'errore peggiore
     * che questa macchina può fare: il contrario del suo scopo.
     */
    #[Test]
    public function chi_e_scaduto_da_poche_ore_non_si_sospende_ancora(): void
    {
        $palestra = $this->unaPalestraChePagava('Appena Scaduta');

        $this->faiFinireLabbonamento($palestra, giorniFa: 0);

        app(LaSospensioneAutomatica::class)->giro();

        self::assertSame(
            TenantStatus::Active,
            Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey())->status,
            '⛔ Sospeso troppo presto: il ritardo di un webhook diventa una sospensione.',
        );
    }

    /**
     * 🔒 **Una sospensione decisa da una PERSONA non si annulla con un pagamento.**
     *
     * ⚠️ È la ragione per cui esiste la colonna `sospensione_automatica_dal`: una
     * palestra sospesa da `/god` per un abuso ha quella colonna a `null`, e la
     * macchina non la guarda nemmeno. ⛔ Senza la colonna, le due sospensioni
     * sarebbero indistinguibili, e qualunque riattivazione automatica sarebbe un
     * modo per annullare la decisione di una persona.
     */
    #[Test]
    public function una_sospensione_messa_a_mano_non_la_annulla_nessun_pagamento(): void
    {
        $palestra = $this->unaPalestraChePagava('Sospesa A Mano');

        /* 🙋 Una persona la sospende dal pannello: nessuna data automatica. */
        $palestra->forceFill(['status' => TenantStatus::Suspended])->save();

        /* 💳 E l'abbonamento è vivissimo. */
        app(LaSospensioneAutomatica::class)->giro();

        self::assertSame(
            TenantStatus::Suspended,
            Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey())->status,
            '🚨 La macchina ha annullato una decisione presa da una persona.',
        );
    }

    // ───────────────────────── e la riattivazione ─────────────────────────

    /**
     * 🔓 **Chi ripaga torna su.**
     *
     * 🚨 Una macchina che sospende e non riattiva **prende i soldi e non ridà il
     * servizio**: è il risultato peggiore possibile, peggiore del buco che questa
     * classe è venuta a chiudere.
     */
    #[Test]
    public function chi_ripaga_torna_attivo(): void
    {
        $palestra = $this->unaPalestraChePagava('Tornata');

        $this->faiFinireLabbonamento($palestra, giorniFa: 5);

        app(LaSospensioneAutomatica::class)->giro();

        self::assertSame(
            TenantStatus::Suspended,
            Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey())->status,
            'Il presupposto del test: deve prima essere sospesa.',
        );

        /* 💳 Ripaga: un abbonamento nuovo, vivo. */
        $this->abbonaloA($palestra, Plan::query()->where('code', Plan::GYM)->firstOrFail());

        app(LaSospensioneAutomatica::class)->giro();

        $fresca = Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey());

        self::assertSame(TenantStatus::Active, $fresca->status);

        self::assertNull(
            $fresca->sospensione_automatica_dal,
            '⛔ Riattivata ma con la data ancora lì: una sospensione futura fatta '
            .'a mano verrebbe letta come automatica.',
        );
    }

    /**
     * ⚡ **E torna su SUBITO sul pagamento, non la notte dopo.**
     *
     * 💡 Chi ha appena pagato con la carta in mano non deve aspettare le 4:40.
     * ⚠️ Qui si prova il metodo che `Cassa` chiama dal webhook, non il giro.
     */
    #[Test]
    public function il_pagamento_riattiva_subito_senza_aspettare_la_notte(): void
    {
        $palestra = $this->unaPalestraChePagava('Subito');

        $this->faiFinireLabbonamento($palestra, giorniFa: 5);

        app(LaSospensioneAutomatica::class)->giro();

        $this->abbonaloA($palestra, Plan::query()->where('code', Plan::GYM)->firstOrFail());

        /* 💳 È quello che fa `Cassa::rinnovo()`. */
        app(LaSospensioneAutomatica::class)->seHaRipagato($palestra->getKey());

        self::assertSame(
            TenantStatus::Active,
            Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey())->status,
        );
    }

    /**
     * ⛔ **Senza `--scrivi` non cambia niente.**
     *
     * 💡 È lo strumento per rispondere a *«chi verrebbe sospeso stanotte?»*
     * senza sospendere nessuno. ⚠️ Un'anteprima che scrive non è un'anteprima.
     */
    #[Test]
    public function senza_scrivi_il_comando_non_tocca_niente(): void
    {
        $palestra = $this->unaPalestraChePagava('Solo Anteprima');

        $this->faiFinireLabbonamento($palestra, giorniFa: 9);

        $this->artisan('abbonamenti:sospendi-i-morosi')
            ->expectsOutputToContain('Solo Anteprima')
            ->assertExitCode(0);

        self::assertSame(
            TenantStatus::Active,
            Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey())->status,
            '🚨 L\'anteprima ha scritto.',
        );
    }

    /** ✅ E con `--scrivi` sì. */
    #[Test]
    public function con_scrivi_il_comando_sospende(): void
    {
        $palestra = $this->unaPalestraChePagava('Applica');

        $this->faiFinireLabbonamento($palestra, giorniFa: 9);

        $this->artisan('abbonamenti:sospendi-i-morosi --scrivi')->assertExitCode(0);

        self::assertSame(
            TenantStatus::Suspended,
            Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey())->status,
        );
    }

    // ───────────── il consumo: stessa regola, altro segnale ─────────────

    /**
     * 💶 **IL PIANO A CONSUMO SI SOSPENDE COME TUTTI GLI ALTRI.**
     *
     * ══ 📌 LA CORREZIONE DEL COMMITTENTE ══════════════════════════════════
     *
     * 📌 27/09: *«quelli che tu chiami piano a consumo non è nient'altro che un
     * abbonamento dal prezzo variabile in base agli iscritti, quindi può funzionare
     * esattamente come l'abbonamento normale: quando fallisce il pagamento si
     * sospende, stessa cosa di tutti gli abbonamenti»*.
     *
     * 🚨 **Avevo lasciato il consumo fuori** scrivendo che «là non c'è un abbonamento
     * che finisce», e da quella premessa avevo dedotto che servisse una decisione
     * commerciale nuova. ⛔ Non serviva: cambia solo **da dove arriva il segnale** —
     * la fattura del mese insolvente invece di `ends_at` passato.
     */
    #[Test]
    public function il_piano_a_consumo_insolvente_si_sospende(): void
    {
        [$palestra, $addebito] = $this->unaPalestraAConsumo('Consumo Moroso');

        /* ⛔ La fattura del mese non è andata: è quello che fa `Cassa`. */
        $addebito->forceFill([
            'stato' => AddebitoMensile::FALLITO,
            'fallito_il' => now()->subDays(9),
        ])->save();

        app(LaSospensioneAutomatica::class)->giro();

        self::assertSame(
            TenantStatus::Suspended,
            Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey())->status,
            '🚨 Un cliente a consumo non paga da nove giorni e il servizio è ancora acceso.',
        );
    }

    /**
     * ⏳ **Ma non al primo rifiuto della carta.**
     *
     * ⚠️ Stripe riprova per giorni: spegnere subito vorrebbe dire punire chi ha
     * cambiato carta di venerdì. 💡 Sono gli stessi sette giorni di grazia che per un
     * canone sono **già dentro `ends_at`**, più un giorno di margine.
     */
    #[Test]
    public function il_consumo_non_si_sospende_al_primo_rifiuto(): void
    {
        [$palestra, $addebito] = $this->unaPalestraAConsumo('Consumo Ieri');

        $addebito->forceFill([
            'stato' => AddebitoMensile::FALLITO,
            'fallito_il' => now()->subDay(),
        ])->save();

        app(LaSospensioneAutomatica::class)->giro();

        self::assertSame(
            TenantStatus::Active,
            Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey())->status,
            '⛔ Sospeso al primo rifiuto: Stripe sta ancora riprovando.',
        );
    }

    /**
     * 🔓 **E se poi paga, torna su.**
     *
     * 🚨 È la metà che si dimentica, e per il consumo era **più facile dimenticarla**:
     * l'abbonamento resta vivo tutto il tempo, quindi la riattivazione non può
     * guardare solo quello. 💡 Per questo c'è `staPagando()`, che chiede **entrambe**
     * le cose.
     */
    #[Test]
    public function il_consumo_pagato_riattiva_lo_spazio(): void
    {
        [$palestra, $addebito] = $this->unaPalestraAConsumo('Consumo Tornato');

        $addebito->forceFill([
            'stato' => AddebitoMensile::FALLITO,
            'fallito_il' => now()->subDays(20),
        ])->save();

        app(LaSospensioneAutomatica::class)->giro();

        self::assertSame(
            TenantStatus::Suspended,
            Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey())->status,
            'Il presupposto: deve prima essere sospesa.',
        );

        /* 💳 Paga: è quello che fa `Cassa::addebitoPagato()`. */
        $addebito->forceFill([
            'stato' => AddebitoMensile::INCASSATO,
            'incassato_il' => now(),
            'fallito_il' => null,
        ])->save();

        app(LaSospensioneAutomatica::class)->giro();

        self::assertSame(
            TenantStatus::Active,
            Tenant::withoutGlobalScopes()->findOrFail($palestra->getKey())->status,
            '🚨 Ha pagato e resta sospeso: la macchina prende i soldi e non ridà il servizio.',
        );
    }

    /**
     * 🚨 **E il test che il BUCO avrebbe lasciato passare.**
     *
     * ⛔ `eraUnClientePagante()` pretendeva `price_cents > 0`. Un piano a consumo ha
     * `price_cents = 0` — il prezzo è **per iscritto** — quindi sarebbe stato escluso
     * dai candidati e **non si sarebbe sospeso mai**, nemmeno con la regola nuova.
     *
     * 💡 Trovato guardando i piani veri a database (`gym_consumo`, `price_cents=0`,
     * `prezzo_iscritto_cent=280`) invece di fidarsi di come «dovrebbero» essere fatti.
     */
    #[Test]
    public function un_piano_a_consumo_conta_come_cliente_pagante(): void
    {
        [$palestra] = $this->unaPalestraAConsumo('Consumo Riconosciuto');

        self::assertTrue(
            app(LaSospensioneAutomatica::class)->eraUnClientePagante($palestra),
            '🚨 Un cliente a consumo non è riconosciuto come pagante: resterebbe fuori '
            .'dai candidati e non si sospenderebbe mai, qualunque regola si scriva.',
        );
    }

    // ───────────────────────── aiuti ─────────────────────────

    /**
     * 💶 Una palestra sul piano **a consumo**, con la fattura del mese già emessa.
     *
     * @return array{0: Tenant, 1: AddebitoMensile}
     */
    private function unaPalestraAConsumo(string $nome): array
    {
        $slug = str_replace(' ', '-', mb_strtolower($nome));

        $palestra = $this->creaPalestra($nome, $slug);
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@'.$slug.'.test');

        $piano = $this->pianoAConsumo();

        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->delete();

        /*
         * ⚠️ `ends_at = null`: un abbonamento a consumo **non scade**, è il prezzo che
         * cambia ogni mese. 🔑 Ed è proprio per questo che il segnale non può essere
         * `ends_at` e deve essere la fattura.
         */
        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $palestra->getKey(),
            'plan_id' => $piano->getKey(),
            'starts_at' => now()->subYear(),
            'ends_at' => null,
        ]);

        $addebito = AddebitoMensile::query()->create([
            'tenant_id' => $palestra->getKey(),
            'plan_id' => $piano->getKey(),
            'periodo' => now()->subMonth()->format('Y-m'),
            'iscritti_contati' => 120,
            'iscritti_fatturati' => 120,
            'prezzo_iscritto_cent' => (int) $piano->prezzo_iscritto_cent,
            'importo_cent' => 120 * (int) $piano->prezzo_iscritto_cent,
            'stato' => AddebitoMensile::EMESSO,
        ]);

        return [$palestra, $addebito];
    }

    private function pianoAConsumo(): Plan
    {
        return Plan::query()->whereNotNull('prezzo_iscritto_cent')->first()
            ?? Plan::create([
                'code' => 'gym_consumo',
                'name' => 'Palestra a consumo',
                'kind' => PlanKind::Gym,
                'ai_enabled' => true,
                'max_members' => 1000,
                /* 🚨 Zero, ed è il punto: il prezzo è per iscritto. */
                'price_cents' => 0,
                'prezzo_iscritto_cent' => 280,
                'is_public' => true,
            ]);
    }

    /** Una palestra con un abbonamento a canone vivo. */
    private function unaPalestraChePagava(string $nome): Tenant
    {
        $slug = str_replace(' ', '-', mb_strtolower($nome));

        $palestra = $this->creaPalestra($nome, $slug);
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@'.$slug.'.test');

        /*
         * ⚠️ `creaPalestra()` crea già l'abbonamento al piano `gym` **se il
         * listino è stato seminato**. Se non c'è, lo si mette qui: senza, la
         * palestra non risulterebbe mai «un cliente a canone» e i test in
         * positivo passerebbero per il motivo sbagliato — cioè non passerebbero.
         */
        if (! app(LaSospensioneAutomatica::class)->eraUnClientePagante($palestra)) {
            $this->abbonaloA($palestra, $this->pianoDaPalestraAPagamento());
        }

        return $palestra;
    }

    /** Chiude ogni abbonamento dello spazio, con la fine a N giorni fa. */
    private function faiFinireLabbonamento(Tenant $spazio, int $giorniFa): void
    {
        PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $spazio->getKey())
            ->update(['ends_at' => now()->subDays($giorniFa)->subHour(), 'rinnova' => false]);
    }

    private function abbonaloA(Tenant $spazio, Plan $piano, ?int $finitoGiorniFa = null): void
    {
        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $spazio->getKey(),
            'plan_id' => $piano->getKey(),
            'starts_at' => now()->subMonth(),
            'ends_at' => $finitoGiorniFa === null
                ? now()->addMonth()
                : now()->subDays($finitoGiorniFa)->subHour(),
        ]);
    }

    private function pianoDaPalestraAPagamento(): Plan
    {
        return Plan::query()->where('code', Plan::GYM)->first()
            ?? Plan::create([
                'code' => Plan::GYM,
                'name' => 'Palestra',
                'kind' => PlanKind::Gym,
                'ai_enabled' => true,
                'max_members' => 200,
                'price_cents' => 19900,
                'is_public' => true,
            ]);
    }

    /** 💳 Il piano che una persona compra per sé: **non** è un canone. */
    private function pianoIndividuale(): Plan
    {
        return Plan::query()->where('code', Plan::PLUS)->first()
            ?? Plan::create([
                'code' => Plan::PLUS,
                'name' => 'Plus',
                'kind' => PlanKind::Person,
                'ai_enabled' => true,
                'max_members' => 1,
                'price_cents' => 990,
                'is_public' => true,
            ]);
    }

    private function pianoDaTrainerAPagamento(): Plan
    {
        return Plan::query()->where('code', Plan::TRAINER_PRO)->first()
            ?? Plan::create([
                'code' => Plan::TRAINER_PRO,
                'name' => 'Trainer Pro',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => true,
                'max_members' => 60,
                'price_cents' => 4900,
                'is_public' => true,
            ]);
    }
}
