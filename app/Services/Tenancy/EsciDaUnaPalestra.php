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
     * senza di lei: il diario, i preferiti, il profilo, i consigli ricevuti.
     */
    public const SEGUONO_LA_PERSONA = [
        'ai_advices',
        'device_tokens',
        'food_entries',
        'food_favorites',
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

        return DB::transaction(function () use ($utente, $palestra): Tenant {
            $personale = $this->creaIlTenantPersonale($utente);

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

            $this->rifaiIRuoli($utente, $palestra, $personale);
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
            $this->pausa->riprendi($utente, $palestra, $personale);

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
            'join_code' => Tenant::generateJoinCode(),
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

                /*
                 * ⚠️ **Il diario segue la persona, il piano che lo ispirava
                 * no.** Una voce che punta a un piano rimasto in palestra
                 * diventerebbe un riferimento **attraverso** i tenant: il
                 * `TenantScope` non lo risolverebbe, e la voce sembrerebbe
                 * rotta.
                 *
                 * 💡 Si stacca il collegamento, non la voce: calorie, macro e
                 * descrizione restano — era quello il dato.
                 */
                $staccate = DB::table('food_entries')
                    ->where('tenant_id', $a->getKey())
                    ->whereNotNull('nutrition_plan_id')
                    ->whereNotIn(
                        'nutrition_plan_id',
                        DB::table('nutrition_plans')
                            ->where('tenant_id', $a->getKey())
                            ->select('id')
                    )
                    ->update(['nutrition_plan_id' => null]);

                if ($staccate > 0) {
                    $spostate['food_entries.nutrition_plan_id_staccati'] = $staccate;
                }

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
    private function rifaiIRuoli(User $utente, Tenant $palestra, Tenant $personale): void
    {
        DB::table('model_has_roles')
            ->where('model_id', $utente->getKey())
            ->where('model_type', $utente->getMorphClass())
            ->where('tenant_id', $palestra->getKey())
            ->delete();

        $this->context->runAs($personale, function () use ($utente): void {
            $fresco = User::withoutGlobalScopes()->findOrFail($utente->getKey());
            $fresco->unsetRelation('roles');
            $fresco->syncRoles([UserRole::FreeUser->value]);
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
