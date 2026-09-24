<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Enums\PlanKind;
use App\Enums\UserRole;
use App\Mail\InvitoDelTrainerMail;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Services\Tenancy\InvitiDelTrainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 📨 **Gli inviti si gestiscono dall'app** — blocco B1, 24/09/2026.
 *
 * 📌 Il committente, il 23/09: *«trainer e palestre possono proprio fare gli
 * inviti direttamente dall'app, non avrebbe senso non farlo: devono poter
 * creare il link di invito e inviarlo per mail o copiarlo»*.
 *
 * ══ ⛔ IL DIFETTO CHE QUESTA FASE CHIUDE ════════════════════════════════════
 *
 * 🚨 **Un invito creato dall'app spariva.** Il link finiva negli appunti e da
 * quel momento non esisteva più in nessuna schermata: non si rivedeva, non si
 * ricopiava, non si revocava, non si sapeva a chi era stato mandato.
 *
 * ⚠️ E se prima di incollarlo si copiava qualcos'altro, **quel posto restava
 * occupato sette giorni**: `postiRimasti()` conta anche gli inviti validi, ed è
 * giusto che lo faccia — è la difesa contro chi manda trenta inviti avendo tre
 * posti. 🚨 Su un piano da 15 posti è il **7% del pagato**, perso per un tocco.
 */
final class GliInvitiDallAppTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private const PAROLA = 'Prova-2409-inviti';

    private function inviti(): InvitiDelTrainer
    {
        return app(InvitiDelTrainer::class);
    }

    /**
     * Un trainer indipendente **che paga**: da U.3.1 le rotte `/trainer/*`
     * pretendono un abbonamento in corso, e uno senza non è «un trainer
     * normale», è un trainer **moroso**.
     */
    private function unTrainer(string $email = 'trainer@esempio.test'): User
    {
        $trainer = app(CreaTenantPersonale::class)(
            'Trainer Indipendente',
            $email,
            ['password' => self::PAROLA],
            UserRole::FreeTrainer,
        );

        $piano = Plan::query()->where('code', Plan::TRAINER_10)->first()
            ?? Plan::create([
                'code' => Plan::TRAINER_10,
                'name' => 'Trainer — 10 allievi',
                'kind' => PlanKind::Trainer,
                'ai_enabled' => true,
                'max_members' => 10,
                'price_cents' => 2999,
                'is_public' => true,
            ]);

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'plan_id' => $piano->id,
            'starts_at' => now()->subYears(2),
        ]);

        return $trainer->fresh();
    }

    // ───────────────── B1.1 — l'elenco ─────────────────

    /**
     * 🚨 **Prima non esisteva nessuna rotta che li restituisse.**
     *
     * ⛔ Senza questa, qualunque schermata di elenco è impossibile: è il primo
     * pezzo della voce 11, non l'ultimo.
     */
    #[Test]
    public function gli_inviti_in_sospeso_si_possono_elencare(): void
    {
        $trainer = $this->unTrainer();

        $uno = $this->inviti()->invita($trainer, 'uno@esempio.test');
        $due = $this->inviti()->invita($trainer);

        $risposta = $this->actingAs($trainer, 'sanctum')
            ->getJson('/api/v1/trainer/invites')
            ->assertOk()
            ->json();

        $id = array_column($risposta['data'], 'id');

        $this->assertEqualsCanonicalizing([$uno->getKey(), $due->getKey()], $id);

        $primo = collect($risposta['data'])->firstWhere('id', $uno->getKey());

        $this->assertSame('uno@esempio.test', $primo['email']);
        $this->assertStringContainsString('/invito/'.$uno->token, $primo['url']);
        $this->assertNotNull($primo['scade_il']);
    }

    /**
     * ⚠️ **Solo quelli in sospeso.** Usati, revocati e scaduti non servono a chi
     * deve decidere se revocare, e su un telefono riempirebbero la lista sopra
     * le due righe che contano.
     */
    #[Test]
    public function usati_revocati_e_scaduti_restano_fuori(): void
    {
        $trainer = $this->unTrainer();

        $vivo = $this->inviti()->invita($trainer);
        $revocato = $this->inviti()->invita($trainer);
        $scaduto = $this->inviti()->invita($trainer);

        $this->inviti()->revoca($revocato);

        TrainerInvite::withoutGlobalScopes()
            ->whereKey($scaduto->getKey())
            ->update(['expires_at' => now()->subDay()]);

        $risposta = $this->actingAs($trainer, 'sanctum')
            ->getJson('/api/v1/trainer/invites')
            ->assertOk()
            ->json('data');

        $this->assertSame([$vivo->getKey()], array_column($risposta, 'id'));
    }

    /**
     * 🚨 **I posti tornano nella stessa risposta.**
     *
     * 💡 Chi guarda l'elenco per revocare sta guardando il contatore: farglielo
     * chiedere con una seconda chiamata vorrebbe dire mostrargli per un istante
     * il numero di prima.
     */
    #[Test]
    public function l_elenco_porta_con_se_i_posti(): void
    {
        $trainer = $this->unTrainer();
        $this->inviti()->invita($trainer);

        $meta = $this->actingAs($trainer, 'sanctum')
            ->getJson('/api/v1/trainer/invites')
            ->assertOk()
            ->json('meta');

        $this->assertSame(10, $meta['totali']);
        $this->assertSame(1, $meta['occupati']);
        $this->assertSame(9, $meta['rimasti']);
    }

    /** ⛔ E gli inviti di un altro trainer non si vedono. */
    #[Test]
    public function ognuno_vede_soltanto_i_propri(): void
    {
        $mio = $this->unTrainer('mio@esempio.test');
        $altrui = $this->unTrainer('altrui@esempio.test');

        $this->inviti()->invita($altrui);
        $ilMio = $this->inviti()->invita($mio);

        $dati = $this->actingAs($mio, 'sanctum')
            ->getJson('/api/v1/trainer/invites')
            ->assertOk()
            ->json('data');

        $this->assertSame([$ilMio->getKey()], array_column($dati, 'id'));
    }

    // ───────────────── B1.2 — la mail parte davvero ─────────────────

    /**
     * 📧 **Una mail sola, e l'app sa se è partita.**
     *
     * ══ 🚨 QUESTO TEST NASCE DA UN ERRORE MIO, E LO TIENE CHIUSO ════════════
     *
     * ⛔ Il 24/09 ho scritto nel piano che l'API *«accetta `email`, la salva, e
     * non manda niente»*: **falso**. Avevo letto `InvitiDelTrainer::invita()`
     * solo fino a metà, e in fondo chiama `mandaIlLink()` dal 22/09. Il mio
     * «rimedio» nel controller mandava **due mail** per un invito solo.
     *
     * 🔍 L'ha trovato un test che pretendeva silenzio, non una rilettura.
     *
     * 💡 `assertSentTimes(…, 1)` e non `assertSent`: il doppio invio passerebbe
     * il secondo, perché una delle due mail è quella giusta.
     */
    #[Test]
    public function creare_un_invito_con_l_email_manda_una_mail_sola(): void
    {
        Mail::fake();

        $trainer = $this->unTrainer();

        $risposta = $this->actingAs($trainer, 'sanctum')
            ->postJson('/api/v1/trainer/invites', ['email' => 'destinatario@esempio.test'])
            ->assertCreated()
            ->json('data');

        $this->assertTrue($risposta['email_inviata'], '🚨 L\'invito è nato ma l\'app non sa se la mail è partita.');

        Mail::assertSent(
            InvitoDelTrainerMail::class,
            fn (InvitoDelTrainerMail $m): bool => $m->hasTo('destinatario@esempio.test'),
        );

        Mail::assertSentTimes(InvitoDelTrainerMail::class, 1);
    }

    /**
     * 📅 **E l'invio si segna in tabella, non solo nella risposta.**
     *
     * 🚨 La schermata «Inviti in sospeso» arriva dopo, magari il giorno dopo:
     * senza una data in tabella dovrebbe scrivere «mandata» sempre — mentendo
     * quando la mail non è partita — o non scriverlo mai, e chi invita non
     * saprebbe se deve rimandarla.
     */
    #[Test]
    public function l_elenco_dice_quando_la_mail_e_partita(): void
    {
        Mail::fake();

        $trainer = $this->unTrainer();

        $this->actingAs($trainer, 'sanctum')
            ->postJson('/api/v1/trainer/invites', ['email' => 'destinatario@esempio.test'])
            ->assertCreated();

        $riga = $this->actingAs($trainer, 'sanctum')
            ->getJson('/api/v1/trainer/invites')
            ->assertOk()
            ->json('data.0');

        $this->assertNotNull($riga['email_inviata_il'], '🚨 L\'elenco non sa dire se la mail è partita.');
    }

    /**
     * ⛔ **E se la mail NON parte, l'invito resta valido e l'app lo sa.**
     *
     * 🚨 Far fallire la creazione perché il trasporto è giù vorrebbe dire
     * perdere un posto del piano per un guasto che non c'entra niente: il link
     * esiste già e chi invita ce l'ha. 💡 Ma `email_inviata_il` resta **vuoto**,
     * e l'elenco può dirlo invece di far credere che sia partita.
     */
    #[Test]
    public function se_la_mail_non_parte_l_invito_resta_ma_non_dice_di_esserlo(): void
    {
        Mail::fake();
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('trasporto giù'));

        $trainer = $this->unTrainer();

        $risposta = $this->actingAs($trainer, 'sanctum')
            ->postJson('/api/v1/trainer/invites', ['email' => 'destinatario@esempio.test'])
            ->assertCreated()
            ->json('data');

        $this->assertFalse($risposta['email_inviata']);

        $this->assertNull(
            TrainerInvite::withoutGlobalScopes()->findOrFail($risposta['id'])->email_inviata_il,
            'La mail non è partita ma l\'invito dice di sì: è la bugia peggiore delle due.',
        );
    }

    /** ⚠️ E senza email non parte niente, senza fingere che sia partito. */
    #[Test]
    public function senza_email_non_parte_nessuna_mail(): void
    {
        Mail::fake();

        $trainer = $this->unTrainer();

        $risposta = $this->actingAs($trainer, 'sanctum')
            ->postJson('/api/v1/trainer/invites')
            ->assertCreated()
            ->json('data');

        $this->assertFalse($risposta['email_inviata']);

        Mail::assertNothingSent();
    }

    // ───────────────── B1.3 — rimandare ─────────────────

    /**
     * 📧 **Rimandare, invece di rifare.**
     *
     * ⚠️ Un invito finito nello spam oggi si poteva solo revocare e rifare — e
     * rifarlo **consuma un altro posto** finché il primo non scade.
     */
    #[Test]
    public function un_invito_si_puo_rimandare(): void
    {
        Mail::fake();

        $trainer = $this->unTrainer();
        $invito = $this->inviti()->invita($trainer, 'primo@esempio.test');

        // ⚠️ La mail di creazione è già partita: qui si conta il RIMANDO.
        Mail::fake();

        $this->actingAs($trainer, 'sanctum')
            ->postJson("/api/v1/trainer/invites/{$invito->getKey()}/email")
            ->assertOk()
            ->assertJsonPath('data.email_inviata', true);

        Mail::assertSent(
            InvitoDelTrainerMail::class,
            fn (InvitoDelTrainerMail $m): bool => $m->hasTo('primo@esempio.test'),
        );
    }

    /**
     * 🚨 **Un indirizzo nuovo si SCRIVE sull'invito**, non si usa e basta.
     *
     * ⛔ Altrimenti l'elenco continuerebbe a mostrare il vecchio: una riga che
     * dice a chi è stato mandato l'invito, sbagliando.
     */
    #[Test]
    public function rimandandolo_a_un_altro_indirizzo_l_invito_se_lo_ricorda(): void
    {
        Mail::fake();

        $trainer = $this->unTrainer();
        $invito = $this->inviti()->invita($trainer, 'sbagliato@esempio.test');

        $this->actingAs($trainer, 'sanctum')
            ->postJson("/api/v1/trainer/invites/{$invito->getKey()}/email", [
                'email' => 'giusto@esempio.test',
            ])
            ->assertOk();

        $this->assertSame(
            'giusto@esempio.test',
            TrainerInvite::withoutGlobalScopes()->findOrFail($invito->getKey())->email,
        );

        Mail::assertSent(
            InvitoDelTrainerMail::class,
            fn (InvitoDelTrainerMail $m): bool => $m->hasTo('giusto@esempio.test'),
        );
    }

    /** ⛔ Un destinatario non si inventa: senza indirizzo è 422, non un finto invio. */
    #[Test]
    public function senza_nessun_indirizzo_si_rifiuta(): void
    {
        Mail::fake();

        $trainer = $this->unTrainer();
        $invito = $this->inviti()->invita($trainer);

        $this->actingAs($trainer, 'sanctum')
            ->postJson("/api/v1/trainer/invites/{$invito->getKey()}/email")
            ->assertStatus(422);

        Mail::assertNothingSent();
    }

    /**
     * ⛔ **E un invito morto non si rimanda**: manderebbe a una persona un link
     * che non funziona, facendole credere che l'app sia rotta.
     */
    #[Test]
    public function un_invito_revocato_non_si_rimanda(): void
    {
        Mail::fake();

        $trainer = $this->unTrainer();

        /*
         * ⚠️ **Senza email alla creazione**, o `invita()` ne manderebbe già una
         * — e questo test conta gli invii. 🚨 È lo scivolone che mi ha fatto
         * scoprire il doppio invio: qui la mail di creazione c'era, e sembrava
         * che a mandarla fosse la rotta del «rimanda».
         */
        $invito = $this->inviti()->invita($trainer);
        $invito->forceFill(['email' => 'tizio@esempio.test'])->save();

        $this->inviti()->revoca($invito);

        $this->actingAs($trainer, 'sanctum')
            ->postJson("/api/v1/trainer/invites/{$invito->getKey()}/email")
            ->assertStatus(409);

        Mail::assertNothingSent();
    }

    /** ⛔ E non si rimanda l'invito di un altro: 404, come per la revoca. */
    #[Test]
    public function non_si_rimanda_l_invito_di_un_altro(): void
    {
        $mio = $this->unTrainer('mio@esempio.test');
        $altrui = $this->unTrainer('altrui@esempio.test');

        $invito = $this->inviti()->invita($altrui, 'tizio@esempio.test');

        $this->actingAs($mio, 'sanctum')
            ->postJson("/api/v1/trainer/invites/{$invito->getKey()}/email")
            ->assertStatus(404);
    }

    // ───────────────── B1.7 — il posto si libera ─────────────────

    /**
     * 🚨 **Il motivo per cui il committente vuole la revoca**, e va **provato,
     * non dedotto**: l'invito occupa un posto, la revoca lo restituisce
     * **subito**.
     */
    #[Test]
    public function revocare_restituisce_il_posto_subito(): void
    {
        $trainer = $this->unTrainer();

        $prima = $this->actingAs($trainer, 'sanctum')
            ->getJson('/api/v1/trainer/invites')->json('meta.rimasti');

        $invito = $this->inviti()->invita($trainer);

        $durante = $this->actingAs($trainer, 'sanctum')
            ->getJson('/api/v1/trainer/invites')->json('meta.rimasti');

        $this->actingAs($trainer, 'sanctum')
            ->deleteJson("/api/v1/trainer/invites/{$invito->getKey()}")
            ->assertOk();

        $dopo = $this->actingAs($trainer, 'sanctum')
            ->getJson('/api/v1/trainer/invites')->json('meta.rimasti');

        $this->assertSame($prima - 1, $durante, 'L\'invito non occupava nessun posto: allora il limite non difende niente.');
        $this->assertSame($prima, $dopo, '🚨 Revocando, il posto non torna: è esattamente il difetto che la revoca deve curare.');
    }

    /** ⛔ E chi non è un trainer non elenca niente. */
    #[Test]
    public function un_iscritto_qualunque_non_vede_nessun_invito(): void
    {
        $chiunque = app(CreaTenantPersonale::class)('Mario', 'mario@esempio.test', [
            'password' => self::PAROLA,
        ]);

        $this->actingAs($chiunque, 'sanctum')
            ->getJson('/api/v1/trainer/invites')
            ->assertStatus(403);
    }
}
