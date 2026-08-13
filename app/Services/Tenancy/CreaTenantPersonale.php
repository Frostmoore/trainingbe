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
use Spatie\Permission\Models\Role;

/**
 * Fa nascere una persona che non ha una palestra — F1.3 della Parte B.
 *
 * ── 🚨 Perché serve un servizio, e non quattro righe nel controller ─────────
 *
 * Perché le porte d'ingresso sono **più di una**: la registrazione con email
 * (`AuthController::register()`, F3) e l'accesso con un fornitore esterno
 * (`SocialAuthController`, A6). Se ciascuna si creasse il tenant per conto suo,
 * basterebbe che una delle due dimenticasse un passo — i ruoli, o il tipo — per
 * avere un utente in uno stato che nessun test copre e che si scopre mesi dopo.
 *
 * ⚠️ E i passi sono facili da sbagliare: sono quattro, **l'ordine conta**, e due
 * di essi vanno eseguiti dentro il contesto del tenant appena creato.
 *
 * ── I quattro passi, e cosa succede se se ne salta uno ──────────────────────
 *
 * | # | Passo | Se manca |
 * |---|---|---|
 * | 1 | Il tenant, `kind = personal`, `status = active` | — |
 * | 2 | I ruoli spatie **dentro** quel tenant | 🚨 `assignRole()` lancia: in modalità teams il ruolo va cercato nel tenant corrente, e lì non c'è |
 * | 3 | L'utente, **dentro** il contesto | `tenant_id` resta vuoto → l'utente diventa **un super admin di fatto** (vedi `TenantKind`) |
 * | 4 | Il ruolo assegnato | Un utente senza ruolo: non rompe nulla subito, e smette di funzionare al primo controllo di permessi |
 *
 * ── ⚠️ Tutto in una transazione ────────────────────────────────────────────
 *
 * A metà si otterrebbe un **tenant senza utente**: invisibile nel pannello
 * (è personale, F1.4) e senza nessuno che possa entrarci. Non darebbe errore,
 * non comparirebbe da nessuna parte, e resterebbe lì.
 *
 * 💡 La transazione è annidabile: `AuthController::register()` ha già la sua, e
 * PDO la trasforma in un savepoint. Averla anche qui significa che il servizio è
 * sicuro **anche** chiamato da solo — da un comando, da un seeder, da un test.
 *
 * ── 🚨 Due scostamenti dal piano, dichiarati ────────────────────────────────
 *
 * `plan_parte_b.md` §5.1 scriveva `__invoke(string $nome, string $email): Tenant`.
 * Qui la firma è diversa in due punti, entrambi deliberati:
 *
 * 1. **Restituisce `User`, non `Tenant`.** Chi chiama deve emettere il token per
 *    quella persona, e da un `Tenant` ci arriverebbe solo con una seconda query
 *    su una relazione che ha appena creato. Il tenant resta raggiungibile con
 *    `$user->tenant`.
 * 2. **Accetta `$attributi` e `$ruolo`.** Senza `$attributi` non ci sarebbe dove
 *    passare la **password**, e la firma del piano creava un utente che non
 *    avrebbe potuto accedere.
 *
 * ✅ **`$ruolo` vale `FreeUser` da F2.1** (13/08/2026). Fino ad allora era
 * `Member`, come segnaposto sicuro, perché i due ruoli senza palestra non
 * esistevano ancora. 💡 Nessun dato è nato sbagliato nel frattempo: la
 * registrazione senza codice palestra è **F3**, quindi quando il valore di serie
 * è cambiato non esisteva ancora un solo utente creato da qui.
 *
 * ⚠️ Il parametro resta perché **la stessa strada serve a due nascite diverse**:
 * una persona che si iscrive da sola (`FreeUser`) e un trainer indipendente
 * (`FreeTrainer`, F6). È lo stesso tenant personale, cambia solo chi ci abita.
 */
final class CreaTenantPersonale
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * @param  array<string, mixed>  $attributi  gli altri campi dell'utente: `password`, `username`, `phone`, `locale`…
     */
    public function __invoke(
        string $nome,
        string $email,
        array $attributi = [],
        UserRole $ruolo = UserRole::FreeUser,
    ): User {
        return DB::transaction(function () use ($nome, $email, $attributi, $ruolo): User {
            $tenant = Tenant::create([
                'name' => $nome,
                'slug' => Tenant::generatePersonalSlug(),

                /*
                 * 🚨 **Il codice d'invito c'è ma non deve aprire niente.**
                 *
                 * `join_code` è `unique` e NOT NULL, quindi un valore ci deve
                 * essere. Ma un tenant personale non ha nessuno da invitare, e
                 * un codice funzionante sarebbe una porta su un account altrui:
                 * chi lo presentasse si registrerebbe **dentro** lo spazio di
                 * un'altra persona, e da lì il `TenantScope` gli mostrerebbe i
                 * dati di quella persona.
                 *
                 * ⚠️ La difesa vera **non è qui**: è in
                 * `AuthController::resolveActiveTenant()`, che rifiuta il codice
                 * quando il tenant è personale. Questo valore casuale serve solo
                 * a non far collidere l'indice unico — se fosse una costante,
                 * il secondo tenant personale non nascerebbe proprio.
                 */
                'join_code' => Tenant::generateJoinCode(),

                'kind' => TenantKind::Personal,

                /*
                 * Attivo subito, non in prova: il periodo di prova è un concetto
                 * commerciale che riguarda le palestre. Uno stato `trial` con
                 * `trial_ends_at` vuoto passerebbe comunque `isActive()`, ma
                 * direbbe una cosa falsa a chiunque legga la riga, e il giorno in
                 * cui qualcuno scrivesse un comando «chiudi le prove scadute»
                 * quella falsità diventerebbe la cancellazione di account veri.
                 */
                'status' => TenantStatus::Active,
                'contact_email' => $email,
            ]);

            return $this->context->runAs($tenant, function () use ($tenant, $nome, $email, $attributi, $ruolo): User {
                /*
                 * ⚠️ I ruoli si creano **dentro** il contesto: spatie gira in
                 * modalità teams e `Role::create()` prende il team dal contesto
                 * corrente. Creati fuori, finirebbero nel team sbagliato (o in
                 * nessuno) e `assignRole()` due righe più sotto non li
                 * troverebbe.
                 *
                 * 💡 Si creano **tutti** i ruoli scopati, non solo quello che
                 * serve adesso: un tenant personale è un tenant vero, e il
                 * giorno in cui una persona passa a una palestra (req. B4) o
                 * diventa trainer indipendente (F6) i ruoli devono esserci già.
                 * Sono quattro righe in una tabella, non un costo.
                 */
                foreach (UserRole::tenantScoped() as $r) {
                    Role::create([
                        'name' => $r->value,
                        'guard_name' => 'web',
                        'tenant_id' => $tenant->id,
                    ]);
                }

                $utente = User::create(array_merge($attributi, [
                    'name' => $nome,
                    'email' => $email,

                    /*
                     * Esplicito, anche se `BelongsToTenant` lo riempirebbe da
                     * solo dal contesto. 🚨 È la riga da cui dipende tutto: se
                     * restasse vuota, questa persona diventerebbe un super admin
                     * di fatto e vedrebbe i dati di ogni palestra. Un valore
                     * scritto a mano si nota leggendo; un riempimento implicito
                     * si nota solo quando manca.
                     */
                    'tenant_id' => $tenant->id,
                ]));

                $utente->assignRole($ruolo->value);

                return $utente;
            });
        });
    }
}
