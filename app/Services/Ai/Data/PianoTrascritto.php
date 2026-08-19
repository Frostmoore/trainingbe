<?php

declare(strict_types=1);

namespace App\Services\Ai\Data;

/**
 * Quello che l'AI **crede** di aver letto in un piano alimentare — N20.2.
 *
 * ── 🚨 Il nome dice apposta «trascritto» e non «piano» ─────────────────────
 *
 * Non e' un piano alimentare: e' una **trascrizione da controllare**. Il piano
 * lo ha fatto un professionista abilitato, ed e' nel PDF; questo e' il
 * tentativo di ricopiarlo in una struttura, e finche' qualcuno non lo ha
 * guardato riga per riga (N20.3) non vale niente.
 *
 * ⚠️ **Il rischio non e' che l'AI fallisca**: un fallimento si vede e si rifa'.
 * E' che riesca **a meta'**. «200 g» letti «20 g» non danno nessun errore:
 * producono un piano plausibile e sbagliato, che qualcuno seguira' per
 * settimane credendolo fedele all'originale.
 */
final readonly class PianoTrascritto
{
    /**
     * @param  array<int, array<string, mixed>>  $giorni
     * @param  array<int, string>  $dubbi
     */
    public function __construct(
        public string $nome,
        public array $giorni,
        public float $confidenza,
        public array $dubbi = [],
    ) {}

    /**
     * @param  array<string, mixed>  $dati
     */
    public static function daArray(array $dati): self
    {
        return new self(
            nome: (string) ($dati['nome'] ?? 'Piano importato'),
            giorni: array_values((array) ($dati['giorni'] ?? [])),
            confidenza: (float) ($dati['confidenza'] ?? 0.0),

            /*
             * 🚨 **I dubbi sono la parte piu' utile della risposta.**
             *
             * ⚠️ Un modello che dice «qui non ero sicuro» permette di portare
             * chi controlla **dritto** su quelle righe. Senza, la revisione e'
             * un elenco di trenta voci tutte uguali, e chi la fa si stanca alla
             * decima — proprio prima di arrivare a quella sbagliata.
             */
            dubbi: array_values(array_filter(
                array_map('strval', (array) ($dati['dubbi'] ?? [])),
                static fn (string $d): bool => trim($d) !== '',
            )),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function perLApp(): array
    {
        return [
            'nome' => $this->nome,
            'giorni' => $this->giorni,
            'confidenza' => $this->confidenza,
            'dubbi' => $this->dubbi,
        ];
    }

    /**
     * Quante righe di alimento ci sono in tutto.
     *
     * 💡 Serve a dire **prima** quanto sara' lunga la revisione: «ci sono 34
     * righe da controllare» e' un'informazione che cambia come qualcuno la
     * affronta.
     */
    public function quanteRighe(): int
    {
        $totale = 0;

        foreach ($this->giorni as $giorno) {
            foreach ((array) ($giorno['pasti'] ?? []) as $pasto) {
                $totale += count((array) ($pasto['alimenti'] ?? []));
            }
        }

        return $totale;
    }
}
