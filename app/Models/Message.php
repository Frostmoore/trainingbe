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
 */
class Message extends Model
{
    protected $fillable = ['conversation_id', 'sender_id', 'body', 'media_id', 'read_at'];

    protected function casts(): array
    {
        return ['read_at' => 'datetime'];
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

    /** @return array<string, mixed> */
    public function toApiArray(): array
    {
        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'body' => $this->body,
            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
