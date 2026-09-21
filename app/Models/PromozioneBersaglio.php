<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TipoBersaglio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 🎯 **A chi si applica una promozione** — 22/09/2026, fase F2.
 *
 * 📌 *«per tutti, per alcuni ruoli, per gli allievi di una palestra o di un
 * trainer specifico o per un allievo in particolare (multipli)»*.
 *
 * ⚠️ **Una promozione ne ha più di uno**, e vale se **almeno uno** la prende:
 * sono in OR, non in AND. ⛔ In AND sarebbero due condizioni da soddisfare
 * insieme — «i trainer che sono anche allievi di Tizio» — che non è quello che
 * si intende dicendo «per i trainer e per gli allievi di Tizio».
 *
 * 🚨 **NON usa `BelongsToTenant`**: la riga appartiene alla promozione, che è
 * della piattaforma. La colonna `tenant_id` qui dice **quale palestra è il
 * bersaglio**, non di chi è la riga — sono due significati diversi per lo
 * stesso nome, ed è il motivo per cui il trait qui sarebbe sbagliato.
 */
class PromozioneBersaglio extends Model
{
    protected $table = 'promozione_bersagli';

    // 🆕 P5 — `escludi`: la riga toglie invece di aggiungere.
    protected $fillable = ['promozione_id', 'tipo', 'escludi', 'ruolo', 'tenant_id', 'user_id'];

    protected function casts(): array
    {
        return ['tipo' => TipoBersaglio::class, 'escludi' => 'boolean'];
    }

    public function promozione(): BelongsTo
    {
        return $this->belongsTo(Promozione::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withoutGlobalScopes();
    }

    /** Come si legge nel pannello: «Palestra Demo», «tutti i member», … */
    public function descrizione(): string
    {
        // 🆕 P5 — una riga che esclude si legge «tranne …».
        return ($this->escludi ? 'tranne ' : '').match ($this->tipo) {
            TipoBersaglio::Tutti => 'tutti',
            TipoBersaglio::Ruolo => 'chi è '.($this->ruolo ?? '?'),
            TipoBersaglio::Palestra => 'la palestra '.($this->tenant?->name ?? '?'),
            TipoBersaglio::AllieviDiTrainer => 'gli allievi di '.($this->user?->name ?? '?'),
            TipoBersaglio::Utente => $this->user?->name ?? '?',
        };
    }
}
