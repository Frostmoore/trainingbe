<?php

declare(strict_types=1);

namespace App\Services\Billing\Stripe;

use App\Enums\SuCosaVale;
use App\Models\Promozione;
use App\Models\User;
use App\Services\Billing\LePromozioni;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Throwable;

/**
 * 🎁💳 **Le promozioni, dette a Stripe** — 22/09/2026, fase F2.
 *
 * ══ 🚨 STRIPE ACCETTA UN SOLO SCONTO PER SESSIONE ════════════════════════
 *
 * `discounts` del checkout prende **un elemento**. ⛔ Quindi due promozioni
 * cumulabili non si possono «passare tutte e due»: o si rinuncia alla
 * cumulabilità — che il committente ha chiesto esplicitamente — o si dice a
 * Stripe **il risultato**.
 *
 * 💡 Si fa la seconda: `LePromozioni` calcola quanto si toglie con le nostre
 * regole (la migliore fra le esclusive, le cumulabili incolonnate, mai sotto
 * zero), e qui si crea **un coupon da quell'importo esatto**.
 *
 * 🚨 Il vantaggio non è tecnico: le regole restano in **un posto solo**. Se
 * passassimo a Stripe una percentuale, il conto lo rifarebbe lui — e il giorno
 * che le due matematiche divergono il cliente paga una cifra che il nostro
 * pannello non sa spiegare.
 *
 * ── ⚠️ `duration: once`, e non è una dimenticanza ─────────────────────────
 *
 * Su un abbonamento lo sconto vale **il primo pagamento**. ⛔ `forever`
 * sconterebbe ogni rinnovo per sempre: è una decisione da prendere una
 * promozione alla volta e con gli occhi aperti, non il default di un metodo.
 * 💡 Il giorno che serve, si aggiunge una colonna alla promozione.
 */
class ScontiSuStripe
{
    public function __construct(private readonly LePromozioni $promozioni) {}

    /**
     * Le parti da aggiungere alla sessione di checkout.
     *
     * @return array{discounts?: list<array{coupon: string}>, promozioni: list<Promozione>, sconto: int}
     */
    public function perIlCheckout(
        User $utente,
        SuCosaVale $cosa,
        int $importoCent,
        ?string $codice = null,
        ?int $pacchettoId = null,
        ?int $planId = null,
    ): array {
        $candidate = $this->promozioni->automaticheDi($utente, $cosa)->all();

        /*
         * 💡 Il codice si somma alle automatiche: chi ha un'offerta sua e
         * riceve anche un link non deve perdere una delle due. ⚠️ Poi
         * decidono le regole di cumulabilità, che restano le stesse.
         */
        if ($codice !== null && ($conCodice = $this->promozioni->conIlCodice($codice, $utente, $cosa)) !== null) {
            $candidate[] = $conCodice;
        }

        $conto = $this->promozioni->applica($importoCent, $candidate, $cosa, $pacchettoId, $planId);

        if ($conto['sconto'] <= 0) {
            return ['promozioni' => [], 'sconto' => 0];
        }

        $coupon = $this->coupon($conto['sconto'], $conto['applicate']);

        if ($coupon === null) {
            /*
             * ⚠️ **Se Stripe non dà il coupon, si vende comunque a prezzo
             * pieno.** ⛔ L'alternativa — fermare il pagamento — trasforma un
             * problema nostro in una vendita persa. 🚨 Ma resta nel registro:
             * uno sconto promesso e non applicato è una telefonata.
             */
            return ['promozioni' => [], 'sconto' => 0];
        }

        return [
            'discounts' => [['coupon' => $coupon]],
            'promozioni' => $conto['applicate'],
            'sconto' => $conto['sconto'],
        ];
    }

    /**
     * I codici delle promozioni, come si scrivono nei `metadata`.
     *
     * 🚨 Servono al webhook per segnare le promozioni **usate**: quello che non
     * è scritto nei metadata, al ritorno non esiste.
     *
     * @param  list<Promozione>  $promozioni
     */
    public function perIMetadata(array $promozioni): string
    {
        return implode(',', array_map(static fn (Promozione $p): string => $p->codice, $promozioni));
    }

    /**
     * Un coupon **di questo importo**, per questa vendita.
     *
     * ⚠️ Si crea ogni volta invece di riusarne uno per promozione: l'importo
     * dipende da **quanto si sta comprando** (una percentuale su un pacchetto
     * da 3 € e su uno da 75 € sono due cifre diverse), e un coupon a importo
     * fisso riusato sconterebbe la cifra sbagliata.
     *
     * @param  list<Promozione>  $promozioni
     */
    private function coupon(int $scontoCent, array $promozioni): ?string
    {
        $nome = $promozioni === []
            ? 'Sconto'
            : implode(' + ', array_map(static fn (Promozione $p): string => $p->nome, $promozioni));

        try {
            return (string) app(StripeClient::class)->coupons->create([
                'amount_off' => $scontoCent,
                'currency' => 'eur',

                // ⚠️ Vedi il docblock: sul primo pagamento, non per sempre.
                'duration' => 'once',

                'name' => mb_substr($nome, 0, 40),

                'metadata' => [
                    'promozioni' => $this->perIMetadata($promozioni),
                ],
            ])->id;
        } catch (Throwable $e) {
            Log::error('Stripe: coupon non creato, si vende a prezzo pieno.', [
                'sconto' => $scontoCent,
                'promozioni' => $this->perIMetadata($promozioni),
                'errore' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
