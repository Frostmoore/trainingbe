<?php

declare(strict_types=1);

namespace App\Services\Billing\Stripe;

use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Stripe\StripeClient;
use Throwable;

/**
 * 💳 **Cambiare il metodo di pagamento** — 23/09/2026, dopo F8.
 *
 * 📌 Il committente, dopo che il portale di Stripe è stato tolto dal pannello
 * (F8.5): *«Ovviamente ci deve essere la possibilità di cambiare metodo di
 * pagamento, sì»*.
 *
 * ══ 🧭 PERCHÉ NON IL PORTALE, E PERCHÉ NON BASTA LA CARTA DEL CLIENTE ═════
 *
 * 1. **Il portale è stato tolto per scelta** del committente: questa è una
 *    porta sola, che fa una cosa sola.
 * 2. 🚨 **Un abbonamento aperto dal Checkout ha una carta SUA**
 *    (`subscription.default_payment_method`), che vince su quella predefinita del
 *    cliente. ⛔ Cambiare solo quella del cliente lascerebbe il canone sulla
 *    carta vecchia — scaduta, di solito: è il motivo per cui si cambia. Quindi
 *    si aggiornano **tutte e due**: il cliente (per il consumo, che fattura con
 *    la predefinita) e ogni abbonamento attivo della palestra.
 *
 * 💡 La carta si scrive **sulla pagina di Stripe** (Checkout in modalità
 * `setup`, la stessa strada del piano a consumo, già accettata dalla sandbox):
 * noi non la vediamo mai.
 *
 * ══ 🔁 IL GIRO ══════════════════════════════════════════════════════════
 *
 * `apri()` → Stripe → `checkout.session.completed` con `tipo = cambia_carta` →
 * `Cassa::primoPagamento()` → `applica()`.
 */
class CambioDellaCarta
{
    /** Il `tipo` nei metadata: è così che il webhook sa di passare di qui. */
    public const TIPO = 'cambia_carta';

    public function __construct(private readonly Cassa $cassa) {}

    /**
     * Apre la pagina di Stripe per la carta nuova, e dice dove mandare la persona.
     *
     * @throws InvalidArgumentException con un messaggio da mostrare
     */
    public function apri(User $utente, string $tornaA): string
    {
        // Stessa guardia di tutto quello che riguarda i soldi dello spazio: solo il titolare.
        if (! Cassa::loPuoGestire($utente)) {
            throw new InvalidArgumentException('Il metodo di pagamento lo cambia chi è titolare dello spazio.');
        }

        // 🚨 Verificato su Stripe (`clienteEsistente`): un id fantasma darebbe «No such customer».
        $cliente = $this->cassa->clienteDi($utente);

        if ($cliente === null) {
            throw new InvalidArgumentException('Non hai ancora un metodo di pagamento da cambiare: scegli prima un piano.');
        }

        $meta = [
            'tipo' => self::TIPO,
            'user_id' => (string) $utente->getKey(),
            'tenant_id' => (string) $utente->tenant_id,
        ];

        $sessione = $this->stripe()->checkout->sessions->create([
            'mode' => 'setup',

            // ⚠️ Come in tutto il resto della cassa: con i Managed Payments accesi
            // Stripe rifiuta la modalità `setup` (trovato nella sandbox il 18/09).
            'managed_payments' => ['enabled' => false],

            'customer' => $cliente,
            'payment_method_types' => ['card'],
            'metadata' => $meta,
            'setup_intent_data' => ['metadata' => $meta],
            'success_url' => $tornaA,
            'cancel_url' => $tornaA,
        ]);

        return (string) $sessione->url;
    }

    /**
     * Il webhook: la carta nuova è salvata, e diventa quella che paga.
     *
     * ⛔ **Non lancia mai** (come `PianiInVendita::accendi()`): un'eccezione farebbe
     * rispondere 500 a Stripe, che ritenterebbe per ore. Un errore finisce nel log.
     *
     * @param  array<string, mixed>  $sessione
     */
    public function applica(array $sessione): void
    {
        $meta = $sessione['metadata'] ?? [];
        $cliente = (string) ($sessione['customer'] ?? '');
        $tenant = Tenant::withoutGlobalScopes()->find($meta['tenant_id'] ?? null);

        if ($cliente === '' || $tenant === null) {
            Log::error('Stripe: cambio carta senza cliente o spazio.', ['metadata' => $meta]);

            return;
        }

        try {
            $metodo = (string) $this->stripe()->setupIntents
                ->retrieve((string) ($sessione['setup_intent'] ?? ''))
                ->payment_method;

            // 1. La predefinita del cliente: la usa la fattura a consumo.
            $this->stripe()->customers->update($cliente, [
                'invoice_settings' => ['default_payment_method' => $metodo],
            ]);

            // 2. 🚨 E quella di ogni abbonamento attivo, che altrimenti vincerebbe.
            $abbonamenti = PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $tenant->getKey())
                ->whereNotNull('stripe_subscription_id')
                ->attivi()
                ->pluck('stripe_subscription_id');

            foreach ($abbonamenti as $id) {
                $this->stripe()->subscriptions->update((string) $id, ['default_payment_method' => $metodo]);
            }
        } catch (Throwable $e) {
            Log::error('Stripe: carta nuova non applicata.', [
                'tenant' => $tenant->getKey(),
                'errore' => $e->getMessage(),
            ]);

            return;
        }

        $this->ripagaLeFattureAperte($cliente, $tenant);

        Log::info('Stripe: metodo di pagamento cambiato.', ['tenant' => $tenant->getKey()]);
    }

    /**
     * 💡 **Chi cambia carta dopo un pagamento rifiutato vuole pagare adesso**, non
     * quando Stripe riproverà fra qualche giorno — intanto il servizio rischia di
     * spegnersi. Le fatture rimaste aperte si ripagano subito con la carta nuova.
     *
     * ⚠️ Una per volta e senza fermarsi: se una fallisce ancora, Stripe la
     * riproverà da solo come prima, e le altre non devono restare indietro. Il
     * pagamento riuscito torna dal solito `invoice.paid`, che toglie l'avviso e
     * scrive l'incasso (e quindi la fattura nostra).
     */
    private function ripagaLeFattureAperte(string $cliente, Tenant $tenant): void
    {
        try {
            $aperte = $this->stripe()->invoices->all(['customer' => $cliente, 'status' => 'open', 'limit' => 10]);
        } catch (Throwable $e) {
            Log::warning('Stripe: non riesco a leggere le fatture aperte dopo il cambio carta.', ['errore' => $e->getMessage()]);

            return;
        }

        foreach ($aperte->data ?? [] as $fattura) {
            try {
                $this->stripe()->invoices->pay((string) $fattura->id);
            } catch (Throwable $e) {
                Log::warning('Stripe: fattura aperta non ripagata con la carta nuova.', [
                    'tenant' => $tenant->getKey(),
                    'fattura' => $fattura->id,
                    'errore' => $e->getMessage(),
                ]);
            }
        }
    }

    private function stripe(): StripeClient
    {
        return app(StripeClient::class);
    }
}
