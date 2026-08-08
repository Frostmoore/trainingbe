<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Stato di una palestra sulla piattaforma.
 *
 * `allowsLogin()` è l'unico punto in cui si decide se gli utenti di un tenant
 * possono entrare: middleware, pannelli e API lo chiamano tutti da lì invece di
 * confrontare stringhe, così aggiungere uno stato non richiede di andare a
 * caccia dei confronti sparsi.
 */
enum TenantStatus: string
{
    case Trial = 'trial';
    case Active = 'active';
    case Suspended = 'suspended';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Trial => 'In prova',
            self::Active => 'Attiva',
            self::Suspended => 'Sospesa',
            self::Cancelled => 'Cessata',
        };
    }

    /** Gli utenti di questo tenant possono autenticarsi? */
    public function allowsLogin(): bool
    {
        return match ($this) {
            self::Trial, self::Active => true,
            self::Suspended, self::Cancelled => false,
        };
    }

    /** Colore per i badge Filament. */
    public function color(): string
    {
        return match ($this) {
            self::Trial => 'info',
            self::Active => 'success',
            self::Suspended => 'warning',
            self::Cancelled => 'danger',
        };
    }
}
