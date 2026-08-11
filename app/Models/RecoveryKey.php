<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il pacchetto incartato della chiave maestra — S6.2.
 *
 * ── Cosa c'e' dentro, e cosa no ────────────────────────────────────────────
 *
 * C'e' la chiave maestra dell'utente, **cifrata** con una chiave derivata dalla
 * sua password di recupero. Non c'e' la password, e non c'e' niente da cui
 * ricavarla senza provarla: Argon2id con i parametri scritti nella riga stessa.
 *
 * 🚨 **Che questo pacchetto stia da noi e' il motivo per cui il KDF non e' un
 * parametro da lasciare al default.** Se il database uscisse, un attaccante
 * potrebbe provare le password *offline*, senza il limite di tentativi che
 * protegge un login. Il costo di Argon2id e' l'unica difesa che resta in quello
 * scenario.
 *
 * ⚠️ **Perche' non ha `tenant_id`**: e' la stessa regola di `messages` e
 * `plan_exercises` — righe che si raggiungono **solo attraverso il padre**, mai
 * per id. Qui il padre e' l'utente, e l'unica lettura possibile e' «il mio».
 * Aggiungere `tenant_id` sarebbe anche un problema in prospettiva: nella Parte B
 * arrivano i *free user*, che una palestra non ce l'hanno.
 */
class RecoveryKey extends Model
{
    protected $fillable = [
        'user_id', 'version', 'kdf', 'ops_limit', 'mem_limit',
        'salt', 'nonce', 'wrapped_key',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'ops_limit' => 'integer',
            'mem_limit' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Come lo vede l'app: gli stessi nomi che l'app scrive nel proprio JSON.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'version' => $this->version,
            'kdf' => $this->kdf,
            'ops_limit' => $this->ops_limit,
            'mem_limit' => $this->mem_limit,
            'salt' => $this->salt,
            'nonce' => $this->nonce,
            'wrapped_key' => $this->wrapped_key,
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
