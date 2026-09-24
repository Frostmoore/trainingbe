<?php

declare(strict_types=1);

namespace Tests\Feature\Avvisi;

use App\Enums\AzioneDellAvviso;
use App\Enums\TipoBersaglio;
use App\Enums\UserRole;
use App\Filament\God\Resources\Avvisi\AvvisoResource;
use App\Filament\God\Resources\Avvisi\Pages\EditAvviso;
use App\Filament\God\Resources\Avvisi\Pages\ListAvvisi;
use App\Jobs\MandaLAvvisoInPush;
use App\Models\Avviso;
use App\Models\AvvisoDecisione;
use App\Models\User;
use App\Services\Avvisi\QualeAvvisoMostrare;
use App\Services\Tenancy\CreaTenantPersonale;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 📣 **Gli avvisi in-app** — blocco B4, 24/09/2026.
 *
 * 📌 Il committente, il 23/09: *«io (god) devo poter mandare notifiche a chi
 * voglio io — gruppi di pubblico o utenti individuali, usiamo gli stessi che
 * possono essere destinatari di una promozione — facendo apparire una modale
 * in-app con un tasto per approvare … e uno per rifiutare»*.
 *
 * ══ 🚨 COSA GUARDANO QUESTI TEST ════════════════════════════════════════════
 *
 * ⚠️ Non «l'avviso arriva»: **che non arrivi a chi non deve**, che **non
 * ricompaia** una volta deciso, e che approvare due volte **non regali due
 * promozioni**. 💡 Sono i tre modi in cui questo meccanismo può fare danni
 * senza dare nessun errore.
 */
final class GliAvvisiInAppTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private const PAROLA = 'Prova-2409-avvisi';

    private function persona(string $email): User
    {
        return app(CreaTenantPersonale::class)(
            ucfirst(explode('@', $email)[0]),
            $email,
            ['password' => self::PAROLA],
        )->fresh();
    }

    /** Un avviso per tutti, acceso. */
    private function avviso(array $extra = []): Avviso
    {
        $avviso = Avviso::query()->create(array_merge([
            'titolo' => 'Una cosa da dirti',
            'testo' => 'Il testo dell\'avviso.',
            'tasto_approva' => 'Va bene',
            'tasto_rifiuta' => 'No, grazie',
            'azione' => AzioneDellAvviso::Dismiss,
            'attivo' => true,
        ], $extra));

        $avviso->bersagli()->create(['tipo' => TipoBersaglio::Tutti]);

        return $avviso->fresh();
    }

    /** Risponde per conto della persona: serve a poter vedere il successivo. */
    private function rispondiA(User $chi, ?Avviso $avviso): void
    {
        if ($avviso === null) {
            return;
        }

        AvvisoDecisione::query()
            ->where('avviso_id', $avviso->getKey())
            ->where('user_id', $chi->getKey())
            ->update(['decisione' => AvvisoDecisione::APPROVATO, 'deciso_il' => now()]);
    }

    // ───────────────── chi lo riceve ─────────────────

    /** 💡 Il pubblico è **quello delle promozioni**: non è stato costruito, è stato puntato. */
    #[Test]
    public function un_avviso_per_tutti_arriva(): void
    {
        $this->avviso();
        $tizio = $this->persona('tizio@esempio.test');

        $this->actingAs($tizio, 'sanctum')
            ->getJson('/api/v1/avvisi/prossimo')
            ->assertOk()
            ->assertJsonPath('data.titolo', 'Una cosa da dirti')
            ->assertJsonPath('data.tasto_rifiuta', 'No, grazie');
    }

    /**
     * 🚨 **A chi non è nel pubblico non arriva.**
     *
     * ⚠️ Il bersaglio è un ruolo: chi non ce l'ha non deve vedere niente. ⛔ Un
     * avviso che arriva a tutti «per sbaglio» non dà nessun errore — arriva e
     * basta, e chi lo riceve non ha modo di sapere che non era per lui.
     */
    #[Test]
    public function a_chi_e_fuori_dal_pubblico_non_arriva(): void
    {
        $avviso = $this->avviso();
        $avviso->bersagli()->delete();
        $avviso->bersagli()->create([
            'tipo' => TipoBersaglio::Ruolo,
            'ruolo' => UserRole::FreeTrainer->value,
        ]);

        $tizio = $this->persona('tizio@esempio.test');

        $this->actingAs($tizio, 'sanctum')
            ->getJson('/api/v1/avvisi/prossimo')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /** ⛔ E uno spento non arriva a nessuno. */
    #[Test]
    public function uno_spento_non_arriva(): void
    {
        $this->avviso(['attivo' => false]);
        $tizio = $this->persona('tizio@esempio.test');

        $this->actingAs($tizio, 'sanctum')
            ->getJson('/api/v1/avvisi/prossimo')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /** ⛔ E nemmeno uno scaduto: letto tardi, un avviso non è un avviso. */
    #[Test]
    public function uno_scaduto_non_arriva(): void
    {
        $this->avviso(['scade_il' => now()->subDay()]);
        $tizio = $this->persona('tizio@esempio.test');

        $this->actingAs($tizio, 'sanctum')
            ->getJson('/api/v1/avvisi/prossimo')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /**
     * ⛔ **Un avviso rotto non si manda**: `link` senza indirizzo apre il vuoto.
     *
     * 🚨 Meglio non mandarlo che mandarlo rotto: chi lo riceve non ha modo di
     * capire che l'errore non è suo.
     */
    #[Test]
    public function un_avviso_senza_il_suo_bersaglio_non_si_manda(): void
    {
        $this->avviso(['azione' => AzioneDellAvviso::Link, 'url' => null]);
        $tizio = $this->persona('tizio@esempio.test');

        $this->actingAs($tizio, 'sanctum')
            ->getJson('/api/v1/avvisi/prossimo')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    // ───────────────── uno per volta, e una volta sola ─────────────────

    /**
     * 🚨 **Uno per volta.** ⛔ Due modali in fila all'avvio non sono un avviso,
     * sono un pedaggio — e chi si trova davanti una coda non legge: preme il
     * primo pulsante per arrivare all'app.
     */
    #[Test]
    public function ne_arriva_uno_solo_per_volta(): void
    {
        $primo = $this->avviso(['titolo' => 'Il primo']);
        $this->avviso(['titolo' => 'Il secondo']);

        $tizio = $this->persona('tizio@esempio.test');

        $risposta = $this->actingAs($tizio, 'sanctum')
            ->getJson('/api/v1/avvisi/prossimo')
            ->assertOk()
            ->json('data');

        $this->assertSame($primo->getKey(), $risposta['id'], 'Deve arrivare il più vecchio.');
    }

    /**
     * 🚨 **Deciso una volta, non ricompare** — e la memoria sta **sul server**:
     * chi entra dal secondo dispositivo non se la deve ripigliare.
     */
    #[Test]
    public function una_volta_deciso_non_ricompare(): void
    {
        $avviso = $this->avviso();
        $tizio = $this->persona('tizio@esempio.test');

        $this->actingAs($tizio, 'sanctum')
            ->postJson("/api/v1/avvisi/{$avviso->getKey()}/decisione", ['decisione' => 'rifiutato'])
            ->assertOk();

        $this->actingAs($tizio, 'sanctum')
            ->getJson('/api/v1/avvisi/prossimo')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /** 🗳️ **Anche il rifiuto si registra**: il numero dei no è metà del motivo per cui si manda un avviso. */
    #[Test]
    public function anche_il_rifiuto_lascia_una_riga(): void
    {
        $avviso = $this->avviso();
        $tizio = $this->persona('tizio@esempio.test');

        $this->actingAs($tizio, 'sanctum')
            ->postJson("/api/v1/avvisi/{$avviso->getKey()}/decisione", ['decisione' => 'rifiutato'])
            ->assertOk();

        $this->assertDatabaseHas('avvisi_decisioni', [
            'avviso_id' => $avviso->getKey(),
            'user_id' => $tizio->getKey(),
            'decisione' => AvvisoDecisione::RIFIUTATO,
        ]);
    }

    /**
     * ⛔ **Una decisione non si cambia.**
     *
     * 🚨 Senza questa guardia, ripremere «approva» su un avviso con azione
     * `promo` regalerebbe la promozione una seconda volta — e la terza.
     */
    #[Test]
    public function non_si_decide_due_volte(): void
    {
        $avviso = $this->avviso();
        $tizio = $this->persona('tizio@esempio.test');

        $this->actingAs($tizio, 'sanctum')
            ->postJson("/api/v1/avvisi/{$avviso->getKey()}/decisione", ['decisione' => 'approvato'])
            ->assertOk();

        $this->actingAs($tizio, 'sanctum')
            ->postJson("/api/v1/avvisi/{$avviso->getKey()}/decisione", ['decisione' => 'approvato'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'gia_deciso');
    }

    // ───────────────── il pannello ─────────────────

    /**
     * 🖥️ **La pagina esiste e si apre**, e mostra i numeri.
     *
     * 💡 Prima che qualcuno l'abbia visto, la scheda dice **quanti lo
     * prenderebbero**: è il numero che serve **prima** di accendere, e
     * `ChiLaRiceve::destinatari()` lo sapeva già fare.
     */
    #[Test]
    public function il_pannello_elenca_gli_avvisi_e_i_loro_numeri(): void
    {
        $this->avviso(['titolo' => 'Una cosa da dirti']);
        $this->persona('tizio@esempio.test');

        // ⚠️ L'aiutante di casa: il ruolo `super_admin` vive senza tenant, e
        //    crearlo a mano qui vorrebbe dire riscrivere quella regola.
        $god = $this->creaSuperAdmin();

        Filament::setCurrentPanel('god');
        $this->actingAs($god->fresh());

        Livewire::test(ListAvvisi::class)
            ->assertSuccessful()
            ->assertSee('Una cosa da dirti')
            ->assertSee('lo prenderebbero');
    }

    /** ⛔ E non la vede nessun altro: gli avvisi li manda la piattaforma. */
    #[Test]
    public function il_pannello_degli_avvisi_e_solo_di_god(): void
    {
        $tizio = $this->persona('tizio@esempio.test');

        $this->actingAs($tizio);

        $this->assertFalse(AvvisoResource::canAccess());
    }

    /**
     * 📲 **Accendendolo dal pannello parte la push** — committente, 24/09:
     * *«sì, gli avvisi devono mandare anche push»*.
     *
     * ⚠️ **Solo quando si accende**: correggere un refuso in un avviso già
     * acceso non deve rimandare la notifica a tutti.
     *
     * ⏳ Il lavoro va in coda e oggi **non manda niente**: Firebase non c'è
     * ancora. 💡 Il test guarda che sia stato **accodato**, non che sia
     * arrivata una notifica — sarebbe provare una cosa che non esiste.
     */
    #[Test]
    public function accendendo_un_avviso_si_accoda_la_push(): void
    {
        Queue::fake();

        $avviso = $this->avviso(['attivo' => false, 'anche_push' => true]);
        $god = $this->creaSuperAdmin();

        Filament::setCurrentPanel('god');
        $this->actingAs($god->fresh());

        Livewire::test(EditAvviso::class, ['record' => $avviso->getKey()])
            ->fillForm(['attivo' => true])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertPushed(MandaLAvvisoInPush::class);
    }

    /** ⛔ E salvando di nuovo un avviso già acceso **non** si rimanda niente. */
    #[Test]
    public function risalvare_un_avviso_acceso_non_rimanda_la_push(): void
    {
        $avviso = $this->avviso(['attivo' => true, 'anche_push' => true]);
        $god = $this->creaSuperAdmin();

        Filament::setCurrentPanel('god');
        $this->actingAs($god->fresh());

        Queue::fake();

        Livewire::test(EditAvviso::class, ['record' => $avviso->getKey()])
            ->fillForm(['titolo' => 'Un refuso corretto'])
            ->call('save')
            ->assertHasNoFormErrors();

        Queue::assertNothingPushed();
    }

    // ───────────────── il tetto di frequenza ─────────────────

    /**
     * 📅 **Quattro al mese**, e il quinto aspetta — decisione mia del 24/09, su
     * mandato del committente (*«mi fido di te»*).
     *
     * ⚠️ Si contano quelli **mostrati**: un avviso mai visto non ha dato
     * fastidio a nessuno.
     */
    #[Test]
    public function oltre_il_tetto_mensile_non_ne_arrivano_altri(): void
    {
        config(['avvisi.al_mese' => 2, 'avvisi.ore_fra_due' => 0]);

        $this->avviso(['titolo' => 'Uno']);
        $this->avviso(['titolo' => 'Due']);
        $this->avviso(['titolo' => 'Tre']);

        $tizio = $this->persona('tizio@esempio.test');
        $quale = app(QualeAvvisoMostrare::class);

        /*
         * ⚠️ **Si decide fra uno e l'altro**, o non si arriva nemmeno al
         * secondo: finché una modale non ha risposta è **quella** che torna, e
         * il tetto non lo si tocca. 💡 Lo prova il test qui sotto.
         */
        $this->rispondiA($tizio, $quale->per($tizio));
        $this->rispondiA($tizio, $quale->per($tizio));

        $this->assertNull($quale->per($tizio), '🚨 Il tetto mensile non difende da niente.');
    }

    /**
     * 🚨 **Finché non decidi, è sempre quello.**
     *
     * ⚠️ È la regola che rende il tetto quasi superfluo nei casi normali: non
     * si arriva al secondo avviso senza aver risposto al primo. ⛔ E una modale
     * che sparisce da sola sarebbe peggio: chi l'ha chiusa per sbaglio non la
     * rivedrebbe mai più.
     */
    #[Test]
    public function finche_non_decidi_torna_sempre_lo_stesso(): void
    {
        config(['avvisi.al_mese' => 10, 'avvisi.ore_fra_due' => 0]);

        $primo = $this->avviso(['titolo' => 'Il primo']);
        $this->avviso(['titolo' => 'Il secondo']);

        $tizio = $this->persona('tizio@esempio.test');
        $quale = app(QualeAvvisoMostrare::class);

        $this->assertSame($primo->getKey(), $quale->per($tizio)?->getKey());
        $this->assertSame($primo->getKey(), $quale->per($tizio)?->getKey());
    }

    /**
     * ⏳ **E c'è una distanza minima**: senza, quattro avvisi arrivano tutti lo
     * stesso pomeriggio e il tetto mensile non se ne accorge.
     */
    #[Test]
    public function fra_due_avvisi_passa_del_tempo(): void
    {
        config(['avvisi.al_mese' => 10, 'avvisi.ore_fra_due' => 24]);

        $this->avviso(['titolo' => 'Uno']);
        $this->avviso(['titolo' => 'Due']);

        $tizio = $this->persona('tizio@esempio.test');
        $quale = app(QualeAvvisoMostrare::class);

        $this->rispondiA($tizio, $quale->per($tizio));

        $this->assertNull($quale->per($tizio), '🚨 Due avvisi nello stesso minuto: è una raffica.');
    }

    /**
     * 🚨 **L'urgente scavalca il tetto.**
     *
     * ⚠️ Un avviso di servizio — *«oggi non si riesce ad accedere»* — non può
     * essere buttato via da un limite pensato per le promozioni.
     */
    #[Test]
    public function un_avviso_urgente_passa_lo_stesso(): void
    {
        config(['avvisi.al_mese' => 1, 'avvisi.ore_fra_due' => 24]);

        $this->avviso(['titolo' => 'Una promozione qualunque']);
        $this->avviso(['titolo' => 'Servizio interrotto', 'urgente' => true]);

        $tizio = $this->persona('tizio@esempio.test');
        $quale = app(QualeAvvisoMostrare::class);

        // ⚠️ L'urgente arriva **per primo**: in coda dietro tre promozioni
        //    arriverebbe fra tre giorni, cioè quando non serve più.
        $urgente = $quale->per($tizio);
        $this->assertSame('Servizio interrotto', $urgente?->titolo);

        $this->rispondiA($tizio, $urgente);

        // ⚠️ E adesso il tetto morde: l'altro avviso non è urgente e aspetta.
        $this->assertNull($quale->per($tizio->fresh()));
    }

    /** 💡 Riaprire l'app dieci volte non consuma dieci posti del tetto. */
    #[Test]
    public function riaprire_l_app_non_consuma_il_tetto(): void
    {
        config(['avvisi.al_mese' => 2, 'avvisi.ore_fra_due' => 0]);

        $avviso = $this->avviso();
        $tizio = $this->persona('tizio@esempio.test');
        $quale = app(QualeAvvisoMostrare::class);

        $quale->per($tizio);
        $quale->per($tizio);
        $quale->per($tizio);

        $this->assertSame(
            1,
            AvvisoDecisione::query()->where('avviso_id', $avviso->getKey())->count(),
            'Lo stesso avviso mostrato tre volte ha creato tre righe: il tetto si esaurirebbe da solo.',
        );
    }
}
