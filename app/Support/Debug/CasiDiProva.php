<?php

declare(strict_types=1);

namespace App\Support\Debug;

use App\Enums\PlanKind;
use App\Enums\TenantKind;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\AiCreditMovement;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\PortafoglioGettoni;
use App\Services\Privacy\LeLiberatorie;
use App\Services\Tenancy\AllenareInUnaPalestra;
use App\Services\Tenancy\CreaTenantPersonale;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * 🧪 **I venticinque casi con cui il committente verifica DA SÉ** — 63.H, 27/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * 📌 Il committente, 27/09: *«per assicurarmi che non fai cazzate, ho bisogno di
 * una view di debug in locale con una serie di link di accesso che mi permettano
 * di verificare ogni singolo caso per conto mio»*.
 *
 * 🔑 **Non è un extra: è il modo in cui la voce 63 si chiude.** Dopo il 27/09 non
 * basta che i test siano verdi — *«è crollato il requisito essenziale della
 * fiducia»*. ⛔ E in quella giornata si sono trovati **due test vacui** e **cinque
 * difetti dietro dei test verdi**: la diffidenza era fondata.
 *
 * ══ ⛔ LE QUATTRO REGOLE DI COSTRUZIONE ════════════════════════════════════
 *
 * 1. 🚨 **Solo in `local` e `testing`.** Il controllo sta qui **dentro**, non
 *    solo sulla rotta: una classe che crea palestre e utenti con una password
 *    nota non deve nemmeno potersi istanziare altrove.
 * 2. ⛔ **NIENTE IMPERSONAZIONE** (voce 64, e `divieti.md` del 27/09). Questi
 *    sono **account finti suoi**, su un database locale: non c'è nessuna persona
 *    vera di cui violare i dati. 🔑 La differenza non è il meccanismo, è il
 *    bersaglio.
 * 3. 🔑 **Gli scenari si costruiscono con i servizi VERI** —
 *    `IscrizioneDalSito` non si usa solo perché vorrebbe un piano comprabile e
 *    manderebbe una mail: si usa la stessa sequenza che usa lui
 *    (`Tenant::create` + ruoli nel contesto + `assignRole`), più
 *    `CreaTenantPersonale`, `AllenareInUnaPalestra` e `PortafoglioGettoni`.
 *    ⚠️ Uno scenario costruito con `insert` diretti prova una finzione.
 * 4. ⛔ **Non cancella niente.** `ricostruisci()` è **idempotente**: se gli
 *    account ci sono già, li riporta allo stato di partenza. 🚨 Una macchina di
 *    debug che cancella utenti è una macchina che un giorno verrà lanciata sul
 *    database sbagliato — e allora sarà servita la prudenza di oggi, non le
 *    guardie.
 *
 * ══ ⚠️ COSA VUOL DIRE «NON HA PAGATO» ═════════════════════════════════════
 *
 * 🔑 Lo stato **vero** in cui finisce un moroso dal 27/09: `status = suspended`
 * **e** l'abbonamento finito. ⛔ Simulare solo l'uno o solo l'altro proverebbe
 * uno stato che in produzione non esiste — ed è la differenza fra provare il
 * sistema e provare una scenografia.
 */
final class CasiDiProva
{
    /**
     * 🏷️ **Il dominio che riconosce i suoi.**
     *
     * ⚠️ Serve a una cosa sola e importante: la vista di debug entra **solo** in
     * un account il cui indirizzo finisce così. ⛔ Senza, un id nell'URL
     * diventerebbe un modo per entrare in un account vero.
     */
    public const DOMINIO = 'casi.local';

    /**
     * 🔑 Una password sola per tutti e venticinque.
     *
     * ⚠️ **Non è un segreto**: vive solo su database locali, dentro account che
     * esistono per essere provati. 💡 Va scritta in chiaro qui perché la vista la
     * mostra, ed è quella che si digita nell'emulatore.
     */
    public const PASSWORD = 'prova-in-locale-2026';

    /**
     * 🚦 **La vista è aperta?** — le due guardie della rotta, in un posto solo.
     *
     * ══ 🚨 PERCHE' E' UN METODO E NON DUE `&&` DENTRO `routes/web.php` ══════
     *
     * ⛔ Il primo giro scriveva la condizione a mano nel file delle rotte, e il
     * test che doveva provarla era **vacuo**: le rotte si registrano **all'avvio**,
     * quindi un test che scrive `config(['casi_di_prova.attiva' => true])` e poi
     * chiede la pagina non fa comparire niente — e passa **qualunque cosa**
     * facciano le guardie.
     *
     * 🔑 Con un metodo la tabella di verità si può interrogare **direttamente**,
     * e quel test diventa una prova vera. 💡 È la stessa lezione della `v36.29.0`
     * vista da un altro lato: non basta che una guardia esista, deve esserci un
     * modo di **dimostrare** che è lei a decidere.
     *
     * ⚠️ È `static` perché il costruttore **lancia** fuori da locale: una
     * domanda su *«questa cosa è accesa?»* non può pretendere che sia accesa per
     * poter essere fatta.
     */
    public static function vistaAttiva(): bool
    {
        return app()->environment('local')
            && config('casi_di_prova.attiva') === true;
    }

    public function __construct(
        private readonly TenantContext $contesto,
        private readonly CreaTenantPersonale $creaPersonale,
        private readonly AllenareInUnaPalestra $assumi,
        private readonly PortafoglioGettoni $portafoglio,
        private readonly LeLiberatorie $liberatorie,
    ) {
        /*
         * 🚨 **La guardia sta nel costruttore.** ⛔ Non su un metodo: così non
         * esiste nessun modo di arrivare a un pezzo di questa classe senza
         * averla attraversata, nemmeno dal contenitore delle dipendenze.
         */
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'I casi di prova esistono solo in locale. '
                .'⛔ Se questo errore compare su un server, qualcuno ha registrato una rotta che non doveva.'
            );
        }
    }

    // ═════════════════════════ l'elenco ═════════════════════════

    /**
     * 📋 **I venticinque casi, con i verdetti.**
     *
     * 🚨 **La metà che conta è `deve` / `nonDeve`.** ⛔ Per venticinque casi il
     * problema non è entrare: è **sapere se quello che si vede è giusto**. Senza
     * i verdetti scritti accanto, questa vista sposterebbe la fiducia dai test
     * alla mia memoria — cioè non risolverebbe niente.
     *
     * @return list<array{chiave: string, dove: 'pannello'|'app', titolo: string, deve: list<string>, nonDeve: list<string>}>
     */
    public function elenco(): array
    {
        return [
            // ───────────── il pannello ─────────────
            $this->voce('P1', 'pannello', 'Palestra che HA pagato', [
                'Il cruscotto pieno, con tutti i riquadri',
                'Il menù completo',
            ], ['Nessun avviso di pagamento']),

            $this->voce('P2', 'pannello', 'Palestra che NON ha pagato', [
                'Entra (mai un 403)',
                "In cima l'avviso «non siamo riusciti a incassare», col nome dello spazio",
                'E il tasto «Modifica il metodo di pagamento»',
                'Il menù con una voce sola',
            ], [
                'Gli altri riquadri del cruscotto',
                'Nessuna pagina raggiungibile oltre al cruscotto e «Il tuo piano»',
            ]),

            $this->voce('P3', 'pannello', 'Trainer di una palestra che HA pagato', [
                'Il suo pannello, con tutti gli iscritti della palestra',
            ], ['Voci da amministratore', 'Nessun avviso']),

            $this->voce('P4', 'pannello', 'Trainer di una palestra che NON ha pagato', [
                'Entra',
                'L\'avviso intestato ALLA PALESTRA, col suo nome',
            ], ['Non deve sembrare che debba pagare lui']),

            $this->voce('P5', 'pannello', 'Trainer indipendente che HA pagato', [
                'Il suo pannello, i suoi allievi',
            ], ['Nessun avviso']),

            $this->voce('P6', 'pannello', 'Trainer indipendente che NON ha pagato', [
                'Entra',
                'L\'avviso intestato A LUI',
            ], ['Nessuna porta chiusa']),

            $this->voce('P7', 'pannello', 'Indipendente che paga + trainer in palestra che paga', [
                'UN SOLO pannello, e il selettore dei due spazi',
            ], ['Nessun avviso, in nessuno dei due spazi']),

            $this->voce('P8', 'pannello', 'Indipendente che paga + palestra che NON paga', [
                'L\'avviso SOLO quando ha davanti lo spazio della palestra',
                'Che «Cambia spazio» lo porti DAVVERO nella palestra sospesa',
            ], ['L\'avviso nel SUO spazio, che è pagato']),

            $this->voce('P9', 'pannello', 'Indipendente che NON paga + palestra che paga', [
                'L\'avviso SOLO nel suo spazio',
            ], ['L\'avviso nello spazio della palestra, che paga']),

            $this->voce('P10', 'pannello', 'Indipendente che NON paga + palestra che NON paga', [
                'L\'avviso in TUTTI E DUE gli spazi, col nome giusto ciascuno',
            ], []),

            // ───────────── l'app ─────────────
            $this->voce('A1', 'app', 'Utente abbonato', [
                'Tutto acceso: AI, schede, diario',
            ], ['Nessuna proposta di abbonarsi'], true),

            $this->voce('A2', 'app', 'Utente NON abbonato', [
                'Entra e usa tutto ciò che è suo',
                'L\'AI chiede di abbonarsi',
            ], ['Nessun errore rosso'], false),

            $this->voce('A3', 'app', 'Utente con AI infinita', [
                'Nessun contatore e nessun tetto',
            ], [], true),

            $this->voce('A4', 'app', 'Utente con 100 gettoni', [
                'Il saldo 100',
                'Le funzioni a richiesta che scalano',
            ], [], false),

            $this->voce('A5', 'app', 'Utente in una palestra che HA pagato', [
                'I vantaggi della palestra e il suo vestito',
            ], [], true),

            $this->voce('A6', 'app', 'Utente in una palestra che NON ha pagato', [
                'Entra',
                'Tiene allenamenti, diario, progressi, backup',
                'L\'interfaccia dei NON abbonati',
            ], [
                'Nessun errore rosso generico',
                'Mai un messaggio che dica che ha perso i suoi dati',
            ], false),

            $this->voce('A7', 'app', '2 trainer, tutti e due pagano', [
                'Il piano migliore dei due',
            ], [], true),

            $this->voce('A8', 'app', '2 trainer, uno NON paga', [
                'Il piano del pagante',
            ], [], true),

            $this->voce('A9', 'app', '2 trainer, nessuno paga', [
                'Entra',
                "L'interfaccia dei NON abbonati",
            ], [], false),

            $this->voce('A10', 'app', '2 trainer paganti + palestra pagante', [
                'Il migliore fra i tre',
            ], [], true),

            $this->voce('A11', 'app', '2 trainer (uno non paga) + palestra pagante', [
                'Il migliore fra i due che pagano',
            ], [], true),

            $this->voce('A12', 'app', '2 trainer NON paganti + palestra pagante', [
                'Quello della palestra',
            ], [], true),

            $this->voce('A13', 'app', '2 trainer paganti + palestra NON pagante', [
                'Quello del trainer migliore',
            ], ['La palestra sospesa NON deve togliere ciò che paga il trainer'], true),

            $this->voce('A14', 'app', '2 trainer (uno non paga) + palestra NON pagante', [
                'Quello del trainer che paga',
            ], [], true),

            $this->voce('A15', 'app', '2 trainer NON paganti + palestra NON pagante', [
                'Entra comunque',
                "L'interfaccia dei NON abbonati",
            ], [], false),
        ];
    }

    /**
     * @param  list<string>  $deve
     * @param  list<string>  $nonDeve
     * @return array{chiave: string, dove: 'pannello'|'app', titolo: string, email: string, deve: list<string>, nonDeve: list<string>}
     */
    private function voce(string $chiave, string $dove, string $titolo, array $deve, array $nonDeve, ?bool $abbonato = null): array
    {
        return [
            'chiave' => $chiave,
            'dove' => $dove,
            'titolo' => $titolo,
            'email' => $this->email($chiave),
            'deve' => $deve,
            'nonDeve' => $nonDeve,
            /*
             * 🎯 **L'attesa verificabile a macchina** — 63.A.
             *
             * ⚠️ I verdetti sopra sono **parole**: le legge il committente. 🔑 Questo
             * è l'unica dimensione che una macchina può controllare da sé — *«l'app
             * lo vede abbonato?»* — e serve alla matrice automatica.
             *
             * ⛔ `null` per i casi del pannello: là la domanda non ha senso.
             */
            'abbonato' => $abbonato,
        ];
    }

    public function email(string $chiave): string
    {
        return mb_strtolower($chiave).'@'.self::DOMINIO;
    }

    /**
     * 🔎 L'utente di un caso, **solo se è uno dei nostri**.
     *
     * 🚨 Il controllo sul dominio è la riga che impedisce a un id nell'URL di
     * diventare un modo per entrare in un account vero.
     */
    public function utente(string $chiave): ?User
    {
        return $this->contesto->runWithoutTenant(
            fn (): ?User => User::withoutGlobalScopes()
                ->where('email', $this->email($chiave))
                ->first(),
        );
    }

    // ═════════════════════════ la costruzione ═════════════════════════

    /**
     * 🏗️ **Costruisce (o riporta allo stato iniziale) tutti i venticinque casi.**
     *
     * ⚠️ **Idempotente**: si può premere il tasto venti volte. 💡 Serve perché
     * dopo tre prove a mano il database è uno stato che nessuno sa più
     * descrivere — e un caso che non si sa più com'è non prova niente.
     */
    public function ricostruisci(): void
    {
        foreach ($this->elenco() as $caso) {
            $this->costruisci($caso['chiave']);
        }
    }

    public function costruisci(string $chiave): User
    {
        return match ($chiave) {
            'P1' => $this->palestraCon($chiave, paga: true),
            'P2' => $this->palestraCon($chiave, paga: false),
            'P3' => $this->trainerDiPalestra($chiave, palestraPaga: true),
            'P4' => $this->trainerDiPalestra($chiave, palestraPaga: false),
            'P5' => $this->indipendente($chiave, paga: true),
            'P6' => $this->indipendente($chiave, paga: false),
            'P7' => $this->dueCappelli($chiave, suoPaga: true, palestraPaga: true),
            'P8' => $this->dueCappelli($chiave, suoPaga: true, palestraPaga: false),
            'P9' => $this->dueCappelli($chiave, suoPaga: false, palestraPaga: true),
            'P10' => $this->dueCappelli($chiave, suoPaga: false, palestraPaga: false),

            'A1' => $this->persona($chiave, abbonata: true),
            'A2' => $this->persona($chiave, abbonata: false),
            'A3' => $this->conAiInfinita($chiave),
            'A4' => $this->conGettoni($chiave, 100),
            'A5' => $this->iscrittoInPalestra($chiave, palestraPaga: true),
            'A6' => $this->iscrittoInPalestra($chiave, palestraPaga: false),
            'A7' => $this->conDueTrainer($chiave, primoPaga: true, secondoPaga: true),
            'A8' => $this->conDueTrainer($chiave, primoPaga: true, secondoPaga: false),
            'A9' => $this->conDueTrainer($chiave, primoPaga: false, secondoPaga: false),
            'A10' => $this->conDueTrainer($chiave, primoPaga: true, secondoPaga: true, palestraPaga: true),
            'A11' => $this->conDueTrainer($chiave, primoPaga: true, secondoPaga: false, palestraPaga: true),
            'A12' => $this->conDueTrainer($chiave, primoPaga: false, secondoPaga: false, palestraPaga: true),
            'A13' => $this->conDueTrainer($chiave, primoPaga: true, secondoPaga: true, palestraPaga: false),
            'A14' => $this->conDueTrainer($chiave, primoPaga: true, secondoPaga: false, palestraPaga: false),
            'A15' => $this->conDueTrainer($chiave, primoPaga: false, secondoPaga: false, palestraPaga: false),

            default => throw new RuntimeException("Caso sconosciuto: {$chiave}."),
        };
    }

    // ───────────────────────── gli scenari ─────────────────────────

    private function palestraCon(string $chiave, bool $paga): User
    {
        $palestra = $this->palestra($chiave.' Palestra', $chiave, $paga);

        return $this->dentro($palestra, $chiave, UserRole::GymAdmin);
    }

    private function trainerDiPalestra(string $chiave, bool $palestraPaga): User
    {
        $palestra = $this->palestra($chiave.' Palestra', $chiave, $palestraPaga);

        /* ⚠️ Una palestra senza titolare è uno stato che non esiste in produzione. */
        $this->dentro($palestra, $chiave.'-capo', UserRole::GymAdmin);

        return $this->dentro($palestra, $chiave, UserRole::Trainer);
    }

    private function indipendente(string $chiave, bool $paga): User
    {
        $trainer = $this->personale($chiave, UserRole::FreeTrainer);

        $this->pagaOppureNo($this->spazioDi($trainer), $paga, $this->pianoDaTrainer());

        return $trainer;
    }

    /**
     * 🎩 **Due cappelli**: trainer indipendente col suo spazio **e** trainer
     * dentro una palestra.
     *
     * 🚨 È il caso in cui si nascondevano i difetti peggiori di F1: l'avviso
     * deve parlare **dello spazio che si ha davanti**, non della casa di chi
     * guarda.
     */
    private function dueCappelli(string $chiave, bool $suoPaga, bool $palestraPaga): User
    {
        $trainer = $this->indipendente($chiave, $suoPaga);

        $palestra = $this->palestra($chiave.' Palestra', $chiave, $palestraPaga);
        $this->dentro($palestra, $chiave.'-capo', UserRole::GymAdmin);

        $this->assumi->assumi($trainer, $palestra);
        $this->firma($trainer);

        return $trainer->fresh() ?? $trainer;
    }

    private function persona(string $chiave, bool $abbonata): User
    {
        $persona = $this->personale($chiave, UserRole::FreeUser);

        $this->pagaOppureNo($this->spazioDi($persona), $abbonata, $this->pianoIndividuale());

        return $persona;
    }

    private function conAiInfinita(string $chiave): User
    {
        $persona = $this->persona($chiave, abbonata: true);

        /*
         * ⚠️ **I due tetti a zero, non uno.** `MemberAiQuota::senzaTetto()`
         * pretende anche quello delle foto: con il solo primo, una richiesta con
         * allegato risponderebbe **402 al primo alimento fotografato** — cioè la
         * concessione prometterebbe l'illimitato e non lo darebbe.
         */
        $persona->forceFill([
            'ai_monthly_call_cap' => 0,
            'ai_monthly_photo_call_cap' => 0,
        ])->save();

        return $persona;
    }

    private function conGettoni(string $chiave, int $quanti): User
    {
        $persona = $this->persona($chiave, abbonata: false);

        /* ⚠️ Prima si azzera, o premendo due volte il tasto diventano 200. */
        DB::table('users')->where('id', $persona->getKey())->update(['ai_credits' => 0]);

        $this->portafoglio->accredita(
            $persona->fresh() ?? $persona,
            $quanti,
            nota: 'caso di prova '.$chiave,
            causale: AiCreditMovement::RETTIFICA,
        );

        return $persona->fresh() ?? $persona;
    }

    private function iscrittoInPalestra(string $chiave, bool $palestraPaga): User
    {
        $palestra = $this->palestra($chiave.' Palestra', $chiave, $palestraPaga);
        $this->dentro($palestra, $chiave.'-capo', UserRole::GymAdmin);

        return $this->dentro($palestra, $chiave, UserRole::Member);
    }

    /**
     * 👥 Un allievo seguito da **due** trainer indipendenti, e — se richiesto —
     * dentro una palestra.
     *
     * 🚨 L'allievo vive nello spazio del **secondo** trainer quando non c'è una
     * palestra: ⚠️ è il caso che smaschera i difetti, perché se i diritti
     * venissero «dall'insieme di chi paga» il primo basterebbe sempre.
     */
    private function conDueTrainer(
        string $chiave,
        bool $primoPaga,
        bool $secondoPaga,
        ?bool $palestraPaga = null,
    ): User {
        $uno = $this->personale($chiave.'-t1', UserRole::FreeTrainer);
        $due = $this->personale($chiave.'-t2', UserRole::FreeTrainer);

        $this->pagaOppureNo($this->spazioDi($uno), $primoPaga, $this->pianoDaTrainer());
        $this->pagaOppureNo($this->spazioDi($due), $secondoPaga, $this->pianoDaTrainer());

        if ($palestraPaga === null) {
            $allievo = $this->dentro($this->spazioDi($due), $chiave, UserRole::FreeUser);
        } else {
            $palestra = $this->palestra($chiave.' Palestra', $chiave, $palestraPaga);
            $this->dentro($palestra, $chiave.'-capo', UserRole::GymAdmin);
            $allievo = $this->dentro($palestra, $chiave, UserRole::Member);
        }

        foreach ([$uno, $due] as $trainer) {
            $this->collega($trainer, $allievo);
        }

        return $allievo->fresh() ?? $allievo;
    }

    // ───────────────────────── i mattoni ─────────────────────────

    /**
     * 🏢 Una palestra, con i suoi ruoli — **la stessa sequenza** di
     * `IscrizioneDalSito::apriLaPalestra()`.
     *
     * ⚠️ Non si chiama quel servizio direttamente perché pretende un **piano
     * comprabile** e manda la mail di benvenuto: due cose che in una vista di
     * debug sono rumore. 💡 Ma la sequenza è identica, compreso lo svuotamento
     * della cache dei permessi — senza il quale `assignRole()` fallisce con «There
     * is no role named…» pur essendo la riga già a database.
     */
    private function palestra(string $nome, string $chiave, bool $paga): Tenant
    {
        $slug = 'casi-'.mb_strtolower($chiave);

        $palestra = $this->contesto->runWithoutTenant(
            fn (): ?Tenant => Tenant::withoutGlobalScopes()->where('slug', $slug)->first(),
        );

        if ($palestra === null) {
            $palestra = Tenant::create([
                'name' => $nome,
                'slug' => $slug,
                'kind' => TenantKind::Gym,
                'status' => TenantStatus::Active,
                'contact_email' => $this->email($chiave),
                'locale' => 'it',
            ]);

            $this->contesto->runAs($palestra, function () use ($palestra): void {
                foreach (UserRole::tenantScoped() as $ruolo) {
                    Role::firstOrCreate([
                        'name' => $ruolo->value,
                        'guard_name' => 'web',
                        'tenant_id' => $palestra->getKey(),
                    ]);
                }

                app(PermissionRegistrar::class)->forgetCachedPermissions();
            });
        }

        $this->pagaOppureNo($palestra, $paga, $this->pianoDaPalestra());

        return $palestra->fresh() ?? $palestra;
    }

    /** 🏠 Uno spazio personale, con la strada vera. */
    private function personale(string $chiave, UserRole $ruolo): User
    {
        $gia = $this->utente($chiave);

        if ($gia !== null) {
            return $gia;
        }

        $persona = ($this->creaPersonale)(
            ucfirst(str_replace('-', ' ', $chiave)),
            $this->email($chiave),
            ['password' => self::PASSWORD],
            $ruolo,
        );

        $this->maggiorenneEDaccordo($persona);
        $this->firma($persona);

        return $persona;
    }

    /** 👤 Una persona **dentro** uno spazio che esiste già. */
    private function dentro(Tenant $spazio, string $chiave, UserRole $ruolo): User
    {
        $gia = $this->utente($chiave);

        if ($gia !== null) {
            return $gia;
        }

        $persona = $this->contesto->runAs($spazio, function () use ($spazio, $chiave, $ruolo): User {
            $u = User::create([
                'tenant_id' => $spazio->getKey(),
                'name' => ucfirst(str_replace('-', ' ', $chiave)),
                'email' => $this->email($chiave),
                'password' => self::PASSWORD,
                'locale' => 'it',
            ]);

            $u->assignRole($ruolo->value);

            return $u;
        });

        if ($ruolo === UserRole::GymAdmin && $spazio->proprietario_id === null) {
            $spazio->forceFill(['proprietario_id' => $persona->getKey()])->save();
        }

        $this->maggiorenneEDaccordo($persona);
        $this->firma($persona);

        return $persona;
    }

    /**
     * 💳 **Paga o non paga**, e «non paga» è lo stato VERO di un moroso.
     *
     * 🔑 `status = suspended` **e** abbonamento finito: è esattamente ciò che
     * produce `LaSospensioneAutomatica`. ⛔ Simulare solo uno dei due proverebbe
     * uno stato che in produzione non esiste.
     */
    private function pagaOppureNo(Tenant $spazio, bool $paga, Plan $piano): void
    {
        $this->contesto->runWithoutTenant(function () use ($spazio, $paga, $piano): void {
            /* ⚠️ Si riparte da zero: premere due volte il tasto non deve accumulare abbonamenti. */
            PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $spazio->getKey())
                ->delete();

            PlanSubscription::withoutGlobalScopes()->create([
                'tenant_id' => $spazio->getKey(),
                'plan_id' => $piano->getKey(),
                'starts_at' => now()->subYears(2),
                'ends_at' => $paga ? null : now()->subDays(30),
                'rinnova' => $paga,
            ]);

            $spazio->forceFill([
                'status' => $paga ? TenantStatus::Active : TenantStatus::Suspended,
                'sospensione_automatica_dal' => $paga ? null : now()->subDays(29),
            ])->save();
        });
    }

    /** 🔗 Il legame trainer ↔ allievo, nello spazio del trainer. */
    private function collega(User $trainer, User $allievo): void
    {
        $this->contesto->runWithoutTenant(function () use ($trainer, $allievo): void {
            $gia = DB::table('trainer_member')
                ->where('trainer_id', $trainer->getKey())
                ->where('member_id', $allievo->getKey())
                ->exists();

            if ($gia) {
                return;
            }

            $trainer->assignedMembers()->attach($allievo->getKey(), [
                'tenant_id' => $trainer->tenant_id,
                'assigned_at' => now(),
            ]);
        });
    }

    private function spazioDi(User $persona): Tenant
    {
        return $this->contesto->runWithoutTenant(
            fn (): Tenant => Tenant::withoutGlobalScopes()->findOrFail($persona->tenant_id),
        );
    }

    /**
     * ✍️ Maggiore età e condizioni: **si danno per fatte**, come in
     * `CreaAmbiente` dei test.
     *
     * ⚠️ `health_consent_at` e `ai_consent_at` restano **`null`** ed è
     * deliberato: è lo stato di serie di chiunque si iscrive. ⛔ Concederli qui
     * vorrebbe dire non poter più provare il cancello dell'AI dalla vista.
     */
    private function maggiorenneEDaccordo(User $persona): void
    {
        $persona->forceFill([
            'age_confirmed_at' => now(),
            'terms_accepted_at' => now(),
        ])->save();
    }

    /**
     * ✍️ La liberatoria dell'art. 28.
     *
     * 🚨 Senza, il pannello risponde **302 verso `/admin/liberatoria`** — che è
     * il comportamento **giusto**, ma dalla vista di debug sembrerebbe un difetto
     * dell'accesso. ⚠️ È la trappola pagata scrivendo i test di F1.
     */
    private function firma(User $persona): void
    {
        $spazio = $this->spazioDi($persona);

        $this->contesto->runAs($spazio, function () use ($persona): void {
            foreach ([LeLiberatorie::PALESTRA, LeLiberatorie::TRAINER] as $tipo) {
                if ($this->liberatorie->riguarda($persona, $tipo)) {
                    $this->liberatorie->firma($persona, $tipo);
                }
            }
        });
    }

    // ───────────────────────── i piani ─────────────────────────

    private function pianoDaPalestra(): Plan
    {
        return $this->pianoOppureCrealo(Plan::GYM, PlanKind::Gym, 'Palestra', 19900, 200);
    }

    private function pianoDaTrainer(): Plan
    {
        return $this->pianoOppureCrealo(Plan::TRAINER_PRO, PlanKind::Trainer, 'Trainer Pro', 4900, 60);
    }

    private function pianoIndividuale(): Plan
    {
        return $this->pianoOppureCrealo(Plan::PLUS, PlanKind::Person, 'Plus', 990, 1);
    }

    /**
     * ⚠️ **Si crea solo se manca.** Un database locale può non avere il listino
     * seminato, e pretenderlo farebbe morire la vista con un errore che non
     * c'entra niente con quello che si sta provando.
     */
    private function pianoOppureCrealo(
        string $codice,
        PlanKind $tipo,
        string $nome,
        int $prezzo,
        int $posti,
    ): Plan {
        $piano = Plan::query()->where('code', $codice)->first();

        if ($piano !== null) {
            return $piano;
        }

        return Plan::create([
            'code' => $codice,
            'name' => $nome,
            'kind' => $tipo,
            'ai_enabled' => true,
            'max_members' => $posti,
            'price_cents' => $prezzo,
            'is_public' => true,
        ]);
    }
}
