<?php

declare(strict_types=1);

namespace Tests\Feature\Accesso;

use App\Enums\SocialProvider;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Account\AccountEraser;
use App\Services\Auth\Social\SocialTokenVerifier;
use App\Services\Auth\Social\VerifiedSocialUser;
use App\Services\Tenancy\AllenareInUnaPalestra;
use App\Services\Tenancy\LeAppartenenze;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\CreaAmbiente;
use Tests\TestCase;

/**
 * 🚨 **QUALUNQUE COSA SIA ROTTA, SI ENTRA** — 63.2, 27/09/2026.
 *
 * ══ 📌 PERCHE' QUESTO E' IL TEST PIU' IMPORTANTE DEL PROGETTO ══════════════
 *
 * 📌 Il committente, dopo che l'accesso si era rotto **tre volte in due giorni**:
 * *«NIENTE AL MONDO DEVE IMPEDIRE A UN UTENTE DI ACCEDERE»*. E: *«chiunque deve
 * poter accedere e pagarmi … Se uno non può entrare, non mi può pagare, e io ho
 * buttato via centinaia di ore di lavoro»*.
 *
 * ══ 🔑 COSA FA DI DIVERSO DALLA MATRICE ═══════════════════════════════════
 *
 * `LaMatriceDellAccessoTest` prova che **venticinque casi noti** accedano. ⛔ Ma i
 * tre guasti del 26–27/09 non erano fra i casi noti: erano rami che **nessuno aveva
 * immaginato**.
 *
 * | | Cosa dice |
 * |---|---|
 * | la matrice | *«questi venticinque entrano»* |
 * | **questo file** | *«qualunque cosa si rompa, si entra»* |
 *
 * 🚨 Qui non si elencano i casi che funzionano: si **rompe una cosa alla volta** e
 * si pretende che l'accesso regga comunque. 💡 È l'unica forma che protegge dai casi
 * che non ho immaginato — cioè da quelli che sono costati la fiducia.
 *
 * ══ ⛔ I SOLI DUE RIFIUTI AMMESSI ══════════════════════════════════════════
 *
 * 1. **credenziali sbagliate**;
 * 2. **account cancellato dalla persona stessa**.
 *
 * ⚠️ Tutto il resto — palestre, abbonamenti, ruoli, spazi, pagamenti — riguarda
 * **cosa funziona dopo**, mai **se si entra**.
 */
class SiEntraSempreTest extends TestCase
{
    use CreaAmbiente;
    use RefreshDatabase;

    /**
     * 🚨 **Si rompe una cosa per volta, e si entra sempre.**
     *
     * ══ ⚠️ PERCHE' UN CICLO E NON DODICI METODI ════════════════════════════
     *
     * 💡 Perché l'elenco delle rotture è **la specifica**, e va letta tutta insieme:
     * chi aggiunge una colonna o un vincolo domani deve poter guardare questa lista
     * e chiedersi *«la mia cosa nuova ci va?»*. ⛔ Spalmata su dodici metodi, quella
     * domanda non si fa più.
     *
     * ⚠️ Ogni giro **ricostruisce il suo mondo** con indirizzi propri: le rotture non
     * si sommano, o il decimo caso proverebbe uno stato che non esiste in natura.
     */
    #[Test]
    public function qualunque_cosa_sia_rotta_si_entra(): void
    {
        $rotture = $this->leRotture();

        self::assertGreaterThanOrEqual(
            10,
            count($rotture),
            '⛔ Una lista di rotture troppo corta non prova «qualunque cosa».',
        );

        foreach ($rotture as $nome => $rompi) {
            $mondo = $this->unMondoIntero((string) $nome);

            $rompi($mondo);

            /*
             * 🚨 **Il limitatore di tentativi va azzerato fra un giro e l'altro.**
             *
             * ⛔ Senza, al sesto accesso arriva un **429** e il test accusa la rottura
             * di turno di un difetto che non ha: l'ha fatto al primo giro, sulla sesta
             * riga della lista. 💡 Una falsa accusa che sposta la colpa su un pezzo di
             * codice innocente è peggio di un test che non c'è.
             *
             * ⚠️ Si **svuota la cache**, non si spegne il middleware: il limitatore è
             * una protezione vera e va lasciata in mezzo. 🔑 E il fatto che esista è
             * scritto nel test qui sotto, perché è un **terzo** rifiuto legittimo che
             * nella specifica non c'era.
             */
            Cache::flush();

            $risposta = $this->postJson('/api/v1/auth/login', [
                'login' => $mondo['iscritto']->email,
                'password' => self::FAKE_PASSWORD,
                'device_name' => 'prova',
            ]);

            self::assertTrue(
                $risposta->isSuccessful(),
                "🚨 ROTTURA «{$nome}»: l'accesso è stato negato con ".$risposta->getStatusCode()
                .' ('.($risposta->json('code') ?? $risposta->json('message') ?? '—').").\n"
                .'⛔ «NIENTE AL MONDO DEVE IMPEDIRE A UN UTENTE DI ACCEDERE»: questa cosa riguarda '
                .'cosa funziona DOPO, non se si entra.',
            );
        }
    }

    /**
     * ⛔ **E i due rifiuti che invece ci vanno.**
     *
     * ⚠️ Senza questo, il test qui sopra passerebbe anche con l'autenticazione
     * **spenta del tutto**: direbbe «entrano tutti» e avrebbe ragione per il motivo
     * peggiore possibile. 🔑 È il controllo che rende vero l'altro.
     */
    #[Test]
    public function i_due_rifiuti_ammessi_restano(): void
    {
        $mondo = $this->unMondoIntero('rifiuti');

        /* 1️⃣ Password sbagliata. */
        /*
         * ⚠️ **Risponde 422, non 401**, e non è un difetto: il rifiuto arriva come
         * errore di validazione sul campo `login` con il messaggio «Credenziali non
         * valide». 💡 Scritto qui perché me l'ero immaginato 401 e il test cadeva
         * per la mia attesa sbagliata, non per il codice.
         */
        $sbagliata = $this->postJson('/api/v1/auth/login', [
            'login' => $mondo['iscritto']->email,
            'password' => 'questa-non-e-la-sua',
            'device_name' => 'prova',
        ]);

        self::assertFalse(
            $sbagliata->isSuccessful(),
            '⛔ Si entra con la password sbagliata.',
        );

        /*
         * 2️⃣ **Account cancellato dalla persona stessa** — con la strada VERA.
         *
         * ══ 🚨 UN FALSO ALLARME CHE HO QUASI RIPORTATO ═════════════════════
         *
         * ⛔ Il primo giro faceva `$iscritto->delete()`, cioè il solo soft-delete — e
         * l'accesso **riusciva**. 🚨 Stavo per segnalarlo come un buco di sicurezza:
         * «una persona che ha cancellato l'account entra ancora».
         *
         * 💡 **Non era vero.** La cancellazione vera passa da `AccountEraser`, che
         * azzera `is_active`, sostituisce l'indirizzo con un segnaposto, randomizza
         * la password **e poi** fa il soft-delete: quattro sbarre, non una.
         *
         * ⚠️ La lezione: **un test che simula un percorso invece di percorrerlo
         * accusa il codice di difetti che non ha.** ⛔ E una falsa accusa costa più
         * di un test mancante, perché manda a «correggere» qualcosa che funziona.
         */
        app(AccountEraser::class)->erase($mondo['iscritto']);

        $risposta = $this->postJson('/api/v1/auth/login', [
            'login' => $mondo['iscritto']->email,
            'password' => self::FAKE_PASSWORD,
            'device_name' => 'prova',
        ]);

        self::assertFalse(
            $risposta->isSuccessful(),
            '⛔ Un account cancellato dalla persona stessa continua ad accedere: '
            .'la cancellazione non è servita a niente.',
        );
    }

    /**
     * ⚠️ **E c'è un TERZO rifiuto, che nella specifica non c'era.**
     *
     * 🚨 Dopo alcuni tentativi ravvicinati il server risponde **429**: «Hai riprovato
     * troppe volte di seguito». ⛔ Non è fra i due ammessi — credenziali sbagliate e
     * account cancellato — eppure **impedisce l'accesso**.
     *
     * ⚖️ E va bene così: senza limite, un elenco di indirizzi e un dizionario di password
     * entrerebbero in qualche account prima o poi. 💡 Ma va **scritto**, perché una
     * persona che sbaglia la password sei volte resta fuori per un minuto — e se un
     * giorno arriva la telefonata *«non riesco ad accedere»*, questa è la terza cosa da
     * guardare.
     *
     * ⛔ Questo test **non chiede di togliere il limite**: chiede che il limite ci sia e
     * che si sappia che c'è.
     */
    #[Test]
    public function il_terzo_rifiuto_e_il_limite_dei_tentativi_e_va_saputo(): void
    {
        $mondo = $this->unMondoIntero('limite');

        Cache::flush();

        $ultima = null;

        for ($i = 0; $i < 12; $i++) {
            $ultima = $this->postJson('/api/v1/auth/login', [
                'login' => $mondo['iscritto']->email,
                'password' => 'sbagliata-'.$i,
                'device_name' => 'prova',
            ]);

            if ($ultima->getStatusCode() === 429) {
                break;
            }
        }

        self::assertSame(
            429,
            $ultima?->getStatusCode(),
            '⚠️ Nessun limite sui tentativi di accesso: un dizionario di password entrerebbe.',
        );

        /*
         * 🔑 **E il limite vale sui tentativi, non sulla persona**: passato il minuto
         * si entra con la password giusta. ⛔ Se così non fosse, sbagliare la password
         * sei volte sarebbe un modo per chiudersi fuori da soli.
         */
        Cache::flush();

        $this->postJson('/api/v1/auth/login', [
            'login' => $mondo['iscritto']->email,
            'password' => self::FAKE_PASSWORD,
            'device_name' => 'prova',
        ])->assertSuccessful();
    }

    /**
     * 🔑 **E CON GOOGLE, sul caso che era rotto: nessuno spazio.**
     *
     * ══ 🚨 PERCHE' UN CASO SOLO E NON TUTTA LA LISTA ═══════════════════════
     *
     * ⛔ Il primo giro provava a ripetere **tutte** le rotture anche con Google, in un
     * ciclo. 🚨 Ma le prove dicevano che stava mentendo: tutte le iterazioni
     * finivano sullo **stesso utente** e con **una sola identità** in tutto il
     * database. Cioè il ciclo non isolava i giri, e le quattordici asserzioni ne
     * provavano una.
     *
     * 💡 **Meglio un caso solo che si capisce** che quattordici che non si sanno
     * spiegare. ⚠️ E il caso che conta è questo: `tenant_id = null` è il guasto vero
     * del 27/09, ed è il ramo dove la correzione era scritta **due volte** in
     * `SocialAuthController` — quindi è la correzione che rischiava di restare a metà.
     *
     * ⚖️ Le altre rotture restano coperte su `/auth/login`, che è lo stesso
     * ragionamento e un controller diverso. ⛔ Sta scritto perché chi legge sappia
     * **cosa non è coperto**, invece di dedurlo da un test che sembra completo.
     *
     * 🚨 E qui pesa più che con la password: chi entra con Google spesso **non ha una
     * password** da provare come alternativa.
     */
    #[Test]
    public function con_google_si_entra_anche_senza_spazio(): void
    {
        $mondo = $this->unMondoIntero('google');

        $this->identitaFinta($mondo['iscritto']->email);

        Cache::flush();

        /* 1️⃣ Prima si collega Google, con lo spazio ancora sano. */
        $this->accediConGoogle()->assertSuccessful();

        /* 💥 Poi gli si porta via lo spazio. */
        DB::table('users')->where('id', $mondo['iscritto']->getKey())->update(['tenant_id' => null]);

        Cache::flush();

        $risposta = $this->accediConGoogle();

        self::assertTrue(
            $risposta->isSuccessful(),
            "🚨 Con Google e senza spazio l'accesso è negato con ".$risposta->getStatusCode()
            .' ('.($risposta->json('code') ?? '—').").\n"
            .'⛔ È il guasto del 27/09, e chi entra con Google non ha una password da provare.',
        );

        /* 🏠 E lo spazio gli è stato dato: il contesto non resta mai vuoto. */
        self::assertNotNull(
            User::withoutGlobalScopes()->find($mondo['iscritto']->getKey())?->tenant_id,
            '🚨 È entrato senza spazio: il `TenantScope` non filtra e vedrebbe i dati di tutti.',
        );
    }

    private function accediConGoogle(): TestResponse
    {
        return $this->postJson('/api/v1/auth/social', [
            'provider' => 'google',
            'id_token' => 'token-finto',
            'age_confirmed' => true,
            'terms_accepted' => true,
        ]);
    }

    /**
     * 🎭 Il verificatore finto: restituisce sempre la stessa identità Google.
     *
     * ⚠️ Non si prova il vero (che chiamerebbe Google): qui interessa **cosa fa il
     * nostro codice dopo** che l'identità è stata verificata.
     */
    private function identitaFinta(string $email): void
    {
        $identita = new VerifiedSocialUser(
            provider: SocialProvider::Google,
            providerUserId: 'sub-'.md5($email),
            email: $email,
            emailVerified: true,
            name: 'Prova',
        );

        $this->app->instance(
            SocialTokenVerifier::class,
            new class($identita) implements SocialTokenVerifier
            {
                public function __construct(private readonly VerifiedSocialUser $identita) {}

                public function verify(SocialProvider $provider, string $idToken): VerifiedSocialUser
                {
                    return $this->identita;
                }

                public function configured(SocialProvider $provider): bool
                {
                    return true;
                }
            }
        );
    }

    // ═════════════════════════ le rotture ═════════════════════════

    /**
     * 📋 **L'elenco delle rotture. Questa lista è la specifica.**
     *
     * 🔑 Ognuna è una cosa che nella vita reale **è già andata storta** o che può
     * andare storta domani. ⚠️ Chi aggiunge un vincolo, una colonna o un servizio
     * dovrebbe aggiungere una riga qui — ed è il motivo per cui stanno tutte insieme.
     *
     * @return array<string, callable(array{palestra: Tenant, iscritto: User, trainer: User, spazioTrainer: Tenant}): void>
     */
    private function leRotture(): array
    {
        return [
            /* 🚨 Il guasto del 26/09: era voluto, ed era sbagliato. */
            'la palestra è sospesa' => function (array $m): void {
                $m['palestra']->forceFill(['status' => TenantStatus::Suspended])->save();
            },

            'la palestra è stata disdetta' => function (array $m): void {
                $m['palestra']->forceFill(['status' => TenantStatus::Cancelled])->save();
            },

            'l\'abbonamento della palestra è stato cancellato' => function (array $m): void {
                PlanSubscription::withoutGlobalScopes()
                    ->where('tenant_id', $m['palestra']->getKey())
                    ->delete();
            },

            'l\'abbonamento è scaduto' => function (array $m): void {
                PlanSubscription::withoutGlobalScopes()
                    ->where('tenant_id', $m['palestra']->getKey())
                    ->update(['ends_at' => now()->subMonth()]);
            },

            'la macchina ha sospeso la palestra' => function (array $m): void {
                $m['palestra']->forceFill([
                    'status' => TenantStatus::Suspended,
                    'sospensione_automatica_dal' => now()->subDay(),
                ])->save();
            },

            'il pagamento è stato rifiutato' => function (array $m): void {
                PlanSubscription::withoutGlobalScopes()
                    ->where('tenant_id', $m['palestra']->getKey())
                    ->update(['pagamento_fallito_il' => now()->subDay()]);
            },

            /* 🚨 Il guasto del 27/09: il super admin senza spazio. */
            'non ha nessuno spazio (tenant_id a null)' => function (array $m): void {
                DB::table('users')->where('id', $m['iscritto']->getKey())->update(['tenant_id' => null]);
            },

            'ha perso tutti i ruoli' => function (array $m): void {
                DB::table('model_has_roles')->where('model_id', $m['iscritto']->getKey())->delete();
            },

            'le sue appartenenze sono state cancellate' => function (array $m): void {
                DB::table('appartenenze')->where('user_id', $m['iscritto']->getKey())->delete();
            },

            'il legame col trainer è stato disattivato' => function (array $m): void {
                DB::table('trainer_member')
                    ->where('member_id', $m['iscritto']->getKey())
                    ->update(['disattivato_il' => now()]);
            },

            'anche lo spazio del trainer è sospeso' => function (array $m): void {
                $m['palestra']->forceFill(['status' => TenantStatus::Suspended])->save();
                $m['spazioTrainer']->forceFill(['status' => TenantStatus::Suspended])->save();
            },

            'il piano è stato tolto dal listino' => function (array $m): void {
                Plan::query()->update(['is_public' => false]);
            },

            'la palestra non ha più un proprietario' => function (array $m): void {
                $m['palestra']->forceFill(['proprietario_id' => null])->save();
            },

            'il suo spazio è stato ritirato (soft-delete)' => function (array $m): void {
                /*
                 * 🚨 **Il caso che sospettavo, e va misurato non dedotto.**
                 *
                 * ⚠️ C'è una nota di questo progetto che dice che un tenant ritirato
                 * fa rispondere **«credenziali non valide»**. ⛔ Se fosse così, sarebbe
                 * insieme una **porta chiusa** e una **diagnosi falsa** — le due cose
                 * peggiori insieme. 💡 Questa riga è qui per scoprirlo.
                 */
                Tenant::withoutGlobalScopes()
                    ->whereKey($m['palestra']->getKey())
                    ->delete();
            },
        ];
    }

    // ═════════════════════════ il mondo ═════════════════════════

    /**
     * 🌍 Un mondo completo: una palestra che paga, un iscritto, e un trainer
     * indipendente che lo segue dal proprio spazio.
     *
     * ⚠️ **Ricco apposta.** Un iscritto solo in una palestra sola non ha niente da
     * rompere: le rotture interessanti stanno nei legami — appartenenze,
     * `trainer_member`, due spazi con due abbonamenti.
     *
     * @return array{palestra: Tenant, iscritto: User, trainer: User, spazioTrainer: Tenant}
     */
    private function unMondoIntero(string $chiave): array
    {
        $slug = 'r'.substr(md5($chiave), 0, 8);

        $palestra = $this->creaPalestra('Mondo '.$slug, $slug);
        $this->creaUtente($palestra, UserRole::GymAdmin, 'capo@'.$slug.'.test');
        $iscritto = $this->creaUtente($palestra, UserRole::Member, 'iscritto@'.$slug.'.test');

        $spazioTrainer = $this->creaPalestra('Trainer '.$slug, 't-'.$slug);
        $trainer = $this->creaUtente($spazioTrainer, UserRole::FreeTrainer, 'trainer@'.$slug.'.test');

        /* 🔗 Il trainer segue l'iscritto dal proprio spazio. */
        $trainer->assignedMembers()->attach($iscritto->getKey(), [
            'tenant_id' => $spazioTrainer->getKey(),
            'assigned_at' => now(),
        ]);

        /* 🏠 E l'appartenenza alla palestra, come in produzione. */
        app(LeAppartenenze::class)->entra($iscritto, $palestra);

        /* 💼 E il trainer lavora anche nella palestra: due cappelli da rompere. */
        app(AllenareInUnaPalestra::class)->assumi($trainer, $palestra);

        return [
            'palestra' => $palestra,
            'iscritto' => $iscritto,
            'trainer' => $trainer,
            'spazioTrainer' => $spazioTrainer,
        ];
    }
}
