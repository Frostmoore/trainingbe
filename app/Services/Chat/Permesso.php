<?php

declare(strict_types=1);

namespace App\Services\Chat;

/**
 * La risposta del cancello: si', no, e **perche'** — 18/08/2026. **M3.1.**
 *
 * ── 🚨 Perche' non basta un `bool` ─────────────────────────────────────────
 *
 * Perche' i «no» di questo cancello non sono tutti uguali, e l'app deve
 * trattarli in modo diverso:
 *
 * - *«hai finito i tre messaggi»* → si propone **l'abbonamento**;
 * - *«questo trainer e' dipendente di una palestra»* → non c'e' niente da
 *   comprare, si spiega e basta;
 * - *«il rapporto e' stato sospeso»* → si dice che e' chiuso.
 *
 * ⚠️ Con un `bool` l'app potrebbe solo mostrare «non puoi scrivere», e la
 * persona resterebbe a chiedersi cosa ha sbagliato. 💡 Con il **motivo
 * leggibile a macchina** (`codice`) l'app decide cosa offrire, e con quello
 * leggibile da una persona (`spiegazione`) lo dice senza che il testo debba
 * stare anche nell'app.
 */
final readonly class Permesso
{
    /** Non e' abbonato e ha esaurito i tre messaggi. 🚨 Qui si propone l'abbonamento. */
    public const TRE_ESAURITI = 'tre_messaggi_esauriti';

    /** Un trainer dipendente di una palestra a cui non si e' iscritti. */
    public const TRAINER_DI_PALESTRA = 'trainer_di_palestra';

    /** Il rapporto fra i due e' stato sospeso dal trainer. */
    public const CANALE_CHIUSO = 'conversation_closed';

    /** Non c'e' nessun legame, e nessuna scheda pubblica da cui partire. */
    public const NESSUN_LEGAME = 'nessun_legame';

    /** Sta scrivendo qualcuno nei panni di un altro. */
    public const IMPERSONAZIONE = 'impersonazione';

    private function __construct(
        public bool $consentito,
        public ?string $codice = null,
        public ?string $spiegazione = null,
        public ?int $restanti = null,
    ) {}

    /**
     * 💡 `restanti` c'e' anche quando si puo' scrivere: l'app deve poter dire
     * «te ne restano due» **prima** che la persona prema invio, non dopo (M4.3).
     * `null` significa **senza limite**, che e' diverso da zero.
     */
    public static function si(?int $restanti = null): self
    {
        return new self(consentito: true, restanti: $restanti);
    }

    public static function no(string $codice, string $spiegazione, ?int $restanti = null): self
    {
        return new self(
            consentito: false,
            codice: $codice,
            spiegazione: $spiegazione,
            restanti: $restanti,
        );
    }

    /** @return array<string, mixed> */
    public function perLApp(): array
    {
        return [
            'consentito' => $this->consentito,
            'codice' => $this->codice,
            'spiegazione' => $this->spiegazione,
            'restanti' => $this->restanti,

            /*
             * 🚨 Il segnale esplicito per l'app: «qui proponi l'abbonamento».
             *
             * ⚠️ **E solo qui.** Fuori da questo caso non c'e' niente da
             * vendere: proporre l'abbonamento a chi non puo' scrivere a un
             * trainer dipendente sarebbe vendergli una cosa che non risolve il
             * suo problema.
             */
            'proponi_abbonamento' => $this->codice === self::TRE_ESAURITI,
        ];
    }
}
