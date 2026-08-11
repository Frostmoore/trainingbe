<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * La chiave **pubblica** X25519 di una persona — S6.3.
 *
 * Pubblica davvero: serve a scrivere a qualcuno, non a leggere quello che ha
 * scritto. Che stia sul nostro server in chiaro non e' una svista.
 *
 * ── L'unico attacco che questo schema non ferma da solo ────────────────────
 *
 * 🚨 **Le chiavi pubbliche le distribuiamo noi.** Un server malevolo potrebbe
 * darne una propria a entrambe le parti e mettersi in mezzo: la crittografia
 * non se ne accorgerebbe, perche' i conti tornerebbero — sarebbero solo le
 * chiavi sbagliate.
 *
 * Le due difese, entrambe lato app:
 * 1. **L'impronta di sicurezza** — le due parti si leggono a voce cinque gruppi
 *    di cinque cifre e sanno di parlare fra loro. Dieci secondi in palestra.
 * 2. **La chiave vista la prima volta si ricorda**, e se cambia l'app lo dice.
 *    Non lo impedisce: lo rende visibile. E' quello che fanno Signal e WhatsApp,
 *    per lo stesso identico motivo.
 *
 * ⚠️ Il caso di gran lunga piu' comune di cambio chiave **non e' un attacco**:
 * e' qualcuno che ha perso la chiave maestra e ne ha generata una nuova. Il
 * messaggio all'utente deve dire quello, non gridare alla manomissione.
 */
class ChatKey extends Model
{
    protected $fillable = ['user_id', 'public_key'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
