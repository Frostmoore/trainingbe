<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Il filo di conversazione fra un trainer e un iscritto — B8.1.
 */
class Conversation extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'trainer_id', 'member_id', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'trainer_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    /**
     * 🚨 L'unico controllo di accesso alla chat.
     *
     * Non basta essere della stessa palestra: una conversazione e' fra **due**
     * persone. Un altro trainer della stessa palestra non deve leggerla — e in
     * modalita' teams il global scope da solo lo lascerebbe passare.
     */
    public function includes(User|int $utente): bool
    {
        $id = $utente instanceof User ? $utente->getKey() : $utente;

        return $this->trainer_id === $id || $this->member_id === $id;
    }

    /** L'altro partecipante, visto da chi guarda. */
    public function otherParty(User $utente): ?User
    {
        return $utente->getKey() === $this->trainer_id ? $this->member : $this->trainer;
    }

    /**
     * La conversazione fra due persone, creandola se non c'e'.
     *
     * `firstOrCreate` sul vincolo unico: due tocchi ravvicinati su una rete
     * lenta creerebbero altrimenti due thread paralleli, e i due si
     * scriverebbero in stanze diverse senza capire perche'.
     */
    public static function between(User $trainer, User $membro): self
    {
        return static::firstOrCreate(
            ['trainer_id' => $trainer->getKey(), 'member_id' => $membro->getKey()],
            ['tenant_id' => $membro->tenant_id],
        );
    }

    public function scopeForUser(Builder $query, User|int $utente): Builder
    {
        $id = $utente instanceof User ? $utente->getKey() : $utente;

        return $query->where(fn (Builder $q) => $q->where('trainer_id', $id)->orWhere('member_id', $id));
    }

    /**
     * L'ordine dell'elenco: l'ultimo messaggio in cima.
     *
     * 🚨 **Il secondo criterio non e' decorativo.** `last_message_at` ha la
     * precisione del secondo: due messaggi nello stesso secondo — che su una
     * chat attiva capita — lascerebbero l'ordine deciso dal motore, cioe'
     * diverso a ogni richiesta. L'elenco «salterebbe» sotto le dita di chi lo
     * guarda, senza nessun errore da nessuna parte.
     */
    public function scopeRecentFirst(Builder $query): Builder
    {
        return $query->orderByDesc('last_message_at')->orderByDesc('id');
    }

    /** Quanti messaggi non letti ha questa persona in questa conversazione. */
    public function unreadFor(User|int $utente): int
    {
        $id = $utente instanceof User ? $utente->getKey() : $utente;

        return $this->messages()
            ->where('sender_id', '!=', $id)
            ->whereNull('read_at')
            ->count();
    }
}
