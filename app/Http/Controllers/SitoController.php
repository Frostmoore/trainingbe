<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PlanKind;
use App\Models\Plan;
use App\Models\TrainerInvite;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;

/**
 * Il sito pubblico — F9 della Parte B.
 *
 * ⚠️ **È l'unica cosa di tutto il piano che non è né app né pannello**: è
 * marketing, prezzi e conformità. Tre pubblici, tre percorsi (F9.1).
 *
 * 🚨 **Il listino si legge dal database, non si scrive nel template.** Un
 * prezzo scritto in una vista è un prezzo che un giorno dirà una cosa diversa da
 * quella che il sistema fattura — ed è la classe di errore che si scopre da un
 * cliente arrabbiato, non da un test.
 */
class SitoController extends Controller
{
    public function home(): View
    {
        return view('sito.home', [
            'perPersone' => $this->pianiDi(PlanKind::Person),
            'perTrainer' => $this->pianiDi(PlanKind::Trainer),
            'perPalestre' => $this->pianiDi(PlanKind::Gym),
        ]);
    }

    /**
     * La pagina d'atterraggio di un invito — F6.2.
     *
     * 🚨 **Non riscatta niente.** Il riscatto ha bisogno di nome, email e
     * password, cioè di un modulo: questa pagina dice soltanto se il link è
     * ancora buono e manda all'app. ⚠️ Un `GET` che consumasse l'invito lo
     * brucerebbe al primo servizio che fa l'anteprima del link — quelli di
     * messaggistica lo aprono da soli per mostrare il riquadro.
     *
     * 💡 E il token **non si mostra** nella pagina: viaggia già nell'URL, e
     * ripeterlo nel corpo lo farebbe finire negli screenshot.
     */
    public function invito(string $token): View
    {
        $invito = TrainerInvite::withoutGlobalScopes()->where('token', $token)->first();

        return view('sito.invito', [
            'valido' => $invito !== null && $invito->eValido(),
            'trainer' => $invito?->trainer?->name,
        ]);
    }

    /** @return Collection<int, Plan> */
    private function pianiDi(PlanKind $kind): Collection
    {
        return Plan::query()
            ->pubblici()
            ->diTipo($kind)
            ->orderBy('price_cents')
            ->get();
    }
}
