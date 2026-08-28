<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantKind;
use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
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
        'importazioni_piani',
        'profiles',
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
     * | `exercises` | La libreria esercizi della palestra |
     * | `model_has_permissions` · `model_has_roles` · `roles` | I ruoli si rifanno, non si spostano — vedi `rifaiIRuoli()` |
     * | `plan_subscriptions` | Abbonamento della palestra alla piattaforma |
     * | `trainer_invites` · `trainer_member` | 🚨 **Il legame con la palestra: è precisamente quello che finisce** |
     * | `users` | La riga della persona si aggiorna, non si sposta |
     * | `workout_plan_imports` | Segue la scheda importata, non la persona |
     */
    public const RESTANO_ALLA_PALESTRA = [
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
        'nutrition_plans',
        'workout_plans',
    ];

    public function __construct(private readonly TenantContext $context) {}

    public function __invoke(User $utente): Tenant
    {
        $palestra = $utente->tenant;

        if ($palestra === null || $palestra->ePersonale()) {
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

    /** @return list<string> */
    public static function tabelleConTenant(): array
    {
        $tabelle = [];

        foreach (Schema::getTableListing() as $tabella) {
            // ⚠️ Il nome può arrivare qualificato con lo schema su alcuni driver.
            $nome = str_contains($tabella, '.') ? explode('.', $tabella)[1] : $tabella;

            if (Schema::hasColumn($nome, 'tenant_id')) {
                $tabelle[] = $nome;
            }
        }

        sort($tabelle);

        return $tabelle;
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
    private function portaViaLeSueRighe(User $utente, Tenant $da, Tenant $a): array
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
        $this->context->runWithoutTenant(function () use ($utente, $palestra): void {
            DB::table('trainer_member')
                ->where('tenant_id', $palestra->getKey())
                ->where('member_id', $utente->getKey())
                ->delete();
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
