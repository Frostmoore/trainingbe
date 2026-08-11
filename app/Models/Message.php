<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un messaggio.
 *
 * Come `PlanExercise`, **non ha `tenant_id`**: vive solo dentro una
 * conversazione, che ce l'ha. La regola — «queste righe si raggiungono solo
 * attraverso il padre, mai per id» — e' scritta per esteso in `PlanExercise` e
 * vale identica qui.
 *
 * ── 🚨 Da S6: `body` NON contiene testo ────────────────────────────────────
 *
 * Contiene il base64 di una busta cifrata con `crypto_box` (X25519 +
 * XSalsa20-Poly1305) fra le due persone della conversazione. **Il server non ha
 * nessuna chiave per aprirla, e non e' previsto che ne abbia una.**
 *
 * Il nome della colonna e' rimasto quello per non riscrivere mezza applicazione,
 * ⚠️ ma chi legge il database non deve confondersi: il segnale che distingue una
 * busta da un vecchio messaggio in chiaro e' `envelope_version`, che nei
 * messaggi in chiaro non esisteva. Quelli, comunque, sono stati **cancellati**
 * dalla migrazione di S6 — non ne resta nessuno.
 *
 * ✅ **`Conversation::unreadFor()` continua a funzionare senza modifiche**, e
 * non e' un caso: conta righe, non legge testo. E' la prova che il progetto
 * regge — tutto cio' che il server deve ancora fare, lo fa sui **metadati**.
 */
class Message extends Model
{
    protected $fillable = [
        'conversation_id', 'sender_id', 'body', 'envelope_version', 'nonce',
        'media_id', 'read_at',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'envelope_version' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // 🚨 `last_message_at` si aggiorna qui e non nei chiamanti: e' la colonna
        // su cui si ordina l'elenco delle conversazioni, e un punto del codice
        // che si dimentica di aggiornarla fa sparire un thread dal fondo della
        // lista invece che dalla cima.
        static::created(function (self $messaggio): void {
            $messaggio->conversation?->forceFill([
                'last_message_at' => $messaggio->created_at ?? now(),
            ])->save();
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * La busta e i metadati — niente altro.
     *
     * ⚠️ `body` esce ancora, ma e' base64 di byte cifrati: chi consuma questa
     * risposta **deve** decifrarla, e se dimenticasse di farlo mostrerebbe una
     * riga di caratteri incomprensibili invece di un testo sbagliato. E' il
     * modo giusto di fallire — rumoroso.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'envelope_version' => $this->envelope_version,
            'nonce' => $this->nonce,
            'body' => $this->body,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
