<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tempo\GiornoLocale;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un consiglio gia' generato, tenuto da parte.
 *
 * Vedi la migration per il perche' dell'hash sul contesto: e' il meccanismo che
 * rende la rigenerazione automatica senza nessun cron.
 */
class AiAdvice extends Model
{
    use BelongsToTenant;

    /**
     * ⚠️ Esplicita, e non per gusto: «advice» in inglese e' un nome
     * incontabile, quindi il pluralizzatore di Laravel lascia `ai_advice`
     * mentre la tabella si chiama `ai_advices`. Senza questa riga il modello
     * cerca una tabella che non esiste, e l'errore arriva solo a runtime, la
     * prima volta che qualcuno chiede il consiglio del giorno.
     */
    protected $table = 'ai_advices';

    protected $fillable = [
        'tenant_id', 'user_id', 'date', 'kind', 'context_hash', 'body', 'model',
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * L'hash di un contesto.
     *
     * 🚨 `ksort` prima di serializzare: senza, due contesti identici con le
     * chiavi in ordine diverso darebbero hash diversi, e la cache non
     * funzionerebbe mai — un guasto che non da' errori, solo una bolletta.
     *
     * @param  array<string, mixed>  $context
     */
    public static function hashOf(array $context): string
    {
        ksort($context);

        return md5((string) json_encode($context));
    }

    /**
     * 🚨 **Butta i consigli dei giorni passati.** — 16/08/2026
     *
     * ── Perche' esiste, e perche' non c'era ───────────────────────────────
     *
     * Questa tabella e' una **cache**, non uno storico: `cached()` filtra
     * `whereDate('date', $oggi)` e non guarda mai indietro. ⚠️ Ma nessuno l'ha
     * mai potata, quindi ogni consiglio mai generato e' rimasto li' — righe che
     * **nessun codice leggera' mai piu'**.
     *
     * 🚨 **E non e' peso morto qualunque: e' il testo piu' intimo che abbiamo
     * sul server.** Un consiglio dice *«hai mangiato 1.400 kcal, ti mancano
     * proteine, ieri non ti sei allenato»* — un racconto sulla salute di una
     * persona, in chiaro, conservato per sempre senza che serva a niente.
     *
     * 💡 Misurato in `I0.1`: a tre consigli al giorno sono **5.475 righe e
     * 2,4 MB** in cinque anni — piu' del diario alimentare e del sonno messi
     * insieme.
     *
     * ── Perche' qui e non in un comando schedulato ────────────────────────
     *
     * ⚠️ Un cron che pota e' un cron che un giorno non gira, e nessuno se ne
     * accorge finche' la tabella non e' gonfia. Potare **nel momento in cui si
     * scrive** non ha quel problema: se si scrive, si pota.
     *
     * 🎯 E l'ordine e' quello giusto — si scrive prima e si pota dopo: al
     * peggio resta una riga in piu' per un giorno, invece di perdere il
     * consiglio appena generato.
     */
    public static function pota(int $userId, GiornoLocale $oggi): int
    {
        return static::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereDate('date', '<', $oggi->etichetta)
            ->delete();
    }

    /**
     * 🚨 **Il giorno fa parte della chiave di cache**, ed e' per questo che il
     * consiglio si rigenera a mezzanotte senza nessun cron. ⚠️ Deve pero'
     * essere la **mezzanotte di chi legge**: con il confine in UTC, a Roma il
     * consiglio cambiava alle 02:00 d'estate.
     *
     * @param  array<string, mixed>  $context
     */
    public static function cached(User $user, GiornoLocale $giorno, string $kind, array $context): ?self
    {
        return static::query()
            ->where('user_id', $user->getKey())
            ->whereDate('date', $giorno->etichetta)
            ->where('kind', $kind)
            ->where('context_hash', self::hashOf($context))
            ->first();
    }
}
