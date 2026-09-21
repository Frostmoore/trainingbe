<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * ⚡ **Quando scatta una promozione «a evento»** — 24/09/2026, P6 di
 * `plan_promozioni.md` (opzione 32).
 *
 * | Caso | Chi la riceve | Parametri | Dove scatta |
 * |---|---|---|---|
 * | `registrazione` | chi si è appena iscritto | — | `User::created` |
 * | `primo_abbonamento` | chi ha pagato il primo canone | — | `Cassa::primoPagamento()` |
 * | `rinnovo` | il titolare dello spazio | `numero` (vuoto = ogni rinnovo) | `Cassa::rinnovo()` (`billing_reason = subscription_cycle`) |
 * | `ingresso_in_palestra` | chi entra con un invito della palestra | — | `InvitoInPalestra` usato |
 * | `invito_accettato` | **chi aveva invitato** (palestra o trainer) | — | `InvitoInPalestra` / `TrainerInvite` usati |
 * | `acquisto_gettoni` | chi ha comprato | — | `Cassa::primoPagamento()` |
 * | `amico_arrivato` | chi arriva **e** chi l'ha portato (premi `a_chi`) | `quando`: `registrazione` \| `primo_acquisto` | `AmicoController::collega()` / `Cassa::primoPagamento()` |
 */
enum EventoDiPromozione: string
{
    case Registrazione = 'registrazione';
    case PrimoAbbonamento = 'primo_abbonamento';
    case Rinnovo = 'rinnovo';
    case IngressoInPalestra = 'ingresso_in_palestra';
    case InvitoAccettato = 'invito_accettato';
    case AcquistoGettoni = 'acquisto_gettoni';
    case AmicoArrivato = 'amico_arrivato';

    public function label(): string
    {
        return match ($this) {
            self::Registrazione => 'Alla registrazione',
            self::PrimoAbbonamento => 'Al primo abbonamento',
            self::Rinnovo => 'A un rinnovo',
            self::IngressoInPalestra => 'Quando si entra in una palestra',
            self::InvitoAccettato => 'Quando un suo invito viene accettato',
            self::AcquistoGettoni => 'Quando si comprano gettoni',
            self::AmicoArrivato => 'Porta un amico',
        };
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        $out = [];

        foreach (self::cases() as $caso) {
            $out[$caso->value] = $caso->label();
        }

        return $out;
    }
}
