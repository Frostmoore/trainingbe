<?php

declare(strict_types=1);

namespace App\Services\Billing\Stripe;

use App\Enums\DurataDelloSconto;
use App\Enums\SuCosaVale;
use App\Enums\TipoDiPremio;
use App\Models\Plan;
use App\Models\Promozione;
use App\Models\PromozionePremio;
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
     * @return array{discounts?: list<array{coupon: string}>, promozioni: list<Promozione>, sconto: int, bonus: list<Promozione>, gettoni_bonus: int}
     */
    public function perIlCheckout(
        User $utente,
        SuCosaVale $cosa,
        int $importoCent,
        ?int $pacchettoId = null,
        ?int $planId = null,
        ?int $gettoni = null,
    ): array {
        /*
         * ⛔ **Niente codice al pagamento** (24/09/2026): le candidate sono le
         * automatiche **più** quelle attivate col link (che `automaticheDi()`
         * comprende, perché hanno un acquisto in sospeso). Nessuno scrive niente.
         */
        $candidate = $this->promozioni->automaticheDi($utente, $cosa)->all();

        $conto = $this->promozioni->applica($importoCent, $candidate, $cosa, $pacchettoId, $planId);

        /*
         * 🆕 🪙 **Il bonus in gettoni** — P2 di `plan_promozioni.md`.
         *
         * Non tocca il prezzo, quindi Stripe non ne sa niente: si scrive nei
         * `metadata` (chiave `bonus`) e lo accredita il webhook insieme ai
         * gettoni comprati.
         */
        $bonus = $cosa === SuCosaVale::Gettoni && $gettoni !== null
            ? $this->promozioni->bonusPer($candidate, $gettoni, $pacchettoId)
            : [];

        $gettoniBonus = array_sum(array_map(
            fn (Promozione $p): int => $this->promozioni->bonusDi($p, (int) $gettoni, $pacchettoId),
            $bonus,
        ));

        $applicate = $conto['applicate'];
        $discounts = null;

        /*
         * 🆕 🧪🔒 **Prova gratuita e prezzo bloccato** — P3. Solo sul canone:
         * sono premi dell'abbonamento, e Stripe li capisce solo lì.
         */
        $piano = $planId !== null ? Plan::query()->find($planId) : null;
        $prova = $cosa === SuCosaVale::Canone ? $this->promozioni->provaPer($candidate, $planId) : null;
        $bloccano = $cosa === SuCosaVale::Canone ? $this->promozioni->bloccanoIlPrezzo($candidate, $planId) : [];

        if ($conto['sconto'] > 0) {
            $coupon = $this->coupon($conto['sconto'], $conto['applicate'], $this->durataPerStripe($conto['applicate'], $cosa, $piano));

            /*
             * ⚠️ **Se Stripe non dà il coupon, si vende comunque a prezzo
             * pieno.** ⛔ L'alternativa — fermare il pagamento — trasforma un
             * problema nostro in una vendita persa. 🚨 Ma resta nel registro:
             * uno sconto promesso e non applicato è una telefonata.
             */
            if ($coupon === null) {
                $applicate = [];
            } else {
                $discounts = [['coupon' => $coupon]];
            }
        }

        // Nei metadata vanno tutte: il webhook segna l'uso di ognuna.
        $tutte = collect([...$applicate, ...$bonus, ...($prova !== null ? [$prova['promozione']] : []), ...$bloccano])
            ->unique(fn (Promozione $p): int => $p->getKey())
            ->values()
            ->all();

        return [
            ...($discounts !== null ? ['discounts' => $discounts] : []),
            'promozioni' => $tutte,
            'sconto' => $discounts !== null ? $conto['sconto'] : 0,
            'bonus' => $bonus,
            'gettoni_bonus' => $gettoniBonus,
            'prova_giorni' => $prova['giorni'] ?? null,
            'prezzo_bloccato' => $bloccano !== [],
        ];
    }

    /**
     * 🆕 ⏳ **Per quanto vale lo sconto, detto a Stripe** — P3 (opzione 8, §4.6 del piano).
     *
     * | Durata del premio | Coupon |
     * |---|---|
     * | solo il primo pagamento | `once` |
     * | per N rinnovi | `repeating` + `duration_in_months` |
     * | per sempre | `forever` |
     *
     * ⚠️ **Più premi, un coupon**: Stripe ne accetta uno per sessione, e vince
     * la durata **più lunga** fra quelli applicati — la promozione più generosa
     * nel tempo è quella che il cliente si aspetta di vedere rispettata.
     *
     * 🚨 `duration_in_months` è in **mesi**, i rinnovi no: per un piano annuale
     * N rinnovi sono N×12 mesi, per uno settimanale o giornaliero si arrotonda
     * per eccesso (mai meno di 1). I pacchetti di gettoni e il consumo sono
     * pagamenti singoli: sempre `once`.
     *
     * @param  list<Promozione>  $applicate
     * @return array{duration: string, duration_in_months?: int}
     */
    public function durataPerStripe(array $applicate, SuCosaVale $cosa, ?Plan $piano): array
    {
        if ($cosa !== SuCosaVale::Canone) {
            return ['duration' => 'once'];
        }

        $premi = collect($applicate)
            ->flatMap(fn (Promozione $p) => $p->premi)
            ->filter(fn (PromozionePremio $r): bool => $r->tipo === TipoDiPremio::ScontoAbbonamento);

        if ($premi->contains(fn (PromozionePremio $r): bool => $r->durata === DurataDelloSconto::PerSempre)) {
            return ['duration' => 'forever'];
        }

        $rinnovi = (int) $premi
            ->filter(fn (PromozionePremio $r): bool => $r->durata === DurataDelloSconto::NVolte)
            ->max('volte');

        if ($rinnovi <= 0) {
            return ['duration' => 'once'];
        }

        $mesi = match ($piano?->intervalloStripe() ?? 'month') {
            'year' => $rinnovi * 12,
            'week' => (int) ceil($rinnovi * 7 / 30),
            'day' => (int) ceil($rinnovi / 30),
            default => $rinnovi,
        };

        return ['duration' => 'repeating', 'duration_in_months' => max(1, $mesi)];
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
    /**
     * @param  array{duration: string, duration_in_months?: int}  $durata  🆕 P3
     */
    private function coupon(int $scontoCent, array $promozioni, array $durata = ['duration' => 'once']): ?string
    {
        $nome = $promozioni === []
            ? 'Sconto'
            : implode(' + ', array_map(static fn (Promozione $p): string => $p->nome, $promozioni));

        try {
            return (string) app(StripeClient::class)->coupons->create([
                'amount_off' => $scontoCent,
                'currency' => 'eur',

                /*
                 * 🆕 P3 — la durata viene dal premio (`durataPerStripe()`).
                 * ⚠️ Il default resta `once`: «per sempre» è una decisione da
                 * prendere su una promozione, non l'effetto di un parametro
                 * dimenticato.
                 */
                ...$durata,

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
