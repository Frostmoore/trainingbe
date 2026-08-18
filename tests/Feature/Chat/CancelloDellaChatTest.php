<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Enums\TipoConversazione;
use App\Enums\UserRole;
use App\Events\MessageSent;
use App\Models\Comune;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\ProfiloPubblico;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Chat\CancelloDellaChat;
use App\Services\Chat\LimiteDeiTreMessaggi;
use App\Services\Chat\Permesso;
use App\Services\Scoperta\ChiaveComune;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * Il cancello della chat e il limite dei tre — Parte M3/M4, 18/08/2026.
 *
 * 🚨 **Questo file copre la tabella §4.6 riga per riga.** È la regola che decide
 * chi può parlare con chi, e vive in `CancelloDellaChat` e in nessun altro
 * posto: se qualcuno la riscrivesse nel controller, questi test resterebbero
 * verdi mentre il prodotto direbbe due cose diverse.
 */
class CancelloDellaChatTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    private Tenant $palestra;

    private User $proprietario;

    private Tenant $altroTenant;

    private User $tizio;

    private ProfiloPubblico $scheda;

    protected function setUp(): void
    {
        parent::setUp();

        /*
         * ⚠️ Senza, ogni messaggio prova a raggiungere Reverb su
         * `127.0.0.1:8081` e fallisce con un errore di rete — che il test
         * mostrerebbe come «atteso 403, ricevuto 201», cioe' dando la colpa alla
         * regola sbagliata. Stessa riga di `ChatApiTest`.
         */
        Event::fake([MessageSent::class]);

        $comune = Comune::create([
            'codice' => '099014', 'nome' => 'Rimini',
            'chiave' => app(ChiaveComune::class)->da('Rimini'),
            'provincia' => 'RN', 'provincia_nome' => 'Rimini', 'regione' => 'Emilia-Romagna',
            'popolazione' => 150_951, 'lat' => 44.060249, 'lng' => 12.565599, 'attivo' => true,
        ]);

        $this->palestra = $this->creaPalestra('Olimpo', 'olimpo', 'OLIM2345');
        $this->proprietario = $this->creaUtente($this->palestra, UserRole::GymAdmin, 'capo@olimpo.it');

        $this->scheda = ProfiloPubblico::create([
            'tenant_id' => $this->palestra->id,
            'comune_id' => $comune->id,
            'titolo' => 'Palestra Olimpo',
            'visibile' => true,
        ]);

        // 🚨 Un tenant DIVERSO: è il caso che il catalogo esiste per creare.
        $this->altroTenant = $this->creaPalestra('Ares', 'ares', 'ARES2345');
        $this->tizio = $this->creaUtente($this->altroTenant, UserRole::Member, 'tizio@esempio.it');

        $this->disabbona($this->altroTenant);
    }

    /**
     * 🚨 **Tizio NON deve essere abbonato, e va tolto a mano.**
     *
     * ⚠️ `creaPalestra()` abbona la palestra al piano `gym` **se il listino
     * esiste**, e fa benissimo: una palestra di prova senza abbonamento è una
     * palestra morosa, non una palestra normale. Ma qui serve l'opposto — una
     * persona che il limite dei tre lo sente davvero.
     *
     * 💡 **Ed è anche la conferma che il comportamento vero è giusto**: chi è
     * coperto dall'abbonamento della propria palestra *è* un utente pagante
     * della piattaforma, e non deve trovarsi tre messaggi contati. La prima
     * stesura di questo file non lo toglieva, e sei test fallivano con
     * «atteso 403, ricevuto 201»: il codice aveva ragione, il test no.
     */
    private function disabbona(Tenant $tenant): void
    {
        PlanSubscription::withoutGlobalScopes()->where('tenant_id', $tenant->id)->delete();
    }

    // ────────────── il filo fra due palestre diverse ──────────────

    #[Test]
    public function un_filo_di_informazioni_non_appartiene_a_nessuna_palestra(): void
    {
        /*
         * 🚨 **Il test che difende la decisione più delicata di M3.**
         *
         * I due capi stanno in tenant diversi. Se la conversazione prendesse il
         * `tenant_id` di uno dei due, ⚠️ **l'altro non la vedrebbe mai** — il
         * global scope la filtrerebbe via e la policy la negherebbe. E non ci
         * sarebbe nessun errore: il messaggio scritto, e il destinatario che non
         * lo trova.
         */
        $c = $this->apriInformazioni();

        $this->assertNull($c->tenant_id);
        $this->assertSame(TipoConversazione::Informazioni, $c->tipo);
    }

    #[Test]
    public function entrambi_i_capi_vedono_il_filo_pur_essendo_di_palestre_diverse(): void
    {
        $c = $this->apriInformazioni();

        foreach ([$this->tizio, $this->proprietario] as $chi) {
            $trovate = $this->actingAs($chi)->getJson('/api/v1/conversations')->assertOk()->json('data');

            $this->assertContains(
                $c->id,
                array_column($trovate, 'id'),
                'Il filo non è visibile a '.$chi->email,
            );
        }
    }

    #[Test]
    public function un_estraneo_NON_vede_il_filo_di_altri(): void
    {
        /*
         * 🚨 Il `tenant_id` nullo non è un buco: il controllo vero è
         * `includes()`, che confronta i partecipanti con l'id di **chi chiede**.
         */
        $c = $this->apriInformazioni();
        $estraneo = $this->creaUtente($this->altroTenant, UserRole::Member, 'estraneo@esempio.it');

        $this->actingAs($estraneo)
            ->getJson("/api/v1/conversations/{$c->id}/messages")
            ->assertStatus(404);
    }

    // ────────────── il limite dei tre, per parte ──────────────

    #[Test]
    public function tre_messaggi_poi_ci_si_ferma(): void
    {
        $c = $this->apriInformazioni();

        for ($i = 1; $i <= 3; $i++) {
            $this->scrivi($this->tizio, $c)->assertStatus(201);
        }

        $risposta = $this->scrivi($this->tizio, $c)->assertStatus(403);

        $this->assertSame(Permesso::TRE_ESAURITI, $risposta->json('code'));
    }

    #[Test]
    public function finiti_i_tre_si_propone_l_abbonamento_e_NON_i_gettoni(): void
    {
        /*
         * 🚨 M4.3-bis. Un gettone vuol dire **una chiamata all'AI**, dietro cui
         * c'è un costo che paghiamo; un messaggio non ci costa niente. ⚠️ Far
         * valere alla stessa unità anche «permesso di parlare» significherebbe
         * che il giorno che se ne cambia il prezzo si muovono due leve credendo
         * di muoverne una.
         */
        $c = $this->apriInformazioni();

        for ($i = 1; $i <= 3; $i++) {
            $this->scrivi($this->tizio, $c);
        }

        $risposta = $this->scrivi($this->tizio, $c)->assertStatus(403);

        $this->assertTrue($risposta->json('permesso.proponi_abbonamento'));
        $this->assertStringNotContainsStringIgnoringCase('gettoni', (string) $risposta->json('message'));
    }

    #[Test]
    public function il_limite_e_di_CIASCUNO_non_di_entrambi(): void
    {
        /*
         * 🚨 **Il test che giustifica il JSON invece di un contatore unico.**
         *
         * ⚠️ Con un numero solo, chi scrive per primo consumerebbe anche la
         * quota dell'altro: una palestra che riceve tre domande non potrebbe
         * rispondere nemmeno una volta.
         */
        $c = $this->apriInformazioni();

        for ($i = 1; $i <= 3; $i++) {
            $this->scrivi($this->tizio, $c)->assertStatus(201);
        }

        $this->scrivi($this->tizio, $c)->assertStatus(403);

        /*
         * ⚠️ Perche' anche la palestra venga contata, deve essere **senza
         * abbonamento**: un tenant che paga non ha limiti (vedi il test qui
         * sopra). Nella prima stesura non l'avevo tolto, e questo test falliva
         * dicendo «atteso 403, ricevuto 201» — con il codice che aveva ragione.
         */
        $this->disabbona($this->palestra);

        for ($i = 1; $i <= 3; $i++) {
            $this->scrivi($this->proprietario, $c)->assertStatus(201);
        }

        $this->scrivi($this->proprietario, $c)->assertStatus(403);
    }

    #[Test]
    public function la_risposta_dice_quanti_ne_restano(): void
    {
        // 💡 M4.3: l'app deve poterlo dire **prima** che si prema invio.
        $c = $this->apriInformazioni();

        $this->assertSame(2, $this->scrivi($this->tizio, $c)->json('restanti'));
        $this->assertSame(1, $this->scrivi($this->tizio, $c)->json('restanti'));
        $this->assertSame(0, $this->scrivi($this->tizio, $c)->json('restanti'));
    }

    #[Test]
    public function leggere_si_puo_SEMPRE_anche_a_limite_finito(): void
    {
        /*
         * 🚨 Il cancello decide **solo la scrittura**. ⚠️ Nascondere la storia a
         * chi ha finito i messaggi vorrebbe dire togliergli qualcosa che
         * possiede già — le buste sono anche sul suo telefono.
         */
        $c = $this->apriInformazioni();

        for ($i = 1; $i <= 3; $i++) {
            $this->scrivi($this->tizio, $c);
        }

        $this->actingAs($this->tizio)
            ->getJson("/api/v1/conversations/{$c->id}/messages")
            ->assertOk()
            ->assertJsonCount(3, 'data');
    }

    // ────────────── l'abbonamento toglie il limite ──────────────

    #[Test]
    public function un_abbonato_scrive_senza_limite(): void
    {
        /*
         * 🚨 **È ciò che l'abbonamento vende.** Il catalogo lo vedono tutti; si
         * compra il diritto di scrivere senza limite a chi non ti segue.
         */
        $this->abbona($this->altroTenant);

        $c = $this->apriInformazioni();

        for ($i = 1; $i <= 5; $i++) {
            $this->scrivi($this->tizio, $c)->assertStatus(201);
        }

        $this->assertNull(app(CancelloDellaChat::class)->puoScrivere($this->tizio, $c->fresh())->restanti);
    }

    #[Test]
    public function restanti_null_NON_e_restanti_zero(): void
    {
        /*
         * 🚨 `null` = «nessun limite», `0` = «finiti». ⚠️ Un `?? 0` scritto di
         * fretta da qualche parte trasformerebbe un abbonato in uno bloccato.
         *
         * 💡 Si interroga **il cancello** e non `LimiteDeiTreMessaggi`: il
         * limite conta i messaggi e non sa niente di abbonamenti. Questo test
         * nella prima stesura lo chiedeva al limite, ed e' cosi' che e' venuto
         * fuori il difetto — la risposta HTTP diceva `2, 1, 0` a un abbonato che
         * il cancello lasciava scrivere all'infinito.
         */
        $this->abbona($this->altroTenant);
        $c = $this->apriInformazioni();

        $restanti = app(CancelloDellaChat::class)->puoScrivere($this->tizio, $c)->restanti;

        $this->assertNull($restanti);
        $this->assertNotSame(0, $restanti);
    }

    #[Test]
    public function la_risposta_HTTP_e_il_cancello_non_si_contraddicono_mai(): void
    {
        /*
         * 🚨 **Il test che tiene insieme le due meta'.**
         *
         * ⚠️ Se `restanti` nella risposta si calcolasse in un modo e il permesso
         * di scrivere in un altro, l'app mostrerebbe un contatore che scende a
         * zero mentre il server continua ad accettare — o il contrario. Nessuno
         * dei due sarebbe un errore visibile.
         */
        $this->abbona($this->altroTenant);
        $c = $this->apriInformazioni();

        for ($i = 1; $i <= 4; $i++) {
            $risposta = $this->scrivi($this->tizio, $c)->assertStatus(201);
            $this->assertNull($risposta->json('restanti'), "Al messaggio {$i} il contatore mente.");
        }
    }

    // ────────────── i fili normali non hanno limiti ──────────────

    #[Test]
    public function chi_e_coperto_dall_abbonamento_della_propria_palestra_NON_ha_limiti(): void
    {
        /*
         * 🚨 **La regola vale a livello di piano, non di persona**, ed è giusto:
         * un iscritto che la palestra paga *è* un utente pagante della
         * piattaforma. Contargli tre messaggi vorrebbe dire far pagare due volte
         * la stessa persona.
         *
         * 💡 Questo test esiste perché il caso è emerso da un mio errore: avevo
         * scritto sei test dando per scontato che un iscritto di palestra fosse
         * «non abbonato», e fallivano tutti. Il comportamento era giusto.
         */
        $coperto = $this->creaUtente($this->palestra, UserRole::Member, 'coperto@olimpo.it');

        // La palestra Olimpo l'abbonamento ce l'ha (glielo mette `creaPalestra`).
        $scheda = ProfiloPubblico::create([
            'user_id' => $this->creaUtente($this->palestra, UserRole::FreeTrainer, 'libero2@esempio.it')->id,
            'comune_id' => Comune::first()->id,
            'titolo' => 'Un altro trainer',
            'visibile' => true,
        ]);

        $id = $this->actingAs($coperto)
            ->postJson('/api/v1/conversations/informazioni', ['profilo_id' => $scheda->id])
            ->assertStatus(201)
            ->json('data.restanti');

        $this->assertNull($id, 'Chi è coperto dal piano della palestra non deve avere un contatore.');
    }

    #[Test]
    public function un_filo_fra_iscritto_e_trainer_non_ha_limiti(): void
    {
        $trainer = $this->creaUtente($this->palestra, UserRole::Trainer, 'coach@olimpo.it');
        $iscritto = $this->creaUtente($this->palestra, UserRole::Member, 'iscritto@olimpo.it');

        $c = Conversation::between($trainer, $iscritto);

        for ($i = 1; $i <= 5; $i++) {
            $this->scrivi($iscritto, $c)->assertStatus(201);
        }

        $this->assertNull(app(LimiteDeiTreMessaggi::class)->restanti($iscritto, $c));
    }

    // ────────────── diventare iscritti sblocca ──────────────

    #[Test]
    public function iscriversi_alla_palestra_sblocca_la_conversazione_che_c_era_gia(): void
    {
        /*
         * 🚨 **M4.4, e il difetto che evita è il più irritante possibile.**
         *
         * ⚠️ Senza questo, chi si iscrive resterebbe bloccato nella stessa
         * conversazione, con la storia sotto gli occhi e la penna ferma —
         * esattamente a chi ha appena pagato.
         *
         * 💡 **Il flusso vero è `join-gym`**, non «aprire una chat». Nella prima
         * stesura questo test collegava le due persone col pivot e chiamava
         * `POST /conversations`: fallisce, perché `open()` pretende lo stesso
         * tenant per entrambi — cioè proprio la condizione che qui non c'è. Il
         * test sbagliato ha mostrato che avevo agganciato lo sblocco al posto
         * sbagliato: ora sta in `UnisciAUnaPalestra`, dove uno «diventa
         * iscritto» davvero.
         */
        $senzaPalestra = $this->personaConTenantPersonale('nuovo@esempio.it');

        $c = Conversation::withoutGlobalScopes()->findOrFail(
            $this->actingAs($senzaPalestra)
                ->postJson('/api/v1/conversations/informazioni', ['profilo_id' => $this->scheda->id])
                ->assertStatus(201)
                ->json('data.id'),
        );

        for ($i = 1; $i <= 3; $i++) {
            $this->scrivi($senzaPalestra, $c)->assertStatus(201);
        }

        $this->scrivi($senzaPalestra, $c)->assertStatus(403);

        // 🎯 Si iscrive davvero, col codice della palestra.
        $this->actingAs($senzaPalestra)
            ->postJson('/api/v1/account/join-gym', ['join_code' => $this->palestra->join_code])
            ->assertOk();

        $this->assertSame(
            TipoConversazione::Iscritto,
            $c->fresh()->tipo,
            'Iscrivendosi, il filo doveva smettere di essere «informazioni».',
        );
    }

    #[Test]
    public function iscriversi_NON_sblocca_i_fili_con_ALTRE_palestre(): void
    {
        /*
         * 🚨 ⚠️ Sbloccarli tutti vorrebbe dire che il limite si aggira
         * iscrivendosi alla palestra più economica, e da lì si scrive senza
         * limiti a chiunque.
         */
        $altraPalestra = $this->creaPalestra('Zeus', 'zeus', 'ZEUS2345');
        $altroCapo = $this->creaUtente($altraPalestra, UserRole::GymAdmin, 'capo@zeus.it');

        $altraScheda = ProfiloPubblico::create([
            'tenant_id' => $altraPalestra->id,
            'comune_id' => Comune::first()->id,
            'titolo' => 'Palestra Zeus',
            'visibile' => true,
        ]);

        $senzaPalestra = $this->personaConTenantPersonale('nuovo@esempio.it');

        $conZeus = Conversation::withoutGlobalScopes()->findOrFail(
            $this->actingAs($senzaPalestra)
                ->postJson('/api/v1/conversations/informazioni', ['profilo_id' => $altraScheda->id])
                ->assertStatus(201)
                ->json('data.id'),
        );

        // Si iscrive a Olimpo, non a Zeus.
        $this->actingAs($senzaPalestra)
            ->postJson('/api/v1/account/join-gym', ['join_code' => $this->palestra->join_code])
            ->assertOk();

        $this->assertSame(TipoConversazione::Informazioni, $conZeus->fresh()->tipo);
    }

    #[Test]
    public function sbloccare_NON_azzera_il_contatore(): void
    {
        /*
         * 💡 Il numero dice quante volte quella persona ha provato prima di
         * iscriversi: è un dato che un giorno vorrà sapere chi guarda i numeri.
         */
        $c = $this->apriInformazioni();
        $this->scrivi($this->tizio, $c);

        app(LimiteDeiTreMessaggi::class)->sblocca($c);

        $this->assertSame(1, app(LimiteDeiTreMessaggi::class)->quanti($this->tizio, $c->fresh()));
    }

    // ────────────── aprire dal catalogo ──────────────

    #[Test]
    public function si_apre_con_l_id_della_SCHEDA_e_non_della_persona(): void
    {
        /*
         * 🚨 Un `user_id` in ingresso avrebbe voluto dire pubblicare nel
         * catalogo gli identificativi di tutti i titolari di palestra.
         */
        $this->actingAs($this->tizio)
            ->postJson('/api/v1/conversations/informazioni', ['profilo_id' => $this->scheda->id])
            ->assertStatus(201)
            ->assertJsonPath('data.con.nome', 'Palestra Olimpo')
            ->assertJsonPath('data.con.tipo', 'palestra')
            ->assertJsonPath('data.restanti', 3);
    }

    #[Test]
    public function una_scheda_non_pubblicata_risponde_404(): void
    {
        // ⚠️ Un messaggio diverso direbbe a chi prova gli id quali palestre sono
        // iscritte senza essersi pubblicate.
        $this->scheda->update(['visibile' => false]);

        $this->actingAs($this->tizio)
            ->postJson('/api/v1/conversations/informazioni', ['profilo_id' => $this->scheda->id])
            ->assertStatus(404);
    }

    #[Test]
    public function una_scheda_senza_destinatario_attivo_risponde_409(): void
    {
        $this->proprietario->forceFill(['is_active' => false])->save();

        $this->actingAs($this->tizio)
            ->postJson('/api/v1/conversations/informazioni', ['profilo_id' => $this->scheda->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'non_contattabile');
    }

    #[Test]
    public function non_si_puo_aprire_un_filo_con_se_stessi(): void
    {
        $this->actingAs($this->proprietario)
            ->postJson('/api/v1/conversations/informazioni', ['profilo_id' => $this->scheda->id])
            ->assertStatus(403);
    }

    #[Test]
    public function riaprire_lo_stesso_filo_non_ne_crea_un_secondo(): void
    {
        // 💡 Due tocchi su una rete lenta creerebbero altrimenti due stanze, e i
        // due si scriverebbero in posti diversi senza capire perché.
        $primo = $this->apriInformazioni();
        $secondo = $this->apriInformazioni();

        $this->assertSame($primo->id, $secondo->id);
        $this->assertSame(1, Conversation::withoutGlobalScopes()
            ->where('trainer_id', $this->proprietario->id)
            ->where('member_id', $this->tizio->id)
            ->count());
    }

    // ────────────── aiutanti ──────────────

    /**
     * Una persona che si allena da sola: tenant **personale**, nessun
     * abbonamento.
     *
     * 🚨 È l'unico stato da cui `join-gym` funziona — spostare un iscritto da
     * una palestra a un'altra è un'operazione commerciale, non una scelta
     * dell'utente.
     */
    private function personaConTenantPersonale(string $email): User
    {
        $suo = Tenant::create([
            'name' => 'Spazio di '.$email,
            'slug' => 'spazio-'.md5($email),
            'join_code' => strtoupper(substr(md5($email), 0, 8)),
            'contact_email' => $email,
            'status' => \App\Enums\TenantStatus::Active,
            'kind' => \App\Enums\TenantKind::Personal,
        ]);

        $this->ctxRuoli($suo);

        return $this->creaUtente($suo, UserRole::FreeUser, $email);
    }

    /** 💡 I ruoli spatie vivono dentro il tenant: vanno creati lì. */
    private function ctxRuoli(Tenant $tenant): void
    {
        app(\App\Support\Tenancy\TenantContext::class)->runAs($tenant, function () use ($tenant): void {
            foreach (UserRole::tenantScoped() as $ruolo) {
                \Spatie\Permission\Models\Role::firstOrCreate([
                    'name' => $ruolo->value,
                    'guard_name' => 'web',
                    'tenant_id' => $tenant->id,
                ]);
            }
        });
    }

    private function apriInformazioni(): Conversation
    {
        $id = $this->actingAs($this->tizio)
            ->postJson('/api/v1/conversations/informazioni', ['profilo_id' => $this->scheda->id])
            ->assertStatus(201)
            ->json('data.id');

        return Conversation::withoutGlobalScopes()->findOrFail($id);
    }

    /** 💡 Una busta di forma valida: il server non ha le chiavi, ma pretende la forma. */
    private function scrivi(User $chi, Conversation $c): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($chi)->postJson("/api/v1/conversations/{$c->id}/messages", [
            'envelope_version' => 1,
            'nonce' => str_repeat('a', 32),
            'body' => base64_encode('busta finta per il test'),
        ]);
    }

    /**
     * 🚨 Il piano si crea qui invece di pescarlo dai seeder.
     *
     * ⚠️ Un test che dipende dal contenuto dei seeder è un test che diventa
     * rosso il giorno che il listino cambia — e il listino di questo progetto è
     * già cambiato tre volte in due giorni. 💡 Quello che conta è **una** cosa:
     * `price_cents > 0`, cioè `eGratuito() === false`.
     */
    private function abbona(Tenant $tenant): void
    {
        $piano = Plan::firstOrCreate(
            ['code' => 'prova_a_pagamento'],
            [
                'name' => 'Piano di prova a pagamento',
                'price_cents' => 799,
                'ai_enabled' => true,
                'is_public' => false,
            ],
        );

        $this->assertFalse($piano->eGratuito(), 'Il piano di prova deve essere a pagamento.');

        PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'plan_id' => $piano->id,
            'starts_at' => now()->subDay(),
        ]);
    }
}
