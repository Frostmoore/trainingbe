<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\ComeSiAttiva;
use App\Enums\OrigineAssegnazione;
use App\Enums\StatoAssegnazione;
use App\Enums\SuCosaVale;
use App\Enums\TipoDiPremio;
use App\Models\Promozione;
use App\Models\PromozioneAssegnazione;
use App\Models\PromozionePremio;
use App\Models\User;
use App\Services\Promozioni\ChiLaRiceve;
use Illuminate\Database\UniqueConstraintViolationException;
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
 *
 * ══ 🆕 P1 di `plan_promozioni.md` — 24/09/2026 ════════════════════════════
 *
 * 4. **Lo sconto lo danno i premi**, non la riga: `applica()` chiede su cosa si
 *    sta comprando e quale pacchetto o piano, perché un premio può valere su
 *    un pacchetto solo.
 * 5. **La priorità viene prima della convenienza** fra le non cumulabili:
 *    📌 *«Cumulabile sì/no, e quale vince se ce ne sono più di una»*. A parità
 *    di priorità vince ancora quella che conviene al cliente.
 * 6. **Il massimo per persona e il budget** tengono fuori chi l'ha già presa e
 *    le promozioni che hanno finito i soldi.
 * 7. **L'uso si segna al webhook** (`segnaUsate()`), una volta sola per
 *    sessione — ⛔ fino al 23/09 non lo segnava nessuno, e `massimo_usi` non
 *    fermava niente.
 */
class LePromozioni
{
    public function __construct(private readonly ChiLaRiceve $chiLaRiceve) {}

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
        /*
         * 🆕 P6 — **e quelle che ha già in tasca**: un premio d'acquisto in
         * sospeso (regalo riscattato col link, sconto sbloccato da un evento)
         * vale al pagamento **senza** ripresentare il codice. ⛔ Altrimenti chi
         * ha guadagnato uno sconto lo perderebbe al primo acquisto fatto
         * dall'app, dove il codice non si scrive.
         */
        return $this->spendibiliDi($utente, $cosa)
            ->filter(static fn (Promozione $p): bool => $p->attivazione === ComeSiAttiva::Automatica
                || $p->haUnAcquistoInSospeso($utente) !== null)
            ->values();
    }

    /**
     * La promozione di un **link**, se questa persona può averla.
     *
     * ⛔ Non è più un «codice sconto» da scrivere (24/09/2026): il codice sta
     * dentro il link, e il link si **apre**. 🚨 E il codice da solo non basta:
     * dice *quale* offerta, non *a chi* spetta — senza il controllo sui
     * bersagli un link inoltrato varrebbe per chiunque.
     *
     * `$cosa` `null` = «su qualunque cosa»: la pagina dell'offerta la spiega,
     * non vende niente.
     */
    public function dalLink(string $codice, User $utente, ?SuCosaVale $cosa = null): ?Promozione
    {
        $promozione = Promozione::query()
            ->with(['bersagli', 'premi'])
            ->where('codice', mb_strtoupper(trim($codice)))
            ->first();

        if ($promozione === null || ! $promozione->eSpendibile()) {
            return null;
        }

        if ($cosa !== null && ! $promozione->valeSu($cosa)) {
            return null;
        }

        /*
         * 🆕 P2 — pagando conta anche un premio d'acquisto **in sospeso**
         * (regalo già dato, sconto ancora da usare).
         */
        $puo = $cosa === null
            ? $promozione->laPuoPrendereAncora($utente) || $promozione->haUnAcquistoInSospeso($utente) !== null
            : $promozione->laPuoUsareAllAcquisto($utente);

        return $this->loPrende($promozione, $utente) && $puo ? $promozione : null;
    }

    /**
     * 💰 **Il conto**: quanto si paga davvero, e con quali promozioni.
     *
     * 🆕 P1 — `$cosa`, `$pacchettoId` e `$planId` dicono **cosa** si sta
     * comprando: i premi di sconto valgono su un genere di acquisto, e alcuni
     * su un pacchetto o un piano solo.
     *
     * @param  iterable<Promozione>  $promozioni
     * @return array{finale: int, sconto: int, applicate: list<Promozione>}
     */
    public function applica(
        int $importoCent,
        iterable $promozioni,
        SuCosaVale $cosa,
        ?int $pacchettoId = null,
        ?int $planId = null,
    ): array {
        $sconto = static fn (Promozione $p, int $su): int => $p->scontoSu($su, $cosa, $pacchettoId, $planId);

        $tutte = collect($promozioni)
            ->filter(static fn (Promozione $p): bool => $sconto($p, max(1, $importoCent)) > 0)
            ->values();

        if ($tutte->isEmpty() || $importoCent <= 0) {
            return ['finale' => max(0, $importoCent), 'sconto' => 0, 'applicate' => []];
        }

        $cumulabili = $tutte->filter(static fn (Promozione $p): bool => $p->cumulabile);
        $esclusive = $tutte->reject(static fn (Promozione $p): bool => $p->cumulabile);

        /*
         * 🚨 **Fra le non cumulabili vince la priorità più alta**, e a parità
         * la migliore **su questo importo**: una percentuale e un importo fisso
         * non si confrontano in astratto — il 10% è meglio di 5 € sopra i
         * 50 €, ed è peggio sotto.
         */
        $migliore = $esclusive
            ->sort(static fn (Promozione $a, Promozione $b): int => [$b->priorita, $sconto($b, $importoCent)]
                <=> [$a->priorita, $sconto($a, $importoCent)])
            ->first();

        /*
         * ⚠️ **Una esclusiva esclude anche le cumulabili**: «non cumulabile»
         * vuol dire «da sola», non «da sola fra le sue pari». 💡 Quindi si
         * sceglie fra due strade e si tiene quella che conviene al cliente.
         */
        $conEsclusiva = $migliore === null
            ? null
            : $this->incolonna($importoCent, collect([$migliore]), $sconto);

        $conCumulabili = $cumulabili->isEmpty()
            ? null
            : $this->incolonna($importoCent, $cumulabili->sortByDesc(
                static fn (Promozione $p): int => $sconto($p, $importoCent),
            ), $sconto);

        /*
         * 🆕 P1 — ⚠️ **Un'esclusiva con priorità più alta di tutte le
         * cumulabili vince comunque**: la priorità è una decisione di chi l'ha
         * creata («questa deve passare davanti»), e la convenienza decide solo
         * fra pari.
         */
        if ($conEsclusiva !== null && $conCumulabili !== null
            && $migliore->priorita > $cumulabili->max('priorita')) {
            return $conEsclusiva;
        }

        return collect([$conEsclusiva, $conCumulabili])
            ->filter()
            ->sortByDesc('sconto')
            ->first() ?? ['finale' => $importoCent, 'sconto' => 0, 'applicate' => []];
    }

    /**
     * Le promozioni in fila, **una sull'importo che resta**.
     *
     * @param  Collection<int, Promozione>  $promozioni
     * @param  callable(Promozione, int): int  $scontoSu
     * @return array{finale: int, sconto: int, applicate: list<Promozione>}
     */
    private function incolonna(int $importoCent, Collection $promozioni, callable $scontoSu): array
    {
        $resta = $importoCent;
        $applicate = [];

        foreach ($promozioni as $promozione) {
            $sconto = $scontoSu($promozione, $resta);

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
     * 🆕 🪙 **Le promozioni che danno un bonus su questo acquisto di gettoni** — P2.
     *
     * Stesse regole dello sconto, applicate ai gettoni in più: le cumulabili si
     * sommano, fra le non cumulabili vince la priorità e poi la più generosa, e
     * fra le due strade quella che dà di più (a parità di priorità).
     *
     * @param  iterable<Promozione>  $candidate
     * @return list<Promozione>
     */
    public function bonusPer(iterable $candidate, int $gettoni, ?int $pacchettoId): array
    {
        $conBonus = collect($candidate)
            ->filter(fn (Promozione $p): bool => $this->bonusDi($p, $gettoni, $pacchettoId) > 0)
            ->values();

        if ($conBonus->isEmpty()) {
            return [];
        }

        $cumulabili = $conBonus->filter(fn (Promozione $p): bool => $p->cumulabile)->values();
        $migliore = $conBonus
            ->reject(fn (Promozione $p): bool => $p->cumulabile)
            ->sort(fn (Promozione $a, Promozione $b): int => [$b->priorita, $this->bonusDi($b, $gettoni, $pacchettoId)]
                <=> [$a->priorita, $this->bonusDi($a, $gettoni, $pacchettoId)])
            ->first();

        if ($migliore === null) {
            return $cumulabili->all();
        }

        $sommaCumulabili = $cumulabili->sum(fn (Promozione $p): int => $this->bonusDi($p, $gettoni, $pacchettoId));

        if ($cumulabili->isEmpty()
            || $migliore->priorita > $cumulabili->max('priorita')
            || $this->bonusDi($migliore, $gettoni, $pacchettoId) >= $sommaCumulabili) {
            return [$migliore];
        }

        return $cumulabili->all();
    }

    /**
     * 🆕 🧪 **La prova gratuita per questo piano** — P3 di `plan_promozioni.md` (opzione 9).
     *
     * Fra le candidate, quella con la priorità più alta e poi con più giorni.
     * ⚠️ Una sola: Stripe accetta un `trial_period_days`, e due prove non si
     * sommano (sarebbe regalare un mese a chi ne ha ricevute due da 15 giorni).
     *
     * @param  iterable<Promozione>  $candidate
     * @return array{giorni: int, promozione: Promozione}|null
     */
    public function provaPer(iterable $candidate, ?int $planId): ?array
    {
        $giorni = static fn (Promozione $p): int => (int) $p->premi
            ->filter(fn (PromozionePremio $r): bool => $r->tipo === TipoDiPremio::ProvaGratuita && $r->valeSulPiano($planId))
            ->max('giorni');

        $scelta = collect($candidate)
            ->filter(fn (Promozione $p): bool => $giorni($p) > 0)
            ->sort(fn (Promozione $a, Promozione $b): int => [$b->priorita, $giorni($b)] <=> [$a->priorita, $giorni($a)])
            ->first();

        return $scelta === null ? null : ['giorni' => $giorni($scelta), 'promozione' => $scelta];
    }

    /**
     * 🆕 🔒 **Le promozioni che bloccano il prezzo di questo piano** — P3 (opzione 12).
     *
     * @param  iterable<Promozione>  $candidate
     * @return list<Promozione>
     */
    public function bloccanoIlPrezzo(iterable $candidate, ?int $planId): array
    {
        return collect($candidate)
            ->filter(fn (Promozione $p): bool => $p->premi->contains(
                fn (PromozionePremio $r): bool => $r->tipo === TipoDiPremio::PrezzoBloccato && $r->valeSulPiano($planId),
            ))
            ->values()
            ->all();
    }

    /**
     * 🪙 Quanti gettoni in più dà questa promozione su questo acquisto.
     *
     * 💡 «+N fissi» e «+N%» si sommano se il premio li ha tutti e due; la
     * percentuale si arrotonda per difetto (un bonus non regala mezzo gettone).
     */
    public function bonusDi(Promozione $promozione, int $gettoni, ?int $pacchettoId): int
    {
        return (int) $promozione->premi
            ->filter(fn (PromozionePremio $p): bool => $p->tipo === TipoDiPremio::GettoniBonus
                && $p->valeSulPacchetto($pacchettoId))
            ->sum(fn (PromozionePremio $p): int => (int) $p->gettoni + intdiv($gettoni * (int) $p->valore, 100));
    }

    /**
     * Tutte le spendibili che prendono questa persona su questo acquisto.
     *
     * 🆕 P1 — e che questa persona **può ancora prendere** (massimo per
     * persona), dentro il budget.
     *
     * @return Collection<int, Promozione>
     */
    private function spendibiliDi(User $utente, SuCosaVale $cosa): Collection
    {
        return Promozione::query()
            ->spendibili()
            ->with(['bersagli', 'premi'])
            ->get()
            ->filter(fn (Promozione $p): bool => $p->valeSu($cosa)
                && ! $p->eFuoriBudget()
                && $this->loPrende($p, $utente)
                && $p->laPuoUsareAllAcquisto($utente))
            ->values();
    }

    /**
     * 🎯 **Questa persona rientra nei destinatari?**
     *
     * 💡 Da P1 la risposta la dà `ChiLaRiceve`, che serve anche ai regali e
     * all'accredito di massa: una regola sola per tutti.
     */
    public function loPrende(Promozione $promozione, User $utente): bool
    {
        return $this->chiLaRiceve->prende($promozione, $utente);
    }

    /**
     * 🧾 **Il webhook segna le promozioni usate** — P1.6.
     *
     * 🐛 Fino al 23/09 i codici finivano nei `metadata` della sessione e al
     * ritorno non li rileggeva nessuno: `usata_volte` restava a zero e
     * `massimo_usi` non fermava niente.
     *
     * 🚨 **Idempotente**: Stripe rimanda gli eventi, e l'indice unico
     * `(promozione_id, riferimento)` fa sì che la stessa sessione conti una
     * volta sola. ⛔ Senza, un evento ripetuto esaurirebbe un'offerta a metà.
     *
     * 💡 Lo sconto incassato si divide fra le promozioni applicate in parti
     * uguali (l'ultima prende il resto): serve al budget e ai numeri, e Stripe
     * restituisce solo il totale.
     *
     * @param  string  $codici  quelli scritti in `metadata['promozioni']`, separati da virgola
     * @return list<PromozioneAssegnazione> le assegnazioni di questo pagamento (🆕 P2: il bonus ci scrive i gettoni)
     */
    public function segnaUsate(string $codici, User $utente, string $riferimento, int $scontoCent): array
    {
        $elenco = array_values(array_filter(array_map('trim', explode(',', $codici))));

        if ($elenco === [] || $riferimento === '') {
            return [];
        }

        $promozioni = Promozione::query()->with('premi')->whereIn('codice', $elenco)->get();

        // 💡 Lo sconto si divide solo fra quelle che scontano: un bonus in gettoni non ha tolto euro.
        $conSconto = $promozioni->filter(fn (Promozione $p): bool => $p->premi->contains(
            fn (PromozionePremio $r): bool => $r->tipo->eUnoSconto(),
        ))->values();

        $quote = [];
        $resta = max(0, $scontoCent);

        foreach ($conSconto as $i => $promozione) {
            $quota = $i === $conSconto->count() - 1 ? $resta : intdiv(max(0, $scontoCent), max(1, $conSconto->count()));
            $resta -= $quota;
            $quote[$promozione->getKey()] = $quota;
        }

        $segnate = [];

        foreach ($promozioni as $promozione) {
            $quota = $quote[$promozione->getKey()] ?? 0;

            try {
                $segnate[] = DB::transaction(function () use ($promozione, $utente, $riferimento, $quota): PromozioneAssegnazione {
                    // Già segnata per questa sessione? Stripe ha rimandato l'evento.
                    $gia = PromozioneAssegnazione::query()
                        ->where('promozione_id', $promozione->getKey())
                        ->where('riferimento', $riferimento)
                        ->first();

                    if ($gia !== null) {
                        return $gia;
                    }

                    /*
                     * 🆕 P2 — **Un premio d'acquisto in sospeso si consuma sulla
                     * sua assegnazione**: il regalo è già stato dato (e contato
                     * in `usata_volte`), questo pagamento lo completa.
                     */
                    $sospesa = $promozione->haUnAcquistoInSospeso($utente);

                    if ($sospesa !== null) {
                        $sospesa->forceFill([
                            'acquisto_in_sospeso' => false,
                            'scontato_cent' => $sospesa->scontato_cent + $quota,
                            'usata_il' => now(),
                            // Finché arrivano gettoni ricorrenti resta «assegnata».
                            'stato' => $sospesa->prossimo_accredito_il === null
                                ? StatoAssegnazione::Usata
                                : StatoAssegnazione::Assegnata,
                        ])->save();

                        return $sospesa;
                    }

                    $nuova = PromozioneAssegnazione::query()->create([
                        'promozione_id' => $promozione->getKey(),
                        'user_id' => $utente->getKey(),
                        'tenant_id' => $utente->tenant_id,
                        'stato' => StatoAssegnazione::Usata,
                        'origine' => $promozione->attivazione === ComeSiAttiva::Automatica
                            ? OrigineAssegnazione::Automatica
                            : OrigineAssegnazione::Link,
                        'riferimento' => $riferimento,
                        'scontato_cent' => $quota,
                        'usata_il' => now(),
                    ]);

                    $promozione->increment('usata_volte');

                    return $nuova;
                });
            } catch (UniqueConstraintViolationException) {
                // 💡 Due eventi uguali nello stesso istante: l'altro ha già scritto.
            }
        }

        return $segnate;
    }
}
