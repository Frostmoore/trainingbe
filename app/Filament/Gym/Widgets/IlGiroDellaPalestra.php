<?php

declare(strict_types=1);

namespace App\Filament\Gym\Widgets;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Services\Reporting\IContiDellaPalestra;
use App\Support\Tenancy\TenantContext;
use Filament\Widgets\ChartWidget;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Carbon;

/**
 * 📈 **Come va la palestra, mese per mese** — 23/09/2026, fase F5.2.
 *
 * 📌 Il committente: *«grafici spesa/guadagno, allievi nel tempo, trainer nel
 * tempo»*.
 *
 * ══ 🚨 DUE GRAFICI IN UNO, CON UNA TENDINA ══════════════════════════════
 *
 * ⛔ Non si affiancano euro e persone sullo stesso asse: «1.200» e «14» in
 * verticale insieme schiacciano la seconda curva contro lo zero, e il grafico
 * diventa una linea piatta accanto a una montagna. 💡 Una tendina sceglie cosa
 * guardare, e l'asse significa sempre una cosa sola.
 *
 * ══ ⚠️ IL GUADAGNO È UNA STIMA, E IL RIQUADRO LO DICE ═══════════════════
 *
 * 🚨 I soldi che la palestra incassa **non passano da noi**: non sappiamo chi
 * ha pagato e chi era in ferie. Quella curva è *iscritti × la quota che avete
 * scritto voi* — ed è utile solo se quella quota è vera (✅ **D9**: la scrive
 * la palestra, noi non la verifichiamo).
 *
 * ⛔ **La spesa invece è esatta**: sono i pagamenti che ci hanno fatto, dal
 * registro degli incassi. ⚠️ Che però esiste dal 22/09/2026: prima di quella
 * data la curva è a zero perché non c'era nessuno a scriverla, non perché non
 * pagassero.
 */
class IlGiroDellaPalestra extends ChartWidget
{
    protected int|string|array $columnSpan = 'full';

    protected static ?int $sort = 1;

    public static function canView(): bool
    {
        /*
         * 🚨 **Solo chi comanda in questo spazio.** Ci sono dentro i conti: il
         * margine del datore di lavoro non è un dato che riguarda un trainer
         * dipendente.
         *
         * ══ 🆕 ANCHE IL TRAINER INDIPENDENTE — F10.7, 24/09/2026 ════════════
         *
         * 📌 Il committente: *«su quella di giulia ci devono essere le stesse
         * cards che riguardano i guadagni e le spese che avrebbe una palestra
         * (di base è solo una palestra con un solo trainer)»*.
         *
         * 💡 `comandaQui()` dice esattamente quello: **lo spazio è tuo**. ⛔ Per
         * un trainer dipendente resta no, com'era prima.
         */
        return auth()->user()?->comandaQui() === true;
    }

    protected function getType(): string
    {
        return $this->cosa() === 'soldi' ? 'bar' : 'line';
    }

    /** @return array<string, string> */
    protected function getFilters(): ?array
    {
        return [
            'soldi' => 'Spesa e guadagno (€)',
            'persone' => 'Iscritti e trainer, nel tempo',
        ];
    }

    public function getHeading(): string|Htmlable|null
    {
        return $this->cosa() === 'soldi'
            ? 'Quanto spendi e quanto incassi (€ al mese)'
            : 'Quante persone, mese per mese';
    }

    public function getDescription(): ?string
    {
        return $this->cosa() === 'soldi'
            ? '💶 La spesa è esatta (parte dal 22/09/2026, da quando il registro esiste). '
              .'⚠️ Il guadagno è una stima: iscritti × la quota che hai scritto tu.'
            : '⚠️ È «chi c\'è oggi, per data d\'arrivo»: chi se n\'è andato non compare, '
              .'quindi la curva non scende mai.';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $palestra = app(TenantContext::class)->get();

        if (! $palestra instanceof Tenant) {
            return ['datasets' => [], 'labels' => []];
        }

        $conti = app(IContiDellaPalestra::class);
        $mesi = $conti->mesi();

        $etichette = array_map(
            static fn (string $m): string => Carbon::parse($m.'-01')->locale('it')->isoFormat('MMM YY'),
            $mesi,
        );

        if ($this->cosa() === 'soldi') {
            $spesa = $conti->spesaPerMese($palestra);
            $guadagno = $conti->guadagnoPerMese($palestra);

            return [
                'datasets' => [
                    [
                        'label' => 'Incassi (stima)',
                        'data' => array_map(
                            static fn (string $m): float => round(($guadagno[$m] ?? 0) / 100, 2),
                            $mesi,
                        ),
                        'backgroundColor' => 'rgba(16, 185, 129, 0.6)',
                    ],
                    [
                        'label' => 'Quanto ci paghi',
                        'data' => array_map(
                            static fn (string $m): float => round(($spesa[$m] ?? 0) / 100, 2),
                            $mesi,
                        ),
                        'backgroundColor' => 'rgba(239, 68, 68, 0.6)',
                    ],
                ],
                'labels' => $etichette,
            ];
        }

        $iscritti = $conti->personePerMese($palestra, UserRole::Member);

        $serie = [
            [
                'label' => $palestra->ePersonale() ? 'Allievi' : 'Iscritti',
                'data' => array_map(static fn (string $m): int => $iscritti[$m] ?? 0, $mesi),
                'borderColor' => 'rgba(59, 130, 246, 1)',
                'backgroundColor' => 'rgba(59, 130, 246, 0.15)',
                'fill' => true,
            ],
        ];

        /*
         * ⛔ **Nessuna curva dei trainer in uno spazio personale**: non può
         * assumere nessuno, e una riga piatta sullo zero con scritto «Trainer»
         * sembra un dato — cioè «i miei trainer sono zero» — invece che una
         * domanda che lì non si pone. 💡 È la stessa scelta della card dei
         * posti in `IPostiDellaPalestra`.
         */
        if (! $palestra->ePersonale()) {
            $trainer = $conti->personePerMese($palestra, UserRole::Trainer);

            $serie[] = [
                'label' => 'Trainer',
                'data' => array_map(static fn (string $m): int => $trainer[$m] ?? 0, $mesi),
                'borderColor' => 'rgba(245, 158, 11, 1)',
                'backgroundColor' => 'rgba(245, 158, 11, 0.15)',
                'fill' => true,
            ];
        }

        return ['datasets' => $serie, 'labels' => $etichette];
    }

    private function cosa(): string
    {
        return (string) ($this->filter ?? 'soldi');
    }
}
