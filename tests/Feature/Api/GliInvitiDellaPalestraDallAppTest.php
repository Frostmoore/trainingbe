<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\UserRole;
use App\Mail\InvitoInPalestraMail;
use App\Models\InvitoInPalestra;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiInPalestra;
use Database\Seeders\PlanSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🏋️ **Gli inviti di una palestra, dall'app** — blocco B2, 24/09/2026.
 *
 * 📌 Il committente, il 23/09: *«trainer e palestre possono proprio fare gli
 * inviti direttamente dall'app… devono poter creare il link di invito e
 * inviarlo per mail o copiarlo»*. B1 ha fatto la metà del trainer; questa è la
 * metà della palestra, e chiude la voce 11.
 *
 * ══ 🚨 COSA GUARDANO QUESTI TEST, OLTRE AI GEMELLI DI B1 ═══════════════════
 *
 * ⛔ **Le tre cose che qui possono andare storte e là non esistevano:**
 *
 * | Rischio | Perché è caro |
 * |---|---|
 * | i due posti si confondono | «restano 4» detto a chi ha finito i posti trainer è un numero vero che risponde alla domanda sbagliata |
 * | la mail **non parte** | `InvitiInPalestra::invita()` non spedisce: chi copia il gemello si ritrova inviti che non arrivano a nessuno, senza nessun errore |
 * | il cancello lascia passare | un trainer che invita trainer si nomina i colleghi da solo |
 */
final class GliInvitiDellaPalestraDallAppTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $gestore;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PlanSeeder::class);

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo');
        $this->gestore = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'gestore@olimpo.it');

        Mail::fake();
    }

    private function inviti(): InvitiInPalestra
    {
        return app(InvitiInPalestra::class);
    }

    /** Quanti posti dichiara il piano, per poterli finire senza creare cento persone. */
    private function posti(int $iscritti, int $trainer): void
    {
        Plan::query()->where('code', Plan::GYM)->firstOrFail()
            ->forceFill(['max_members' => $iscritti, 'max_trainers' => $trainer])
            ->save();
    }

    // ───────────────────── B2.1 — l'elenco ─────────────────────

    #[Test]
    public function il_gestore_vede_gli_inviti_della_sua_palestra(): void
    {
        $this->inviti()->invita($this->palestra, $this->gestore, 'mario@esempio.test');

        $dati = $this->actingAs($this->gestore, 'sanctum')
            ->getJson('/api/v1/gym/invites')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $dati);
        $this->assertSame('mario@esempio.test', $dati[0]['email']);
        $this->assertSame('iscritto', $dati[0]['ruolo']);
    }

    /**
     * ⛔ **Gli inviti di un'altra palestra non si vedono.**
     *
     * 🚨 Il modello è scopato per tenant, ma l'elenco gira con
     * `withoutGlobalScopes()` per non essere silenziosamente parziale: è
     * **questo filtro esplicito** a tenere separate le due palestre, e se
     * qualcuno lo toglie deve cadere qui.
     */
    #[Test]
    public function non_vede_gli_inviti_di_unaltra_palestra(): void
    {
        $altra = $this->creaPalestra('Rivale', 'rivale');
        $suoGestore = $this->creaUtente($altra, UserRole::GymAdmin, 'gestore@rivale.it');

        $this->inviti()->invita($altra, $suoGestore, 'loro@esempio.test');

        $dati = $this->actingAs($this->gestore, 'sanctum')
            ->getJson('/api/v1/gym/invites')
            ->assertOk()
            ->json('data');

        $this->assertSame([], $dati);
    }

    /** Un invito revocato non è più «in sospeso»: non tiene posti e non si mostra. */
    #[Test]
    public function un_invito_revocato_sparisce_dallelenco(): void
    {
        $invito = $this->inviti()->invita($this->palestra, $this->gestore, 'mario@esempio.test');
        $this->inviti()->revoca($invito);

        $dati = $this->actingAs($this->gestore, 'sanctum')
            ->getJson('/api/v1/gym/invites')
            ->assertOk()
            ->json('data');

        $this->assertSame([], $dati);
    }

    /**
     * 🚨 **I due contatori sono separati, e si muovono uno per volta.**
     *
     * ⛔ Il difetto che questo test rende impossibile: sommarli. Con un totale
     * unico, una palestra con i posti trainer finiti e quattro posti iscritti
     * liberi leggerebbe «restano 4» e non capirebbe perché non può invitare un
     * trainer.
     */
    #[Test]
    public function i_due_contatori_sono_separati(): void
    {
        $this->posti(iscritti: 10, trainer: 3);

        $this->inviti()->invita($this->palestra, $this->gestore, 'coach@esempio.test', UserRole::Trainer);

        $meta = $this->actingAs($this->gestore, 'sanctum')
            ->getJson('/api/v1/gym/invites')
            ->assertOk()
            ->json('meta');

        $this->assertSame(10, $meta['iscritti']['totali']);
        $this->assertSame(3, $meta['trainer']['totali']);

        // 💡 Il gestore occupa un posto? No: `gym_admin` non è né iscritto né
        //    trainer. L'unico posto consumato è quello dell'invito appena fatto.
        $this->assertSame(2, $meta['trainer']['rimasti']);
        $this->assertSame(10, $meta['iscritti']['rimasti']);
    }

    // ───────────────────── B2.1 — creare ─────────────────────

    /**
     * 🔗 **Il link lo compone il server**, e punta alla pagina della palestra.
     *
     * ⚠️ `/invito-palestra/…`, non `/invito/…`: sono due pagine diverse, e
     * quella del trainer racconta una persona invece di una palestra. 🚨 È il
     * valore che `EUnInvito::link()` legge da `PERCORSO_DEL_LINK`, e questo test
     * è quello che si accorge se i due modelli se lo scambiano.
     */
    #[Test]
    public function creare_un_invito_restituisce_il_link_della_palestra(): void
    {
        $dati = $this->actingAs($this->gestore, 'sanctum')
            ->postJson('/api/v1/gym/invites', [])
            ->assertCreated()
            ->json('data');

        $this->assertStringContainsString('/invito-palestra/', $dati['url']);
        $this->assertStringEndsWith($dati['token'], $dati['url']);
        $this->assertSame('iscritto', $dati['ruolo']);
    }

    /** 🚨 La differenza di prodotto di tutta la fase: si invita anche un trainer. */
    #[Test]
    public function si_puo_invitare_un_trainer(): void
    {
        $dati = $this->actingAs($this->gestore, 'sanctum')
            ->postJson('/api/v1/gym/invites', ['ruolo' => 'trainer'])
            ->assertCreated()
            ->json('data');

        $this->assertSame('trainer', $dati['ruolo']);

        $invito = InvitoInPalestra::withoutGlobalScopes()->findOrFail($dati['id']);
        $this->assertSame(UserRole::Trainer, $invito->ruolo);
    }

    /**
     * ⛔ **Da un invito si entra come iscritto o come trainer, e basta.**
     *
     * 🚨 Un invito da `gym_admin` regalerebbe a chi lo riceve il comando
     * sull'abbonamento della palestra. Il servizio lo rifiuta già, ma qui
     * l'errore nomina il campo che l'app ha davvero mandato.
     */
    #[Test]
    public function non_si_invita_qualcuno_a_comandare(): void
    {
        $this->actingAs($this->gestore, 'sanctum')
            ->postJson('/api/v1/gym/invites', ['ruolo' => 'gym_admin'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ruolo');
    }

    /**
     * 📧 **Con l'indirizzo la mail parte, e la data lo dimostra.**
     *
     * ⚠️ `email_inviata` è il risultato dell'invio, non «c'era un indirizzo».
     */
    #[Test]
    public function con_lindirizzo_la_mail_parte_e_resta_scritto(): void
    {
        $dati = $this->actingAs($this->gestore, 'sanctum')
            ->postJson('/api/v1/gym/invites', ['email' => 'mario@esempio.test'])
            ->assertCreated()
            ->json('data');

        $this->assertTrue($dati['email_inviata']);

        Mail::assertSent(InvitoInPalestraMail::class);

        $invito = InvitoInPalestra::withoutGlobalScopes()->findOrFail($dati['id']);
        $this->assertNotNull($invito->email_inviata_il);
    }

    /**
     * 🚨 **UNA mail, non due** — la trappola speculare a quella di B1.
     *
     * ⛔ In B1 avevo aggiunto un invio credendo che il servizio non spedisse, e
     * ne partivano **due**. Qui il servizio davvero **non** spedisce e l'invio
     * lo fa il controller: se un giorno qualcuno lo sposta dentro `invita()`
     * per «uniformare i gemelli», questo test cade prima che la gente riceva
     * l'invito due volte.
     */
    #[Test]
    public function parte_una_mail_sola(): void
    {
        $this->actingAs($this->gestore, 'sanctum')
            ->postJson('/api/v1/gym/invites', ['email' => 'mario@esempio.test'])
            ->assertCreated();

        Mail::assertSentTimes(InvitoInPalestraMail::class, 1);
    }

    /** Senza indirizzo non si manda niente: il link si copia a mano. */
    #[Test]
    public function senza_indirizzo_non_parte_nessuna_mail(): void
    {
        $dati = $this->actingAs($this->gestore, 'sanctum')
            ->postJson('/api/v1/gym/invites', [])
            ->assertCreated()
            ->json('data');

        $this->assertFalse($dati['email_inviata']);

        Mail::assertNothingSent();
    }

    /**
     * ⚠️ **I posti si verificano PRIMA**, non al riscatto.
     *
     * ⛔ Un invito creato oltre il limite fallirebbe davanti alla persona
     * invitata, che ha già installato l'app e si becca un errore che non la
     * riguarda. Chi invita, invece, il problema può risolverlo.
     */
    #[Test]
    public function finiti_i_posti_non_si_invita_piu(): void
    {
        $this->posti(iscritti: 1, trainer: 5);

        $this->inviti()->invita($this->palestra, $this->gestore, 'primo@esempio.test');

        $this->actingAs($this->gestore, 'sanctum')
            ->postJson('/api/v1/gym/invites', ['email' => 'secondo@esempio.test'])
            ->assertStatus(422);
    }

    /** 🚨 E i posti trainer sono un limite a parte: finiscono da soli. */
    #[Test]
    public function finiti_i_posti_trainer_restano_quelli_degli_iscritti(): void
    {
        $this->posti(iscritti: 10, trainer: 1);

        $this->inviti()->invita($this->palestra, $this->gestore, 'coach@esempio.test', UserRole::Trainer);

        $this->actingAs($this->gestore, 'sanctum')
            ->postJson('/api/v1/gym/invites', ['ruolo' => 'trainer'])
            ->assertStatus(422);

        // ⚠️ L'altro limite non si è mosso: l'iscritto entra lo stesso.
        $this->actingAs($this->gestore, 'sanctum')
            ->postJson('/api/v1/gym/invites', [])
            ->assertCreated();
    }

    // ───────────────────── B2.1 — rimandare ─────────────────────

    /**
     * 📧 L'indirizzo nuovo **si scrive sull'invito**.
     *
     * ⛔ Altrimenti l'elenco continuerebbe a mostrare il vecchio, cioè una riga
     * che dice a chi è stato mandato l'invito — sbagliando.
     */
    #[Test]
    public function rimandare_a_un_altro_indirizzo_lo_aggiorna(): void
    {
        $invito = $this->inviti()->invita($this->palestra, $this->gestore, 'vecchio@esempio.test');

        $this->actingAs($this->gestore, 'sanctum')
            ->postJson("/api/v1/gym/invites/{$invito->getKey()}/email", ['email' => 'nuovo@esempio.test'])
            ->assertOk()
            ->assertJsonPath('data.email', 'nuovo@esempio.test');

        $this->assertSame('nuovo@esempio.test', $invito->fresh()?->email);
    }

    /**
     * ⛔ **Un invito morto non si rimanda.**
     *
     * 🚨 Mandare un link che non funziona fa credere che l'app sia rotta a una
     * persona che non ha fatto niente di sbagliato.
     */
    #[Test]
    public function non_si_rimanda_un_invito_revocato(): void
    {
        $invito = $this->inviti()->invita($this->palestra, $this->gestore, 'mario@esempio.test');
        $this->inviti()->revoca($invito);

        $this->actingAs($this->gestore, 'sanctum')
            ->postJson("/api/v1/gym/invites/{$invito->getKey()}/email", [])
            ->assertStatus(409);
    }

    /** Un destinatario non si inventa: senza indirizzo, 422 con il campo giusto. */
    #[Test]
    public function rimandare_senza_nessun_indirizzo_e_un_422(): void
    {
        $invito = $this->inviti()->invita($this->palestra, $this->gestore);

        $this->actingAs($this->gestore, 'sanctum')
            ->postJson("/api/v1/gym/invites/{$invito->getKey()}/email", [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    // ───────────────────── B2.1 — revocare ─────────────────────

    /**
     * 🚨 **Revocare restituisce il posto**: è il motivo per cui la rotta esiste.
     *
     * ⛔ Senza, un tocco distratto costa sette giorni di un posto pagato.
     */
    #[Test]
    public function revocare_libera_il_posto(): void
    {
        $this->posti(iscritti: 5, trainer: 5);

        $invito = $this->inviti()->invita($this->palestra, $this->gestore, 'mario@esempio.test');

        $this->assertSame(4, $this->inviti()->postiRimasti($this->palestra));

        $this->actingAs($this->gestore, 'sanctum')
            ->deleteJson("/api/v1/gym/invites/{$invito->getKey()}")
            ->assertOk();

        $this->assertSame(5, $this->inviti()->postiRimasti($this->palestra));
    }

    /**
     * ⛔ **L'id non è un permesso.**
     *
     * 🚨 Senza il filtro sul tenant, una palestra potrebbe revocare gli inviti
     * di un'altra tirando a indovinare un numero.
     */
    #[Test]
    public function non_si_revoca_linvito_di_unaltra_palestra(): void
    {
        $altra = $this->creaPalestra('Rivale', 'rivale');
        $suoGestore = $this->creaUtente($altra, UserRole::GymAdmin, 'gestore@rivale.it');

        $invito = $this->inviti()->invita($altra, $suoGestore, 'loro@esempio.test');

        $this->actingAs($this->gestore, 'sanctum')
            ->deleteJson("/api/v1/gym/invites/{$invito->getKey()}")
            ->assertStatus(404);

        $this->assertTrue($invito->fresh()?->eValido());
    }

    // ───────────────────── B2.3 — il cancello ─────────────────────

    /**
     * ⛔ **Un trainer della palestra NON invita.**
     *
     * 🚨 Non è una gerarchia per il gusto di averla: invitare consuma **posti
     * pagati dalla palestra**, e un trainer che può invitare trainer si nomina
     * i colleghi da solo. 📌 E in palestra un trainer segue già tutti gli
     * iscritti: non ha niente da guadagnare invitandone.
     */
    #[Test]
    public function un_trainer_della_palestra_non_invita(): void
    {
        $coach = $this->creaUtente($this->palestra, UserRole::Trainer, 'coach@olimpo.it');

        $this->actingAs($coach, 'sanctum')
            ->getJson('/api/v1/gym/invites')
            ->assertStatus(403);

        $this->actingAs($coach, 'sanctum')
            ->postJson('/api/v1/gym/invites', [])
            ->assertStatus(403);
    }

    /**
     * ⛔ **Un trainer indipendente non arriva qui**, benché a casa sua comandi.
     *
     * 🚨 È la ragione per cui il cancello **non** è `comandaQui()`: quello gli
     * direbbe di sì, e poi il servizio lo fermerebbe con un errore di
     * validazione su un campo `palestra` che l'app non ha mai mandato — cioè
     * una schermata che dice «correggi qualcosa» senza dire cosa.
     *
     * 💡 Lui ha già la sua pagina: quella di B1.
     */
    #[Test]
    public function un_trainer_indipendente_non_ha_una_palestra_da_riempire(): void
    {
        $indipendente = app(CreaTenantPersonale::class)(
            'Giulia',
            'giulia@esempio.test',
            ['password' => self::FAKE_PASSWORD],
            UserRole::FreeTrainer,
        );

        $this->actingAs($indipendente, 'sanctum')
            ->getJson('/api/v1/gym/invites')
            ->assertStatus(403);
    }

    /** E un iscritto qualunque, ovviamente, nemmeno. */
    #[Test]
    public function un_iscritto_non_invita_nessuno(): void
    {
        $tizio = $this->creaUtente($this->palestra, UserRole::Member, 'tizio@olimpo.it');

        $this->actingAs($tizio, 'sanctum')
            ->getJson('/api/v1/gym/invites')
            ->assertStatus(403);
    }
}
