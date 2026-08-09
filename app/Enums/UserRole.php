<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * I quattro ruoli della piattaforma.
 *
 * I valori coincidono con i nomi dei ruoli spatie salvati a database: l'enum e'
 * la fonte di verita', e il seeder (B1.8) li crea leggendo `cases()`. Cosi'
 * aggiungerne uno significa toccare un file solo.
 *
 * ⚠️ `SuperAdmin` e' l'unico che vive FUORI da ogni palestra: il suo utente ha
 * `tenant_id = null`. Gli altri tre esistono solo dentro una palestra, ed e' per
 * questo che spatie gira in modalita' teams (ADR-05): lo stesso utente puo'
 * essere Trainer nella palestra 1 senza esserlo nella 2.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case GymAdmin = 'gym_admin';
    case Trainer = 'trainer';
    case Member = 'member';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Amministratore piattaforma',
            self::GymAdmin => 'Amministratore palestra',
            self::Trainer => 'Trainer',
            self::Member => 'Iscritto',
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

    /** Puo' entrare in un pannello Filament? Gli iscritti usano solo l'app. */
    public function canAccessAnyPanel(): bool
    {
        return $this !== self::Member;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
