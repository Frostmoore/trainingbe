<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantKind;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\LaPausaDellAbbonamento;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Uscire da una palestra — 3b-P.13.3, 23/08/2026.
 *
 * ══ 🚨 NON È L'INVERSO SIMMETRICO DI `UnisciAUnaPalestra` ══════════════════
 *
 * Entrare sposta **tutte** le righe di un tenant personale dentro la palestra:
 * si può, perché in un tenant personale c'è una persona sola e tutto quello che
 * contiene è suo.
 *
 * ⛔ **Uscire no.** Bisogna estrarre le righe di **una** persona da un tenant
 * che ne ha molte, e non tutte le tabelle hanno una colonna da cui filtrare:
 * `workout_plans` ha `member_id` e `created_by`, le conversazioni hanno due
 * estremi, il registro non ha nessuno.
 *
 * 🚨 **Per questo la classificazione è esplicita e a prova di dimenticanza**:
 * ogni tabella con `tenant_id` deve stare in uno dei tre elenchi, e una tabella
 * nuova che non ci sta **fa fallire** `esciDallaPalestraTest`. ⚠️ È la stessa
 * disciplina di `AccountErasureSweepTest`, e per la stessa ragione: il difetto
 * di `health_readings` è rimasto invisibile per settimane perché quella tabella
 * era nata **dopo** l'eraser.
 *
 * ── 📌 Le decisioni di prodotto, prese dal committente il 23/08/2026 ────────
 *
 * *«Le schede del trainer restano al trainer»*.
 *
 * 💡 Ed è coerente con quello che il sistema fa già: `AccountEraser` cancella le
 * schede che uno si è scritto da solo e **lascia** quelle prescritte dal
 * trainer, *«perché sono lavoro suo»*. Uscire da una palestra non è un caso
 * nuovo: è lo stesso principio a una porta diversa.
 *
 * ── ⛔ Chi NON può uscire, e perché ────────────────────────────────────────
 *
 * **Un trainer.** Se ne andasse portandosi dietro il proprio tenant, la palestra
 * resterebbe con schede e piani firmati da un utente che non c'è più, e i suoi
 * iscritti con un trainer sparito. 🚨 È un'operazione **commerciale**, come
 * cambiare palestra da soli: la fa la palestra dal pannello, non la persona
 * dall'app.
 */
final class EsciDaUnaPalestra
{
    /**
     * Le tabelle che **seguono la persona**, filtrando su `user_id`.
     *
     * 💡 Sono i dati che la persona ha prodotto per sé, e che non hanno senso
     * senza di lei: i consigli ricevuti, le stime, le importazioni. ⛔ Diario e
     * preferiti non ci sono più: dal 22/09/2026 stanno solo sul telefono.
     */
    public const SEGUONO_LA_PERSONA = [
        'ai_advices',
        'device_tokens',
        'importazioni_da_documento',
        'stime_cibo',
    ];

    /**
     * Le tabelle che **restano alla palestra**, con il motivo.
     *
     * | Tabella | Perché resta |
     * |---|---|
     * | `ai_credit_movements` | Contabilità dei gettoni della palestra |
     * | `ai_usage_logs` | Consumo addebitato alla palestra: è la sua fattura |
     * | `audit_logs` | Registro della palestra, e serve proprio a ricostruire cose come questa |
     * | `campagne` · `profili_pubblici` | Schede del catalogo: appartengono a palestre e trainer, non agli iscritti |
     * | `conversations` · `media` | 🚨 I messaggi restano dove sono. Sono di **tutti e due**, e sono cifrati: la palestra non li legge comunque. Portarli via vorrebbe dire toglierli al trainer, che ne è l'altro autore |
     * | `addebiti_mensili` | 💶 La fattura del piano a consumo: documento contabile **della palestra** |
     * | `exercises` | La libreria esercizi della palestra |
     * | `model_has_permissions` · `model_has_roles` · `roles` | I ruoli si rifanno, non si spostano — vedi `rifaiIRuoli()` |
     * | `plan_subscriptions` | Abbonamento della palestra alla piattaforma |
     * | `trainer_invites` · `trainer_member` | 🚨 **Il legame con la palestra: è precisamente quello che finisce** |
     * | `users` | La riga della persona si aggiorna, non si sposta |
     * | `workout_plan_imports` | Segue la scheda importata, non la persona |
     */
    public const RESTANO_ALLA_PALESTRA = [
        /*
         * 💶 **La fattura del piano a consumo** — 3b-AH.6.9, 17/09/2026.
         *
         * 🚨 E' un documento contabile **della palestra**, e chi esce non se ne
         * porta via un pezzo. ⛔ Farla seguire la persona vorrebbe dire
         * spostare in un tenant personale una riga che dice quanti iscritti
         * aveva quella palestra — cioe' consegnare a un ex iscritto il numero
         * che la palestra tiene piu' stretto.
         *
         * ⚠️ E il conteggio **non si ricalcola**: quel mese quella persona
         * c'era davvero, e una fattura gia' emessa non cambia perche' qualcuno
         * se n'e' andato dopo.
         */
        /*
         * 🆕 💰 **Il registro degli incassi** — 22/09/2026, fase F3.
         *
         * 🚨 Stessa ragione delle fatture qui sotto: è **contabilità della
         * palestra**. ⛔ Farla seguire chi esce vorrebbe dire spostare in un
         * tenant personale le righe che dicono quanto ha incassato quella
         * palestra — e per giunta è lei che quei soldi li ha tirati fuori.
         */
        'pagamenti_incassati',

        /*
         * 🆕 ✂️ **Gli abbonamenti tolti dalla palestra** — 23/09/2026, F5.
         *
         * 🚨 Restano alla palestra, e non per burocrazia: sono **la sua
         * decisione** su quanto pagare, e servono a ricostruire la sua revenue
         * e le sue fatture. ⛔ Se seguissero la persona nel tenant personale,
         * lì direbbero *«senza abbonamento»* a chi lo spazio lo paga da sé — e
         * `PianoAttivo::per()` lo metterebbe sul gratuito nel suo stesso
         * spazio.
         */
        'abbonamenti_tolti',

        /*
         * 🆕 🧾 **Le fatture** — 23/09/2026, F8.
         *
         * 🚨 Sono intestate **alla palestra** e si conservano dieci anni: chi
         * esce non se ne porta via nessuna. ⛔ E non hanno nemmeno una persona
         * da seguire: una fattura è della palestra che ha pagato, non di chi
         * l'ha cliccata.
         */
        'fatture',

        /*
         * 🆕 🏋️ **Gli esercizi riservati a una palestra** — 23/09/2026, F9.5.
         *
         * 💡 Dicono quali esercizi della piattaforma **vede la palestra**, non
         * una persona: chi esce non ha niente da portarsi via.
         */
        'esercizi_delle_palestre',

        'addebiti_mensili',
        'ai_credit_movements',
        'ai_usage_logs',
        'audit_logs',
        'campagne',
        'conversations',
        'exercises',
        'media',
        'model_has_permissions',
        'model_has_roles',
        /*
         * 🔗 **Le offerte di un piano su misura** — 21/09/2026.
         *
         * 🚨 Sono il **contratto della palestra**: dicono quanto paga e
         * quanti posti ha concordato. ⛔ Farle seguire chi esce vorrebbe dire
         * consegnare a un ex iscritto il prezzo cucito per il suo ex titolare.
         *
         * ⚠️ Qui la colonna è **nullable** (`BelongsToTenantOrGlobal`): le
         * offerte a chi non è ancora cliente hanno `tenant_id` a `null`, e non
         * riguardano nessuna uscita.
         */
        'offerte_di_piano',
        'plan_subscriptions',
        'profili_pubblici',
        'roles',
        /*
         * 🔗 Gli inviti — 3b-V.
         *
         * ⚠️ Sono **della palestra**, non di chi li riceve: chi esce non se li
         * porta dietro, ed e' ovvio. 💡 Ma vanno nominati lo stesso, perche' il
         * controllo qui sotto pretende una classificazione per ogni tabella con
         * `tenant_id` — e una tabella non classificata e' un dato che finisce
         * nel tenant sbagliato senza che nessuno se ne accorga.
         */
        'tenant_invites',
        'trainer_invites',
        'trainer_member',
        'users',
        'workout_plan_imports',
    ];

    /**
     * Le tabelle con una regola tutta loro, gestite da codice dedicato.
     *
     * ⚠️ Stanno qui **per essere nominate**: senza, il controllo che pretende
     * una classificazione per ogni tabella le segnalerebbe come dimenticate.
     */
    public const REGOLE_PROPRIE = [
        /*
         * 🆕 🏠 **Le appartenenze** — F2.1, 27/09/2026.
         *
         * 🚨 Qui `tenant_id` **non vuol dire «di chi è la riga»**: vuol dire
         * «a chi appartiene questa persona». ⛔ Spostarlo quando esce da una
         * palestra riscriverebbe la storia — direbbe che è sempre appartenuta
         * a qualcun altro — invece di dire che quell'appartenenza **è finita**.
         *
         * 💡 La regola sua è: l'appartenenza a quella palestra si **chiude**
         * (`finita_il`), le altre non si toccano. ⚠️ Non si cancella: la
         * storia serve, ed è quella che fa ripartire l'abbonamento di prima
         * quando tutti se ne vanno.
         *
         * ⏳ La chiusura la scriverà **F2.4**: oggi questa tabella è ancora
         * solo descrittiva e nessuno la legge.
         */
        'appartenenze',

        /*
         * 🆕 ✍️ **La liberatoria firmata non si sposta** — 20/09/2026.
         *
         * 🚨 È un **atto avvenuto**: «il giorno X, dentro la palestra Y, questa
         * persona ha accettato la versione Z». ⛔ Spostarle il `tenant_id`
         * quando esce vorrebbe dire riscrivere dove è stata firmata — cioè
         * falsificare la prova che serve proprio a dimostrare l'atto.
         *
         * 💡 E non serve nemmeno: `LeLiberatorie` guarda **la persona e la
         * versione**, non lo spazio.
         */
        'liberatorie_firmate',
        'nutrition_plans',

        /*
         * 🆕 🎁 **I bersagli di una promozione** — 22/09/2026, fase F2.
         *
         * 🚨 Qui `tenant_id` **non vuol dire «di chi è la riga»**: vuol dire
         * «quale palestra è il bersaglio dell'offerta». È lo stesso nome di
         * colonna con un significato diverso, ed è il motivo per cui questa
         * tabella non può stare in nessuna delle altre due liste.
         *
         * ⛔ Quando una persona esce da una palestra qui non si tocca niente:
         * la promozione resta della piattaforma e continua a puntare a quella
         * palestra. 💡 Che quella persona non la prenda più lo decide
         * `LePromozioni::loPrende()`, guardando dove sta **adesso**.
         */
        'promozione_bersagli',

        /*
         * 🆕 🧾 **Chi ha preso una promozione** — 24/09/2026, P1 di `plan_promozioni.md`.
         *
         * 🚨 Come la liberatoria: è un **atto avvenuto**. `tenant_id` dice dove
         * stava la persona quando l'ha presa, e resta quello. ⛔ Spostarlo
         * riscriverebbe la storia di uno sconto già incassato.
         *
         * 💡 E non serve: i limiti per persona si contano su `user_id`, che
         * segue la persona ovunque vada.
         */
        'promozione_assegnazioni',

        'workout_plans',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly LaPausaDellAbbonamento $pausa,
        private readonly LoSlotDelTrainer $slot,
    ) {}

    public function __invoke(User $utente): Tenant
    {
        $palestra = $utente->tenant;

        /*
         * ══ 🆕 «NON E' MIO», non «non e' personale» — 3b-AH, 12/09/2026 ══════
         *
         * ⛔ Prima si chiedeva `ePersonale()`, e voleva dire la stessa cosa
         * finche' in un tenant personale ci stava una persona sola.
         *
         * 🚨 Dopo AH.1 gli allievi di un trainer vivranno nel tenant del
         * trainer, che resta `personal`: con la domanda vecchia, a loro
         * l'uscita sarebbe stata **negata** — sarebbero rimasti dentro lo
         * spazio di qualcun altro senza nessun modo di uscirne, e la richiesta
         * del committente e' l'opposto (*«anche loro devono poter disdire per
         * conto loro»*).
         *
         * 💡 Da chi e' coperto da qualcun altro si esce; dal proprio spazio no,
         * perche' non c'e' nessuno da cui uscire.
         */
        if ($palestra === null || $palestra->eDi($utente)) {
            throw ValidationException::withMessages([
                'gym' => __('Non sei iscritto a nessuna palestra.'),
            ]);
        }

        /*
         * 🚨 **Solo gli iscritti.** Un trainer che se ne va da solo lascerebbe
         * la palestra con schede firmate da un utente che non c'è più, e i suoi
         * iscritti senza trainer. È una faccenda commerciale, e la fa la
         * palestra.
         */
        /*
         * 🚨 **Il ruolo si legge DENTRO il tenant della palestra.** In modalita'
         * teams spatie filtra su `model_has_roles.tenant_id`: chiedere
         * `hasRole()` fuori dal contesto risponde **sempre di no**, e il
         * servizio rifiuterebbe a tutti — iscritti compresi.
         *
         * ⚠️ Non e' teorico: al primo giro questo controllo bloccava ogni
         * uscita, e i test lo hanno detto subito.
         */
        $eIscritto = $this->context->runAs($palestra, function () use ($utente): bool {
            $fresco = User::withoutGlobalScopes()->findOrFail($utente->getKey());
            $fresco->unsetRelation('roles');

            return $fresco->hasRole(UserRole::Member->value);
        });

        if (! $eIscritto) {
            throw ValidationException::withMessages([
                'gym' => __('Solo un iscritto può uscire da solo. Parlane con la palestra.'),
            ]);
        }

        $this->pretendiUnaClassificazionePerOgniTabella();

        /*
         * ══ 🆕 🎟️ CHI HA UN TRAINER CHE GLI TIENE IL POSTO TORNA DA LUI ══════
         *
         * 📌 Il committente, 22/09/2026: *«Ovviamente se il trainer gli ha
         * tenuto il posto torna nello spazio del trainer, sennò a che servirebbe
         * tenere il posto?»*. E da D1 seguire un trainer indipendente vuol dire
         * **stare nel suo spazio**, dove paga lui: il Plus resta in pausa.
         *
         * ⛔ **Prima si creava sempre uno spazio personale** e si riprendeva
         * l'abbonamento: il rapporto restava, ma l'allievo finiva a casa sua a
         * pagarsi il Plus mentre il trainer continuava a pagargli il posto —
         * **pagavano in due**, e su Stripe l'addebito ripartiva. Trovato nel giro
         * A5 (Q-A5-1).
         *
         * 💡 Si decide **prima** della transazione: più sotto
         * `sciogliIlLegameConLaPalestra()` e `chiudiLaSovrapposizione()`
         * cambiano proprio le righe che lo dicono.
         */
        $spazioDelTrainer = $this->spazioDelTrainerCheLoTiene($utente, $palestra);

        return DB::transaction(function () use ($utente, $palestra, $spazioDelTrainer): Tenant {
            $personale = $spazioDelTrainer ?? $this->creaIlTenantPersonale($utente);

            $spostate = $this->portaViaLeSueRighe($utente, $palestra, $personale);

            /*
             * ⚠️ **Prima di cambiargli il tenant**, non dopo: da qui in avanti
             * `$utente->tenant_id` e' gia' quello personale, e non ci sarebbe
             * piu' modo di sapere da quale libreria sta uscendo se non
             * ripescando `$palestra` — cioe' affidandosi all'ordine delle
             * righe invece che a un fatto.
             */
            $this->ricordaLaLibreria($utente, $palestra);

            $utente->forceFill(['tenant_id' => $personale->getKey()])->save();

            /*
             * 💡 Nello spazio del trainer si è `Member`, come dopo aver
             * accettato il suo invito (`InvitiDelTrainer::traslocaSeHaUnoSpazioSuo`).
             */
            $this->rifaiIRuoli(
                $utente,
                $palestra,
                $personale,
                $spazioDelTrainer === null ? UserRole::FreeUser : UserRole::Member,
            );
            $this->sciogliIlLegameConLaPalestra($utente, $palestra);

            /*
             * ══ 🆕 ⏸️ L'ABBONAMENTO RIPRENDE — 3b-AH 8.1.6, 18/09/2026 ══════
             *
             * 📌 *«…e che riprenda quando l'allievo torna libero.»*
             *
             * 🚨 **Dopo aver creato il tenant personale**, perché la riga
             * dell'abbonamento ci si deve spostare sopra: il vecchio spazio è
             * sospeso e cancellato, e lasciarcela sopra vorrebbe dire
             * riattivare qualcosa che non troverà più nessuno. ⛔ È esattamente
             * il motivo per cui prima non tornava niente.
             *
             * ⚠️ E chi **non era** abbonato esce non abbonato: qui non si crea
             * niente, si riprende solo ciò che era stato messo in pausa.
             */
            /*
             * 🚨 **Tornando dal trainer non riprende niente**: lì paga lui, e la
             * pausa resta com'è, con i giorni che restavano. Riprenderà quando la
             * persona tornerà davvero individuale (uscendo dallo spazio del
             * trainer passa di nuovo da qui). ⛔ Riprendere e rimettere in pausa
             * non sarebbe innocuo: senza `trial_end` Stripe riaddebita subito.
             */
            // 🆕 🏠 F2.4: l'appartenenza si CHIUDE, non si sposta e non si cancella.
            app(LeAppartenenze::class)->esci($utente, $palestra);

            if ($spazioDelTrainer === null) {
                $this->pausa->riprendi($utente, $palestra, $personale);
            }

            /*
             * ══ 🆕 🎟️ E GLI SLOT TENUTI SI RIACCENDONO — AH.1-ter.6 ═════════
             *
             * 📌 *«se il trainer decide di mantenere lo slot occupato […] poi se
             * si dissocia dalla palestra torna al trainer.»*
             *
             * ⛔ **Chi aveva liberato lo slot resta chiuso**, e non torna: quella
             * riga ha `disattivato_il` valorizzato e il servizio non la guarda.
             * 💡 È la metà della regola che dà senso all'altra — si pagava per
             * prenotarsi il ritorno, e chi non ha pagato non lo ha prenotato.
             */
            $this->slot->chiudiLaSovrapposizione($utente, $palestra);

            Log::info('Un iscritto è uscito da una palestra', [
                'user_id' => $utente->getKey(),
                'da' => $palestra->getKey(),
                'a' => $personale->getKey(),
                'righe_spostate' => $spostate,
            ]);

            return $personale;
        });
    }

    /**
     * 🚨 **Fallisce se una tabella non è classificata.**
     *
     * ⚠️ Il controllo è **qui e non solo nel test**: un ambiente che ha una
     * tabella in più — una migrazione applicata a metà, un modulo aggiunto —
     * deve fermarsi, non tirare a indovinare su dati di qualcuno.
     */
    private function pretendiUnaClassificazionePerOgniTabella(): void
    {
        $ignote = array_diff(
            self::tabelleConTenant(),
            self::SEGUONO_LA_PERSONA,
            self::RESTANO_ALLA_PALESTRA,
            self::REGOLE_PROPRIE,
        );

        if ($ignote !== []) {
            throw new RuntimeException(
                'Tabelle senza una regola per l\'uscita da una palestra: '
                .implode(', ', $ignote)
                .'. Vanno messe in SEGUONO_LA_PERSONA, RESTANO_ALLA_PALESTRA o '
                .'REGOLE_PROPRIE, con il motivo scritto.'
            );
        }
    }

    /**
     * Le tabelle che hanno `tenant_id`, in ordine alfabetico.
     *
     * ⚡ **Una query sola** — 19/09/2026. Prima era `Schema::getTableListing()`
     * più un `Schema::hasColumn()` per ognuna delle ~50 tabelle: su MariaDB
     * ogni `hasColumn` legge l'`information_schema`, e **entrare o uscire da una
     * palestra costava ~10 secondi** — sul server come nei test (83 test su
     * 1.398 si prendevano 647 dei 761 secondi della suite).
     *
     * ⚠️ È MySQL/MariaDB e basta, di proposito: è l'unico motore dell'app,
     * anche nei test (vedi il commento in `phpunit.xml`).
     *
     * @return list<string>
     */
    public static function tabelleConTenant(): array
    {
        $tabelle = array_map(
            static fn (object $riga): string => (string) $riga->tabella,
            DB::select(
                'select table_name as tabella from information_schema.columns '
                .'where table_schema = database() and column_name = ?',
                ['tenant_id'],
            ),
        );

        sort($tabelle);

        return array_values(array_unique($tabelle));
    }

    /**
     * ⛔ Non si riusa `CreaTenantPersonale`: quello crea **anche l'utente**, e
     * qui l'utente esiste già. Riusarlo vorrebbe dire un secondo account.
     */
    private function creaIlTenantPersonale(User $utente): Tenant
    {
        $tenant = Tenant::create([
            'name' => $utente->name,
            'slug' => Tenant::generatePersonalSlug(),
            'kind' => TenantKind::Personal,
            'status' => TenantStatus::Active,
            'contact_email' => $utente->email,
        ]);

        /*
         * 🆕 **Lo spazio nuovo è suo** — 3b-AH, Passo 0.
         *
         * 💡 Qui, a differenza di `CreaTenantPersonale`, la persona esiste
         * già: si potrebbe scrivere nella `create()` di sopra. ⚠️ Si scrive
         * dopo lo stesso, così le due strade che creano un tenant personale
         * riempiono la colonna **nello stesso modo** — e chi ne aggiunge una
         * terza domani copia un gesto solo.
         */
        $tenant->forceFill(['proprietario_id' => $utente->getKey()])->save();

        /*
         * 🚨 **I ruoli si creano dentro il tenant nuovo, o `syncRoles()`
         * lancia.** In modalita' teams spatie cerca il ruolo nel tenant
         * corrente: in uno appena nato non c'e' niente, e l'errore che si
         * ottiene — *«There is no role named `free_user`»* — non assomiglia
         * per niente alla causa.
         *
         * 💡 Si creano **tutti** quelli scopati, non solo `FreeUser`: e' la
         * stessa scelta di `CreaTenantPersonale`, e serve il giorno in cui
         * questa persona rientra in una palestra o diventa trainer
         * indipendente. Sono quattro righe.
         */
        $this->context->runAs($tenant, function () use ($tenant): void {
            foreach (UserRole::tenantScoped() as $ruolo) {
                Role::create([
                    'name' => $ruolo->value,
                    'guard_name' => 'web',
                    'tenant_id' => $tenant->getKey(),
                ]);
            }
        });

        return $tenant;
    }

    /**
     * @return array<string, int>
     */
    /**
     * 🆕 **Pubblico da AH.1-ter.6** (18/09/2026): serve anche a
     * `UnisciAUnaPalestra` quando una persona entra in una palestra venendo
     * dallo spazio di un **trainer**.
     *
     * 🚨 Li' non si puo' spostare il tenant intero — dentro ci sono gli altri
     * allievi di quel trainer — e serve esattamente questo: estrarre le righe
     * di **una** persona. ⛔ Riscriverne una seconda versione vorrebbe dire due
     * elenchi di tabelle da tenere allineati, e il giorno che qualcuno ne
     * aggiorna uno solo la differenza non la segnala nessuno.
     */
    public function portaViaLeSueRighe(User $utente, Tenant $da, Tenant $a): array
    {
        $spostate = [];
        $id = $utente->getKey();

        return $this->context->runWithoutTenant(
            function () use ($id, $da, $a, &$spostate): array {
                foreach (self::SEGUONO_LA_PERSONA as $tabella) {
                    $righe = DB::table($tabella)
                        ->where('tenant_id', $da->getKey())
                        ->where('user_id', $id)
                        ->update(['tenant_id' => $a->getKey()]);

                    if ($righe > 0) {
                        $spostate[$tabella] = $righe;
                    }
                }

                /*
                 * 🚨 **Solo le schede che si è scritto da sé.** `member_id` da
                 * solo porterebbe via anche quelle prescritte dal trainer, che
                 * è esattamente la cosa che il committente ha deciso di non
                 * fare: *«le schede del trainer restano al trainer»*.
                 */
                foreach (['workout_plans', 'nutrition_plans'] as $tabella) {
                    $righe = DB::table($tabella)
                        ->where('tenant_id', $da->getKey())
                        ->where('member_id', $id)
                        ->where('created_by', $id)
                        ->update(['tenant_id' => $a->getKey()]);

                    if ($righe > 0) {
                        $spostate[$tabella] = $righe;
                    }
                }

                // ⛔ Qui si staccavano le voci del diario dai piani rimasti in palestra:
                // dal 22/09/2026 il diario non è più sul server (A1 di plan_blocco_A.md).

                return $spostate;
            }
        );
    }

    /**
     * I ruoli si rifanno nel tenant nuovo e si tolgono dal vecchio.
     *
     * 🚨 Stessa logica di `UnisciAUnaPalestra::rifaiIRuoli()`, al contrario: in
     * modalità teams un ruolo vale solo nel proprio tenant, e senza questo
     * passaggio la persona resterebbe **senza nessun ruolo** — un account che
     * esiste e non può fare niente.
     *
     * ⚠️ Si esce come `FreeUser`, non come `Member`: «iscritto» è un ruolo che
     * ha senso dentro una palestra, e fuori non vuol dire niente.
     */
    private function rifaiIRuoli(User $utente, Tenant $palestra, Tenant $personale, UserRole $ruolo = UserRole::FreeUser): void
    {
        DB::table('model_has_roles')
            ->where('model_id', $utente->getKey())
            ->where('model_type', $utente->getMorphClass())
            ->where('tenant_id', $palestra->getKey())
            ->delete();

        $this->context->runAs($personale, function () use ($utente, $ruolo): void {
            $fresco = User::withoutGlobalScopes()->findOrFail($utente->getKey());
            $fresco->unsetRelation('roles');
            $fresco->syncRoles([$ruolo->value]);
        });
    }

    /**
     * 🆕 **Lo spazio del trainer indipendente che gli tiene il posto**, se c'è
     * — 22/09/2026 (Q-A5-1).
     *
     * 💡 Il segno è una riga di `trainer_member` **ancora in piedi** con un
     * trainer indipendente: il posto il trainer lo paga, che abbia risposto
     * «tengo» alla domanda della sovrapposizione o che la domanda non sia mai
     * nata (il trainer non lavora in quella palestra, il caso D1).
     *
     * ⚠️ Se i trainer sono più d'uno (*«infiniti free trainer»*) la casa è una
     * sola: vince quello **sovrapposto in questa palestra** (ha scelto di
     * tenerlo proprio per questo ritorno), poi il rapporto più recente.
     *
     * ⛔ Solo uno spazio **personale, attivo, del trainer**, e solo se il trainer
     * può ancora avere allievi suoi: mandare qualcuno nello spazio di chi ha
     * smesso di pagare vorrebbe dire lasciarlo senza nessuno che paghi.
     */
    private function spazioDelTrainerCheLoTiene(User $utente, Tenant $palestra): ?Tenant
    {
        return $this->context->runWithoutTenant(function () use ($utente, $palestra): ?Tenant {
            $righe = DB::table('trainer_member')
                ->where('member_id', $utente->getKey())
                ->where('tenant_id', '!=', $palestra->getKey())
                ->whereNull('disattivato_il')
                ->orderByRaw('CASE WHEN sovrapposto_in_tenant_id = ? THEN 0 ELSE 1 END', [$palestra->getKey()])
                ->orderByDesc('assigned_at')
                ->get(['trainer_id', 'tenant_id']);

            foreach ($righe as $riga) {
                $spazio = Tenant::withoutGlobalScopes()->find($riga->tenant_id);
                $trainer = User::withoutGlobalScopes()->find($riga->trainer_id);

                if ($spazio !== null
                    && $trainer !== null
                    && $spazio->ePersonale()
                    && $spazio->status->allowsLogin()
                    && $spazio->eDi($trainer)
                    && app(InvitiDelTrainer::class)->puoAvereAllieviSuoi($trainer)) {
                    return $spazio;
                }
            }

            return null;
        });
    }

    /**
     * ⛔ **Il filo con il trainer si taglia, la conversazione no.**
     *
     * 🚨 `trainer_member` è il legame di appartenenza, ed è la cosa che
     * finisce. ⚠️ Le conversazioni restano dove sono: i messaggi sono di **tutti
     * e due**, sono cifrati, e portarli via vorrebbe dire toglierli al trainer
     * che li ha scritti.
     *
     * 💡 Chi esce non li vede più, perché non è più in quel tenant. Se la chiave
     * ce l'ha ancora, i messaggi che aveva già scaricato restano leggibili sul
     * suo telefono: è la stessa proprietà per cui la chat è end-to-end.
     */
    private function sciogliIlLegameConLaPalestra(User $utente, Tenant $palestra): void
    {
        /*
         * ══ 🆕 SI DISATTIVANO, NON SI CANCELLANO — 3b-AH.2 (12/09/2026) ══════
         *
         * ⛔ **Qui c'era una `delete()`**, e contraddiceva la decisione D5 —
         * *«il legame resta, la storia si conserva, il canale si chiude»* —
         * che `InvitiDelTrainer::disattiva()` applica **sulla stessa tabella**.
         *
         * 🚨 Due regole opposte sulla stessa riga, e la differenza si vedeva
         * solo dallo storico che spariva: chi usciva da una palestra perdeva la
         * traccia di **chi l'aveva seguito e quando**, e la palestra perdeva la
         * traccia di aver avuto quella persona fra gli assegnati.
         *
         * 💡 **L'effetto pratico è identico**: un rapporto con `disattivato_il`
         * valorizzato non copre più niente, non conta per nessuno slot e non
         * apre nessun canale — lo dicono già `QuotaDelTrainer` e la chat.
         *
         * ⚠️ **`updateExistingPivot` non si può usare qui**: il pivot è quello
         * del **trainer** e vale per ogni trainer di quel tenant, non per uno.
         * Si scrive sulla tabella, con lo stesso filtro di prima.
         *
         * 🆕 **E si toccano solo i rapporti ancora in piedi**: uno disattivato
         * sei mesi fa conserva la sua data, che è il suo pezzo di storia.
         */
        $this->context->runWithoutTenant(function () use ($utente, $palestra): void {
            DB::table('trainer_member')
                ->where('tenant_id', $palestra->getKey())
                ->where('member_id', $utente->getKey())
                ->whereNull('disattivato_il')
                ->update(['disattivato_il' => now()]);
        });
    }

    /**
     * 🆕 ⚖️ **La libreria esercizi resta leggibile** — 3b-M, 28/08/2026.
     *
     * 📌 *«gli utenti che sono stati iscritti con questi non devono piu'
     * perdere quegli esercizi»*.
     *
     * ⛔ `exercises` sta in `RESTANO_ALLA_PALESTRA`, ed e' giusto: la libreria
     * e' della palestra e non se la porta via chi esce. 🚨 Ma «non se la porta
     * via» non vuol dire «non la sa piu' leggere»: lo storico degli allenamenti
     * di quella persona parla ancora di quegli esercizi, e senza il permesso di
     * lettura si ritroverebbe righe mute — non cancellate, peggio.
     *
     * ⚠️ **Questo e' l'unico istante sicuro** in cui scriverlo. Non c'e' un
     * momento di «ingresso» per chi e' stato creato dentro la palestra dal
     * pannello, e a fine metodo il suo `tenant_id` sara' gia' un altro.
     *
     * 💡 Da' **lettura e basta**: la policy sulla scrittura non cambia, e
     * `Exercise::isGlobal()` continua a dire di no.
     */
    private function ricordaLaLibreria(User $utente, Tenant $palestra): void
    {
        $viste = $utente->librerie_viste ?? [];
        $viste[] = $palestra->getKey();

        $utente->forceFill([
            'librerie_viste' => array_values(array_unique(array_map(intval(...), $viste))),
        ])->save();
    }
}
