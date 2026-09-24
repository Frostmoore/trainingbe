<?php

declare(strict_types=1);

namespace App\Services\Avvisi;

use App\Models\Avviso;
use App\Models\AvvisoDecisione;
use App\Models\User;
use App\Services\Promozioni\ChiLaRiceve;
use Illuminate\Support\Carbon;

/**
 * 📣 **Quale avviso mostrare a questa persona, adesso** — blocco B4, 24/09/2026.
 *
 * ══ 🚨 UNO PER VOLTA, E NON E' UNA SEMPLIFICAZIONE ══════════════════════════
 *
 * 📌 Decisione mia del 24/09, su mandato del committente (*«come tetto di
 * frequenza non lo so, mi fido di te»*): l'app chiede *«ce n'è uno?»* e riceve
 * **il più vecchio non ancora deciso**.
 *
 * ⛔ Due modali in fila all'avvio non sono un avviso, sono un pedaggio. ⚠️ E chi
 * si trova davanti una coda **non legge**: preme il primo pulsante per
 * arrivare all'app.
 *
 * ══ 📅 IL TETTO: QUATTRO AL MESE, UNO OGNI 24 ORE ═══════════════════════════
 *
 * | Limite | Perché |
 * |---|---|
 * | **4 al mese** | circa uno a settimana: sopra, smette di essere una notizia |
 * | **24 ore** fra uno e l'altro | il tetto mensile da solo non difende da una raffica in un pomeriggio |
 * | ⚠️ **si contano i MOSTRATI** | un avviso mai visto non ha dato fastidio, e non deve rubare il posto a uno che servirà |
 * | 🚨 **`urgente` scavalca** | un avviso di servizio non può essere buttato via da un limite pensato per le promozioni |
 *
 * 💡 I numeri stanno in `config/avvisi.php`, non qui: il giorno che cambiano,
 * cambiano in un posto solo.
 */
class QualeAvvisoMostrare
{
    public function __construct(private readonly ChiLaRiceve $pubblico) {}

    /**
     * L'avviso da mostrare adesso, o `null`.
     *
     * 🚨 **Registra anche che gliel'abbiamo mostrato** (`mostrato_il`): è il
     * momento che conta per il tetto, e senza questa riga lo stesso avviso
     * verrebbe riproposto a ogni apertura senza mai consumare niente.
     */
    public function per(User $utente): ?Avviso
    {
        $urgentiPrima = Avviso::query()
            ->inPiedi()
            ->whereDoesntHave('decisioni', fn ($q) => $q->where('user_id', $utente->getKey())->whereNotNull('deciso_il'))
            /*
             * ⚠️ **Gli urgenti prima**, poi i più vecchi: se una persona ha un
             * avviso di servizio in coda dietro tre promozioni, quell'avviso
             * arriva fra tre giorni — cioè quando non serve più.
             */
            ->orderByDesc('urgente')
            ->orderBy('created_at')
            ->get();

        foreach ($urgentiPrima as $avviso) {
            if (! $avviso->eCompleto()) {
                /*
                 * ⛔ Un `link` senza indirizzo o una `promo` senza promozione
                 * non si mandano: chi li riceve non ha modo di capire che
                 * l'errore non è suo.
                 */
                continue;
            }

            if (! $avviso->urgente && ! $this->ciStaNelTetto($utente)) {
                /*
                 * 💡 `continue` e non `return null`: più avanti nell'elenco può
                 * esserci un **urgente**, che il tetto non lo guarda.
                 */
                continue;
            }

            if (! $this->pubblico->prende($avviso, $utente)) {
                continue;
            }

            $this->segnaCheGliELStatoMostrato($avviso, $utente);

            return $avviso;
        }

        return null;
    }

    /**
     * 📅 Questa persona può riceverne un altro adesso?
     *
     * ⚠️ Si contano le righe **mostrate** negli ultimi 30 giorni, e si guarda
     * **quando** è stata l'ultima: due limiti diversi che difendono da due cose
     * diverse (il totale, e la raffica).
     */
    public function ciStaNelTetto(User $utente): bool
    {
        $quanti = AvvisoDecisione::query()
            ->where('user_id', $utente->getKey())
            ->whereNotNull('mostrato_il')
            ->where('mostrato_il', '>=', Carbon::now()->subDays(30))
            ->count();

        if ($quanti >= (int) config('avvisi.al_mese', 4)) {
            return false;
        }

        $ultimo = AvvisoDecisione::query()
            ->where('user_id', $utente->getKey())
            ->whereNotNull('mostrato_il')
            ->max('mostrato_il');

        if ($ultimo === null) {
            return true;
        }

        return Carbon::parse($ultimo)
            ->addHours((int) config('avvisi.ore_fra_due', 24))
            ->isPast();
    }

    /**
     * ⚠️ `firstOrCreate` e poi l'aggiornamento: se la riga c'è già — perché
     * l'avviso era stato mostrato e non deciso — **non si riscrive
     * `mostrato_il`**, o la stessa modale riaperta dieci volte consumerebbe
     * dieci posti del tetto.
     */
    private function segnaCheGliELStatoMostrato(Avviso $avviso, User $utente): void
    {
        AvvisoDecisione::query()->firstOrCreate(
            ['avviso_id' => $avviso->getKey(), 'user_id' => $utente->getKey()],
            ['mostrato_il' => Carbon::now()],
        );
    }
}
