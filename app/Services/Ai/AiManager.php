<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiFeature;
use App\Services\Ai\Contracts\AiProvider;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Sceglie il fornitore e il modello, e li tiene in un posto solo.
 *
 * 🚨 **La palestra puo' avere un driver diverso da quello di sistema.**
 * `tenants.ai_driver` vince su `config('ai.default')`: serve a due cose reali —
 * una palestra che porta la propria chiave, e la possibilita' di spostare un
 * cliente su un fornitore alternativo quando il primo ha un disservizio, senza
 * toccare gli altri.
 *
 * I fornitori si registrano con `extend()`, non con un `match` su una stringa:
 * cosi' i test possono sostituirne uno senza toccare la configurazione, ed e'
 * quello che rende possibile la regola «nessun test tocca la rete».
 */
class AiManager
{
    /** @var array<string, \Closure(Container): AiProvider> */
    private array $custom = [];

    /** @var array<string, AiProvider> */
    private array $resolved = [];

    public function __construct(
        private readonly Container $app,
        private readonly TenantContext $tenants,
    ) {}

    /**
     * Registra o sostituisce un fornitore.
     *
     * @param  \Closure(Container): AiProvider  $factory
     */
    public function extend(string $name, \Closure $factory): void
    {
        $this->custom[$name] = $factory;
        unset($this->resolved[$name]);
    }

    /** Il driver richiesto, o quello che vale per la palestra corrente. */
    public function driver(?string $name = null): AiProvider
    {
        $name ??= $this->driverName();

        return $this->resolved[$name] ??= $this->build($name);
    }

    public function for(AiFeature $feature): AiProvider
    {
        return $this->driver();
    }

    /**
     * Il nome del driver che vale adesso.
     *
     * Ordine: preferenza della palestra, poi default di sistema. Non c'e' una
     * terza voce: un driver sconosciuto deve dare errore subito e non ricadere
     * in silenzio su un altro fornitore, perche' significherebbe mandare i dati
     * di una palestra a un servizio che nessuno ha scelto.
     */
    public function driverName(): string
    {
        $daTenant = $this->tenants->get()?->ai_driver;

        return is_string($daTenant) && $daTenant !== ''
            ? $daTenant
            : (string) config('ai.default');
    }

    /**
     * Il modello da usare per questa funzione.
     *
     * @throws InvalidArgumentException se la configurazione non lo dichiara —
     *         meglio un errore chiaro che una chiamata a un modello vuoto, che
     *         il fornitore rifiuta con un messaggio che non dice niente.
     */
    public function modelFor(AiFeature $feature, ?string $driver = null): string
    {
        $driver ??= $this->driverName();
        $modello = config("ai.models.{$driver}.{$feature->value}");

        if (! is_string($modello) || $modello === '') {
            throw new InvalidArgumentException(
                "Nessun modello configurato per «{$feature->value}» sul driver «{$driver}»: ".
                "aggiungere ai.models.{$driver}.{$feature->value}."
            );
        }

        return $modello;
    }

    /** Il modello di riserva per l'escalation dell'import PDF (B7.5). */
    public function escalationModel(): string
    {
        return (string) config('ai.pdf.escalation_model');
    }

    private function build(string $name): AiProvider
    {
        if (isset($this->custom[$name])) {
            return ($this->custom[$name])($this->app);
        }

        return match ($name) {
            'anthropic' => $this->app->make(Providers\AnthropicProvider::class),
            'openai' => $this->app->make(Providers\OpenAiProvider::class),
            // Il finto e' selezionabile da `.env` (`AI_DRIVER=fake`) perche'
            // serve anche in sviluppo, quando non si ha una chiave, e non solo
            // ai test.
            'fake' => $this->app->make(Providers\FakeAiProvider::class),
            default => throw new InvalidArgumentException("Driver AI sconosciuto: «{$name}»."),
        };
    }
}
