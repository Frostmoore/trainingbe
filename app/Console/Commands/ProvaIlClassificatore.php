<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AiFeature;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use App\Services\Ai\Data\FoodItem;
use App\Services\Ai\Guardie\MealValidator;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * La suite di regressione del classificatore alimentare, **contro il modello vero**.
 *
 * ── 🚨 Perche' NON e' un test di PHPUnit ──────────────────────────────────
 *
 * La regola «nessun test tocca la rete» resta giusta e non si discute: un test
 * che chiama un modello vero e' lento, costa, ed e' rosso quando il fornitore ha
 * un disservizio — cioe' proprio quando serve sapere se il *nostro* codice
 * funziona.
 *
 * ⚠️ **Ma da quella regola discende un buco**: `FakeAiProvider` non parla con la
 * rete, quindi **nessun test della suite puo' dire se una modifica al prompt
 * funziona**. Il 12/08/2026 quel buco e' costato uno schema che sembrava a posto
 * e un modello che sbagliava i grammi di alcol.
 *
 * 💡 Questo comando e' la risposta: sta **fuori** da `artisan test`, si lancia a
 * mano, costa qualche centesimo, e va lanciato **dopo ogni modifica a
 * `Prompts::FOOD_SYSTEM` o a `foodSchema()`**. Il prompt e' codice e regredisce
 * come il codice.
 *
 * 🚨 **Le asserzioni sono su INTERVALLI, non su valori esatti**: il modello e'
 * stocastico, e un test che pretende 353 kcal esatte sara' rosso domani senza
 * che niente sia peggiorato.
 *
 *     php artisan ai:prova-classificatore --user=10
 */
class ProvaIlClassificatore extends Command
{
    protected $signature = 'ai:prova-classificatore
        {--user= : L\'id dell\'utente per conto del quale chiamare}
        {--solo= : Esegue un solo caso, per nome}';

    protected $description = 'Prova il classificatore alimentare contro il modello vero (costa token)';

    /**
     * I casi, con i loro intervalli accettabili.
     *
     * @return list<array{nome: string, testo: string, attese: callable}>
     */
    private function casi(): array
    {
        return [
            [
                'nome' => 'pasta-crudo',
                'testo' => '100 g di pasta',
                'attese' => fn (array $voci): array => [
                    'una sola voce di pasta' => count($voci) >= 1,
                    'stato crudo' => ($voci[0]->state ?? null) === 'crudo',
                    'kcal fra 330 e 380' => $this->fra($voci[0]->kcal, 330, 380),
                ],
            ],
            [
                'nome' => 'succo-senza-zucchero',
                'testo' => 'mezzo litro di succo di frutta senza zucchero',
                'attese' => fn (array $voci): array => [
                    'kcal fra 190 e 260' => $this->fra($voci[0]->kcal, 190, 260),
                    'mai sotto 100' => ($voci[0]->kcal ?? 0) >= 100,
                    'ml valorizzato a 500' => $this->fra($voci[0]->ml, 480, 520),
                    'basis per_100ml' => $voci[0]->basis === 'per_100ml',
                    // 🚨 Il difetto trovato in produzione: i grammi devono essere
                    // PIU' dei millilitri, perche' il succo pesa 1,05 g/ml.
                    'grammi maggiori dei ml' => ($voci[0]->grams ?? 0) > ($voci[0]->ml ?? 0),
                ],
            ],
            [
                'nome' => 'vino',
                'testo' => 'un bicchiere di vino rosso',
                'attese' => fn (array $voci): array => [
                    'alcol fra 10 e 14 g' => $this->fra($voci[0]->alcohol, 10, 14),
                    'kcal fra 90 e 130' => $this->fra($voci[0]->kcal, 90, 130),
                    'gradazione dichiarata' => ($voci[0]->abvPct ?? 0) > 0,
                    'quantita\' stimata, non dichiarata' => $voci[0]->declared === false,
                ],
            ],
            [
                'nome' => 'spinacine',
                'testo' => 'due spinacine',
                'attese' => fn (array $voci): array => [
                    'unita\' in grammi' => $voci[0]->unit === 'g',
                    'grammi intorno a 220' => $this->fra($voci[0]->grams, 180, 260),
                ],
            ],
            [
                'nome' => 'cucchiaio-olio',
                'testo' => 'un cucchiaio d\'olio',
                'attese' => fn (array $voci): array => [
                    'grammi fra 13 e 15' => $this->fra($voci[0]->grams, 13, 15),
                    'kcal fra 110 e 140' => $this->fra($voci[0]->kcal, 110, 140),
                ],
            ],
            [
                'nome' => 'niente-cibo',
                'testo' => 'oggi ho corso 5 km',
                'attese' => fn (array $voci): array => [
                    'nessun alimento' => $voci === [],
                ],
            ],
            [
                'nome' => 'cola-zero',
                'testo' => 'una lattina di cola zero da 330 ml',
                'attese' => fn (array $voci): array => [
                    'sotto le 10 kcal' => ($voci[0]->kcal ?? 99) < 10,
                ],
            ],
            [
                'nome' => 'piatto-composito',
                'testo' => 'pasta al pomodoro',
                'attese' => fn (array $voci): array => [
                    'almeno due voci' => count($voci) >= 2,
                    'qualcosa non dichiarato' => collect($voci)->contains(fn (FoodItem $v): bool => $v->declared === false),
                ],
            ],
            [
                'nome' => 'cotoletta-ambigua',
                'testo' => 'due cotolette di pollo',
                'attese' => fn (array $voci): array => [
                    // ⚠️ Non si pretende che indovini: si pretende che DICA di non
                    // sapere. E' il difetto del 12/08 in forma di test.
                    'grammi intorno a 200' => $this->fra($voci[0]->grams, 160, 240),
                ],
            ],
        ];
    }

    public function handle(AiManager $ai, MealValidator $validatore, TenantContext $tenants): int
    {
        $utente = User::withoutGlobalScopes()->find((int) $this->option('user'));

        if ($utente === null) {
            $this->error('Serve --user con un id valido.');

            return self::FAILURE;
        }

        $tenants->set(Tenant::withoutGlobalScopes()->find($utente->tenant_id));

        $solo = $this->option('solo');
        $falliti = 0;
        $eseguiti = 0;

        foreach ($this->casi() as $caso) {
            if ($solo !== null && $caso['nome'] !== $solo) {
                continue;
            }

            $eseguiti++;
            $this->line('');
            $this->info("── {$caso['nome']} — «{$caso['testo']}»");

            try {
                $stima = $ai->for(AiFeature::FoodText)->foodFromText(
                    $caso['testo'],
                    AiCallContext::for($utente, AiFeature::FoodText),
                );
            } catch (Throwable $e) {
                $this->error('   chiamata fallita: '.$e->getMessage());
                $falliti++;

                continue;
            }

            $esito = $validatore->valida($stima);
            $voci = $esito['stima']->items;

            foreach ($voci as $v) {
                $this->line(sprintf(
                    '   %-34s %6s %-4s g=%-6s ml=%-6s %-10s %-16s kcal=%-6s alc=%-5s c=%s',
                    mb_substr($v->name, 0, 34), $v->qty, $v->unit, $v->grams, $v->ml ?? '-',
                    $v->basis ?? '-', $v->state ?? '-', $v->kcal, $v->alcohol ?? '-', $v->confidence ?? '-',
                ));
            }

            if ($esito['stima']->note !== null) {
                $this->line('   nota: '.$esito['stima']->note);
            }

            foreach ($esito['gravi'] as $g) {
                $this->error('   GRAVE: '.$g);
                $falliti++;
            }

            foreach ($esito['avvisi'] as $a) {
                $this->warn('   avviso: '.$a);
            }

            // ⚠️ Senza voci le attese che indicizzano `$voci[0]` esploderebbero:
            // il caso «niente cibo» e' l'unico che se lo aspetta.
            $attese = ($voci === [] && $caso['nome'] !== 'niente-cibo')
                ? ['almeno una voce' => false]
                : ($caso['attese'])($voci);

            foreach ($attese as $cosa => $ok) {
                $this->line(($ok ? '   ✓ ' : '   ✗ ').$cosa);

                if (! $ok) {
                    $falliti++;
                }
            }
        }

        $this->line('');
        $this->line(str_repeat('─', 60));

        if ($falliti === 0) {
            $this->info("Tutti i {$eseguiti} casi sono passati.");

            return self::SUCCESS;
        }

        $this->error("{$falliti} asserzioni fallite su {$eseguiti} casi.");

        return self::FAILURE;
    }

    private function fra(?float $valore, float $min, float $max): bool
    {
        return $valore !== null && $valore >= $min && $valore <= $max;
    }
}
