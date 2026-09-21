<?php

declare(strict_types=1);

namespace App\Services\Billing\Exceptions;

use InvalidArgumentException;

/**
 * 🆕 L'abbonamento salvato ha un id Stripe che su Stripe **non esiste** —
 * 23/09/2026, F8.6.
 *
 * 🚨 Succede con un piano assegnato a mano con un id di prova, o con un
 * abbonamento cancellato dalla dashboard di Stripe. ⛔ Prima «passa a questo»
 * finiva in un errore generico. 💡 Chi la riceve dal pannello **apre il
 * pagamento** al posto del cambio (`IlTuoPiano::scegli()`): per Stripe quella
 * palestra non ha niente da cambiare, ha un piano da comprare.
 *
 * ⚠️ Estende `InvalidArgumentException` apposta: l'API, che già risponde 422
 * con il messaggio a quelle, non ha bisogno di saperne niente.
 */
final class AbbonamentoNonSuStripeException extends InvalidArgumentException {}
