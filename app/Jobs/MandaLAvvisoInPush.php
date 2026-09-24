<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Avviso;
use App\Models\User;
use App\Services\Promozioni\ChiLaRiceve;
use App\Services\Push\PushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * 📲 **La push di un avviso** — blocco B4, 24/09/2026.
 *
 * 📌 Il committente, il 24/09: *«sì, gli avvisi devono mandare anche push»*.
 *
 * ══ ⏳ OGGI NON PARTE NIENTE, ED E' SCRITTO APPOSTA ═════════════════════════
 *
 * ⚠️ `PushSender` esiste ed è provato, ma **Firebase non è configurato**
 * (`DA-FARE.md` voce 20): senza credenziali non parte nessuna notifica. 💡 Il
 * pezzo si scrive **adesso** lo stesso, perché il giorno in cui Firebase c'è
 * questa strada è già in piedi e già provata — e non si va a cercare dove
 * agganciarla mentre si combatte con le chiavi.
 *
 * ══ 🚨 IN CODA, E MAI DENTRO LA RICHIESTA ══════════════════════════════════
 *
 * ⛔ Un avviso «per tutti» vuol dire scorrere ogni persona e ogni suo
 * dispositivo: farlo mentre qualcuno preme «salva» nel pannello vorrebbe dire
 * una schermata ferma per minuti, e un errore di rete che fa fallire **il
 * salvataggio** invece della notifica.
 *
 * 💡 **La push non è l'avviso**: se non parte, la modale compare lo stesso alla
 * prossima apertura dell'app. Per questo qui si registra e si tira avanti.
 */
class MandaLAvvisoInPush implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $avvisoId) {}

    public function handle(ChiLaRiceve $pubblico, PushSender $push): void
    {
        $avviso = Avviso::query()->find($this->avvisoId);

        if ($avviso === null || ! $avviso->attivo || ! $avviso->anche_push) {
            return;
        }

        $quante = 0;

        foreach ($pubblico->destinatari($avviso) as $utente) {
            /** @var User $utente */
            $quante += $push->toUser($utente, $avviso->titolo, $avviso->testo, [
                /*
                 * 💡 `avviso_id` nel carico: toccando la notifica l'app sa
                 * **quale** modale aprire, invece di chiedere «ce n'è uno?» e
                 * sperare che sia quello.
                 */
                'tipo' => 'avviso',
                'avviso_id' => (string) $avviso->getKey(),
            ]);
        }

        Log::info('Avviso mandato in push', [
            'avviso' => $avviso->getKey(),
            'notifiche' => $quante,
        ]);
    }
}
