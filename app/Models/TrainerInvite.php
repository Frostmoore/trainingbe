<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\EUnInvito;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * L'invito di un trainer indipendente a **una persona** — F6.2.
 *
 * 🚨 Usa `BelongsToTenant`: senza, un trainer vedrebbe gli inviti di tutti gli
 * altri — cioè gli indirizzi email dei clienti dei suoi concorrenti.
 */
class TrainerInvite extends Model
{
    use BelongsToTenant;

    /*
     * ── 🚨 Le regole di validita' NON stanno piu' qui — 3b-V.1.1 ──────────
     *
     * Da 3b-V esiste un secondo invito (`InvitoInPalestra`, per le palestre), e
     * le condizioni che rendono valido un invito sono **le stesse**.
     *
     * ⛔ Copiarle di la' e lasciarle qui vorrebbe dire due regole che divergono
     * al primo ritocco — e quella che diverge non da' nessun errore: e' un
     * invito che continua a funzionare quando non dovrebbe.
     *
     * 💡 La prova che serviva davvero e' arrivata subito: 3b-V aggiunge una
     * **quarta** condizione (`rifiutato_il`), e con il concern arriva a tutti e
     * due nello stesso istante.
     */
    use EUnInvito;

    /**
     * 🆕 🔗 La pagina che apre questo invito — B2, 24/09/2026.
     *
     * ⚠️ **Non è la stessa di `InvitoInPalestra`**, e non è un dettaglio: quella
     * di un trainer racconta chi ti allenerà, quella di una palestra racconta
     * dove ti iscrivi. Il link lo compone `EUnInvito::link()` leggendo di qui.
     */
    public const PERCORSO_DEL_LINK = 'invito';

    protected $fillable = ['tenant_id', 'trainer_id', 'token', 'email', 'expires_at'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'rifiutato_il' => 'datetime',

            /*
             * 🆕 📧 **Quando è partita la mail** — B1, 24/09/2026.
             *
             * ⚠️ Non è `email !== null`: quello dice **a chi** si voleva
             * mandarlo. La mail può non partire (trasporto giù, indirizzo
             * rifiutato) e l'invito resta valido di proposito — il link esiste
             * e chi invita ce l'ha.
             */
            'email_inviata_il' => 'datetime',
        ];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    public function accettatoDa(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_by');
    }
}
