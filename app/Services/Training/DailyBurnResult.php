<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Enums\KcalSource;

/**
 * Le calorie bruciate in un giorno, con l'indicazione di **da dove vengono**.
 *
 * 🚨 Il secondo campo non e' un di piu': senza, chi riceve il numero non sa se
 * puo' sovrascriverlo. Nell'app storica dashboard, calendario e diario
 * mostravano tre numeri diversi proprio perche' ognuno riceveva un intero nudo e
 * decideva da se' se ricalcolarlo.
 */
final readonly class DailyBurnResult
{
    public function __construct(
        public int $kcal,
        public KcalSource $source,
        public int $sessions = 0,
    ) {}

    public function isManual(): bool
    {
        return $this->source === KcalSource::Manual;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kcal' => $this->kcal,
            'source' => $this->source->value,
            'sessions' => $this->sessions,
        ];
    }
}
