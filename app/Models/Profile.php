<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Il profilo di un iscritto **sul server**: dal 19/09/2026 contiene solo le note
 * dello staff.
 *
 * ══ 📱 DOVE SONO FINITI I DATI DEL CORPO ══════════════════════════════════
 *
 * ⛔ Qui c'erano sesso, data di nascita, altezza, livello di attivita',
 * obiettivo, peso obiettivo e orari dei pasti, con le traduzioni verso le
 * formule (`sexForFormula`, `activityForFormula`, `goalForFormula`), l'eta' e
 * `computedTargets()`. 📌 Il committente: *«assicurati che non ci scriva niente
 * di personale (esattamente come peso altezza e data di nascita)»*.
 *
 * 💡 Stanno sul telefono (`ProfiloSulTelefono` nell'app), e il fabbisogno lo
 * calcola `CalcolatoreCalorie` nell'app. Le colonne sono state tolte dalla
 * migrazione `2026_09_19_150000_il_profilo_non_ha_piu_i_dati_del_corpo`.
 *
 * ⚠️ **`notes` resta**: sono le note dello staff («infortuni, limitazioni»), le
 * scrive la palestra dal pannello. ⏳ Possono riguardare la salute: la base
 * giuridica e' da definire con il legale.
 */
class Profile extends Model
{
    use BelongsToTenant;
    use HasFactory;

    protected $fillable = ['tenant_id', 'user_id', 'notes'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
