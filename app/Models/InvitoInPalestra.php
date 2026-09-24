<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
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

    /**
     * 🆕 🔗 La pagina che apre questo invito — B2, 24/09/2026.
     *
     * ⚠️ **Diversa da quella di `TrainerInvite`**: qui si racconta una palestra
     * in cui iscriversi, là una persona che ti allenerà. Il link lo compone
     * `EUnInvito::link()` leggendo di qui.
     */
    public const PERCORSO_DEL_LINK = 'invito-palestra';

    protected $fillable = [
        'tenant_id', 'invitato_da', 'token', 'email', 'expires_at',

        /*
         * 🆕 **Con che ruolo si entra** — 3b-AH.1-quater (12/09/2026).
         *
         * ⚠️ È assegnabile perché chi crea l'invito è **la palestra**, dal suo
         * pannello, e lì il ruolo è una scelta legittima. 🚨 Quello che non deve
         * mai succedere è che arrivi da **chi riscatta**: il token dice già
         * tutto, e il ruolo si legge da qui, non dalla richiesta.
         */
        'ruolo',
    ];

    protected function casts(): array
    {
        return [
            'ruolo' => UserRole::class,
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'rifiutato_il' => 'datetime',

            /*
             * 🆕 📧 **Quando è partita la mail** — B2, 24/09/2026.
             *
             * ⚠️ Non è `email !== null`: quello dice **a chi** si voleva
             * mandarlo. La mail può non partire, e in quel caso l'invito resta
             * valido di proposito — il link c'è e chi invita ce l'ha.
             */
            'email_inviata_il' => 'datetime',
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
