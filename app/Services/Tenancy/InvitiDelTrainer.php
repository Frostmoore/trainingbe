<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\PlanKind;
use App\Enums\TipoConversazione;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Plan;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Billing\PianoAttivo;
use App\Services\Tenancy\Exceptions\HaiGiaUnTrainerException;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Inviti personali di un trainer indipendente — F6.2, F6.4, F6.5.
 *
 * 🚨 **Il punto unico da cui passano le tre regole del rapporto trainer↔utente**:
 * quanti utenti può avere, come ne invita uno, e cosa succede quando lo
 * disattiva. Sparse fra controller e pannello divergerebbero.
 */
class InvitiDelTrainer
{
    public function __construct(
        private readonly PianoAttivo $piano,
        private readonly CreaTenantPersonale $creaTenantPersonale,
        private readonly UnisciAUnaPalestra $unisci,
        private readonly TenantContext $context,
    ) {}

    /**
     * 🚨 **Solo chi ha uno spazio SUO può invitare allievi propri** — AH.1-bis.
     *
     * ══ ⛔ IL BUCO CHE AH.1 AVEVA APERTO, E CHE QUESTA RIGA CHIUDE ═════════
     *
     * Da AH.1 chi riscatta un invito di un trainer **nasce dentro il tenant del
     * trainer**. ⚠️ Per un trainer di palestra quel tenant **è la palestra**.
     *
     * 🚨 Quindi, senza questa guardia, un trainer dipendente avrebbe potuto
     * creare inviti personali e far entrare persone **dentro la palestra**
     * saltando `InvitiInPalestra::postiRimasti()`, cioè **il tetto dei posti
     * che la palestra paga**. ⛔ Il suo conteggio (`postiRimasti()` qui sotto)
     * guarda gli allievi assegnati **a lui**, non gli iscritti della palestra:
     * con tre trainer, una palestra da cinquanta posti se ne sarebbe ritrovati
     * centocinquanta, e nessun errore da nessuna parte.
     *
     * 💡 **Prima di AH.1 non era un buco** perché l'invitato nasceva in un
     * tenant personale suo e nella palestra non entrava.
     *
     * ── ⚠️ E non è una perdita di funzioni per il trainer di palestra ──────
     *
     * I suoi allievi sono **gli iscritti della palestra**, che vede tutti e
     * subito senza che nessuno glieli assegni — vedi `ChiSiVede`. È la divisione
     * già scritta in `AH.1-bis`, dove
     * *«può comprare un pacchetto allievi»* è `no` per lui e `sì` per un
     * indipendente. 💡 Chi lavora in palestra e vuole clienti suoi **si abbona
     * da `free_trainer`**, e allora ha uno spazio suo — le due cose non sono
     * mutuamente esclusive (`AH.1-quater`).
     *
     * 🚨 **La domanda è `eDi()`, non `isFreeTrainer()`**, ed è la regola 1 di
     * AH: quello che conta non è il ruolo, è **chi paga per quello spazio**.
     *
     * @throws ValidationException
     */
    private function pretendiUnoSpazioSuo(User $trainer): void
    {
        if (! $this->puoAvereAllieviSuoi($trainer)) {
            throw ValidationException::withMessages([
                /*
                 * 🆕 ⛔ **La frase diceva una cosa falsa** — 15/09/2026.
                 *
                 * Diceva *«gli iscritti della palestra te li assegna la
                 * palestra»*, e non glieli assegna nessuno: gli iscritti di una
                 * palestra sono **gia' di tutti** i suoi trainer, senza nessuna
                 * assegnazione. 📌 E' la regola detta dal committente, e sta
                 * scritta per esteso in `ChiSiVede`.
                 */
                'invito' => __('Gli iscritti della palestra li vedi gia\' tutti. Per avere allievi tuoi, fuori dalla palestra, serve un abbonamento da trainer indipendente.'),
            ]);
        }
    }

    /**
     * Lo spazio in cui vive questo trainer è suo?
     *
     * 🆕 **Pubblica da 15/09/2026**: e' la stessa domanda che decide se
     * mostrargli i posti del piano. ⛔ Un trainer di sola palestra non ha posti
     * propri — gli iscritti li paga la palestra — e vedersi scritto «utenti
     * illimitati» gli dice una cosa che non lo riguarda.
     */
    public function haUnoSpazioSuo(User $trainer): bool
    {
        $suo = $trainer->tenant;

        return $suo !== null && $suo->eDi($trainer);
    }

    /**
     * 🆕 **Può avere allievi suoi?** — giro A6, 22/09/2026.
     *
     * 💡 Uno spazio suo **e** un piano da trainer su quello spazio
     * (`PlanKind::Trainer`). ⛔ La fascia gratuita `trainer_free` non conta:
     * `PianoAttivo::per()` la scarta (committente, 22/09/2026: *«l'unica
     * possibilità di essere free trainer è l'abbonamento minimo»*).
     *
     * ⛔ **Prima bastava `haUnoSpazioSuo()`**, e fino al 18/09 era la stessa
     * domanda: un trainer di sola palestra viveva **dentro** la palestra, quindi
     * lo spazio non era suo. Dalla decisione 2 (`DaiUnaCasaAlTrainer`) ogni
     * trainer ha uno spazio suo, e un trainer creato dal pannello — sul piano
     * gratuito da persona, `max_members` nullo — risultava avere allievi propri
     * **senza limite**, gratis. 📌 La regola resta quella scritta sopra: *«per
     * avere allievi tuoi serve un abbonamento da trainer indipendente»*.
     *
     * ⚠️ E un indipendente col piano scaduto ricade sul gratuito da persona:
     * niente inviti nuovi e niente riscatti di quelli vecchi, come per tutte
     * le altre funzioni da trainer (U.3.1).
     */
    public function puoAvereAllieviSuoi(User $trainer): bool
    {
        return $this->haUnoSpazioSuo($trainer)
            && $this->piano->per($trainer)->kind === PlanKind::Trainer;
    }

    /**
     * 🆕 Un allievo nuovo, dentro lo spazio del suo trainer — AH.1.
     *
     * ⚠️ **Non passa da `CreaTenantPersonale`**, che di mestiere crea *un tenant
     * e una persona dentro*: qui il tenant c'è già ed è quello del trainer.
     *
     * 🚨 **Se il trainer non avesse un tenant si ricade sulla strada vecchia.**
     * ⛔ Un `tenant_id` nullo su un utente significa **super admin**
     * (`TenantScope` non filtra niente): la direzione dell'errore dev'essere
     * «questa persona resta nel suo spazio», mai «questa persona vede tutto».
     *
     * @param  array<string, mixed>  $attributi
     */
    private function nasceNelloSpazioDi(User $trainer, string $nome, string $email, array $attributi): User
    {
        $spazio = $trainer->tenant;

        if ($spazio === null) {
            return ($this->creaTenantPersonale)($nome, $email, $attributi);
        }

        return $this->context->runAs($spazio, function () use ($spazio, $nome, $email, $attributi): User {
            $utente = User::create(array_merge($attributi, [
                'name' => $nome,
                'email' => $email,

                /*
                 * ⚠️ Esplicito anche se `BelongsToTenant` lo riempirebbe dal
                 * contesto: è la riga da cui dipende tutto, e un valore scritto
                 * a mano si nota leggendo mentre un riempimento implicito si
                 * nota solo quando manca.
                 */
                'tenant_id' => $spazio->getKey(),
            ]));

            $utente->assignRole(UserRole::Member->value);

            return $utente;
        });
    }

    /**
     * Quanti utenti può ancora accettare. `null` = senza limite — F6.5.
     *
     * ⚠️ **Gli inviti ancora validi contano come posti occupati.** Senza,
     * un trainer con tre posti potrebbe mandare trenta inviti e ritrovarsi con
     * trenta utenti: il limite verrebbe verificato al momento sbagliato, cioè
     * quando è troppo tardi per dire di no a qualcuno che ha già cliccato.
     */
    public function postiRimasti(User $trainer): ?int
    {
        $max = $this->limiteDelPiano($trainer);

        if ($max === null) {
            return null;
        }

        /*
         * ══ 🆕 SOLO I RAPPORTI IN PIEDI — 3b-AH.1-ter (12/09/2026) ══════════
         *
         * 📌 *«se uno dei due libera l'allievo, semplicemente gli si libera uno
         * slot allievo»*.
         *
         * ⛔ **Qui si contavano tutte le righe**, disattivate comprese. Finché
         * chi se ne andava veniva **cancellato** dalla tabella il conto tornava
         * per caso; da AH.2 le righe si disattivano e basta (D5: *«il legame
         * resta, la storia si conserva»*), e senza questo filtro un trainer si
         * ritroverebbe i posti occupati da rapporti finiti — **per sempre, e
         * senza nessun errore**.
         *
         * 🚨 È lo slot che è **il rapporto**, non la persona: un rapporto
         * chiuso non occupa niente.
         */
        $occupati = $trainer->assignedMembers()->wherePivotNull('disattivato_il')->count()
            + TrainerInvite::withoutGlobalScopes()
                ->where('trainer_id', $trainer->getKey())
                ->validi()
                ->count();

        return max(0, $max - $occupati);
    }

    /**
     * Crea un invito monouso.
     *
     * @throws ValidationException quando i posti sono finiti
     */
    public function invita(User $trainer, ?string $email = null): TrainerInvite
    {
        $this->pretendiUnoSpazioSuo($trainer);

        $rimasti = $this->postiRimasti($trainer);

        if ($rimasti !== null && $rimasti <= 0) {
            /*
             * 💡 Il messaggio dice **cosa fare**, non solo cosa non si può fare.
             * «Hai raggiunto il limite» lascia la persona ferma; dire che il
             * piano si può cambiare è l'unica informazione utile in quel momento.
             */
            throw ValidationException::withMessages([
                'invito' => __('Hai raggiunto il numero di utenti compreso nel tuo piano. Per averne di più serve un piano superiore.'),
            ]);
        }

        return TrainerInvite::withoutGlobalScopes()->create([
            'tenant_id' => $trainer->tenant_id,
            'trainer_id' => $trainer->getKey(),
            'token' => TrainerInvite::generaToken(),
            'email' => $email,
            'expires_at' => now()->addDays(TrainerInvite::GIORNI_DI_VITA),
        ]);
    }

    /**
     * Riscatta un invito: nasce l'utente, con il suo tenant personale, e il
     * legame con il trainer.
     *
     * 🚨 **`$maggiorenne` e `$condizioni` sono parametri obbligatori e senza
     * valore di serie** (§2.4). Un `= true` qui dentro vorrebbe dire dichiarare
     * la maggiore età al posto di chi si iscrive — esattamente ciò che lo
     * sbarramento esiste per impedire. Questa è una **porta d'ingresso nuova**,
     * e nasce con lo sbarramento già montato.
     *
     * ⚠️ Il posto si ricontrolla **adesso**, non solo alla creazione
     * dell'invito: fra i due momenti passa fino a una settimana, e nel
     * frattempo il trainer può aver riempito i posti con altri inviti.
     *
     * @param  array<string, mixed>  $attributi  password, username, telefono…
     *
     * @throws ValidationException
     */
    /**
     * 🆕 👀 **L'anteprima di un invito, per chi ancora non è nessuno** —
     * 17/09/2026.
     *
     * ⛔ **Mancava, e con lei mancava tutta la strada.** La pagina web diceva
     * *«apri l'app e tocca "Ho un invito"»*, e quel pulsante non esisteva: i due
     * endpoint che accettano un invito da trainer — `riscatta` e
     * `register-with-invite` — non li chiamava **nessuno**. 🚨 L'intero giro
     * viveva sul server senza un cliente.
     *
     * ⚠️ **Pubblica**, come quella della palestra e per la stessa ragione: chi
     * tocca il link spesso non ha ancora l'app. Se l'anteprima chiedesse
     * l'accesso, la prima cosa che vedrebbe sarebbe un modulo invece
     * dell'invito che gli è stato mandato.
     *
     * ⛔ **E dice pochissimo**: il nome di chi invita e quando scade. Il token
     * è una credenziale che gira nelle chat, e da un'anteprima pubblica non deve
     * uscire niente su cui costruire un profilo di qualcun altro.
     *
     * 💡 `null` per scaduto, usato, revocato **e** inesistente: distinguerli
     * permetterebbe di provare token a tappeto e sapere quali sono esistiti.
     *
     * @return array{trainer: ?string, scade_il: string}|null
     */
    public function anteprima(string $token): ?array
    {
        $invito = TrainerInvite::withoutGlobalScopes()->where('token', $token)->first();

        if ($invito === null || ! $invito->eValido()) {
            return null;
        }

        $trainer = User::query()->withoutGlobalScopes()->find($invito->trainer_id);

        /*
         * ⚠️ **Le stesse guardie di `riscatta()`**, e non e' un doppione: un
         * invito di un trainer spento, o che ha perso il proprio spazio, non
         * porta da nessuna parte. 💡 Mostrarlo come valido vorrebbe dire far
         * toccare «accetto» a qualcuno per poi dirgli di no.
         */
        if ($trainer === null || ! $trainer->is_active || ! $this->puoAvereAllieviSuoi($trainer)) {
            return null;
        }

        return [
            'trainer' => $trainer->name,
            'scade_il' => $invito->expires_at?->toIso8601String() ?? '',
        ];
    }

    public function riscatta(
        string $token,
        string $nome,
        string $email,
        array $attributi,
        bool $maggiorenne,
        bool $condizioni,
    ): User {
        if (! $maggiorenne || ! $condizioni) {
            throw ValidationException::withMessages([
                'age_confirmed' => __('Per iscriverti devi dichiarare di essere maggiorenne e accettare le condizioni.'),
            ]);
        }

        $invito = TrainerInvite::withoutGlobalScopes()->where('token', $token)->first();

        if ($invito === null || ! $invito->eValido()) {
            /*
             * 🚨 Un solo messaggio per «non esiste», «già usato», «scaduto» e
             * «revocato». Distinguerli permetterebbe di provare token a tappeto
             * e capire quali sono esistiti — e quando.
             */
            throw ValidationException::withMessages([
                'token' => __('Questo invito non è più valido.'),
            ]);
        }

        $trainer = $invito->trainer;

        if ($trainer === null || ! $trainer->is_active || ! $this->puoAvereAllieviSuoi($trainer)) {
            /*
             * 🚨 **Lo spazio si ricontrolla ADESSO, non solo quando l'invito è
             * nato** — AH.1-bis.
             *
             * ⚠️ Fra i due momenti passa fino a una settimana, e in mezzo il
             * trainer può essere entrato in una palestra o aver smesso di
             * pagare. ⛔ Un invito nato valido non è un permesso perpetuo: è
             * lo stesso ragionamento già scritto per i posti, due righe più in
             * basso.
             *
             * 💡 E copre anche gli inviti creati **prima** di questa guardia,
             * che altrimenti resterebbero a far entrare gente dentro una
             * palestra per un'altra settimana.
             */
            throw ValidationException::withMessages([
                'token' => __('Questo invito non è più valido.'),
            ]);
        }

        $rimasti = $this->postiRimasti($trainer);

        if ($rimasti !== null && $rimasti <= 0) {
            throw ValidationException::withMessages([
                'token' => __('Questo invito non è più valido.'),
            ]);
        }

        /*
         * ══ 🆕 NASCE DENTRO LO SPAZIO DEL TRAINER — 3b-AH, AH.1 (12/09/2026) ══
         *
         * 📌 Il committente: *«un allievo che è iscritto a un `free_trainer`
         * deve avere come tenant quel trainer — un trainer in sostanza è **una
         * palestra con un unico trainer***».
         *
         * ⛔ **Prima nasceva con un tenant personale tutto suo**, e la copertura
         * dell'abbonamento gli arrivava per una strada laterale
         * (`QuotaDelTrainer`, nata per la quota AI e allargata al gate il
         * 09/09). 🚨 Due domande vicine — *«ha l'AI?»* e *«è abbonato?»* —
         * rispondevano in modo diverso alla stessa situazione, e per settimane
         * nessuna delle due ha dato un errore.
         *
         * 💡 Con il tenant giusto quella deviazione **non serve più**, ed è
         * stata tolta: l'abbonamento sta sul tenant, e adesso l'allievo sta nel
         * tenant che paga.
         *
         * ⚠️ Lo spazio del trainer resta `kind = personal`: il cliente è **lui**,
         * non un'organizzazione. Chi lo possiede lo dice `proprietario_id`.
         */
        $utente = $this->nasceNelloSpazioDi($trainer, $nome, $email, $attributi);

        $utente->forceFill([
            'age_confirmed_at' => now(),
            'terms_accepted_at' => now(),
        ])->save();

        /*
         * 🚨 **`trainer_member.tenant_id` è quello del TRAINER** — F6.1, C4.
         *
         * La colonna è NOT NULL e va decisa. È quello del trainer perché è lui
         * che «possiede» il rapporto: altrimenti non vedrebbe i propri legami
         * sotto il proprio scope, cioè non vedrebbe i propri utenti.
         */
        $utente->assignedTrainers()->attach($trainer->getKey(), [
            'tenant_id' => $trainer->tenant_id,
            'assigned_at' => now(),
            'assigned_by' => $trainer->getKey(),
        ]);

        $invito->forceFill([
            'used_at' => now(),
            'accepted_by' => $utente->getKey(),
        ])->save();

        return $utente;
    }

    /**
     * 🚨 **Il riscatto di chi ha GIA' un account** — M6.2, 18/08/2026.
     *
     * ── Perche' non bastava `riscatta()` ───────────────────────────────────
     *
     * Quello crea una persona nuova: e' la strada di chi arriva dal link senza
     * avere ancora niente. ⚠️ Ma il caso che la Parte M ha reso normale e'
     * l'opposto: qualcuno che **usa gia' l'app**, ha scritto a un trainer dal
     * catalogo, ha finito i tre messaggi, e riceve da lui un invito **in chat**.
     * Quella persona un account ce l'ha, e mandarla su un modulo di iscrizione
     * le direbbe di crearne un secondo.
     *
     * 💡 **E qui non si chiede l'abbonamento**, che e' tutto il punto della
     * decisione del committente: *«il link d'invito assegna chi lo usa — anche
     * se non abbonato — a chi l'ha creato»*. Diventare allievo di un trainer
     * apre la chat illimitata **con lui**, che e' un rapporto vero e non un
     * contatto a freddo.
     *
     * ══ 🆕 E ADESSO IL TENANT LO TOCCA, MA SOLO A CHI HA UNO SPAZIO SUO ═══
     *
     * 🚨 **Fino all'11/09 non toccava il tenant**, e con AH.1 quella scelta si
     * sarebbe rovesciata da sola: togliendo la deviazione da `QuotaDelTrainer`,
     * chi arriva da questa strada **smetterebbe di risultare abbonato** — cioè
     * un allievo che paga il trainer con cinque card sfumate e un allenamento
     * al giorno. ⛔ Lo stesso difetto chiuso il 09/09, riaperto dall'altro lato.
     *
     * 💡 Quindi si trasloca, con **lo stesso identico gesto** di chi entra in
     * una palestra (`UnisciAUnaPalestra::dentroQuestoTenant()`): stesse
     * guardie, stesso elenco di tabelle, stesso modo di rifare i ruoli.
     *
     * ── ⛔ Ma solo chi lo spazio ce l'ha suo, e vuoto ──────────────────────
     *
     * 🚨 **Un iscritto di palestra resta dov'è**, ed è la richiesta di AH.1-ter:
     * *«un allievo di una palestra potrebbe avere anche un personal trainer che
     * non è parte della palestra»*. Per lui il rapporto attraversa i tenant come
     * sempre, e occupa uno slot da tutte e due le parti.
     *
     * ⚠️ **E chi ha già degli allievi nel proprio spazio non si muove**: un
     * trainer che diventa allievo di un altro trainer si porterebbe dietro i
     * propri. In tutti e due i casi il rapporto si crea lo stesso — cambia solo
     * chi paga, che resta chi pagava prima.
     *
     * @param  bool  $affianco  🆕 la persona **sa** di avere gia' un trainer e
     *                          vuole comunque questo secondo — AH.1-ter.4
     *
     * @throws ValidationException
     * @throws HaiGiaUnTrainerException
     */
    public function riscattaPerChiEsiste(string $token, User $utente, bool $affianco = false): User
    {
        $invito = TrainerInvite::withoutGlobalScopes()->where('token', $token)->first();

        // 🚨 Un solo messaggio per «non esiste», «gia' usato», «scaduto» e
        // «revocato»: distinguerli permetterebbe di provare token a tappeto.
        $nonValido = fn (): never => throw ValidationException::withMessages([
            'token' => __('Questo invito non è più valido.'),
        ]);

        if ($invito === null || ! $invito->eValido()) {
            $nonValido();
        }

        /*
         * 🚨 `withoutGlobalScopes()`, e senza questa riga NIENTE funziona.
         *
         * ⚠️ `TrainerInvite::trainer()` e' una relazione su `User`, che usa
         * `BelongsToTenant`: risolta qui viene filtrata dal tenant di **chi sta
         * riscattando**, che per definizione e' un altro. Il trainer risulta
         * `null` e ogni invito valido viene respinto come «non piu' valido».
         *
         * 💡 La strada della **registrazione** (`riscatta()`) non ha questo
         * problema perche' li' non c'e' nessuno autenticato, quindi nessun
         * contesto: e' lo stesso codice che si comporta in due modi a seconda di
         * chi lo chiama. Terza volta che questa trappola si presenta nella
         * Parte M — vedi §49.6 e §50.6 dell'atlante.
         */
        $trainer = User::query()->withoutGlobalScopes()->find($invito->trainer_id);

        // 🚨 Stessa guardia di `riscatta()`, per la stessa ragione — AH.1-bis.
        if ($trainer === null || ! $trainer->is_active || ! $this->puoAvereAllieviSuoi($trainer)) {
            $nonValido();
        }

        /*
         * ⚠️ **Non si e' allievi di se' stessi.** Un trainer che apre il proprio
         * link — capita, provando come si vede — si ritroverebbe fra i propri
         * allievi, e da li' in ogni conteggio di posti occupati.
         */
        if ($trainer->is($utente)) {
            $nonValido();
        }

        $rimasti = $this->postiRimasti($trainer);

        if ($rimasti !== null && $rimasti <= 0) {
            $nonValido();
        }

        /*
         * 🆕 👥 **Chi ha gia' un trainer deve saperlo** — AH.1-ter.4.
         *
         * ⛔ Prima il secondo rapporto nasceva **in silenzio**, e da quel momento
         * tutti e due vedevano gli allenamenti di questa persona, tutti e due le
         * scrivevano, e tutti e due le tenevano occupato uno slot.
         *
         * ⚠️ **Fuori dalla transazione, e prima di toccare qualunque cosa**: e'
         * una domanda, non un rimedio. Chiederla dopo aver gia' traslocato
         * vorrebbe dire annullare a meta'.
         */
        $this->pretendiCheSappiaDellAltroTrainer($utente, $trainer, $affianco);

        return DB::transaction(function () use ($invito, $trainer, $utente, $affianco): User {
            $this->traslocaSeHaUnoSpazioSuo($utente, $trainer);

            /*
             * 🆕 📌 **La risposta si ricorda** — AH.1-ter.5: *«per non
             * richiederla ogni volta»*. 💡 Chi ha gia' detto di si' una volta sa
             * come funziona, e rifargli la domanda a ogni invito sarebbe
             * trattarlo come se non avesse capito.
             */
            if ($affianco && $utente->affiancamento_accettato_il === null) {
                $utente->forceFill(['affiancamento_accettato_il' => now()])->save();
            }

            $gia = $utente->assignedTrainers()->where('users.id', $trainer->getKey())->exists();

            /*
             * ══ 🆕 🔁 IL RAPPORTO RIPRENDE DA DOVE SI ERA FERMATO — D5, 19/09 ══
             *
             * ⛔ **Prima non succedeva niente**: la riga c'era (chiusa, perche'
             * il trainer aveva liberato il posto o chiuso la conversazione),
             * `$gia` era vero e il riscatto finiva qui. L'app diceva «fatto» e
             * il rapporto restava chiuso. Trovato nel giro del 18/09.
             *
             * 📌 Il committente: *«il loro rapporto deve riprendere da dove si
             * era fermato»*. 💡 Si riapre **la stessa riga** — la stessa
             * conversazione, lo stesso storico — e non se ne crea un'altra.
             */
            if ($gia) {
                $this->riapriSeChiuso($trainer, $utente);
            }

            if (! $gia) {
                /*
                 * 🚨 `trainer_member.tenant_id` e' quello del **trainer** — F6.1:
                 * e' lui che possiede il rapporto, e sotto il proprio scope deve
                 * vedere i propri allievi.
                 */
                $utente->assignedTrainers()->attach($trainer->getKey(), [
                    'tenant_id' => $trainer->tenant_id,
                    'assigned_at' => now(),
                    'assigned_by' => $trainer->getKey(),
                ]);
            }

            /*
             * 🚨 **La conversazione che c'era gia' diventa illimitata** — M6.2.
             *
             * ⚠️ Senza, la persona verrebbe assegnata al trainer e resterebbe
             * comunque bloccata nel filo dove si erano conosciuti: assegnata,
             * con la storia sotto gli occhi, e la penna ferma.
             *
             * 💡 `between()` la trova o la crea: se l'invito arriva da fuori
             * dalla chat, quella conversazione nasce adesso — gia' senza limite.
             */
            $c = Conversation::between($trainer, $utente);
            $c->tipo = TipoConversazione::Iscritto;
            $c->save();

            $invito->forceFill([
                'used_at' => now(),
                'accepted_by' => $utente->getKey(),
            ])->save();

            return $utente;
        });
    }

    /**
     * 🆕 👥 **Si ferma se questa persona ha gia' un trainer** — AH.1-ter.4,
     * 17/09/2026.
     *
     * 📌 Dal piano: *«la modale della sovrapposizione, con "Lascia com'e'" in
     * evidenza»*.
     *
     * 🚨 **Il server si ferma invece di fidarsi dell'app.** La modale la
     * disegna l'app, ma se il permesso di procedere lo desse l'app, un client
     * vecchio — o uno che non conosce questa regola — creerebbe la
     * sovrapposizione senza chiedere niente a nessuno. ⛔ E non darebbe errore:
     * funzionerebbe.
     *
     * ⚠️ **Solo i rapporti ancora in piedi.** Uno chiuso mesi fa non e' un
     * trainer che segue: chiedere conferma per un rapporto finito vorrebbe dire
     * far ricordare a qualcuno una cosa che ha gia' chiuso.
     *
     * ⛔ **E il trainer di questo invito non conta**, se per caso c'e' gia': non
     * si e' in sovrapposizione con se' stessi.
     *
     * 💡 Chi ha gia' risposto una volta non se lo sente richiedere:
     * `affiancamento_accettato_il` è la memoria di quella risposta (AH.1-ter.5).
     *
     * 🚨 **Gli iscritti di una palestra non ci finiscono dentro**, e sarebbe
     * l'errore facile: loro vedono tutti i trainer della palestra per costruzione
     * (`ChiSiVede`), senza nessuna riga in `trainer_member`. ⚠️ Chiedere
     * «hai gia' un trainer?» a ogni iscritto di palestra vorrebbe dire una
     * domanda che non c'entra, a tutti.
     *
     * @throws HaiGiaUnTrainerException
     */
    private function pretendiCheSappiaDellAltroTrainer(User $utente, User $nuovo, bool $affianco): void
    {
        if ($affianco || $utente->affiancamento_accettato_il !== null) {
            return;
        }

        $altro = $utente->assignedTrainers()
            ->whereKeyNot($nuovo->getKey())
            ->wherePivotNull('disattivato_il')
            ->first();

        if ($altro !== null) {
            throw new HaiGiaUnTrainerException($altro->name ?? '');
        }
    }

    /**
     * 🆕 Chi ha uno spazio suo e vuoto passa in quello del trainer — AH.1.
     *
     * ⛔ **Chi non ce l'ha resta dov'è, e non è un errore**: un iscritto di
     * palestra è coperto dalla palestra, e il rapporto con un trainer esterno
     * attraversa i tenant come ha sempre fatto (AH.1-ter).
     *
     * ⚠️ **Il rifiuto delle guardie non deve far fallire il riscatto**, e
     * questo `try` è la riga che lo dice: `dentroQuestoTenant()` lancia quando
     * lo spazio non è tuo o non è vuoto — che qui non è un guasto, è il caso
     * normale di chi sta già da qualche parte. 🚨 Lasciar salire l'eccezione
     * vorrebbe dire che un iscritto di palestra **non può più farsi seguire da
     * un trainer esterno**, cioè rompere una cosa che oggi funziona.
     */
    private function traslocaSeHaUnoSpazioSuo(User $utente, User $trainer): void
    {
        $suo = $utente->tenant;
        $delTrainer = $trainer->tenant;

        if ($suo === null || $delTrainer === null || $suo->is($delTrainer)) {
            return;
        }

        if (! $suo->eDi($utente)) {
            return;
        }

        try {
            $this->unisci->dentroQuestoTenant($utente, $delTrainer, UserRole::Member, 'token');
        } catch (ValidationException) {
            // Non si trasloca: il rapporto si crea lo stesso, e paga chi pagava.
        }
    }

    /**
     * 🚨 **«Disattivare» un utente chiude SOLO i messaggi** — decisione D5.
     *
     * Il legame resta, la storia si conserva, il canale si chiude. **Reversibile.**
     *
     * 💡 E copre più di quanto sembri: dopo D11/D13 i piani viaggiano *dentro la
     * chat*, quindi chiudere il canale **chiude anche la consegna di piani
     * nuovi**. Non serve una seconda regola.
     *
     * 🚨 **Ciò che NON si può fare, e va scritto perché qualcuno lo chiederà**:
     * revocare i piani **già ricevuti**. Vivono sul telefono e il server non può
     * togliere ciò che non ha mai avuto. L'unica strada sarebbe chiedere all'app
     * di nasconderli — cioè fidarsi del client, che non si fa.
     */
    public function disattiva(User $trainer, User $utente): void
    {
        $utente->assignedTrainers()->updateExistingPivot($trainer->getKey(), [
            'disattivato_il' => now(),
        ]);
    }

    public function riattiva(User $trainer, User $utente): void
    {
        $utente->assignedTrainers()->updateExistingPivot($trainer->getKey(), [
            'disattivato_il' => null,
        ]);
    }

    /**
     * 🆕 Riapre un rapporto chiuso quando l'allievo riaccetta un invito — D5.
     *
     * ⚠️ **La sovrapposizione vecchia si toglie e si rifà.** Quella scritta
     * riguardava una scelta gia' presa («lo libero»): lasciarla vorrebbe dire
     * che il trainer non viene piu' interpellato. 💡 Se l'allievo sta **ora**
     * in una palestra dove quel trainer lavora, la si segna di nuovo, e al
     * trainer ricompare la domanda sul posto.
     */
    private function riapriSeChiuso(User $trainer, User $utente): void
    {
        $riga = DB::table('trainer_member')
            ->where('trainer_id', $trainer->getKey())
            ->where('member_id', $utente->getKey())
            ->first();

        if ($riga === null || $riga->disattivato_il === null) {
            return;
        }

        DB::table('trainer_member')
            ->where('trainer_id', $trainer->getKey())
            ->where('member_id', $utente->getKey())
            ->update([
                'disattivato_il' => null,
                'sovrapposto_dal' => null,
                'sovrapposto_in_tenant_id' => null,
                'slot_deciso_il' => null,
                'updated_at' => now(),
            ]);

        $dove = $utente->fresh()?->tenant;

        if ($dove !== null && ! $dove->ePersonale()) {
            app(LoSlotDelTrainer::class)->segnalaLaSovrapposizione($utente, $dove);
        }
    }

    /**
     * Il piano di questo trainer prevede un limite di utenti?
     *
     * 🆕 🎀 P4 di `plan_promozioni.md` — più i posti regalati da una promozione,
     * finché valgono. ⚠️ Un piano senza limite resta senza limite: i posti
     * regalati non trasformano `null` in un numero.
     */
    public function limiteDelPiano(User $trainer): ?int
    {
        $max = $this->piano->per($trainer)->max_members;

        return $max === null ? null : $max + ($trainer->tenant?->postiRegalati('iscritti') ?? 0);
    }

    /** 💡 Comodo per il sito e per il pannello: «sei sul piano X». */
    public function codicePiano(User $trainer): string
    {
        return $this->piano->per($trainer)->code ?? Plan::FREE;
    }
}
