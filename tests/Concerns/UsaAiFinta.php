<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Services\Ai\AiManager;
use App\Services\Ai\Providers\FakeAiProvider;

/**
 * Sostituisce il fornitore AI con il doppio.
 *
 * 🚨 **Regola non negoziabile: nessun test tocca la rete.** Un test che chiama un
 * modello vero e' lento, costa, e fallisce quando il fornitore ha un
 * disservizio — cioe' proprio quando serve sapere se il *nostro* codice funziona.
 *
 * Passa da `AiManager::extend()` e non da un bind nel container perche' e' la
 * stessa strada che userebbe chiunque volesse aggiungere un fornitore: se un
 * giorno quel meccanismo si rompe, questi test lo scoprono.
 */
trait UsaAiFinta
{
    protected function aiFinta(): FakeAiProvider
    {
        $manager = app(AiManager::class);

        $finto = app(FakeAiProvider::class);

        foreach (['anthropic', 'openai', 'fake'] as $nome) {
            $manager->extend($nome, fn () => $finto);
        }

        return $finto;
    }
}
