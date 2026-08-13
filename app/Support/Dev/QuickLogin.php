<?php

declare(strict_types=1);

namespace App\Support\Dev;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * L'accesso rapido di sviluppo.
 *
 * 🚨 **Doppia serratura, e la seconda non si può aprire.**
 *
 * 1. `config('dev.quick_login')` — l'interruttore, spento di default.
 * 2. `app()->environment('production')` — se l'ambiente è produzione questa
 *    classe risponde «disattivata» **qualunque cosa dica la configurazione**.
 *
 * La seconda esiste perché la prima non basta: i `.env` si copiano fra
 * ambienti, e prima o poi qualcuno porta in produzione quello dello staging.
 * Una funzione che entra senza password non deve dipendere dall'attenzione di
 * nessuno.
 *
 * Non è nemmeno una comodità in più: è l'unico modo in cui un utente non
 * autenticato può ottenere una sessione senza credenziali, quindi è l'unico
 * punto del sistema che va guardato con questo sospetto.
 */
final class QuickLogin
{
    public static function enabled(): bool
    {
        if (app()->environment('production')) {
            return false;
        }

        return (bool) config('dev.quick_login', false);
    }

    /**
     * Gli account proposti, in ordine di potere decrescente.
     *
     * Cerca utenti che **esistono già** (creati dai seeder): non ne crea, così
     * non può popolare un database vero con account di prova.
     *
     * @return list<array{label: string, email: string, description: string}>
     */
    public static function candidates(): array
    {
        if (! self::enabled()) {
            return [];
        }

        $context = app(TenantContext::class);

        return $context->runWithoutTenant(function (): array {
            $out = [];

            $god = User::withoutGlobalScopes()->where('is_super_admin', true)->first();

            if ($god !== null) {
                $out[] = [
                    'label' => 'Piattaforma',
                    'email' => $god->email,
                    'description' => 'Super admin — vede tutte le palestre',
                ];
            }

            foreach ([UserRole::GymAdmin, UserRole::Trainer, UserRole::Member] as $ruolo) {
                $u = self::primoConRuolo($ruolo);

                if ($u !== null) {
                    $out[] = [
                        'label' => $ruolo->label(),
                        'email' => $u->email,
                        'description' => $u->tenant?->name ?? '—',
                    ];
                }
            }

            return $out;
        });
    }

    /**
     * Il primo utente con quel ruolo, cercando palestra per palestra.
     *
     * ⚠️ Non si può fare con una query sola: i ruoli sono limitati al tenant
     * corrente (modalità teams), quindi `role('trainer')` fuori da un contesto
     * non trova niente. Va interrogata ogni palestra dentro al suo contesto.
     */
    private static function primoConRuolo(UserRole $ruolo): ?User
    {
        $context = app(TenantContext::class);

        foreach (Tenant::query()->orderBy('id')->get() as $tenant) {
            $u = $context->runAs($tenant, fn () => User::role($ruolo->value)->first());

            if ($u !== null) {
                return $u;
            }
        }

        return null;
    }

    /** L'utente da autenticare, o null se l'email non è fra i candidati. */
    public static function resolve(string $email): ?User
    {
        if (! self::enabled()) {
            return null;
        }

        // Solo indirizzi presenti nell'elenco proposto: senza questo controllo
        // l'endpoint diventerebbe «entra come chiunque, basta il suo indirizzo».
        $ammessi = array_column(self::candidates(), 'email');

        if (! in_array($email, $ammessi, true)) {
            return null;
        }

        return app(TenantContext::class)->runWithoutTenant(
            fn () => User::withoutGlobalScopes()->where('email', $email)->first(),
        );
    }
}
