<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantStatus;
use App\Enums\TipoConversazione;
use App\Enums\UserRole;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\LaPausaDellAbbonamento;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/**
 * Un utente senza palestra entra in una palestra — requisito **B4**.
 *
 * ── 🚨 NON è un `UPDATE` su una colonna ────────────────────────────────────
 *
 * `plan_parte_b.md` §5.1 lo scriveva a chiare lettere fin dal principio, ed è
 * il motivo per cui era stato rimandato: **è una migrazione di dati**. Una
 * persona con un tenant personale ha diario, allenamenti, piani, foto e
 * conversazioni **tutti marcati con il suo tenant**. Spostando solo
 * `users.tenant_id`, il `TenantScope` smetterebbe di far vedere a quella
 * persona **tutta la sua storia**: non cancellata — invisibile, che è peggio,
 * perché non se ne accorge nessuno finché non la cerca.
 *
 * ── 💡 Come si trovano le tabelle da spostare ──────────────────────────────
 *
 * **Si enumerano i modelli, non si scrive un elenco a mano.** Un elenco
 * invecchia: il primo modello aggiunto in una fase futura resterebbe indietro,
 * e nessun test se ne accorgerebbe. È lo stesso principio di
 * `TenantIsolationTest` e dello sweep di cancellazione account (S9.3).
 *
 * ── ⚠️ Cosa NON si sposta, e perché ────────────────────────────────────────
 *
 * | Tabella | Perché resta |
 * |---|---|
 * | `ai_usage_logs` | È la **contabilità**: dice quanto è costata una persona a *quel* tenant, in *quel* mese. Spostarla farebbe comparire nella fattura della palestra consumi di quando quella persona non era ancora sua |
 * | `audit_logs` | Il registro è immodificabile per costruzione: riscriverlo sarebbe riscrivere la storia |
 * | `plan_subscriptions` | L'abbonamento del tenant personale è di **quel** tenant; entrando in palestra si passa sotto quello della palestra |
 * | `trainer_member` | Il legame è del **trainer** (F6.1), non dell'utente |
 * | `roles` · `model_has_roles` · `model_has_permissions` | 🚨 I ruoli sono **del tenant**, e la palestra ha già i suoi: spostarli fa collidere `UNIQUE(tenant_id, name, guard_name)` e il server risponde **500** a metà migrazione. È successo davvero scrivendo questa classe, ed è il motivo per cui l'enumerazione dello schema ha bisogno di eccezioni. Ci pensa `rifaiIRuoli()` |
 *
 * 🚨 **La conseguenza da conoscere**: dopo il passaggio la persona è coperta
 * dall'abbonamento della palestra, e il suo eventuale piano personale non vale
 * più. È corretto — paga la palestra — ma va detto a chi lo fa.
 */
class UnisciAUnaPalestra
{
    /**
     * Le tabelle che **non** seguono la persona.
     *
     * ⚠️ Elenco scritto a mano di proposito, ed è l'unico: sono eccezioni
     * motivate una per una nel docblock qui sopra. Tutto il resto si sposta,
     * e si trova enumerando lo schema — così un modello nuovo viene portato
     * dietro senza che nessuno debba ricordarsene.
     *
     * @var array<int, string>
     */
    public const RESTANO_INDIETRO = [
        'ai_usage_logs',
        'audit_logs',
        'model_has_permissions',
        'model_has_roles',
        'plan_subscriptions',
        'roles',
        'trainer_member',
        'users',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly LaPausaDellAbbonamento $pausa,
        private readonly LoSlotDelTrainer $slot,
    ) {}

    /*
     * ⛔ Qui c'era `__invoke(User $utente, string $joinCode)`: l'ingresso col
     * codice palestra. Tolto con F11 (24/09/2026) — in una palestra si entra
     * solo col link d'invito, che arriva qui da `dentroQuestoTenant()`.
     */

    /**
     * 🆕 **Lo stesso trasloco, verso un tenant che si ha già in mano** —
     * 3b-AH, AH.1 (12/09/2026).
     *
     * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════
     *
     * 📌 Il committente: *«un allievo che è iscritto a un `free_trainer` deve
     * avere come tenant quel trainer — un trainer in sostanza è **una palestra
     * con un unico trainer***».
     *
     * ⛔ **E allora entrare da un trainer deve essere lo stesso gesto di entrare
     * in una palestra**, non un gesto che gli somiglia. Scriverne una seconda
     * versione vorrebbe dire due elenchi di tabelle da spostare, due modi di
     * rifare i ruoli, due modi di sbloccare le conversazioni — e il giorno in
     * cui qualcuno ne aggiorna uno solo, la differenza non la segnala nessuno.
     *
     * ⚠️ **La sola differenza è come si trova il tenant**: con l'invito della
     * palestra, o con quello (monouso) di un trainer. Tutto il resto —
     * le guardie, il trasloco, i ruoli — è identico, e sta qui dentro una volta.
     *
     * @param  UserRole  $ruolo  con che ruolo si entra. ⛔ Mai un dato in ingresso: lo decide il chiamante che ha già verificato l'invito
     * @param  string  $campo  su quale campo appendere l'errore di validazione
     *
     * @throws ValidationException
     */
    public function dentroQuestoTenant(
        User $utente,
        Tenant $destinazione,
        UserRole $ruolo = UserRole::Member,
        string $campo = 'gym',
    ): Tenant {
        /*
         * ══ 🆕 🚨 CHI VIENE DALLO SPAZIO DI UN TRAINER — AH.1-ter.6 ═════════
         *
         * ⛔ **Prima questo caso era impossibile, e nessuno se n'era accorto.**
         * Un allievo che accetta l'invito di un free trainer viene traslocato
         * nello spazio del trainer (`traslocaSeHaUnoSpazioSuo`). Da quel
         * momento il suo spazio **non è più suo** e ha dentro altre persone:
         * `pretendiUnoSpazioTuoEVuoto()` rispondeva *«Sei già iscritto a una
         * palestra. Per cambiarla, parlane con loro.»*
         *
         * 🚨 Cioè: **l'allievo di un trainer indipendente non poteva entrare in
         * NESSUNA palestra.** Ed è precisamente lo scenario che il committente
         * ha chiesto di far funzionare — *«un allievo che successivamente si
         * iscrive anche alla palestra dove lavora il trainer»*.
         *
         * ⚠️ Trovato con un test rosso scrivendo AH.1-ter.6, non leggendo.
         *
         * 💡 La soluzione non è allentare la guardia: è **estrarre le righe di
         * una persona sola**, che è esattamente quello che sa fare
         * `EsciDaUnaPalestra`. Il tenant del trainer resta in piedi con dentro
         * gli altri suoi allievi.
         */
        $attuale = $utente->tenant;

        if ($attuale !== null
            && ! $attuale->is($destinazione)
            && ! $attuale->eDi($utente)
            && $attuale->ePersonale()
        ) {
            return $this->daLoSpazioDiUnTrainer($utente, $attuale, $destinazione, $ruolo, $campo);
        }

        $vecchio = $this->pretendiUnoSpazioTuoEVuoto($utente, $campo);

        if ($destinazione->is($vecchio)) {
            /*
             * 💡 Ci si può già essere: un invito riscattato due volte, o una
             * richiesta ripetuta. ⛔ Proseguire vorrebbe dire cancellare il
             * tenant di destinazione, che è anche quello di partenza.
             */
            return $destinazione;
        }

        if (! $destinazione->isActive()) {
            throw ValidationException::withMessages([
                $campo => __('Questo spazio non è attivo.'),
            ]);
        }

        return $this->trasferisci($utente, $vecchio, $destinazione, $ruolo, $campo);
    }

    /**
     * 🆕 **Entrare in una palestra venendo dallo spazio di un trainer** —
     * AH.1-ter.6, 18/09/2026.
     *
     * ══ ⚠️ IN COSA DIFFERISCE DAL TRASLOCO NORMALE ════════════════════════
     *
     * | | Trasloco normale | Da uno spazio altrui |
     * |---|---|---|
     * | Cosa si sposta | **tutte** le righe del tenant | **solo le sue** |
     * | Il tenant di partenza | sospeso e cancellato | ⛔ **resta in piedi**: dentro ci sono gli altri allievi |
     * | Il legame col trainer | — | ⛔ **non si tocca**. Se quel trainer lavora qui si segna solo che c'è una domanda da fargli sullo **slot** |
     *
     * 🚨 **Il legame non si chiude, ed è il punto.** Uscire dallo spazio di un
     * trainer per entrare in una palestra **non** vuol dire licenziare il
     * trainer: se lui lavora lì, i due si ritrovano dentro la stessa palestra —
     * ed è il caso per cui esiste `LoSlotDelTrainer`. ⛔ Chiuderlo qui
     * renderebbe la domanda sullo slot una domanda su un rapporto già morto.
     */
    private function daLoSpazioDiUnTrainer(
        User $utente,
        Tenant $spazioDelTrainer,
        Tenant $palestra,
        UserRole $ruolo,
        string $campo,
    ): Tenant {
        if (! $palestra->isActive()) {
            throw ValidationException::withMessages([
                $campo => __('Questo spazio non è attivo.'),
            ]);
        }

        $giaPresente = User::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->where('email', $utente->email)
            ->exists();

        if ($giaPresente) {
            throw ValidationException::withMessages([
                $campo => __('In questa palestra esiste già un account con la tua email. Contattala.'),
            ]);
        }

        return DB::transaction(function () use ($utente, $spazioDelTrainer, $palestra, $ruolo): Tenant {
            $spostate = app(EsciDaUnaPalestra::class)
                ->portaViaLeSueRighe($utente, $spazioDelTrainer, $palestra);

            /*
             * ⚠️ **Prima di cambiargli il tenant.** L'abbonamento da fermare è
             * quello del suo spazio — e chi sta nello spazio di un trainer non
             * ne ha uno suo, quindi qui di solito non c'è niente da fermare.
             * 💡 Si chiama lo stesso: la guardia `eDi()` dentro il servizio
             * risponde da sola, e il giorno in cui questo caso cambierà non
             * bisognerà ricordarsi di aggiungere la chiamata.
             */
            $this->pausa->metti($utente, $spazioDelTrainer, $palestra);

            $utente->forceFill(['tenant_id' => $palestra->getKey()])->save();

            // 🆕 🏠 F2.3: la tabella nuova si tiene allineata accanto al trasloco.
            app(LeAppartenenze::class)->entra($utente, $palestra);

            $this->rifaiIRuoli($utente, $spazioDelTrainer, $palestra, $ruolo);

            $this->slot->segnalaLaSovrapposizione($utente, $palestra);

            $this->sbloccaLeConversazioniConLaPalestra($utente, $palestra);

            Log::info('Utente passato dallo spazio di un trainer a una palestra', [
                'user_id' => $utente->getKey(),
                'da' => $spazioDelTrainer->getKey(),
                'a' => $palestra->getKey(),
                'righe_spostate' => $spostate,
            ]);

            return $palestra;
        });
    }

    /**
     * Lo spazio da cui si parte dev'essere **tuo** e **senza nessun altro
     * dentro**.
     *
     * @throws ValidationException
     */
    private function pretendiUnoSpazioTuoEVuoto(User $utente, string $campo): Tenant
    {
        $vecchio = $utente->tenant;

        if ($vecchio === null || ! $vecchio->eDi($utente)) {
            /*
             * 🚨 **Solo dal proprio spazio.** Spostare un iscritto da una
             * palestra a un'altra è un'operazione **commerciale**, non una
             * scelta dell'utente: vorrebbe dire che chiunque conosca il codice
             * di un'altra palestra può portarsi via i propri dati dalla propria.
             *
             * ══ 🆕 PERCHE' NON E' PIU' `ePersonale()` — 3b-AH, 12/09/2026 ═══
             *
             * ⛔ **`ePersonale()` qui stava per diventare una falla grave.**
             * Questo metodo **sposta tutte le righe del tenant di partenza**
             * dentro la palestra. Dopo AH.1 gli allievi di un trainer vivranno
             * nel tenant del trainer, che resta `personal`: un allievo che
             * digita il codice di una palestra si sarebbe portato dietro **il
             * tenant intero del suo trainer** — le schede degli altri allievi,
             * gli esercizi, tutto. Nessun errore, e i dati di venti persone
             * dentro una palestra che non c'entra niente.
             *
             * 💡 La domanda giusta non è *«questo spazio è di una persona
             * sola?»* ma *«questo spazio è **tuo**?»*.
             */
            throw ValidationException::withMessages([
                $campo => __('Sei già iscritto a una palestra. Per cambiarla, parlane con loro.'),
            ]);
        }

        /*
         * 🚨 **E deve essere tuo E vuoto**, non solo tuo.
         *
         * ⛔ Senza questa riga il `gym_admin` di una palestra potrebbe digitare
         * il codice di un'altra palestra e portarle **la propria palestra
         * intera**: il suo tenant è suo — `eDi()` dice di sì — e il metodo
         * sposterebbe ogni riga di ogni suo iscritto.
         *
         * ⚠️ Lo stesso varrà per un trainer dopo AH.1, con i suoi allievi
         * dentro. 💡 In tutti e due i casi non è una cosa che si fa digitando
         * un codice: è una faccenda commerciale, e la si fa parlando.
         */
        $altri = User::withoutGlobalScopes()
            ->where('tenant_id', $vecchio->getKey())
            ->whereKeyNot($utente->getKey())
            ->exists();

        if ($altri) {
            throw ValidationException::withMessages([
                $campo => __('Nel tuo spazio ci sono altre persone: non puoi portarlo dentro una palestra.'),
            ]);
        }

        return $vecchio;
    }

    /**
     * Il trasloco vero e proprio. ⚠️ Da qui in poi non si rifiuta più niente:
     * chi ci arriva è già stato verificato.
     *
     * @throws ValidationException
     */
    private function trasferisci(
        User $utente,
        Tenant $vecchio,
        Tenant $palestra,
        UserRole $ruolo,
        string $campo,
    ): Tenant {
        /*
         * 🚨 **L'email è unica PER palestra, e in quella nuova può essere già
         * presa.**
         *
         * Senza questo controllo l'`UPDATE` finale violerebbe
         * `UNIQUE(tenant_id, email)` e il server risponderebbe **500** — a metà
         * migrazione, con parte dei dati già spostati se non fossimo in
         * transazione.
         *
         * 💡 Il caso non è teorico: è la persona che si era iscritta da sola e
         * che la palestra aveva **già** registrato con lo stesso indirizzo.
         * Serve che sia la palestra a sistemare, perché solo lei sa quale dei
         * due account è quello buono.
         */
        $giaPresente = User::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->where('email', $utente->email)
            ->exists();

        if ($giaPresente) {
            throw ValidationException::withMessages([
                $campo => __('In questa palestra esiste già un account con la tua email. Contattala.'),
            ]);
        }

        return DB::transaction(function () use ($utente, $vecchio, $palestra, $ruolo): Tenant {
            $spostate = $this->spostaLeRighe($vecchio, $palestra);

            /*
             * ══ 🆕 ⏸️ LA PAUSA DELL'ABBONAMENTO — 3b-AH 8.1.6, 18/09/2026 ════
             *
             * 📌 Il committente: *«Assicurati che l'abbonamento dell'allievo
             * venga sospeso se si iscrive a una palestra, se era abbonato.»*
             *
             * 🚨 **Prima non succedeva niente**, e costava soldi veri: la riga
             * restava su un tenant che qui sotto viene sospeso e cancellato, e
             * **Stripe continuava ad addebitare** a una persona già coperta
             * dalla palestra.
             *
             * ⚠️ **Prima di cambiargli il `tenant_id`**, e con `$vecchio` in
             * mano: dopo non ci sarebbe più modo di sapere quale abbonamento
             * fermare.
             */
            $this->pausa->metti($utente, $vecchio, $palestra);

            $utente->forceFill(['tenant_id' => $palestra->getKey()])->save();

            // 🆕 🏠 F2.3: la tabella nuova si tiene allineata accanto al trasloco.
            app(LeAppartenenze::class)->entra($utente, $palestra);

            $this->rifaiIRuoli($utente, $vecchio, $palestra, $ruolo);

            /*
             * ══ 🆕 🎟️ GLI SLOT DEI SUOI TRAINER — AH.1-ter.6 ════════════════
             *
             * 🚨 **Dopo `rifaiIRuoli()`, non prima.** La sospensione riguarda i
             * trainer che lavorano **in questa palestra**, e quell'elenco si
             * legge dai ruoli: chiederlo prima che i ruoli siano a posto
             * funzionerebbe lo stesso — i ruoli dei *trainer* non li tocca
             * questa riga — ma l'ordine giusto è quello che regge anche il
             * giorno in cui qualcuno cambia `rifaiIRuoli()`.
             *
             * ⛔ **Non chiude niente**: mette in sospeso e segna che al trainer
             * va fatta una domanda. Liberare lo slot di default vorrebbe dire
             * decidere al posto suo, nella direzione che gli toglie l'allievo.
             */
            $this->slot->segnalaLaSovrapposizione($utente, $palestra);

            $this->sbloccaLeConversazioniConLaPalestra($utente, $palestra);

            /*
             * ⚠️ **Il tenant personale si cancella logicamente, non davvero.**
             *
             * Restare in tabella `active` lo farebbe contare fra i tenant vivi;
             * cancellarlo del tutto perderebbe la traccia di dove quella persona
             * stava prima — che serve il giorno in cui qualcosa non torna.
             *
             * 🚨 `SoftDeletes` conserva anche `join_code` e `slug`, quindi
             * quegli indici unici restano occupati: è voluto. Un codice
             * riassegnato a un tenant diverso è un modo per far entrare
             * qualcuno nel posto sbagliato.
             */
            $vecchio->update(['status' => TenantStatus::Suspended]);
            $vecchio->delete();

            Log::info('Utente passato da un tenant personale a una palestra', [
                'user_id' => $utente->getKey(),
                'da' => $vecchio->getKey(),
                'a' => $palestra->getKey(),
                'righe_spostate' => $spostate,
            ]);

            return $palestra;
        });
    }

    /**
     * Sposta ogni riga del tenant personale sotto la palestra.
     *
     * 🚨 **Enumera lo schema**, non un elenco: un modello aggiunto domani viene
     * portato dietro senza che nessuno debba ricordarsene. È lo stesso principio
     * di `TenantIsolationTest`.
     *
     * ⚠️ `runWithoutTenant`: qui si scrive **attraverso** i tenant, ed è
     * l'unico posto del sistema in cui è legittimo. Il filtro non si perde — la
     * `WHERE` sul tenant vecchio è più stretta di quella che lo scope
     * metterebbe.
     *
     * @return array<string, int> quante righe per tabella
     */
    private function spostaLeRighe(Tenant $da, Tenant $a): array
    {
        $spostate = [];

        return $this->context->runWithoutTenant(function () use ($da, $a, &$spostate): array {
            foreach ($this->tabelleDaSpostare() as $tabella) {
                $righe = DB::table($tabella)
                    ->where('tenant_id', $da->getKey())
                    ->update(['tenant_id' => $a->getKey()]);

                if ($righe > 0) {
                    $spostate[$tabella] = $righe;
                }
            }

            return $spostate;
        });
    }

    /**
     * 🚨 **Iscriversi sblocca le conversazioni aperte dal catalogo** — M4.4.
     *
     * ── Perche' sta qui e non in `open()` ──────────────────────────────────
     *
     * Perche' **questo** e' il momento in cui una persona «diventa iscritta».
     * ⚠️ Averlo messo solo dove si apre una chat sarebbe stato il posto
     * sbagliato: chi ha scritto a una palestra dal catalogo e poi si iscrive non
     * riapre niente — apre la conversazione che ha gia', e la troverebbe **con
     * la penna ferma**. E' il difetto piu' irritante possibile, perche' capita
     * esattamente a chi ha appena pagato.
     *
     * ── ⚠️ Solo i fili con gente di QUESTA palestra ────────────────────────
     *
     * Iscriversi da Olimpo non sblocca la conversazione aperta con Ares. 🚨
     * Sbloccarle tutte vorrebbe dire che iscriversi da qualunque parte regala
     * messaggi illimitati verso chiunque — cioe' che il limite si aggira
     * iscrivendosi alla palestra piu' economica.
     *
     * 💡 Il contatore **non si azzera**: cambia il tipo. Quel numero dice
     * quante volte quella persona ha provato prima di iscriversi.
     */
    private function sbloccaLeConversazioniConLaPalestra(User $utente, Tenant $palestra): void
    {
        /*
         * 🚨 **`whereKeyNot($utente)`, e senza questa riga il limite si aggira.**
         *
         * ⚠️ Questo metodo gira **dopo** che `users.tenant_id` e' stato
         * spostato: la persona che si sta iscrivendo e' gia' dentro l'elenco
         * della palestra. Senza escluderla, `member_id IN (…)` combacia con
         * **ogni** suo filo — anche quelli con palestre diverse — e iscriversi
         * alla palestra piu' economica sbloccherebbe la scrittura illimitata
         * verso chiunque.
         *
         * 💡 L'ha trovato un test: `iscriversi_NON_sblocca_i_fili_con_ALTRE_palestre`.
         */
        $dellaPalestra = User::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->whereKeyNot($utente->getKey())
            ->pluck('id');

        if ($dellaPalestra->isEmpty()) {
            return;
        }

        Conversation::withoutGlobalScopes()
            ->where('tipo', TipoConversazione::Informazioni->value)
            ->forUser($utente)
            ->where(fn ($q) => $q
                ->whereIn('trainer_id', $dellaPalestra)
                ->orWhereIn('member_id', $dellaPalestra))
            ->update(['tipo' => TipoConversazione::Iscritto->value]);
    }

    /**
     * Le tabelle con `tenant_id`, meno le eccezioni motivate.
     *
     * @return array<int, string>
     */
    private function tabelleDaSpostare(): array
    {
        // ⚡ Una query sola, non una per tabella: vedi `EsciDaUnaPalestra::tabelleConTenant()`.
        return array_values(array_diff(
            EsciDaUnaPalestra::tabelleConTenant(),
            self::RESTANO_INDIETRO,
        ));
    }

    /**
     * I ruoli si rifanno **dentro** la palestra nuova.
     *
     * 🚨 In modalità teams un ruolo vale solo nel proprio tenant: quelli del
     * tenant personale non seguono la persona, e senza questo passaggio si
     * ritroverebbe **senza nessun ruolo** nella palestra — cioè un account che
     * esiste e non può fare niente.
     *
     * ⚠️ **Il ruolo non è mai un dato in ingresso.** Chi era `FreeTrainer` non
     * diventa trainer della palestra perché ha un invito: il ruolo lo decide
     * l'invito, verificato da chi chiama.
     *
     * 🆕 Il parametro esiste dal 12/09 perché `dentroQuestoTenant()` serve anche
     * all'invito di un trainer — ma anche lì lo decide **il chiamante che ha già
     * verificato l'invito**, non chi lo riscatta.
     */
    private function rifaiIRuoli(User $utente, Tenant $vecchio, Tenant $palestra, UserRole $ruolo = UserRole::Member): void
    {
        /*
         * 🚨 **`syncRoles()` tocca solo il tenant corrente.**
         *
         * In modalità teams spatie filtra su `model_has_roles.tenant_id`:
         * sincronizzando dentro la palestra, l'assegnazione nel tenant
         * personale **sopravvive**. ⚠️ Resterebbe una riga che punta a un ruolo
         * di un tenant cancellato — innocua finché nessuno la legge, e
         * confondente il giorno in cui qualcuno la legge.
         *
         * 💡 Si cancella con una query esplicita perché non c'è modo di
         * chiedere a spatie di operare su un team che non è quello corrente:
         * entrare nel contesto vecchio solo per togliere un ruolo sarebbe più
         * codice per lo stesso effetto.
         */
        DB::table('model_has_roles')
            ->where('model_id', $utente->getKey())
            ->where('model_type', $utente->getMorphClass())
            ->where('tenant_id', $vecchio->getKey())
            ->delete();

        $this->context->runAs($palestra, function () use ($utente, $ruolo): void {
            $fresco = User::withoutGlobalScopes()->findOrFail($utente->getKey());
            $fresco->unsetRelation('roles');
            $fresco->syncRoles([$ruolo->value]);
        });
    }
}
