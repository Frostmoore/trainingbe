<?php

declare(strict_types=1);

namespace App\Services\Promozioni;

use App\Enums\ComeSiAttiva;
use App\Enums\EventoDiPromozione;
use App\Enums\OrigineAssegnazione;
use App\Models\Promozione;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ⚡ **Le promozioni che scattano quando succede qualcosa** — 24/09/2026, P6 di
 * `plan_promozioni.md` (opzioni 15, 18, 27, 32).
 *
 * Chi chiama dice **cosa** è successo, **a chi**, e con quale riferimento;
 * qui si cercano le promozioni `attivazione = evento` con quell'evento e si
 * consegnano con `ConsegnaDeiPremi` — le stesse regole di tutti gli altri
 * regali (destinatari, limiti, budget).
 *
 * ══ 🚨 DUE REGOLE CHE NON SI TOCCANO ═════════════════════════════════════
 *
 * 1. **Dopo il commit.** Un regalo per una registrazione che poi fallisce e
 *    torna indietro resterebbe dato: `DB::afterCommit()`.
 * 2. **Non lancia mai.** Chi chiama sta facendo altro — registrare una persona,
 *    incassare un pagamento — e una promozione rotta non deve fermarlo.
 *
 * Il riferimento dell'assegnazione è `<evento>:<riferimento>` (unico per
 * promozione): lo stesso evento ripetuto non regala due volte.
 */
class GliEventi
{
    public function __construct(private readonly ConsegnaDeiPremi $consegna) {}

    /**
     * ⚡ **È successo questo.**
     *
     * @param  string  $riferimento  che cosa, in modo univoco (l'id dell'utente, della sessione, della fattura, dell'invito)
     * @param  array{numero?: int, invitante?: User}  $contesto
     */
    public function succede(EventoDiPromozione $evento, User $utente, string $riferimento, array $contesto = []): void
    {
        DB::afterCommit(function () use ($evento, $utente, $riferimento, $contesto): void {
            try {
                $this->subito($evento, $utente, $riferimento, $contesto);
            } catch (Throwable $e) {
                Log::error('Promozioni: evento non consegnato.', [
                    'evento' => $evento->value,
                    'utente' => $utente->getKey(),
                    'errore' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Il lavoro vero, senza `afterCommit` — `succede()` lo avvolge.
     *
     * @param  array{numero?: int, invitante?: User}  $contesto
     * @return int quante consegne
     */
    public function subito(EventoDiPromozione $evento, User $utente, string $riferimento, array $contesto = []): int
    {
        $quante = 0;

        foreach ($this->promozioniPer($evento, $contesto) as $promozione) {
            $chiave = "{$evento->value}:{$riferimento}";

            if ($this->consegna->consegna($promozione, $utente, OrigineAssegnazione::Evento, $chiave) !== null) {
                $quante++;
            }

            /*
             * 🤝 **Porta un amico**: i premi `a_chi = chi_ha_invitato` vanno a
             * chi l'ha portato, con un riferimento suo — così ogni amico
             * arrivato lo premia una volta, e il massimo per persona dice per
             * quanti amici.
             */
            if ($evento === EventoDiPromozione::AmicoArrivato && ($contesto['invitante'] ?? null) instanceof User) {
                $premio = $this->consegna->consegna(
                    $promozione,
                    $contesto['invitante'],
                    OrigineAssegnazione::Amico,
                    "{$chiave}:invitante",
                    'chi_ha_invitato',
                );

                $quante += $premio !== null ? 1 : 0;
            }
        }

        return $quante;
    }

    /**
     * Le promozioni spendibili a questo evento.
     *
     * ⚠️ Parametri: `rinnovo` può dire **quale** rinnovo (`numero`; vuoto = ogni
     * rinnovo); `amico_arrivato` dice **quando** (`registrazione` o
     * `primo_acquisto`, passato in `$contesto['quando']`).
     *
     * @param  array<string, mixed>  $contesto
     * @return iterable<Promozione>
     */
    private function promozioniPer(EventoDiPromozione $evento, array $contesto): iterable
    {
        return Promozione::query()
            ->spendibili()
            ->where('attivazione', ComeSiAttiva::Evento->value)
            ->where('evento', $evento->value)
            ->with(['premi', 'bersagli', 'condizioni'])
            ->get()
            ->filter(function (Promozione $p) use ($evento, $contesto): bool {
                $parametri = (array) $p->evento_parametri;

                return match ($evento) {
                    EventoDiPromozione::Rinnovo => empty($parametri['numero'])
                        || (int) $parametri['numero'] === (int) ($contesto['numero'] ?? 0),
                    EventoDiPromozione::AmicoArrivato => ($parametri['quando'] ?? 'registrazione') === ($contesto['quando'] ?? 'registrazione'),
                    default => true,
                };
            });
    }
}
