<?php

declare(strict_types=1);

namespace App\Services\Promozioni;

use App\Mail\PromozionePerTeMail;
use App\Models\Promozione;
use App\Models\PromozioneAssegnazione;
use App\Models\User;
use App\Services\Push\PushSender;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * 📣 **Dire alla gente che ha qualcosa** — 24/09/2026, P8 di `plan_promozioni.md`
 * (opzione 37).
 *
 * I canali li sceglie la promozione (`promozioni.avviso`: `app`, `email`, tutti
 * e due, o niente — in silenzio). Due momenti:
 *
 * | Quando | Metodo | Cosa dice |
 * |---|---|---|
 * | un regalo è arrivato (consegna, evento, massa) | [dopoLaConsegna] | «Hai ricevuto…» |
 * | «Avvisa i destinatari» dal pannello (sconti, link) | [aTutti] | «C'è un'offerta per te» |
 *
 * 🚨 **Non lancia mai**: un avviso non partito non deve togliere un regalo già
 * dato. ⚠️ Con `MAIL_MAILER=log` (oggi, finché non c'è Zeptomail) la mail finisce
 * nel registro: il codice è quello vero.
 */
class AvvisaIDestinatari
{
    public function __construct(
        private readonly PushSender $push,
        private readonly ChiLaRiceve $chiLaRiceve,
    ) {}

    /** Dopo una consegna riuscita. */
    public function dopoLaConsegna(PromozioneAssegnazione $assegnazione): void
    {
        $promozione = $assegnazione->promozione;
        $utente = User::withoutGlobalScopes()->find($assegnazione->user_id);

        if ($promozione !== null && $utente !== null) {
            $this->manda($promozione, $utente, ricevuta: true);
        }
    }

    /**
     * «Avvisa i destinatari» — a tutte le persone che la promozione prende oggi.
     *
     * @return int quante persone
     */
    public function aTutti(Promozione $promozione): int
    {
        $quante = 0;

        foreach ($this->chiLaRiceve->destinatari($promozione) as $utente) {
            $this->manda($promozione, $utente, ricevuta: false);
            $quante++;
        }

        return $quante;
    }

    private function manda(Promozione $promozione, User $utente, bool $ricevuta): void
    {
        $canali = (array) $promozione->avviso;

        try {
            if (in_array('app', $canali, true)) {
                $this->push->toUser(
                    $utente,
                    $ricevuta ? '🎁 '.$promozione->nome : 'Un\'offerta per te',
                    $ricevuta ? ucfirst($promozione->scrittoBene()) : $promozione->nome.': '.$promozione->scrittoBene(),
                    ['tipo' => 'promozione', 'codice' => $promozione->codice],
                );
            }

            if (in_array('email', $canali, true) && filled($utente->email)) {
                Mail::to($utente->email)->send(new PromozionePerTeMail($promozione, $utente, $ricevuta));
            }
        } catch (Throwable $e) {
            Log::warning('Promozioni: avviso non partito.', [
                'promozione' => $promozione->codice,
                'utente' => $utente->getKey(),
                'errore' => $e->getMessage(),
            ]);
        }
    }
}
