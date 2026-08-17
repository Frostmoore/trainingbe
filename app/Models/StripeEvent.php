<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Un evento ricevuto da Stripe — 17/08/2026.
 *
 * 🚨 **Non ha `TenantScope`, ed e' voluto.** Un webhook arriva senza sessione e
 * senza utente: non c'e' nessun tenant nel contesto, e un filtro globale lo
 * renderebbe invisibile a se stesso — cioe' il controllo di doppione non
 * troverebbe mai niente e ogni ritentativo verrebbe accreditato di nuovo.
 */
class StripeEvent extends Model
{
    protected $fillable = ['event_id', 'type', 'payload', 'processed_at'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
