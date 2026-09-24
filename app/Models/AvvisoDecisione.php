<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🗳️ **Chi ha visto un avviso, e cosa ha risposto** — blocco B4, 24/09/2026.
 *
 * 📌 Il committente: *«Il tasto per approvare deve poter fare una serie di cose
 * … e logga. Rifiuta chiude sempre la modale e logga il rifiuto»*.
 *
 * ══ 🚨 NON E' UN REGISTRO «PER LE STATISTICHE» ══════════════════════════════
 *
 * Fa **tre** lavori, e solo il primo è ovvio:
 *
 * | Lavoro | Perché |
 * |---|---|
 * | i numeri | *«il numero di chi dice di no è metà del motivo per cui si manda un avviso»* |
 * | **la memoria** | decisa una volta, quella modale **non ricompare** |
 * | **la difesa** | il giorno che qualcuno dice *«non ho mai accettato»*, la risposta è una riga con una data |
 *
 * ⚠️ E sta **sul server**, non nelle preferenze del telefono: chi entra dal
 * secondo dispositivo non se la deve ripigliare.
 */
class AvvisoDecisione extends Model
{
    protected $table = 'avvisi_decisioni';

    /** Le due risposte possibili. ⛔ `null` = ancora da decidere. */
    public const APPROVATO = 'approvato';

    public const RIFIUTATO = 'rifiutato';

    protected $fillable = ['avviso_id', 'user_id', 'mostrato_il', 'decisione', 'deciso_il'];

    protected function casts(): array
    {
        return [
            'mostrato_il' => 'datetime',
            'deciso_il' => 'datetime',
        ];
    }

    /** @return BelongsTo<Avviso, $this> */
    public function avviso(): BelongsTo
    {
        return $this->belongsTo(Avviso::class);
    }

    /** @return BelongsTo<User, $this> */
    public function utente(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
