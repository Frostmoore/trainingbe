<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\SuCosaVale;
use App\Enums\TipoBersaglio;
use App\Models\Promozione;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🎁 **Chi ha diritto a quale promozione, e quanto si sconta** — 22/09/2026,
 * fase F2 di `plan_UX_pannello.md`.
 *
 * ══ 📌 COME SONO STATE CHIESTE ════════════════════════════════════════════
 *
 * > *«promozioni e/o sconti attivabili con link o in automatico (per uno o più
 * > utenti, per uno o più ruoli, per una o più palestre) … Valgono su tutto,
 * > devo poter scegliere se farle cumulabili o no»*
 *
 * ══ 🚨 PERCHÉ IL CONTO STA QUI E NON NELLA PAGINA CHE VENDE ══════════════
 *
 * Perché i posti che vendono sono **tre** — `Cassa` (abbonamento personale e
 * gettoni), `PianiInVendita` (piani trainer e palestra), `AddebitoDelMese` (il
 * consumo a mese chiuso) — e uno sconto calcolato in tre modi diversi è uno
 * sconto che un giorno regala il doppio in uno dei tre.
 *
 * ══ ⚠️ LE TRE REGOLE DEL CONTO, E PERCHÉ ═════════════════════════════════
 *
 * 1. **Non cumulabili → vince la migliore.** ⛔ Non la prima trovata: chi
 *    riceve due offerte si aspetta che gli si applichi quella che conviene a
 *    lui, e il contrario si legge come una fregatura.
 * 2. **Cumulabili → una dopo l'altra, non sommate.** 🚨 30% + 20% fa **44%**,
 *    non 50: si sconta il 30, e il 20 si applica a quel che resta. Sommare le
 *    percentuali è il modo classico di regalare il 6% senza accorgersene.
 * 3. **Non si scende mai sotto zero.** ⛔ Uno sconto a importo più grande del
 *    prezzo non regala la differenza.
 */
class LePromozioni
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Le promozioni **automatiche** che questa persona ha, su questo acquisto.
     *
     * ⚠️ Quelle `link` non compaiono: quelle si spendono solo aprendo
     * l'indirizzo che le porta, ed è il senso di averle divise.
     *
     * @return Collection<int, Promozione>
     */
    public function automaticheDi(User $utente, SuCosaVale $cosa): Collection
    {
        return $this->spendibiliDi($utente, $cosa)
            ->filter(static fn (Promozione $p): bool => $p->attivazione === 'automatica')
            ->values();
    }

    /**
     * Una promozione presa dal suo codice, se questa persona può usarla.
     *
     * 🚨 **Il codice non basta**: dice *quale* offerta, non *a chi* spetta. ⛔
     * Senza il controllo sui bersagli, un codice inoltrato varrebbe per
     * chiunque — e una promozione «per gli allievi della palestra Tal dei
     * Tali» finirebbe su mezza piattaforma.
     */
    public function conIlCodice(string $codice, User $utente, SuCosaVale $cosa): ?Promozione
    {
        $promozione = Promozione::query()
            ->with('bersagli')
            ->where('codice', mb_strtoupper(trim($codice)))
            ->first();

        if ($promozione === null || ! $promozione->eSpendibile() || ! $promozione->valeSu($cosa)) {
            return null;
        }

        return $this->loPrende($promozione, $utente) ? $promozione : null;
    }

    /**
     * 💰 **Il conto**: quanto si paga davvero, e con quali promozioni.
     *
     * @param  iterable<Promozione>  $promozioni
     * @return array{finale: int, sconto: int, applicate: list<Promozione>}
     */
    public function applica(int $importoCent, iterable $promozioni): array
    {
        $tutte = collect($promozioni)->values();

        if ($tutte->isEmpty() || $importoCent <= 0) {
            return ['finale' => max(0, $importoCent), 'sconto' => 0, 'applicate' => []];
        }

        $cumulabili = $tutte->filter(static fn (Promozione $p): bool => $p->cumulabile);
        $esclusive = $tutte->reject(static fn (Promozione $p): bool => $p->cumulabile);

        /*
         * 🚨 **Fra le non cumulabili vince la migliore**, e si guarda quanto
         * toglie **su questo importo**: una percentuale e un importo fisso non
         * si confrontano in astratto — il 10% è meglio di 5 € sopra i 50 €, ed
         * è peggio sotto.
         */
        $migliore = $esclusive
            ->sortByDesc(static fn (Promozione $p): int => $p->scontoSu($importoCent))
            ->first();

        /*
         * ⚠️ **Una esclusiva esclude anche le cumulabili**: «non cumulabile»
         * vuol dire «da sola», non «da sola fra le sue pari». 💡 Quindi si
         * sceglie fra due strade e si tiene quella che conviene al cliente.
         */
        $conEsclusiva = $migliore === null
            ? null
            : $this->incolonna($importoCent, collect([$migliore]));

        $conCumulabili = $cumulabili->isEmpty()
            ? null
            : $this->incolonna($importoCent, $cumulabili->sortByDesc(
                static fn (Promozione $p): int => $p->scontoSu($importoCent),
            ));

        return collect([$conEsclusiva, $conCumulabili])
            ->filter()
            ->sortByDesc('sconto')
            ->first() ?? ['finale' => $importoCent, 'sconto' => 0, 'applicate' => []];
    }

    /**
     * Le promozioni in fila, **una sull'importo che resta**.
     *
     * @param  Collection<int, Promozione>  $promozioni
     * @return array{finale: int, sconto: int, applicate: list<Promozione>}
     */
    private function incolonna(int $importoCent, Collection $promozioni): array
    {
        $resta = $importoCent;
        $applicate = [];

        foreach ($promozioni as $promozione) {
            $sconto = $promozione->scontoSu($resta);

            if ($sconto <= 0) {
                continue;
            }

            $resta -= $sconto;
            $applicate[] = $promozione;

            // 💡 Arrivati a zero non si continua: le altre non toglierebbero
            // niente, e comparirebbero in ricevuta come se avessero fatto
            // qualcosa.
            if ($resta <= 0) {
                break;
            }
        }

        return [
            'finale' => max(0, $resta),
            'sconto' => $importoCent - max(0, $resta),
            'applicate' => $applicate,
        ];
    }

    /**
     * Tutte le spendibili che prendono questa persona su questo acquisto.
     *
     * @return Collection<int, Promozione>
     */
    private function spendibiliDi(User $utente, SuCosaVale $cosa): Collection
    {
        return Promozione::query()
            ->spendibili()
            ->with('bersagli')
            ->get()
            ->filter(fn (Promozione $p): bool => $p->valeSu($cosa) && $this->loPrende($p, $utente))
            ->values();
    }

    /**
     * 🎯 **Questa persona rientra in almeno un bersaglio?**
     *
     * ⚠️ I bersagli sono in **OR**: «per i trainer e per gli allievi di Tizio»
     * vuol dire «chi è trainer **oppure** allievo di Tizio». ⛔ In AND sarebbe
     * «i trainer che sono anche allievi di Tizio», cioè quasi nessuno.
     */
    public function loPrende(Promozione $promozione, User $utente): bool
    {
        foreach ($promozione->bersagli as $bersaglio) {
            $preso = match ($bersaglio->tipo) {
                TipoBersaglio::Tutti => true,

                TipoBersaglio::Utente => $bersaglio->user_id === $utente->getKey(),

                /*
                 * ⚠️ La palestra è quella **in cui la persona sta**, non quella
                 * che possiede: un trainer con un portfolio suo lavora in una
                 * palestra senza avere lì il proprio `tenant_id`, e il
                 * conteggio dei ruoli è il posto in cui quel legame è scritto.
                 */
                TipoBersaglio::Palestra => $bersaglio->tenant_id !== null
                    && $this->staNellaPalestra($utente, (int) $bersaglio->tenant_id),

                TipoBersaglio::Ruolo => $bersaglio->ruolo !== null
                    && $this->haIlRuolo($utente, $bersaglio->ruolo),

                TipoBersaglio::AllieviDiTrainer => $bersaglio->user_id !== null
                    && $this->eAllievoDi($utente, (int) $bersaglio->user_id),
            };

            if ($preso) {
                return true;
            }
        }

        return false;
    }

    /**
     * 🚨 Si guarda `model_has_roles`, non `users.tenant_id`.
     *
     * ⛔ Con la colonna, un trainer che lavora in una palestra ma ha casa
     * altrove risulterebbe fuori — cioè escluso proprio dalla promozione che
     * la sua palestra gli sta facendo.
     */
    private function staNellaPalestra(User $utente, int $tenantId): bool
    {
        if ($utente->tenant_id === $tenantId) {
            return true;
        }

        return $this->context->runWithoutTenant(fn (): bool => DB::table('model_has_roles')
            ->where('model_type', $utente->getMorphClass())
            ->where('model_id', $utente->getKey())
            ->where('tenant_id', $tenantId)
            ->exists());
    }

    private function haIlRuolo(User $utente, string $ruolo): bool
    {
        return $this->context->runWithoutTenant(fn (): bool => DB::table('model_has_roles as mhr')
            ->join('roles as r', function ($join): void {
                $join->on('r.id', '=', 'mhr.role_id')->on('r.tenant_id', '=', 'mhr.tenant_id');
            })
            ->where('mhr.model_type', $utente->getMorphClass())
            ->where('mhr.model_id', $utente->getKey())
            ->where('r.name', $ruolo)
            ->exists());
    }

    /**
     * ⚠️ «Allievo di» è il rapporto di `trainer_member`, quello dei trainer
     * indipendenti: dentro una palestra i trainer seguono tutti, e un bersaglio
     * «allievi di» lì dentro non vorrebbe dire niente.
     */
    private function eAllievoDi(User $utente, int $trainerId): bool
    {
        return $this->context->runWithoutTenant(fn (): bool => DB::table('trainer_member')
            ->where('trainer_id', $trainerId)
            ->where('member_id', $utente->getKey())
            ->exists());
    }

    /**
     * 🚨 **Si segna usata quando i soldi sono arrivati**, non quando qualcuno
     * apre il link.
     *
     * ⛔ Un contatore che scende guardando una pagina è un'offerta che si
     * esaurisce senza vendere niente.
     */
    public function segnaUsata(Promozione $promozione): void
    {
        $promozione->increment('usata_volte');
    }
}
