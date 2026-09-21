<?php

declare(strict_types=1);

namespace App\Services\Fatturazione;

use App\Enums\PlanKind;
use App\Enums\TenantKind;
use App\Models\Fattura;
use App\Models\PagamentoIncassato;
use App\Models\Plan;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 🧾 **Dall'incasso alla fattura** — fase F8, 23/09/2026.
 *
 * ══ 📌 COSA SI FATTURA, E A CHI ═════════════════════════════════════════
 *
 * 📌 *«ogni acquisto delle palestre - incluso l'abbonamento ogni mese - deve
 * generare una vera fattura»*. 🚨 **Delle palestre**: il registro degli incassi
 * mette sotto il tenant della palestra anche soldi che la palestra **non ha
 * speso** — il Plus personale di un suo iscritto, i gettoni che un iscritto
 * compra per sé. Fatturarli alla palestra vorrebbe dire intestarle un acquisto
 * non suo. Le regole stanno tutte in `laPagaLaPalestra()`.
 *
 * ══ 🔢 LA NUMERAZIONE ════════════════════════════════════════════════════
 *
 * Progressiva **per anno e per sezionale**, senza buchi e senza doppioni:
 * - il numero si assegna **solo all'emissione** (una fattura in attesa non ne
 *   ha: un numero a una fattura che non esiste lascerebbe un buco);
 * - si legge il massimo **sotto lock** (`lockForUpdate`), in transazione;
 * - e il vincolo unico `(anno, sezionale, numero)` è la seconda serratura: se
 *   due emissioni si incrociano comunque, la seconda riprova.
 *
 * ══ ⛔ NON DEVE MAI FAR FALLIRE UN PAGAMENTO ═════════════════════════════
 *
 * Come il registro: si chiama da un job in coda (`EmettiLaFattura`), mai dal
 * webhook di Stripe direttamente.
 */
class EmissioneDelleFatture
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly FatturaXml $xml,
    ) {}

    /**
     * La fattura di un incasso: creata, ed emessa se si può.
     *
     * 💡 **Idempotente**: un incasso ha al massimo una fattura, e chiamarla due
     * volte (i webhook arrivano due volte) restituisce la stessa.
     *
     * @return Fattura|null `null` se questo incasso non si fattura
     */
    public function perIncasso(PagamentoIncassato $pagamento): ?Fattura
    {
        return $this->context->runWithoutTenant(function () use ($pagamento): ?Fattura {
            $esistente = Fattura::query()->where('pagamento_incassato_id', $pagamento->getKey())->first();

            if ($esistente !== null) {
                return $esistente;
            }

            // Un pagamento di zero (una promozione al 100%) non è una vendita da fatturare.
            if ($pagamento->importo_cent <= 0 || ! $this->laPagaLaPalestra($pagamento)) {
                return null;
            }

            try {
                $fattura = Fattura::query()->create([
                    'tenant_id' => $pagamento->tenant_id,
                    'pagamento_incassato_id' => $pagamento->getKey(),
                    'stato' => Fattura::IN_ATTESA,
                    'descrizione' => $this->descrizioneDi($pagamento),
                    'imponibile_cent' => $pagamento->importo_cent,
                    'bollo_cent' => self::bolloPer($pagamento->importo_cent),
                    'totale_cent' => $pagamento->importo_cent,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Due job sullo stesso incasso: ha vinto l'altro.
                return Fattura::query()->where('pagamento_incassato_id', $pagamento->getKey())->first();
            }

            $palestra = Tenant::withoutGlobalScopes()->find($pagamento->tenant_id);

            if ($palestra !== null && $palestra->datiDiFatturazioneMancanti() === []) {
                return $this->emetti($fattura);
            }

            return $fattura;
        });
    }

    /**
     * 🚨 **La palestra ha pagato questo incasso?** L'unica porta della domanda.
     *
     * | Incasso | Si fattura alla palestra se |
     * |---|---|
     * | canone | il piano è **da palestra** (⛔ non il Plus personale di un iscritto) |
     * | consumo | sempre: l'addebito a consumo è solo dei piani delle palestre |
     * | gettoni | li ha comprati **il titolare** della palestra (per lei o in regalo a un iscritto) |
     *
     * E in ogni caso il tenant dev'essere una **palestra**: lo spazio personale
     * di un trainer non è un cliente da fatturare qui (debito dichiarato: le
     * fatture ai trainer e ai privati non esistono ancora).
     */
    public function laPagaLaPalestra(PagamentoIncassato $pagamento): bool
    {
        $palestra = Tenant::withoutGlobalScopes()->find($pagamento->tenant_id);

        if ($palestra === null || $palestra->kind !== TenantKind::Gym) {
            return false;
        }

        return match ($pagamento->tipo) {
            PagamentoIncassato::CONSUMO => true,
            PagamentoIncassato::CANONE => Plan::query()->where('code', (string) $pagamento->cosa)->first()?->kind === PlanKind::Gym,
            PagamentoIncassato::GETTONI => $this->haPagatoIlTitolare($pagamento, $palestra),
            default => false,
        };
    }

    /**
     * Emette una fattura in attesa: numero, data, cliente fotografato, XML.
     *
     * @throws RuntimeException se alla palestra mancano i dati per fatturare
     */
    public function emetti(Fattura $fattura): Fattura
    {
        if ($fattura->eEmessa()) {
            return $fattura;
        }

        $palestra = Tenant::withoutGlobalScopes()->find($fattura->tenant_id);

        if ($palestra === null) {
            throw new RuntimeException('La palestra di questa fattura non esiste più.');
        }

        $mancano = $palestra->datiDiFatturazioneMancanti();

        if ($mancano !== []) {
            throw new RuntimeException('Mancano '.implode(', ', $mancano).'.');
        }

        // Una riprova sola: il vincolo unico scatta solo se due emissioni si incrociano.
        for ($tentativo = 1; ; $tentativo++) {
            try {
                return $this->emettiInTransazione($fattura, $palestra);
            } catch (UniqueConstraintViolationException $e) {
                if ($tentativo >= 2) {
                    throw $e;
                }

                $fattura->refresh();
            }
        }
    }

    /**
     * Emette tutte le fatture in attesa di una palestra.
     *
     * 💡 La chiamano le impostazioni della palestra quando salva i dati per le
     * fatture: il pagamento di ieri riceve la sua fattura oggi, senza che
     * nessuno se ne ricordi.
     *
     * @return int quante ne ha emesse
     */
    public function emettiQuelleInAttesa(Tenant $palestra): int
    {
        if ($palestra->datiDiFatturazioneMancanti() !== []) {
            return 0;
        }

        return $this->context->runWithoutTenant(function () use ($palestra): int {
            $emesse = 0;

            Fattura::query()
                ->where('tenant_id', $palestra->getKey())
                ->inAttesa()
                ->orderBy('id')
                ->each(function (Fattura $f) use (&$emesse): void {
                    $this->emetti($f);
                    $emesse++;
                });

            return $emesse;
        });
    }

    /**
     * Il cliente, **fotografato**: i campi del `CessionarioCommittente`.
     *
     * @return array<string, string|null>
     */
    public function cessionarioDi(Tenant $palestra): array
    {
        return [
            'ragione_sociale' => $palestra->fatt_ragione_sociale,
            'partita_iva' => $palestra->fatt_partita_iva,
            'codice_fiscale' => $palestra->fatt_codice_fiscale,
            'indirizzo' => $palestra->fatt_indirizzo,
            'cap' => $palestra->fatt_cap,
            'comune' => $palestra->fatt_comune,
            'provincia' => $palestra->fatt_provincia,
            'nazione' => mb_strtoupper((string) ($palestra->fatt_nazione ?: 'IT')),
            'codice_sdi' => $palestra->fatt_codice_sdi,
            'pec' => $palestra->fatt_pec,
        ];
    }

    /** 2 € sopra 77,47 €, zero sotto. */
    public static function bolloPer(int $imponibileCent): int
    {
        return $imponibileCent > (int) config('fatture.bollo.soglia_cent')
            ? (int) config('fatture.bollo.importo_cent')
            : 0;
    }

    /**
     * Il progressivo d'invio: cinque caratteri, dall'id della fattura.
     *
     * 💡 In base 36 e maiuscolo: unico per costruzione (è l'id), e sta nei
     * cinque caratteri che il nome del file SDI concede fino a 60 milioni di
     * fatture.
     */
    public static function progressivoPer(int $id): string
    {
        return str_pad(strtoupper(base_convert((string) $id, 10, 36)), 5, '0', STR_PAD_LEFT);
    }

    // ───────────────────────── dentro ─────────────────────────

    private function emettiInTransazione(Fattura $fattura, Tenant $palestra): Fattura
    {
        return DB::transaction(function () use ($fattura, $palestra): Fattura {
            $oggi = Carbon::today();
            $anno = (int) $oggi->year;
            $sezionale = (string) config('fatture.sezionale');

            $ultimo = (int) Fattura::withoutGlobalScopes()
                ->where('anno', $anno)
                ->where('sezionale', $sezionale)
                ->lockForUpdate()
                ->max('numero');

            $numero = max($ultimo + 1, (int) config('fatture.primo_numero'));

            $fattura->forceFill([
                'stato' => Fattura::EMESSA,
                'anno' => $anno,
                'numero' => $numero,
                'sezionale' => $sezionale,
                'data' => $oggi,
                'cessionario' => $this->cessionarioDi($palestra),
                'progressivo_invio' => self::progressivoPer((int) $fattura->getKey()),
                'emessa_il' => Carbon::now(),
            ]);

            // L'XML si scrive con i dati appena fissati, e si fissa anche lui.
            $fattura->xml = $this->xml->genera($fattura);
            $fattura->save();

            return $fattura;
        });
    }

    private function haPagatoIlTitolare(PagamentoIncassato $pagamento, Tenant $palestra): bool
    {
        $chi = $pagamento->user_id === null ? null : User::withoutGlobalScopes()->find($pagamento->user_id);

        return $chi !== null && $palestra->eDi($chi);
    }

    /**
     * La riga della fattura, in parole.
     *
     * ⛔ **Mai il nome di un iscritto**: i gettoni regalati a un allievo dicono
     * «per un iscritto». La fattura va al commercialista della palestra, e il
     * nome di chi si allena lì non gli serve.
     */
    private function descrizioneDi(PagamentoIncassato $p): string
    {
        $quando = ($p->pagato_il ?? Carbon::now())->locale('it');

        $testo = match ($p->tipo) {
            PagamentoIncassato::CANONE => sprintf(
                'Training Companion - abbonamento mensile al piano %s (%s)',
                Plan::query()->where('code', (string) $p->cosa)->value('name') ?? (string) $p->cosa,
                $quando->translatedFormat('F Y'),
            ),
            PagamentoIncassato::CONSUMO => sprintf(
                'Training Companion - piano a consumo, iscritti del mese di %s',
                $this->meseDelConsumo((string) $p->cosa) ?? $quando->translatedFormat('F Y'),
            ),
            PagamentoIncassato::GETTONI => sprintf(
                'Training Companion - pacchetto di %s gettoni per le funzioni AI%s',
                preg_match('/^(\d+)/', (string) $p->cosa, $m) === 1 ? $m[1] : '',
                str_contains((string) $p->cosa, ' per ') ? ', regalati a un iscritto' : '',
            ),
            default => 'Training Companion - '.(string) $p->cosa,
        };

        if ($p->sconto_cent > 0) {
            $testo .= sprintf(' - sconto promozionale di %s EUR già applicato', number_format($p->sconto_cent / 100, 2, ',', '.'));
        }

        return mb_substr($testo, 0, 500);
    }

    /** `consumo 2026-08` → «agosto 2026». */
    private function meseDelConsumo(string $cosa): ?string
    {
        if (preg_match('/(\d{4})-(\d{2})/', $cosa, $m) !== 1) {
            return null;
        }

        return Carbon::create((int) $m[1], (int) $m[2], 1)->locale('it')->translatedFormat('F Y');
    }
}
