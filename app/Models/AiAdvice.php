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
