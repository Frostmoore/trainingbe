<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

use App\Services\Ai\Guardie\CoerenzaEnergetica;

/**
 * Cosa il modello ha visto in un piatto o letto in una frase.
 *
 * 🚨 **I totali NON arrivano dal modello: li calcola PHP.**
 *
 * Fino al 13/08/2026 lo schema chiedeva anche `totals`, e `normalizeTotals()` li
 * usava quando c'erano. Era un'occasione di errore regalata: chiedere a un
 * modello di sommare quattro numeri **dopo** avergli fatto fare tutta
 * l'inferenza aggiunge un modo di sbagliare e non aggiunge niente. Il backend le
 * somme non le sbaglia mai.
 *
 * ⚠️ E' anche la ragione per cui `totals` e' sparito dallo schema: un campo che
 * il modello puo' compilare e' un campo che qualcuno prima o poi legge.
 *
 * `confidence` non e' decorazione: sotto una soglia l'app deve chiedere conferma
 * invece di scrivere nel diario. Dal 12/08/2026 lo fa davvero — vedi
 * `ConfermaStimaSheet` lato app.
 */
final readonly class FoodEstimate
{
    /**
     * @param  list<FoodItem>  $items
     * @param  array{kcal: ?float, protein: ?float, carbs: ?float, fat: ?float}  $totals
     */
    public function __construct(
        public array $items,
        public array $totals,
        public float $confidence,
        public ?string $note = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $items = array_map(
            static fn (array $i): FoodItem => FoodItem::fromArray($i),
            array_values($data['items'] ?? []),
        );

        $stima = new self(
            items: $items,
            // 🚨 Sempre ricalcolati, anche se il modello li avesse mandati.
            totals: self::sommaDi($items),
            confidence: (float) ($data['confidence'] ?? 0.0),
            note: self::notaDi($data),
        );

        /*
         * 🚨 **La guardia si applica QUI e non nei provider.**
         *
         * `fromArray()` e' l'unica porta da cui passa ogni stima — Anthropic,
         * OpenAI, il doppio dei test e la conferma dall'app. Metterla nei
         * provider vorrebbe dire ripeterla in sei punti e dimenticarla nel
         * settimo: il fornitore aggiunto fra un anno nascerebbe **senza**
         * guardia, e funzionerebbe benissimo.
         */
        return CoerenzaEnergetica::correggi($stima);
    }

    /**
     * La nota del modello.
     *
     * 🚨 **Resta un campo libero, e non va ristretto a «segnala l'assenza di
     * cibo».** E' il campo che il 12/08/2026 ha spiegato perche' una cotoletta
     * fosse stimata male — *«non e' stato specificato se sono panate»* — mentre
     * la `confidence` diceva 0.85, cioe' «sicuro». Il numero mentiva, il testo
     * no.
     *
     * ⚠️ Una nota di soli spazi vale **assente**: mostrarla disegnerebbe un
     * riquadro d'avviso con dentro il nulla.
     *
     * @param  array<string, mixed>  $data
     */
    private static function notaDi(array $data): ?string
    {
        $nota = isset($data['note']) ? trim((string) $data['note']) : '';

        return $nota === '' ? null : $nota;
    }

    /**
     * La somma delle voci.
     *
     * 💡 Statica e pubblica perche' la usa anche `MealValidator` dopo aver
     * corretto una voce: due somme scritte in due punti sono due totali che
     * prima o poi divergono.
     *
     * @param  list<FoodItem>  $items
     * @return array{kcal: ?float, protein: ?float, carbs: ?float, fat: ?float}
     */
    public static function sommaDi(array $items): array
    {
        $somma = ['kcal' => 0.0, 'protein' => 0.0, 'carbs' => 0.0, 'fat' => 0.0];

        foreach ($items as $i) {
            $somma['kcal'] += $i->kcal ?? 0;
            $somma['protein'] += $i->protein ?? 0;
            $somma['carbs'] += $i->carbs ?? 0;
            $somma['fat'] += $i->fat ?? 0;
        }

        return array_map(static fn (float $n): float => round($n, 2), $somma);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'items' => array_map(static fn (FoodItem $i): array => $i->toArray(), $this->items),
            'totals' => $this->totals,
            'confidence' => $this->confidence,
            'note' => $this->note,
        ];
    }
}
