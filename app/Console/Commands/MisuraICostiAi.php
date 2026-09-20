<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AiFeature;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * 💸 **Quanto costa davvero una chiamata** — 20/09/2026.
 *
 * 📌 Il committente: *«Un test con tutte le chiamate che verranno mai fatte
 * dall'app per capire davvero i miei costi di inferenza, così vediamo davvero i
 * miei costi e possiamo aggiustare il listino in tempo reale»*.
 *
 * ── 🚨 Perché è un comando e non un foglio di calcolo ──────────────────────
 *
 * Perché `memory/STIMA-COSTI-AI.md` lo dice da sé: **un documento di costi
 * invecchia in silenzio**. Il 13/08 si è scoperto che `FOOD_SYSTEM` era
 * cresciuto del **329%** tre giorni dopo l'ultima misurazione, e per un mese il
 * listino è stato deciso su numeri sbagliati del doppio.
 *
 * ⚠️ Quelle misure erano **contate a mano, carattere per carattere**: una stima
 * dei token, non i token. Questo comando invece **chiama il modello vero** e
 * legge i token che risponde lui, in `ai_usage_logs`.
 *
 * ── 💰 Costa soldi, e per questo non parte da solo ────────────────────────
 *
 * 🚨 Senza `--davvero` non chiama niente: stampa cosa farebbe e quanto
 * costerebbe, sul listino di `config/ai.php`. ⛔ Un comando che spende senza
 * chiederlo è un comando che qualcuno lancerà in un ciclo.
 *
 * ── ⚠️ La cache cambia il conto, ed è voluto ──────────────────────────────
 *
 * Il prompt di sistema viaggia con `cacheControl: ephemeral`: la **prima**
 * chiamata lo paga pieno, le successive lo pagano un decimo. 💡 Le due cose si
 * leggono separate qui sotto, perché il costo di un utente vero somiglia alla
 * seconda, non alla prima.
 */
class MisuraICostiAi extends Command
{
    protected $signature = 'ai:misura-i-costi
        {--quante=10 : quante chiamate per tipo}
        {--davvero : chiama il modello VERO (costa soldi)}
        {--foto= : cartella da cui pescare le fotografie}';

    protected $description = 'Misura il costo reale delle chiamate AI: automatiche, cibo da testo, cibo da foto';

    /** Le frasi del cibo scritto a mano: corte, storte e realistiche. */
    private const FRASI = [
        'due uova strapazzate, una fetta di pane integrale e mezzo avocado',
        'un piatto di pasta al pomodoro, circa 100 grammi a crudo, con un cucchiaio di parmigiano',
        'petto di pollo alla piastra 200g, insalata mista con un filo di olio',
        'cappuccino e cornetto alla crema al bar',
        'una banana e una manciata di mandorle',
        'pizza margherita intera',
        '150 grammi di salmone al forno con patate, direi 200 grammi di patate',
        'yogurt greco bianco con miele e due cucchiai di granola',
        'insalata di riso con tonno, mais e olive, un piatto abbondante',
        'due fette biscottate con marmellata e un caffè macchiato',
        'hamburger con patatine fritte medie e una coca cola',
        'minestrone di verdure con un cucchiaio di olio e crostini',
    ];

    /**
     * 🚨 **Il contesto più grande che il consiglio possa ricevere davvero.**
     *
     * ⚠️ Non «il più grande possibile»: **il più grande sensato**. Sei pasti
     * scritti, sonno, bruciate, obiettivi, l'allenamento del giorno e la
     * settimana. È la giornata di chi usa l'app sul serio — cioè proprio
     * l'utente che costa di più, che è quello da misurare.
     */
    private function contestoDelConsiglio(int $giro): array
    {
        $pasti = [
            ['meal' => 'breakfast', 'at' => '07:20', 'kcal' => 365, 'items' => [
                ['name' => 'Porridge di avena e mirtilli', 'qty' => '80 g', 'kcal' => 310],
                ['name' => 'Caffè con latte scremato', 'qty' => '150 ml', 'kcal' => 55],
            ]],
            ['meal' => 'morning_snack', 'at' => '10:40', 'kcal' => 216, 'items' => [
                ['name' => 'Yogurt greco 0%', 'qty' => '170 g', 'kcal' => 100],
                ['name' => 'Mandorle', 'qty' => '20 g', 'kcal' => 116],
            ]],
            ['meal' => 'lunch', 'at' => '13:10', 'kcal' => 659, 'items' => [
                ['name' => 'Petto di pollo alla piastra', 'qty' => '180 g', 'kcal' => 297],
                ['name' => 'Riso basmati', 'qty' => '90 g', 'kcal' => 320],
                ['name' => 'Zucchine grigliate', 'qty' => '200 g', 'kcal' => 42],
            ]],
            ['meal' => 'afternoon_snack', 'at' => '16:45', 'kcal' => 160, 'items' => [
                ['name' => 'Mela', 'qty' => '180 g', 'kcal' => 94],
                ['name' => 'Fette di tacchino', 'qty' => '60 g', 'kcal' => 66],
            ]],
        ];

        return [
            'date' => Carbon::now()->subDays($giro)->toDateString(),
            'time' => '18:40',
            'day_progress_pct' => 78,
            'goal' => 'ricomposizione: perdere grasso mantenendo la massa',
            'totals' => ['kcal' => 1400, 'protein_g' => 116, 'carbs_g' => 171, 'fat_g' => 27],
            'targets' => ['kcal' => 2715, 'protein_g' => 170, 'carbs_g' => 326, 'fat_g' => 81],
            'burned' => ['total' => 2162, 'daily_life' => 1750, 'workout' => 412, 'steps' => 8432],
            'meals_logged' => count($pasti),
            'meals' => $pasti,
            'weight' => ['kg' => 78.4, 'trend_14d_kg' => -0.7],
            'recovery' => ['hours' => 7.4, 'quality' => 'buona', 'hrv' => 58, 'resting_hr' => 52, 'readiness' => 36],
            'workout' => [
                'name' => 'Full body A',
                'when' => 'oggi 18:30',
                'exercises' => [
                    ['name' => 'Panca piana', 'sets' => 4, 'reps' => 8, 'kg' => 72.5],
                    ['name' => 'Rematore con bilanciere', 'sets' => 4, 'reps' => 10, 'kg' => 60],
                    ['name' => 'Squat', 'sets' => 4, 'reps' => 6, 'kg' => 95],
                    ['name' => 'Military press', 'sets' => 3, 'reps' => 10, 'kg' => 40],
                    ['name' => 'Curl con manubri', 'sets' => 3, 'reps' => 12, 'kg' => 14],
                ],
            ],
            'week' => [
                'sessions' => 3,
                'load_pct' => 90,
                'streak_days' => 12,
            ],
        ];
    }

    /**
     * 🚨 **L'altra chiamata automatica**: l'analisi della scheda, che parte da
     * sola quando si chiude un allenamento.
     *
     * ⚠️ Il contesto vero è **grosso**: fino a dodici sedute per esercizio, coi
     * primati e i cambi fatti alla scheda. È il tetto che la validazione
     * ammette (`esercizi.*.sedute` max 12), e misurare sotto quel tetto
     * darebbe un costo più basso di quello che si paga davvero.
     */
    private function contestoDelProgresso(int $giro): array
    {
        $esercizi = [
            ['Panca piana', 72.5, 8],
            ['Rematore con bilanciere', 60.0, 10],
            ['Squat', 95.0, 6],
            ['Military press', 40.0, 10],
            ['Curl con manubri', 14.0, 12],
            ['Stacco rumeno', 80.0, 8],
        ];

        $fuori = [];

        foreach ($esercizi as $i => [$nome, $carico, $reps]) {
            $sedute = [];

            for ($s = 11; $s >= 0; $s--) {
                $sedute[] = [
                    'data' => Carbon::now()->subDays($s * 3 + $giro)->toDateString(),
                    'carico' => $carico - ($s * 1.25),
                    'ripetizioni' => $reps - ($s % 3),
                ];
            }

            $fuori[] = [
                'id' => $i + 1,
                'nome' => $nome,
                'sedute' => $sedute,
                'primati' => [
                    'sedute_totali' => 34 + $i,
                    'dal' => Carbon::now()->subMonths(5)->toDateString(),
                    'carico_massimo' => $carico,
                    'quando_il_massimo' => Carbon::now()->subDays(3)->toDateString(),
                    'sedute_allo_stesso_carico' => 3,
                ],
                'cambi_alla_scheda' => [
                    ['cosa' => 'carico', 'prima' => (string) ($carico - 2.5), 'dopo' => (string) $carico],
                    ['cosa' => 'ripetizioni', 'prima' => (string) ($reps - 2), 'dopo' => (string) $reps],
                ],
            ];
        }

        $allenamenti = [];

        for ($s = 11; $s >= 0; $s--) {
            $allenamenti[] = [
                'data' => Carbon::now()->subDays($s * 3)->toDateString(),
                'kcal' => 380 + ($s * 7) % 90,
                'fonte' => 'stima',
            ];
        }

        return ['scheda' => 'Full body A', 'esercizi' => $fuori, 'allenamenti' => $allenamenti];
    }

    public function handle(AiManager $ai): int
    {
        $quante = max(1, (int) $this->option('quante'));
        $davvero = (bool) $this->option('davvero');

        /*
         * 🚨 **L'utente di prova, non il primo che capita.** La riga in
         * `ai_usage_logs` porta il suo id: appiccicare trenta chiamate di
         * misurazione all'account di qualcuno vuol dire sporcargli i consumi.
         */
        $utente = User::withoutGlobalScopes()->where('email', 'marco@esempio.test')->first()
            ?? User::withoutGlobalScopes()->orderBy('id')->first();

        if ($utente === null) {
            $this->error('Serve almeno un utente: questo comando gira in locale, su dati finti.');

            return self::FAILURE;
        }

        $foto = $this->fotografie();

        $this->riga('driver', $ai->driverName());
        $this->riga('utente', (string) $utente->email);
        $this->riga('chiamate', (string) ($quante * 3));
        $this->riga('fotografie trovate', (string) count($foto));

        if (! $davvero) {
            $this->newLine();
            $this->warn('⛔ Prova a vuoto: non ho chiamato niente. Rilancia con --davvero per spendere sul serio.');

            return self::SUCCESS;
        }

        if ($foto === []) {
            $this->error('Senza fotografie non posso misurare le chiamate con immagine.');

            return self::FAILURE;
        }

        $da = Carbon::now();

        $this->misura('automatiche', $quante, function (int $i) use ($ai, $utente): void {
            $ai->for(AiFeature::DailyAdvice)->dailyAdvice(
                $this->contestoDelConsiglio($i),
                AiCallContext::for($utente, AiFeature::DailyAdvice),
            );
        });

        $this->misura('automatiche (analisi scheda)', $quante, function (int $i) use ($ai, $utente): void {
            $ai->for(AiFeature::PlanProgress)->progressoScheda(
                $this->contestoDelProgresso($i),
                AiCallContext::for($utente, AiFeature::PlanProgress),
            );
        });

        $this->misura('cibo da testo', $quante, function (int $i) use ($ai, $utente): void {
            $ai->for(AiFeature::FoodText)->foodFromText(
                self::FRASI[$i % count(self::FRASI)],
                AiCallContext::for($utente, AiFeature::FoodText),
            );
        });

        $this->misura('cibo da foto', $quante, function (int $i) use ($ai, $utente, $foto): void {
            $scelta = $foto[$i % count($foto)];

            $ai->for(AiFeature::FoodPhoto)->foodFromImage(
                $scelta,
                'image/jpeg',
                AiCallContext::for($utente, AiFeature::FoodPhoto),
            );
        });

        $this->conti($da);

        return self::SUCCESS;
    }

    /** @return list<string> */
    private function fotografie(): array
    {
        $cartella = (string) ($this->option('foto') ?: base_path('../memory/prove-k7'));

        $trovate = glob(rtrim($cartella, '/\\').'/*.jpg') ?: [];

        sort($trovate);

        return $trovate;
    }

    private function misura(string $nome, int $quante, callable $chiamata): void
    {
        $this->newLine();
        $this->line("── {$nome} ".str_repeat('─', max(2, 40 - mb_strlen($nome))));

        $barra = $this->output->createProgressBar($quante);
        $barra->start();

        for ($i = 0; $i < $quante; $i++) {
            try {
                $chiamata($i);
            } catch (Throwable $e) {
                /*
                 * ⚠️ **Una chiamata che fallisce non ferma la misurazione.** Il
                 * fornitore ogni tanto risponde 529, e perdere venti chiamate
                 * già pagate per la ventunesima sarebbe il modo peggiore di
                 * spendere. 💡 Il conto finale guarda `ai_usage_logs`, che le
                 * fallite le segna come tali.
                 */
                $this->newLine();
                $this->warn("  ⚠️ {$nome} #{$i}: ".mb_substr($e->getMessage(), 0, 120));
            }

            $barra->advance();
        }

        $barra->finish();
        $this->newLine();
    }

    /**
     * I conti, e sono la ragione per cui questo comando esiste.
     *
     * 🚨 **Si leggono da `ai_usage_logs`**, non dalle risposte: è la stessa
     * tabella su cui si fattura, quindi se qui i numeri sono giusti lo sono
     * anche là — e se sono sbagliati, sono sbagliati in tutti e due i posti
     * nello stesso modo.
     */
    private function conti(Carbon $da): void
    {
        $righe = AiUsageLog::withoutGlobalScopes()
            ->where('created_at', '>=', $da)
            ->get();

        if ($righe->isEmpty()) {
            $this->error('Nessuna riga in ai_usage_logs: non è stata misurata nessuna chiamata.');

            return;
        }

        $this->newLine();
        $this->info('══ Quanto è costata ogni chiamata ══');

        $perFunzione = [];

        foreach ($righe->groupBy('feature') as $funzione => $gruppo) {
            $riuscite = $gruppo->where('success', true);
            $quante = max(1, $riuscite->count());

            /*
             * 💡 **Freddo e caldo si separano guardando la cache**, non
             * l'ordine: una chiamata che ha letto dalla cache è calda,
             * qualunque posto occupi nella fila. ⚠️ Con l'ordine si
             * sbaglierebbe la prima volta che il fornitore scade la cache a
             * metà misurazione — e sarebbe un errore invisibile.
             */
            $fredde = $riuscite->where('cache_read_tokens', 0);
            $calde = $riuscite->where('cache_read_tokens', '>', 0);

            $perFunzione[$funzione] = [
                'quante' => $riuscite->count(),
                'modello' => (string) $riuscite->first()?->model,
                'in' => $riuscite->avg('input_tokens'),
                'out' => $riuscite->avg('output_tokens'),
                'cache' => $riuscite->avg('cache_read_tokens'),
                'creazione' => $riuscite->avg('cache_creation_tokens'),
                'freddo' => $fredde->avg('cost_millicents') ?? $riuscite->avg('cost_millicents'),
                'caldo' => $calde->avg('cost_millicents') ?? $riuscite->avg('cost_millicents'),
                'medio' => $riuscite->avg('cost_millicents'),
            ];
        }

        $tabella = [];

        foreach ($perFunzione as $funzione => $d) {
            $tabella[] = [
                $funzione,
                $d['modello'],
                $d['quante'],
                round((float) $d['in']),
                round((float) $d['out']),
                round((float) $d['creazione']),
                round((float) $d['cache']),
                $this->dollari($d['freddo']),
                $this->dollari($d['caldo']),
            ];
        }

        /*
         * 🚨 **«freddo» e «caldo» non sono una sfumatura: sono due prezzi.**
         *
         * Il prompt di sistema viaggia con `cacheControl: ephemeral`. La prima
         * chiamata lo **scrive** in cache e lo paga 1,25 volte l'input; le
         * successive lo **leggono** e lo pagano un decimo. ⚠️ La cache dura
         * cinque minuti: quale dei due prezzi si paga davvero dipende dal
         * traffico, non dal codice. Con pochi utenti sparsi nella giornata si
         * paga quasi sempre il **freddo**; con traffico continuo il **caldo**.
         */
        $this->table(
            ['funzione', 'modello', 'n', 'in', 'out', 'cache-scritta', 'cache-letta', '$ freddo', '$ caldo'],
            $tabella,
        );

        $this->newLine();
        $this->info('══ Il conto che serve al listino ══');

        foreach (['freddo' => 'cache fredda (traffico rado)', 'caldo' => 'cache calda (traffico continuo)'] as $come => $etichetta) {
            $consiglio = (float) ($perFunzione[AiFeature::DailyAdvice->value][$come] ?? 0);
            $testo = (float) ($perFunzione[AiFeature::FoodText->value][$come] ?? 0);
            $foto = (float) ($perFunzione[AiFeature::FoodPhoto->value][$come] ?? 0);

            $this->newLine();
            $this->line("── {$etichetta}");

            /*
             * 🚨 **Tre consigli al giorno è il TETTO, non la media.** Le fasce
             * di `FasciaDelConsiglio` sono tre (9:00, 14:00, 22:00) e il
             * secondo cancello — «senza notizie non si paga» — ne toglie
             * ancora: chi apre l'app una volta sola ne riceve uno.
             *
             * 💡 Per questo si stampano tutti e due: il tetto serve a non
             * sbagliare il listino, la media a capire quanto si incassa.
             */
            /*
             * 🚨 **Il giorno di un abbonato è consigli + analisi.** Sono le due
             * sole chiamate che partono da sole: il consiglio fino a tre volte,
             * l'analisi una volta per allenamento — e chi si allena tre volte a
             * settimana fa ~0,43 allenamenti al giorno.
             */
            $analisi = (float) ($perFunzione[AiFeature::PlanProgress->value][$come] ?? 0);

            $pieno = $consiglio * 3 + $analisi;
            $tipico = $consiglio * 1.5 + $analisi * 0.43;

            $this->table(['cosa', 'quante', '$', '€ (1 $ = 0,92 €)'], [
                ['giorno pieno, per utente', '3 consigli + 1 analisi', $this->dollari($pieno), $this->euro($pieno)],
                ['giorno tipico, per utente', '1,5 consigli + 0,43 analisi', $this->dollari($tipico), $this->euro($tipico)],
                ['mese pieno, per utente', '×30', $this->dollari($pieno * 30), $this->euro($pieno * 30)],
                ['mese tipico, per utente', '×30', $this->dollari($tipico * 30), $this->euro($tipico * 30)],
                ['un gettone speso in cibo-testo', '1 gettone', $this->dollari($testo), $this->euro($testo)],
                ['un gettone speso in cibo-foto', '10 gettoni', $this->dollari($foto / 10), $this->euro($foto / 10)],
            ]);
        }

        $this->newLine();
        $this->line('💡 Il prezzo di un gettone lo si legge così: quanto costa la chiamata, diviso quanti gettoni costa.');
        $this->line('⚠️ Le fotografie di prova sono pagine di un piano alimentare, non piatti: i token in ingresso sono quelli veri, quelli in uscita possono essere un po\' più alti del normale.');
    }

    private function dollari(float|int|null $millicent): string
    {
        return '$ '.number_format(((float) $millicent) / 100_000, 5, ',', '.');
    }

    private function euro(float|int|null $millicent): string
    {
        return '€ '.number_format(((float) $millicent) / 100_000 * 0.92, 5, ',', '.');
    }

    private function riga(string $etichetta, string $valore): void
    {
        $this->line(sprintf('  %-22s %s', $etichetta, $valore));
    }
}
