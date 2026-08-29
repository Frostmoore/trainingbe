<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\EUnInvito;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'invito di una palestra a **una persona** — 3b-V.1.
 *
 * 🚨 **Il gemello di `TrainerInvite`**, e le regole di validita' non sono
 * copiate: stanno tutte e due in [EUnInvito]. Il perche' e' scritto li', ed e'
 * la cosa piu' importante di questo file.
 *
 * 🚨 Usa `BelongsToTenant`: senza, il gestore di una palestra vedrebbe gli
 * inviti di tutte le altre — cioe' gli indirizzi email dei clienti dei suoi
 * concorrenti.
 */
class InvitoInPalestra extends Model
{
    use BelongsToTenant;
    use EUnInvito;

    /**
     * ⚠️ **La tabella si dichiara**: il nome della classe e' italiano, quello
     * della tabella e' inglese come il suo gemello (`trainer_invites`). Lasciare
     * indovinare a Eloquent darebbe `invito_in_palestras`, che oltre a essere
     * brutto non esiste.
     */
    protected $table = 'tenant_invites';

    protected $fillable = [
        'tenant_id', 'invitato_da', 'token', 'email', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'rifiutato_il' => 'datetime',
        ];
    }

    /** La palestra che invita. */
    public function palestra(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'tenant_id');
    }

    /** Chi materialmente ha premuto «invita». */
    public function invitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invitato_da');
    }

    public function accettatoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
