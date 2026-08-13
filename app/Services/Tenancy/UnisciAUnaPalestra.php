<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantStatus;
use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
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

    public function __construct(private readonly TenantContext $context) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(User $utente, string $joinCode): Tenant
    {
        $vecchio = $utente->tenant;

        if ($vecchio === null || ! $vecchio->ePersonale()) {
            /*
             * 🚨 **Solo da un tenant personale.** Spostare un iscritto da una
             * palestra a un'altra è un'operazione **commerciale**, non una
             * scelta dell'utente: vorrebbe dire che chiunque conosca il codice
             * di un'altra palestra può portarsi via i propri dati dalla propria.
             */
            throw ValidationException::withMessages([
                'join_code' => __('Sei già iscritto a una palestra. Per cambiarla, parlane con loro.'),
            ]);
        }

        $palestra = Tenant::where('join_code', $joinCode)->first();

        // ⚠️ Stesso rifiuto indistinguibile del login: codice inesistente,
        // palestra sospesa e tenant personale danno lo stesso errore.
        if ($palestra === null || $palestra->ePersonale() || ! $palestra->isActive()) {
            throw ValidationException::withMessages([
                'join_code' => __('Codice palestra non valido.'),
            ]);
        }

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
                'join_code' => __('In questa palestra esiste già un account con la tua email. Contattala.'),
            ]);
        }

        return DB::transaction(function () use ($utente, $vecchio, $palestra): Tenant {
            $spostate = $this->spostaLeRighe($vecchio, $palestra);

            $utente->forceFill(['tenant_id' => $palestra->getKey()])->save();

            $this->rifaiIRuoli($utente, $vecchio, $palestra);

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
     * Le tabelle con `tenant_id`, meno le eccezioni motivate.
     *
     * @return array<int, string>
     */
    private function tabelleDaSpostare(): array
    {
        $tabelle = [];

        foreach (Schema::getTableListing() as $tabella) {
            // ⚠️ Il nome può arrivare qualificato con lo schema su alcuni driver.
            $nome = str_contains($tabella, '.') ? explode('.', $tabella)[1] : $tabella;

            if (in_array($nome, self::RESTANO_INDIETRO, true)) {
                continue;
            }

            if (Schema::hasColumn($nome, 'tenant_id')) {
                $tabelle[] = $nome;
            }
        }

        return $tabelle;
    }

    /**
     * I ruoli si rifanno **dentro** la palestra nuova.
     *
     * 🚨 In modalità teams un ruolo vale solo nel proprio tenant: quelli del
     * tenant personale non seguono la persona, e senza questo passaggio si
     * ritroverebbe **senza nessun ruolo** nella palestra — cioè un account che
     * esiste e non può fare niente.
     *
     * ⚠️ E si entra come `Member`, sempre: il ruolo non è un dato in ingresso.
     * Chi era `FreeTrainer` non diventa trainer della palestra perché ha
     * digitato il suo codice — quello lo decide la palestra dal suo pannello.
     */
    private function rifaiIRuoli(User $utente, Tenant $vecchio, Tenant $palestra): void
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

        $this->context->runAs($palestra, function () use ($utente): void {
            $fresco = User::withoutGlobalScopes()->findOrFail($utente->getKey());
            $fresco->unsetRelation('roles');
            $fresco->syncRoles([UserRole::Member->value]);
        });
    }
}
