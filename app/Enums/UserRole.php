<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * I sei ruoli della piattaforma.
 *
 * I valori coincidono con i nomi dei ruoli spatie salvati a database: l'enum e'
 * la fonte di verita', e il seeder (B1.8) li crea leggendo `cases()`. Cosi'
 * aggiungerne uno significa toccare un file solo.
 *
 * ⚠️ `SuperAdmin` e' l'unico che vive FUORI da ogni palestra: il suo utente ha
 * `tenant_id = null`. Gli altri esistono solo dentro un tenant, ed e' per
 * questo che spatie gira in modalita' teams (ADR-05): lo stesso utente puo'
 * essere Trainer nella palestra 1 senza esserlo nella 2.
 *
 * ── 🆕 I due ruoli senza palestra — F2.1 della Parte B ──────────────────────
 *
 * `FreeUser` e `FreeTrainer` vivono in un **tenant personale** (`TenantKind`),
 * che e' un tenant vero a tutti gli effetti: quindi non c'e' niente di speciale
 * nel modo in cui i loro ruoli funzionano. E' esattamente il motivo per cui la
 * scelta D1 e' stata presa — il problema dei ruoli «senza palestra» **sparisce
 * da solo** quando una palestra ce l'hanno, anche se e' solo loro.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case GymAdmin = 'gym_admin';
    case Trainer = 'trainer';
    case Member = 'member';

    /** Un trainer indipendente: nessuna palestra alle spalle, utenti propri. */
    case FreeTrainer = 'free_trainer';

    /** Una persona che si e' iscritta da sola, senza codice palestra. */
    case FreeUser = 'free_user';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Amministratore piattaforma',
            self::GymAdmin => 'Amministratore palestra',
            self::Trainer => 'Trainer',
            self::Member => 'Iscritto',
            self::FreeTrainer => 'Trainer indipendente',
            self::FreeUser => 'Utente senza palestra',
        };
    }

    /**
     * Il ruolo vive fuori dalle palestre?
     *
     * ⚠️ Chi risponde `true` **NON è un ruolo spatie**: è la colonna
     * `users.is_super_admin`. In modalità teams `model_has_roles.tenant_id` è
     * NOT NULL e in chiave primaria, quindi un'assegnazione senza palestra è
     * impossibile; e comunque i ruoli sono limitati al tenant corrente, mentre
     * il super admin deve valere **anche dentro** una palestra.
     *
     * Il seeder (B1.8) crea ruoli spatie solo per i casi che rispondono `false`.
     */
    public function isPlatformLevel(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * I soli ruoli che esistono davvero come ruoli spatie.
     *
     * @return array<int, self>
     */
    public static function tenantScoped(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $r): bool => ! $r->isPlatformLevel(),
        ));
    }

    /**
     * Puo' entrare in **qualche** pannello Filament? Gli iscritti usano solo l'app.
     *
     * ── 🚨 Perche' e' un `match` senza `default` ────────────────────────────
     *
     * Prima di F2 questo metodo era `return $this !== self::Member;` — una
     * **lista di esclusione**. Aggiungendo `FreeUser` all'enum, quella riga gli
     * avrebbe risposto `true` **in silenzio**: nessun errore, nessun test rosso,
     * solo un ruolo nuovo che risulta ammesso a un pannello per il fatto di non
     * essere `Member`.
     *
     * Adesso e' un `match` **esaustivo e senza `default`**: aggiungere un caso
     * all'enum senza decidere qui cosa fa produce un `UnhandledMatchError`. Non
     * e' una scortesia — e' l'unico modo perche' la decisione «questo ruolo entra
     * in un pannello?» non si possa **dimenticare**. Il tipo impone la regola, e
     * non serve ricordarsene.
     *
     * ── ⚠️ Onesta' su cosa questo metodo NON fa ────────────────────────────
     *
     * **Nessuno lo chiama.** Verificato con un grep su tutta la codebase al
     * 13/08/2026: il cancello vero e' `User::canAccessPanel()`, che Filament
     * invoca e che decide **pannello per pannello**. Questo metodo e' un
     * riepilogo leggibile, non una guardia.
     *
     * 🚨 E' esattamente il difetto ricorrente di questo progetto — *«un commento
     * non e' un vincolo»*: un metodo che si legge come una regola di sicurezza e
     * che non protegge niente. Non e' stato cancellato perche' e' il posto giusto
     * dove leggere la risposta in una riga; e' stato **legato a un test**
     * (`PanelAccessTest`) che confronta cio' che dice con cio' che
     * `canAccessPanel()` fa davvero, per ogni ruolo. Se i due divergono, il test
     * lo dice nominando il ruolo.
     */
    public function canAccessAnyPanel(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::GymAdmin, self::Trainer, self::FreeTrainer => true,

            // 💡 `FreeUser` sta con `Member` e non e' un caso: sono la stessa
            // persona vista da due lati — qualcuno che usa l'app e basta. La
            // differenza e' solo chi paga per lei.
            self::Member, self::FreeUser => false,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
