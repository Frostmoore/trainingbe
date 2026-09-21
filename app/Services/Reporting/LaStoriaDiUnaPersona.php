<?php

declare(strict_types=1);

namespace App\Services\Reporting;

use App\Models\AiCreditMovement;
use App\Models\AiUsageLog;
use App\Models\PagamentoIncassato;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;

/**
 * 📊 **I tre conti nel tempo di una persona** — 22/09/2026, fase F4.3.
 *
 * 📌 *«grafici: acquisti, gettoni spesi (granularità scelta), chiamate
 * automatiche consumate»*.
 *
 * ══ 🚨 PERCHÉ È UN SERVIZIO E NON TRE QUERY DENTRO IL WIDGET ═════════════
 *
 * Perché le stesse tre domande tornano **su altre tre pagine**: la palestra
 * vuole i grafici dei suoi iscritti (F5.7), e il trainer quelli dei suoi.
 * ⛔ Tre copie della stessa query, con tre `GROUP BY` scritti a mano in tre
 * momenti diversi, diventano tre numeri diversi per la stessa domanda — e il
 * bello è che nessuno dei tre dà errore.
 *
 * ══ ⚠️ I BUCHI SI RIEMPIONO, E NON È ESTETICA ════════════════════════════
 *
 * Un `GROUP BY` restituisce **solo i periodi in cui è successo qualcosa**. ⛔
 * Su un grafico a barre, tre acquisti fatti a gennaio, marzo e settembre
 * diventano tre colonne **adiacenti**: sembra che abbia comprato tre volte di
 * fila. 💡 Per questo ogni serie parte da uno scheletro di periodi a zero, che
 * poi si riempie.
 *
 * ══ 🚨 E IL PERIODO LO DECIDE IL SERVER, NON IL DATABASE ═════════════════
 *
 * `DATE_FORMAT` è MySQL. ⛔ Le settimane però non sono uguali ovunque —
 * `%v` dipende dalla modalità del server, e un lunedì che diventa domenica
 * sposta i dati di un giorno senza dirlo. ✅ Qui la chiave si costruisce in
 * PHP con lo stesso `Carbon` che genera lo scheletro: se sbagliasse, la serie
 * risulterebbe **vuota** invece che sbagliata di un giorno — un errore che si
 * vede subito, non uno che si scopre fra sei mesi.
 */
class LaStoriaDiUnaPersona
{
    /** Quanti periodi indietro si guarda, passo per passo. */
    public const QUANTI = [
        'giorno' => 30,
        'settimana' => 26,
        'mese' => 12,
    ];

    public function __construct(private readonly TenantContext $context) {}

    /**
     * 💶 Quanto ha pagato, in **centesimi**, periodo per periodo.
     *
     * @return array<string, int>
     */
    public function acquisti(User $utente, string $passo = 'mese'): array
    {
        return $this->context->runWithoutTenant(fn (): array => $this->serie(
            PagamentoIncassato::query()
                ->where('user_id', $utente->getKey())
                ->toBase(),
            'pagato_il',
            'importo_cent',
            $passo,
        ));
    }

    /**
     * 🎟️ Quanti gettoni ha **bruciato**, periodo per periodo.
     *
     * ⚠️ Solo i movimenti negativi, e col segno girato: `delta` è firmato, e
     * un grafico con le barre sotto lo zero risponderebbe alla domanda
     * «quanto ha speso» con un numero negativo.
     *
     * @return array<string, int>
     */
    public function gettoniSpesi(User $utente, string $passo = 'mese'): array
    {
        $serie = $this->serie(
            AiCreditMovement::query()
                ->withoutGlobalScopes([TenantScope::class])
                ->where('user_id', $utente->getKey())
                ->where('delta', '<', 0)
                ->toBase(),
            'created_at',
            'delta',
            $passo,
        );

        return array_map(static fn (int $v): int => abs($v), $serie);
    }

    /**
     * 🤖 Quante chiamate al modello ha consumato, periodo per periodo.
     *
     * @return array<string, int>
     */
    public function chiamate(User $utente, string $passo = 'mese'): array
    {
        return $this->serie(
            AiUsageLog::query()
                ->withoutGlobalScopes([TenantScope::class])
                ->where('user_id', $utente->getKey())
                ->toBase(),
            'created_at',
            null,
            $passo,
        );
    }

    /**
     * Come si scrive un periodo a chi legge il grafico.
     *
     * 💡 La settimana si scrive col **giorno d'inizio**: «sett. 14/09» si
     * colloca da sola, «2026-38» richiede un calendario.
     */
    public static function etichetta(string $chiave, string $passo): string
    {
        $quando = Carbon::parse($chiave);

        return match ($passo) {
            'giorno' => $quando->format('d/m'),
            'settimana' => 'sett. '.$quando->format('d/m'),
            default => $quando->locale('it')->isoFormat('MMM YY'),
        };
    }

    // ───────────────────────── il motore ─────────────────────────

    /**
     * Somma (o conta) una tabella per periodo, **buchi compresi**.
     *
     * @param  QueryBuilder  $query  già filtrata sulla persona
     * @param  string  $quando  la colonna della data
     * @param  ?string  $cosa  la colonna da sommare, `null` per contare le righe
     * @return array<string, int>
     */
    private function serie(QueryBuilder $query, string $quando, ?string $cosa, string $passo): array
    {
        $scheletro = $this->scheletro($passo);
        $da = Carbon::parse((string) array_key_first($scheletro));

        $righe = $query
            ->where($quando, '>=', $da)
            ->get([$quando, ...($cosa === null ? [] : [$cosa])]);

        foreach ($righe as $riga) {
            $chiave = $this->chiave(Carbon::parse($riga->{$quando}), $passo);

            if (! array_key_exists($chiave, $scheletro)) {
                continue;
            }

            $scheletro[$chiave] += $cosa === null ? 1 : (int) $riga->{$cosa};
        }

        return $scheletro;
    }

    /**
     * I periodi da mostrare, tutti a zero.
     *
     * @return array<string, int>
     */
    private function scheletro(string $passo): array
    {
        $quanti = self::QUANTI[$passo] ?? self::QUANTI['mese'];

        $cursore = $this->inizioDi(Carbon::now(), $passo);
        $periodi = [];

        for ($i = 0; $i < $quanti; $i++) {
            $periodi[$this->chiave($cursore, $passo)] = 0;

            $cursore = match ($passo) {
                'giorno' => $cursore->copy()->subDay(),
                'settimana' => $cursore->copy()->subWeek(),
                default => $cursore->copy()->subMonth(),
            };
        }

        return array_reverse($periodi, preserve_keys: true);
    }

    private function inizioDi(Carbon $quando, string $passo): Carbon
    {
        return match ($passo) {
            'giorno' => $quando->copy()->startOfDay(),
            'settimana' => $quando->copy()->startOfWeek(),
            default => $quando->copy()->startOfMonth(),
        };
    }

    private function chiave(Carbon $quando, string $passo): string
    {
        return $this->inizioDi($quando, $passo)->format('Y-m-d');
    }
}
