<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🎀 **Cosa dà una promozione** — 24/09/2026, fase P1 di `plan_promozioni.md`.
 *
 * Una promozione ha **uno o più premi** (tabella `promozione_premi`): «abbonamento
 * scontato **e** gettoni in regalo» è una promozione sola.
 *
 * 💡 Ogni tipo usa solo alcune colonne del premio: le dicono
 * [campiObbligatori] e [campiFacoltativi], e il modulo mostra quelle e
 * nient'altro. ⛔ Un campo che si vede ma non conta è un campo che qualcuno
 * compila convinto di aver deciso qualcosa.
 */
enum TipoDiPremio: string
{
    // ── gettoni ──
    case GettoniRegalo = 'gettoni_regalo';
    case GettoniBonus = 'gettoni_bonus';
    case ScontoGettoni = 'sconto_gettoni';
    case PrezzoPacchetto = 'prezzo_pacchetto';
    case GettoniRicorrenti = 'gettoni_ricorrenti';

    // ── abbonamenti ──
    case ScontoAbbonamento = 'sconto_abbonamento';
    case ProvaGratuita = 'prova_gratuita';
    case AbbonamentoRegalato = 'abbonamento_regalato';
    case Upgrade = 'upgrade';
    case PrezzoBloccato = 'prezzo_bloccato';
    case PostiExtra = 'posti_extra';
    case ScontoConsumo = 'sconto_consumo';

    // ── tutti e due ──
    case GettoniAOgniRinnovo = 'gettoni_a_ogni_rinnovo';
    case SbloccaSconto = 'sblocca_sconto';

    public function label(): string
    {
        return match ($this) {
            self::GettoniRegalo => 'Gettoni in regalo',
            self::GettoniBonus => 'Bonus sull\'acquisto di gettoni',
            self::ScontoGettoni => 'Sconto sui pacchetti di gettoni',
            self::PrezzoPacchetto => 'Prezzo speciale su un pacchetto',
            self::GettoniRicorrenti => 'Gettoni ogni mese',
            self::ScontoAbbonamento => 'Sconto sull\'abbonamento',
            self::ProvaGratuita => 'Prova gratuita',
            self::AbbonamentoRegalato => 'Abbonamento regalato',
            self::Upgrade => 'Upgrade gratuito',
            self::PrezzoBloccato => 'Prezzo bloccato',
            self::PostiExtra => 'Posti extra',
            self::ScontoConsumo => 'Sconto sul piano a consumo',
            self::GettoniAOgniRinnovo => 'Gettoni a ogni rinnovo',
            self::SbloccaSconto => 'Sblocca uno sconto sull\'altro',
        };
    }

    public function descrizione(): string
    {
        return match ($this) {
            self::GettoniRegalo => 'N gettoni accreditati, senza comprare niente.',
            self::GettoniBonus => 'Chi compra un pacchetto ne riceve di più: +N fissi o +N%.',
            self::ScontoGettoni => 'Percentuale o importo in meno su tutti i pacchetti, o su uno solo.',
            self::PrezzoPacchetto => 'Un pacchetto preciso a un prezzo deciso da te.',
            self::GettoniRicorrenti => 'N gettoni al mese, per N mesi.',
            self::ScontoAbbonamento => 'Sul primo pagamento, per N rinnovi o per sempre.',
            self::ProvaGratuita => 'N giorni gratis, poi paga. La carta si lascia subito.',
            self::AbbonamentoRegalato => 'N periodi gratis, senza carta. Alla fine torna com\'era.',
            self::Upgrade => 'Paga il suo piano e ha quello superiore per N mesi.',
            self::PrezzoBloccato => 'Il prezzo di oggi resta anche se il listino sale.',
            self::PostiExtra => 'Palestre e trainer: +N posti per N mesi.',
            self::ScontoConsumo => 'Il piano a consumo costa meno a fine mese.',
            self::GettoniAOgniRinnovo => 'N gettoni ogni volta che l\'abbonamento si rinnova.',
            self::SbloccaSconto => 'Chi compra una cosa riceve uno sconto personale sull\'altra.',
        };
    }

    /**
     * In quali generi si può scegliere — tabella §4.2 del piano.
     *
     * @return list<GenereDiPromozione>
     */
    public function generi(): array
    {
        return match ($this) {
            self::GettoniRegalo, self::GettoniBonus, self::ScontoGettoni,
            self::PrezzoPacchetto, self::GettoniRicorrenti => [GenereDiPromozione::Gettoni, GenereDiPromozione::Entrambi],

            self::ScontoAbbonamento, self::ProvaGratuita, self::AbbonamentoRegalato, self::Upgrade,
            self::PrezzoBloccato, self::PostiExtra, self::ScontoConsumo => [GenereDiPromozione::Abbonamenti, GenereDiPromozione::Entrambi],

            self::GettoniAOgniRinnovo, self::SbloccaSconto => [GenereDiPromozione::Entrambi],
        };
    }

    /** Toglie soldi da un acquisto: passa da `LePromozioni::applica()`. */
    public function eUnoSconto(): bool
    {
        return in_array($this, [self::ScontoGettoni, self::PrezzoPacchetto, self::ScontoAbbonamento, self::ScontoConsumo], true);
    }

    /**
     * 🆕 P2 — **Si usa pagando qualcosa**: uno sconto, o un bonus sull'acquisto.
     *
     * 💡 Diverso da [eUnoSconto]: il bonus non toglie soldi, aggiunge gettoni —
     * ma vive nello stesso momento, il pagamento, e passa dagli stessi
     * `metadata` di Stripe.
     */
    public function siUsaAllAcquisto(): bool
    {
        return $this->eUnoSconto() || $this === self::GettoniBonus;
    }

    /** Si consegna senza acquisto: passa da `ConsegnaDeiPremi` (P2, P4). */
    public function eUnRegalo(): bool
    {
        return in_array($this, [
            self::GettoniRegalo, self::GettoniRicorrenti, self::AbbonamentoRegalato, self::Upgrade, self::PostiExtra,
        ], true);
    }

    /**
     * Su quale acquisto agisce — la domanda che fanno `Cassa` e `PianiInVendita`.
     *
     * `null` per i premi che non toccano un acquisto (i regali, lo sblocco).
     */
    public function suCosaVale(): ?SuCosaVale
    {
        return match ($this) {
            self::GettoniBonus, self::ScontoGettoni, self::PrezzoPacchetto => SuCosaVale::Gettoni,
            self::ScontoAbbonamento, self::ProvaGratuita, self::PrezzoBloccato, self::GettoniAOgniRinnovo => SuCosaVale::Canone,
            self::ScontoConsumo => SuCosaVale::Consumo,
            default => null,
        };
    }

    /**
     * Le colonne di `promozione_premi` che questo tipo **pretende**.
     *
     * @return list<string>
     */
    public function campiObbligatori(): array
    {
        return match ($this) {
            self::GettoniRegalo, self::GettoniAOgniRinnovo => ['gettoni'],
            self::GettoniBonus, self::PrezzoBloccato => [],
            self::ScontoGettoni, self::ScontoConsumo => ['tipo_sconto', 'valore'],
            self::PrezzoPacchetto => ['pacchetto_id', 'prezzo_cent'],
            self::GettoniRicorrenti => ['gettoni', 'volte'],
            self::ScontoAbbonamento => ['tipo_sconto', 'valore', 'durata'],
            self::ProvaGratuita => ['giorni'],
            self::AbbonamentoRegalato, self::Upgrade => ['plan_id', 'volte'],
            self::PostiExtra => ['posti', 'volte'],
            self::SbloccaSconto => ['sblocca_genere', 'tipo_sconto', 'valore'],
        };
    }

    /**
     * Le colonne che questo tipo **può** usare, oltre a quelle obbligatorie.
     *
     * @return list<string>
     */
    public function campiFacoltativi(): array
    {
        return match ($this) {
            self::GettoniRegalo, self::GettoniRicorrenti, self::SbloccaSconto => ['giorni'],
            // 💡 Il bonus è «+N fissi» **o** «+N%»: nessuno dei due è obbligatorio da solo, uno dei due sì.
            self::GettoniBonus => ['gettoni', 'valore', 'pacchetto_id', 'giorni'],
            self::ScontoGettoni => ['pacchetto_id'],
            self::ScontoAbbonamento => ['plan_id', 'volte'],
            self::ProvaGratuita, self::PrezzoBloccato => ['plan_id'],
            self::Upgrade => ['da_plan_id'],
            self::GettoniAOgniRinnovo => ['plan_id', 'giorni'],
            default => [],
        };
    }

    /** @return list<string> */
    public function campi(): array
    {
        return [...$this->campiObbligatori(), ...$this->campiFacoltativi()];
    }

    public function usa(string $campo): bool
    {
        return in_array($campo, $this->campi(), true);
    }

    public function pretende(string $campo): bool
    {
        return in_array($campo, $this->campiObbligatori(), true);
    }

    /**
     * 🚧 **Funziona già?** — i tipi si accendono fase per fase.
     *
     * ⛔ Un premio che si può scegliere ma non fa niente è la cosa peggiore che
     * questo modulo potrebbe fare: la promozione si salva, risulta attiva, e
     * nessuno riceve niente. Finché la sua fase non è chiusa, il tipo non
     * compare fra le scelte.
     */
    public function costruito(): bool
    {
        /*
         * ⚠️ `ScontoConsumo` **non** c'è, anche se F2 lo offriva: `AddebitoDelMese`
         * non ha mai letto le promozioni, e uno sconto «su consumo» si salvava
         * senza togliere niente. Arriva con P3.4.
         */
        return in_array($this, [
            self::ScontoGettoni, self::ScontoAbbonamento,
            // 🆕 P2 — i gettoni.
            self::GettoniRegalo, self::GettoniBonus, self::PrezzoPacchetto, self::GettoniRicorrenti,
        ], true);
    }

    /**
     * @param  ?string  $attuale  il tipo già salvato su un premio: resta sceglibile
     *                            anche se non è costruito, o la modifica non si salverebbe
     * @return array<string, string> i tipi di questo genere, già costruiti
     */
    public static function optionsPer(?GenereDiPromozione $genere, ?string $attuale = null): array
    {
        $out = [];

        foreach (self::cases() as $caso) {
            if ($genere !== null && ! in_array($genere, $caso->generi(), true)) {
                continue;
            }

            if ($caso->costruito()) {
                $out[$caso->value] = $caso->label();
            } elseif ($caso->value === $attuale) {
                $out[$caso->value] = $caso->label().' (in arrivo: per ora non fa niente)';
            }
        }

        return $out;
    }
}
