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
     * Serve al seeder e alle policy: un ruolo di piattaforma non va assegnato
     * con un tenant, e un ruolo di palestra non va assegnato senza.
     */
    public function isPlatformLevel(): bool
    {
        return $this === self::SuperAdmin;
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
