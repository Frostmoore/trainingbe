<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * Un alimento riconosciuto dal modello.
 *
 * 🚨 **`grams` arriva dal modello e vince sulla tabella di `FoodUnit`.** Un
 * cucchiaio d'olio pesa 14 g, uno di miele 21: la tabella deterministica ne
 * conosce uno solo perche' non sa di che alimento si parla, il modello si'.
 * Percio' qui i grammi sono un campo a se' e non una derivazione di qty × unit.
 *
 * ── 🚨 Volume, peso e base di calcolo sono TRE cose diverse ────────────────
 *
 * Fino al 12/08/2026 esisteva solo `grams`, e il conflitto era irrisolvibile.
 * La prova, presa dal diario del committente **in produzione**:
 *
 *     Succo: qty 521 ml · grams 547 · kcal 245,9
 *     45 kcal/100 ml applicati ai ML     -> 234 kcal
 *     45 kcal/100 ml applicati ai GRAMMI -> 246 kcal   <- era questo
 *
 * Il modello applicava il valore per 100 ml al **peso**. Le tabelle dei liquidi
 * sono quasi sempre per 100 ml, quindi l'errore vale il **5% su ogni bevanda**,
 * sempre nella stessa direzione, e nessuno se ne accorge mai.
 *
 * Da qui i tre campi separati: `ml` (volume consumato), `grams` (peso fisico),
 * `basis` (su quale dei due sono stati calcolati i nutrienti).
 */
final readonly class FoodItem
{
    /** Le basi di calcolo ammesse. */
    public const BASI = ['per_100g', 'per_100ml'];

    /**
     * Lo stato di cottura.
     *
     * 🚨 **E' la singola fonte di errore piu' grande del dominio**: 100 g di
     * pasta valgono ~350 kcal da cruda e ~150 da cotta. Prima l'interpretazione
     * restava implicita nei numeri e non era ne' visibile ne' verificabile.
     *
     * `ambiguo` non e' un ripiego: e' il segnale con cui l'app **chiede**.
     */
    public const STATI = ['crudo', 'cotto', 'non_applicabile', 'ambiguo'];

    public function __construct(
        public string $name,
        public ?float $qty,
        public ?string $unit,
        public ?float $grams,
        public ?float $kcal,
        public ?float $protein,
        public ?float $carbs,
        public ?float $fat,
        /**
         * I grammi di **alcol etilico**, che rendono 7 kcal l'uno.
         *
         * 🚨 Senza, ogni bevanda alcolica sembrerebbe incoerente: il vino ha ~4 g
         * di carboidrati e ~14 g di alcol per 150 ml, quindi Atwater senza
         * l'alcol prevede 16 kcal contro le ~125 vere.
         *
         * ⚠️ **Non ha una colonna nel database**: non entra in nessun totale e
         * nessuna schermata lo mostra. Sopravvive in `food_entries.ai_raw`.
         */
        public ?float $alcohol = null,
        /** Il volume in millilitri. `null` per i solidi. */
        public ?float $ml = null,
        /** `per_100g` oppure `per_100ml`: su cosa sono calcolati i nutrienti. */
        public ?string $basis = null,
        /** Vedi {@see self::STATI}. */
        public ?string $state = null,
        /**
         * La quantita' l'ha detta la persona, o l'ha stimata il modello?
         *
         * 💡 Serve all'interfaccia: **una quantita' dichiarata non si rimette in
         * discussione**. Chiedere «sei sicuro che fossero 100 g?» a chi ha appena
         * scritto «100 g» e' il modo piu' rapido per farlo smettere di scrivere
         * le quantita'.
         */
        public ?bool $declared = null,
        /** La marca, quando la persona l'ha nominata. */
        public ?string $brand = null,
        /** La gradazione usata per il calcolo dell'alcol, in percentuale in volume. */
        public ?float $abvPct = null,
        /**
         * La confidenza di **questa voce**.
         *
         * 🚨 «Il pasto ha confidenza 0.68» non serve a nessuno: serve sapere
         * **quale** ingrediente e' incerto, perche' e' quello da correggere.
         */
        public ?float $confidence = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $numero = static fn (string $chiave): ?float => isset($data[$chiave]) && is_numeric($data[$chiave])
            ? (float) $data[$chiave]
            : null;

        return new self(
            name: (string) ($data['name'] ?? 'Alimento'),
            qty: $numero('qty'),
            unit: isset($data['unit']) ? (string) $data['unit'] : null,
            grams: $numero('grams'),
            kcal: $numero('kcal'),
            // ⚠️ Si accettano anche i nomi con il suffisso `_g` dello schema del
            // modello: dentro casa i campi restano `protein`/`carbs`/`fat`,
            // perche' cosi' si chiamano le colonne e cosi' e' scritto ogni
            // `ai_raw` gia' in archivio.
            protein: $numero('protein') ?? $numero('protein_g'),
            carbs: $numero('carbs') ?? $numero('carbs_g'),
            fat: $numero('fat') ?? $numero('fat_g'),
            alcohol: $numero('alcohol') ?? $numero('alcohol_g'),
            ml: $numero('ml'),
            basis: isset($data['basis']) ? (string) $data['basis'] : null,
            state: isset($data['state']) ? (string) $data['state'] : null,
            declared: isset($data['declared']) ? (bool) $data['declared'] : null,
            brand: isset($data['brand']) && $data['brand'] !== '' ? (string) $data['brand'] : null,
            abvPct: $numero('abv_pct'),
            confidence: $numero('confidence'),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'brand' => $this->brand,
            'qty' => $this->qty,
            'unit' => $this->unit,
            'grams' => $this->grams,
            'ml' => $this->ml,
            'basis' => $this->basis,
            'state' => $this->state,
            'declared' => $this->declared,
            'kcal' => $this->kcal,
            'protein' => $this->protein,
            'carbs' => $this->carbs,
            'fat' => $this->fat,
            'alcohol' => $this->alcohol,
            'abv_pct' => $this->abvPct,
            'confidence' => $this->confidence,
        ];
    }

    /** Una copia con qualche campo diverso. Usata dalle correzioni del validatore. */
    public function con(
        ?float $kcal = null,
        ?float $alcohol = null,
        ?float $confidence = null,
    ): self {
        return new self(
            name: $this->name,
            qty: $this->qty,
            unit: $this->unit,
            grams: $this->grams,
            kcal: $kcal ?? $this->kcal,
            protein: $this->protein,
            carbs: $this->carbs,
            fat: $this->fat,
            alcohol: $alcohol ?? $this->alcohol,
            ml: $this->ml,
            basis: $this->basis,
            state: $this->state,
            declared: $this->declared,
            brand: $this->brand,
            abvPct: $this->abvPct,
            confidence: $confidence ?? $this->confidence,
        );
    }

    /** E' un liquido con un volume dichiarato? */
    public function eLiquido(): bool
    {
        return $this->ml !== null && $this->ml > 0;
    }

    /**
     * La quantita' su cui vanno letti i valori per 100.
     *
     * 🚨 Per un liquido con `basis = per_100ml` sono i **millilitri**; per tutto
     * il resto i grammi. E' la distinzione che valeva il 5% su ogni bevanda.
     */
    public function baseDiCalcolo(): ?float
    {
        return $this->basis === 'per_100ml' && $this->eLiquido()
            ? $this->ml
            : $this->grams;
    }
}
