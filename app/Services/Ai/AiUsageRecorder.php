<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use Throwable;

/**
 * Scrive quanto e' costata ogni chiamata AI — B6.4.
 *
 * 🚨 **Il costo si congela qui, non si ricalcola dopo.** I listini cambiano: se
 * il totale del mese scorso si ricalcolasse dai prezzi di oggi, cambierebbe da
 * solo a ogni aggiornamento di `config/ai.php` — e un costo storico che si muove
 * non e' un costo storico.
 *
 * Come `AuditLogger`, **non fa mai fallire l'azione che documenta**: se scrivere
 * il contatore esplode, la risposta AI e' comunque arrivata all'utente e deve
 * essergli consegnata. Si registra l'errore e si va avanti.
 */
class AiUsageRecorder
{
    /** Quanto costa scrivere la cache, rispetto all'input: 1,25x (TTL 5 minuti). */
    public const MOLTIPLICATORE_SCRITTURA = 1.25;

    public function record(
        AiCallContext $ctx,
        string $provider,
        string $model,
        int $inputTokens,
        int $outputTokens,
        int $cacheReadTokens,
        int $durationMs,
        bool $success,
        ?string $errorCode = null,
        int $cacheCreationTokens = 0,
    ): ?AiUsageLog {
        try {
            return AiUsageLog::create([
                'tenant_id' => $ctx->tenantId,
                'user_id' => $ctx->userId,
                'provider' => $provider,
                'model' => $model,
                'feature' => $ctx->feature->value,
                'input_tokens' => max(0, $inputTokens),
                'output_tokens' => max(0, $outputTokens),
                'cache_read_tokens' => max(0, $cacheReadTokens),
                'cache_creation_tokens' => max(0, $cacheCreationTokens),
                'cost_millicents' => $this->costMillicents(
                    $model,
                    $inputTokens,
                    $outputTokens,
                    $cacheReadTokens,
                    $cacheCreationTokens,
                ),
                'duration_ms' => max(0, $durationMs),
                'success' => $success,
                'error_code' => $errorCode,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /**
     * Il costo in millesimi di centesimo.
     *
     * I listini in `config/ai.php` sono **per milione di token**, che e' come
     * vengono pubblicati: la divisione sta qui, in un punto solo, cosi' nessun
     * chiamante puo' sbagliarla di un fattore mille.
     *
     * I token letti dalla cache si pagano a parte e molto meno: sono gia'
     * esclusi da `inputTokens` nella risposta di Anthropic, quindi si sommano e
     * non si sottraggono.
     *
     * Un modello senza listino costa **zero** e non lancia: un modello nuovo
     * messo in `.env` deve funzionare subito, e un contatore a zero e' un
     * problema visibile — un'eccezione in mezzo a una chiamata riuscita no.
     */
    public function costMillicents(
        string $model,
        int $in,
        int $out,
        int $cacheRead = 0,
        int $cacheWrite = 0,
    ): int {
        $listino = config("ai.pricing.{$model}");

        if (! is_array($listino)) {
            return 0;
        }

        $costo = ($in * ($listino['in'] ?? 0))
            + ($out * ($listino['out'] ?? 0))
            + ($cacheRead * ($listino['cache_read'] ?? 0))
            /*
             * 🚨 **La scrittura della cache costa 1,25 volte l'input.**
             *
             * Non e' una tariffa a se': e' un moltiplicatore su quella
             * d'ingresso, e per questo si ricava dal listino invece di
             * essere una quarta riga da tenere allineata a mano.
             *
             * ⚠️ Quindi una chiamata che **scrive** la cache costa il 25%
             * IN PIU' di una senza cache. Conviene solo se qualcun altro la
             * legge entro cinque minuti: il pareggio sta intorno a 1,3
             * chiamate per finestra. Su un'installazione con un utente solo
             * la cache **costa**; con traffico vero paga dieci volte tanto.
             */
            + ($cacheWrite * ($listino['in'] ?? 0) * self::MOLTIPLICATORE_SCRITTURA);

        return (int) round($costo / 1_000_000);
    }
}
