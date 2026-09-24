<?php

declare(strict_types=1);

namespace Tests\Feature\Account;

use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\User;
use App\Services\Account\AccountEraser;
use App\Services\Account\IlNomeUtente;
use App\Services\Chat\CancelloDellaChat;
use App\Services\Chat\ChiHoBloccato;
use App\Services\Chat\Permesso;
use App\Services\Tenancy\CreaTenantPersonale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🆔 **Il nome utente diventa un nome pubblico** — blocco B3, 24/09/2026.
 *
 * 📌 Il committente, il 23/09: *«bisognerà inventarsi un sistema di username per
 * poter scrivere a chiunque, così una palestra o un trainer possono invitare
 * direttamente un allievo o un trainer da app a app»*.
 *
 * ══ 🚨 QUI SBAGLIARE NON NASCONDE UNA PERSONA: NE ESPONE UNA ════════════════
 *
 * ⚠️ È la differenza con ogni altro elenco del progetto. Se `ChiPossoSeguire`
 * sbaglia, un trainer non vede un allievo e se ne accorge. Se **questo** cancello
 * sbaglia, qualcuno che aveva chiesto di non farsi trovare **viene trovato**, e
 * non se ne accorge nessuno.
 *
 * 💡 Per questo i test guardano soprattutto **chi NON si deve vedere**.
 */
final class IlNomeUtentePubblicoTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private const PAROLA = 'Prova-2409-nomi';

    private function nomi(): IlNomeUtente
    {
        return app(IlNomeUtente::class);
    }

    /** Una persona qualunque, col suo spazio. */
    private function persona(string $email, string $nome, UserRole $ruolo = UserRole::FreeUser): User
    {
        $utente = app(CreaTenantPersonale::class)(
            ucfirst(explode('@', $email)[0]),
            $email,
            ['password' => self::PAROLA, 'username' => $nome],
            $ruolo,
        );

        return $utente->fresh();
    }

    /** ⚠️ Trovabile **a mano**: di serie è spenta, ed è metà del punto. */
    private function trovabile(User $utente): User
    {
        $utente->forceFill(['trovabile' => true])->save();

        return $utente->fresh();
    }

    // ───────────────── il nome: facoltativo, e non si ricicla ─────────────────

    /**
     * 📌 Decisione 53.5a: **facoltativo**.
     *
     * ⚠️ Non è un allentamento, è un allineamento: era `required` nel modulo di
     * registrazione, ma chi nasce da un invito o dal pannello quel modulo non lo
     * vede — e infatti **18 utenti su 38** non ce l'avevano.
     */
    #[Test]
    public function ci_si_registra_anche_senza_nome_utente(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Senza Nome',
            'email' => 'senza@esempio.test',
            'password' => self::PAROLA,
            'password_confirmation' => self::PAROLA,
            'tenant_id' => $palestra->getKey(),
            'age_confirmed' => true,
            'terms_accepted' => true,
        ])->assertStatus(201);

        $this->assertNull(
            User::withoutGlobalScopes()->where('email', 'senza@esempio.test')->value('username'),
        );
    }

    /**
     * 🔥 **Il vecchio nome si brucia**, non torna libero — decisione 53.4.
     *
     * ⛔ Altrimenti basterebbe cambiare nome per liberare il proprio, farlo
     * prendere a un altro, e dirottargli addosso i messaggi che cercavano noi.
     */
    #[Test]
    public function cambiando_nome_il_vecchio_si_brucia(): void
    {
        $io = $this->persona('io@esempio.test', 'vecchio');

        $this->actingAs($io, 'sanctum')
            ->patchJson('/api/v1/account/nome-utente', ['username' => 'nuovo'])
            ->assertOk()
            ->assertJsonPath('data.username', 'nuovo');

        $this->assertDatabaseHas('nomi_utente_bruciati', ['nome' => 'vecchio']);

        // ⛔ E nessun altro se lo può prendere.
        $altro = $this->persona('altro@esempio.test', 'altro');

        $this->actingAs($altro, 'sanctum')
            ->patchJson('/api/v1/account/nome-utente', ['username' => 'vecchio'])
            ->assertStatus(422);
    }

    /**
     * 📅 **Una volta ogni 30 giorni** — decisione 53.5b.
     *
     * 💡 Cambiarlo di continuo è il modo di sfuggire a chi ti ha bloccato.
     */
    #[Test]
    public function non_si_cambia_due_volte_nello_stesso_mese(): void
    {
        $io = $this->persona('io@esempio.test', 'primo');

        $this->actingAs($io, 'sanctum')
            ->patchJson('/api/v1/account/nome-utente', ['username' => 'secondo'])
            ->assertOk();

        $this->actingAs($io->fresh(), 'sanctum')
            ->patchJson('/api/v1/account/nome-utente', ['username' => 'terzo'])
            ->assertStatus(422);

        $this->assertSame('secondo', $io->fresh()->username);
    }

    /** 💡 Ma chi non l'ha mai cambiato lo può fare **subito**: la finestra parte dal primo cambio. */
    #[Test]
    public function chi_non_l_ha_mai_cambiato_lo_puo_fare_subito(): void
    {
        $io = $this->persona('io@esempio.test', 'iniziale');

        $this->actingAs($io, 'sanctum')
            ->getJson('/api/v1/account/nome-utente')
            ->assertOk()
            ->assertJsonPath('data.puo_cambiare', true);
    }

    /**
     * 🚨 **Anche cancellando l'account il nome si brucia.**
     *
     * ⛔ `AccountEraser` mette `username = null`: fino al 24/09 quel nome
     * tornava libero **nel momento peggiore**, cioè quando la persona che lo
     * usava non c'è più per accorgersene.
     */
    #[Test]
    public function cancellando_l_account_il_nome_resta_bruciato(): void
    {
        $io = $this->persona('io@esempio.test', 'sparisco');

        app(AccountEraser::class)->erase($io);

        $this->assertDatabaseHas('nomi_utente_bruciati', ['nome' => 'sparisco']);

        $altro = $this->persona('altro@esempio.test', 'altro');

        $this->actingAs($altro, 'sanctum')
            ->patchJson('/api/v1/account/nome-utente', ['username' => 'sparisco'])
            ->assertStatus(422);
    }

    // ───────────────── l'interruttore ─────────────────

    /** ⛔ **Spento di serie** — decisione 53.1. */
    #[Test]
    public function la_trovabilita_nasce_spenta(): void
    {
        $io = $this->persona('io@esempio.test', 'tizio');

        $this->assertFalse((bool) $io->trovabile);

        $this->actingAs($io, 'sanctum')
            ->getJson('/api/v1/account/nome-utente')
            ->assertOk()
            ->assertJsonPath('data.trovabile', false);
    }

    /**
     * ⚠️ **Accenderla senza un nome non si può**: sarebbe un interruttore che
     * non fa niente, e chi lo accende crederebbe di essere trovabile.
     */
    #[Test]
    public function non_ci_si_fa_trovare_senza_un_nome(): void
    {
        $senza = app(CreaTenantPersonale::class)('Senza', 'senza@esempio.test', [
            'password' => self::PAROLA,
        ])->fresh();

        $this->actingAs($senza, 'sanctum')
            ->patchJson('/api/v1/account/trovabile', ['trovabile' => true])
            ->assertStatus(422)
            ->assertJsonPath('code', 'serve_il_nome_utente');

        $this->assertFalse((bool) $senza->fresh()->trovabile);
    }

    // ───────────────── la ricerca: il cancello ─────────────────

    /** ✅ Un **trainer** trovabile lo trova chiunque — decisione 53.2. */
    #[Test]
    public function un_trainer_trovabile_lo_trova_chiunque(): void
    {
        $trainer = $this->trovabile($this->persona('coach@esempio.test', 'ilcoach', UserRole::FreeTrainer));
        $chiunque = $this->persona('tizio@esempio.test', 'tizio');

        $this->actingAs($chiunque, 'sanctum')
            ->getJson('/api/v1/persone/cerca?nome=ilcoach')
            ->assertOk()
            ->assertJsonPath('data.0.id', $trainer->getKey());
    }

    /** ⛔ Ma se non si è fatto trovare, non lo trova nessuno. */
    #[Test]
    public function chi_non_si_fa_trovare_non_si_trova(): void
    {
        $this->persona('coach@esempio.test', 'ilcoach', UserRole::FreeTrainer);
        $chiunque = $this->persona('tizio@esempio.test', 'tizio');

        $this->actingAs($chiunque, 'sanctum')
            ->getJson('/api/v1/persone/cerca?nome=ilcoach')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * 🚨 **Il caso che conta: un iscritto NON si trova.**
     *
     * 📌 *«Gli utenti individuali possono essere cercati solo dai loro trainer o
     * dalle loro palestre»*. ⛔ Qui il trainer c'è, l'iscritto pure, ma non si
     * conoscono: non si devono trovare.
     */
    #[Test]
    public function un_iscritto_qualunque_non_lo_trova_un_trainer_estraneo(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');
        $iscritto->forceFill(['username' => 'iscritto', 'trovabile' => true])->save();

        $estraneo = $this->trovabile($this->persona('coach@esempio.test', 'ilcoach', UserRole::FreeTrainer));

        $this->actingAs($estraneo, 'sanctum')
            ->getJson('/api/v1/persone/cerca?nome=iscritto')
            ->assertOk()
            ->assertJsonCount(0, 'data', '🚨 Un trainer estraneo trova un iscritto che non è suo.');
    }

    /** ✅ Lo trova invece **il suo** trainer. */
    #[Test]
    public function un_allievo_lo_trova_il_suo_trainer(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $allievo = $this->creaUtente($palestra, UserRole::Member, 'allievo@olimpo.test');
        $allievo->forceFill(['username' => 'allievo', 'trovabile' => true])->save();

        $coach = $this->trovabile($this->persona('coach@esempio.test', 'ilcoach', UserRole::FreeTrainer));

        $allievo->assignedTrainers()->attach($coach->getKey(), [
            'tenant_id' => $coach->tenant_id,
            'assigned_at' => now(),
            'assigned_by' => $coach->getKey(),
        ]);

        $this->actingAs($coach->fresh(), 'sanctum')
            ->getJson('/api/v1/persone/cerca?nome=allievo')
            ->assertOk()
            ->assertJsonPath('data.0.id', $allievo->getKey());
    }

    /** ✅ E lo trova **il titolare della sua palestra** — che non «allena» nessuno. */
    #[Test]
    public function un_iscritto_lo_trova_il_titolare_della_sua_palestra(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');

        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');
        $iscritto->forceFill(['username' => 'iscritto', 'trovabile' => true])->save();

        $titolare = $this->creaUtente($palestra, UserRole::GymAdmin, 'admin@olimpo.test');

        $this->actingAs($titolare->fresh(), 'sanctum')
            ->getJson('/api/v1/persone/cerca?nome=iscritto')
            ->assertOk()
            ->assertJsonPath('data.0.id', $iscritto->getKey());
    }

    /** ⛔ Sotto le tre lettere non si cerca: due lettere sono mille persone. */
    #[Test]
    public function con_due_lettere_non_si_cerca(): void
    {
        $this->trovabile($this->persona('coach@esempio.test', 'ilcoach', UserRole::FreeTrainer));
        $chiunque = $this->persona('tizio@esempio.test', 'tizio');

        $this->actingAs($chiunque, 'sanctum')
            ->getJson('/api/v1/persone/cerca?nome=il')
            ->assertStatus(422);
    }

    // ───────────────── il blocco ─────────────────

    /**
     * 🚫 **Il blocco fa due cose**, e la seconda si dimentica: non ti scrive
     * **e non ti trova più**.
     *
     * ⛔ Senza la seconda sarebbe una porta chiusa accanto a una aperta.
     */
    #[Test]
    public function chi_e_bloccato_non_ti_trova_piu(): void
    {
        $coach = $this->trovabile($this->persona('coach@esempio.test', 'ilcoach', UserRole::FreeTrainer));
        $molesto = $this->persona('molesto@esempio.test', 'molesto');

        $this->actingAs($molesto, 'sanctum')
            ->getJson('/api/v1/persone/cerca?nome=ilcoach')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($coach, 'sanctum')
            ->postJson("/api/v1/persone/{$molesto->getKey()}/blocca")
            ->assertOk();

        $this->actingAs($molesto, 'sanctum')
            ->getJson('/api/v1/persone/cerca?nome=ilcoach')
            ->assertOk()
            ->assertJsonCount(0, 'data', '🚨 Chi è stato bloccato trova ancora chi l\'ha bloccato.');
    }

    /** ⚠️ E vale **nei due versi**: nemmeno chi blocca trova il bloccato. */
    #[Test]
    public function il_blocco_vale_nei_due_versi(): void
    {
        $coach = $this->trovabile($this->persona('coach@esempio.test', 'ilcoach', UserRole::FreeTrainer));
        $altro = $this->trovabile($this->persona('altro@esempio.test', 'altrocoach', UserRole::FreeTrainer));

        app(ChiHoBloccato::class)->blocca($coach, $altro);

        $this->actingAs($coach->fresh(), 'sanctum')
            ->getJson('/api/v1/persone/cerca?nome=altrocoach')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** Sbloccare rimette le cose come prima, per la ricerca. */
    #[Test]
    public function sbloccare_lo_rende_di_nuovo_trovabile(): void
    {
        $coach = $this->trovabile($this->persona('coach@esempio.test', 'ilcoach', UserRole::FreeTrainer));
        $tizio = $this->persona('tizio@esempio.test', 'tizio');

        app(ChiHoBloccato::class)->blocca($coach, $tizio);

        $this->actingAs($coach->fresh(), 'sanctum')
            ->deleteJson("/api/v1/persone/{$tizio->getKey()}/blocca")
            ->assertOk();

        $this->actingAs($tizio, 'sanctum')
            ->getJson('/api/v1/persone/cerca?nome=ilcoach')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** L'elenco di chi ho bloccato. */
    #[Test]
    public function si_vede_chi_si_e_bloccato(): void
    {
        $io = $this->persona('io@esempio.test', 'ioio');
        $tizio = $this->persona('tizio@esempio.test', 'tizio');

        app(ChiHoBloccato::class)->blocca($io, $tizio);

        $this->actingAs($io, 'sanctum')
            ->getJson('/api/v1/account/bloccati')
            ->assertOk()
            ->assertJsonPath('data.0.id', $tizio->getKey());
    }

    /**
     * 🚨 **E il blocco chiude anche la chat**, che è la metà che ci si aspetta.
     *
     * ⚠️ Sta **prima** del limite dei tre messaggi, come il canale chiuso: dire
     * «ti restano due messaggi» a chi è bloccato sarebbe una bugia, e per di più
     * una bugia che lo invita a riprovare.
     *
     * ⛔ E il messaggio è **lo stesso nei due versi**: chi è stato bloccato non
     * deve poterlo dedurre dalla risposta del server, o sarebbe la conferma di
     * aver colpito nel segno.
     */
    #[Test]
    public function chi_e_bloccato_non_puo_piu_scrivere(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $coach = $this->creaUtente($palestra, UserRole::Trainer, 'coach@olimpo.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');

        $filo = Conversation::between($coach, $iscritto);

        $permesso = app(CancelloDellaChat::class)->puoScrivere($iscritto, $filo);
        $this->assertTrue($permesso->consentito, 'Senza blocco non doveva esserci nessun ostacolo.');

        app(ChiHoBloccato::class)->blocca($coach, $iscritto);

        $dopo = app(CancelloDellaChat::class)->puoScrivere($iscritto->fresh(), $filo);

        $this->assertFalse($dopo->consentito, '🚨 Il blocco non impedisce di scrivere: è metà blocco.');
        $this->assertSame(Permesso::BLOCCATO, $dopo->codice);
    }

    // ───────────────── dalla ricerca alla conversazione ─────────────────

    /**
     * 🔎➡️💬 **Dalla persona trovata si deve poter scrivere**, o la ricerca non
     * porta da nessuna parte.
     *
     * ⚠️ Si apre un filo **di informazioni**: tre messaggi di presentazione, il
     * limite che esisteva già. 💡 Da lì il trainer può mandare un invito in
     * chat — la strada «da app a app» che il committente chiedeva, e che
     * esisteva già **tranne** il modo di trovarsi.
     */
    #[Test]
    public function da_una_persona_trovata_si_apre_una_conversazione(): void
    {
        $coach = $this->trovabile($this->persona('coach@esempio.test', 'ilcoach', UserRole::FreeTrainer));
        $tizio = $this->persona('tizio@esempio.test', 'tizio');

        $this->actingAs($tizio, 'sanctum')
            ->postJson('/api/v1/conversations/da-nome-utente', ['utente_id' => $coach->getKey()])
            ->assertStatus(201)
            ->assertJsonPath('data.con.username', 'ilcoach')
            ->assertJsonPath('data.restanti', 3);
    }

    /**
     * ⛔ **E con chi non si può trovare non si può nemmeno scrivere**: 404,
     * uguale a «non esiste».
     *
     * 🚨 Distinguere «esiste ma non lo puoi trovare» da «non esiste» renderebbe
     * questa rotta un modo per scoprire chi c'è, una prova alla volta.
     */
    #[Test]
    public function a_chi_non_si_puo_trovare_non_si_puo_scrivere(): void
    {
        $palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@olimpo.test');
        $iscritto->forceFill(['username' => 'iscritto', 'trovabile' => true])->save();

        $estraneo = $this->persona('coach@esempio.test', 'ilcoach', UserRole::FreeTrainer);

        $this->actingAs($estraneo, 'sanctum')
            ->postJson('/api/v1/conversations/da-nome-utente', ['utente_id' => $iscritto->getKey()])
            ->assertStatus(404);
    }

    /** ⛔ E bloccare due volte non crea un secondo blocco. */
    #[Test]
    public function bloccare_due_volte_non_e_un_secondo_blocco(): void
    {
        $io = $this->persona('io@esempio.test', 'ioio');
        $tizio = $this->persona('tizio@esempio.test', 'tizio');

        $this->actingAs($io, 'sanctum')->postJson("/api/v1/persone/{$tizio->getKey()}/blocca")->assertOk();
        $this->actingAs($io, 'sanctum')->postJson("/api/v1/persone/{$tizio->getKey()}/blocca")->assertOk();

        $this->assertSame(1, DB::table('blocchi')->count());
    }
}
