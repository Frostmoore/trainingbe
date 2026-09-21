<?php

declare(strict_types=1);

namespace App\Services\Promozioni;

use App\Enums\ComeSiAttiva;
use App\Enums\OrigineAssegnazione;
use App\Enums\StatoAssegnazione;
use App\Enums\TipoDiPremio;
use App\Models\AiCreditMovement;
use App\Models\Promozione;
use App\Models\PromozioneAssegnazione;
use App\Models\PromozionePremio;
use App\Models\User;
use App\Services\Billing\LePromozioni;
use App\Services\Billing\PortafoglioGettoni;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 🎁 **Consegnare quello che una promozione regala** — 24/09/2026, fase P2 di
 * `plan_promozioni.md`.
 *
 * 📌 *«Voglio però sicuramente poter fare una cosa: una promozione per regalare
 * gettoni»*.
 *
 * Gli sconti vivono al pagamento (`LePromozioni`); i **regali** arrivano senza
 * pagare niente, e passano tutti da qui — qualunque sia la strada:
 *
 * | Strada | Chi chiama | Riferimento (unico per promozione) |
 * |---|---|---|
 * | automatica | `promozioni:consegna-automatiche`, ogni 15 minuti | `auto:<user>` → **una volta per persona**, sempre |
 * | link | «Riscatta» nella pagina dell'offerta | `link:<user>:<n>` → fino al massimo per persona |
 * | massa | «Esegui» nel pannello → `ConsegnaLaPromozioneATutti` | `massa:<ts>:<user>` → due «Esegui» sono due giri |
 * | evento | P6 | |
 *
 * 🚨 **Il riferimento unico è la difesa contro i doppioni**: un comando che
 * gira due volte, un doppio clic su «Riscatta», un job ritentato. ⛔ Senza,
 * ognuno di questi regalerebbe due volte.
 *
 * ⚠️ **Il budget in gettoni taglia, non rifiuta**: se ne restano 30 e il regalo
 * è di 50, se ne danno 30 e la promozione si esaurisce. Se non ne resta
 * nessuno, la consegna non avviene.
 */
class ConsegnaDeiPremi
{
    public function __construct(
        private readonly ChiLaRiceve $chiLaRiceve,
        private readonly PortafoglioGettoni $portafoglio,
        private readonly LePromozioni $promozioni,
    ) {}

    /**
     * 🎁 **Consegna i regali di questa promozione a questa persona.**
     *
     * `null` se non le spetta (fuori dai destinatari, già presa, esaurita,
     * senza regali) o se questo riferimento è già stato consegnato.
     */
    public function consegna(
        Promozione $promozione,
        User $utente,
        OrigineAssegnazione $origine,
        ?string $riferimento = null,
    ): ?PromozioneAssegnazione {
        $promozione->loadMissing(['premi', 'bersagli']);

        if ($this->percheNo($promozione, $utente) !== null) {
            return null;
        }

        try {
            return DB::transaction(function () use ($promozione, $utente, $origine, $riferimento): ?PromozioneAssegnazione {
                /*
                 * 🚨 La riga della promozione si blocca: due consegne insieme
                 * leggerebbero lo stesso `usata_volte` e lo stesso budget, e
                 * l'ultimo posto andrebbe a tutte e due.
                 */
                $bloccata = Promozione::query()->with('premi')->lockForUpdate()->findOrFail($promozione->getKey());

                if (! $bloccata->eSpendibile() || ! $bloccata->laPuoPrendereAncora($utente)) {
                    return null;
                }

                $ricorrente = $bloccata->premi->first(fn (PromozionePremio $p): bool => $p->tipo === TipoDiPremio::GettoniRicorrenti);

                $assegnazione = PromozioneAssegnazione::query()->create([
                    'promozione_id' => $bloccata->getKey(),
                    'user_id' => $utente->getKey(),
                    'tenant_id' => $utente->tenant_id,
                    'origine' => $origine,
                    'riferimento' => $riferimento,
                    'stato' => StatoAssegnazione::Usata,
                    'usata_il' => now(),
                    // P2 — se ha anche uno sconto o un bonus, resta da usare pagando.
                    'acquisto_in_sospeso' => $bloccata->haPremiDAcquisto(),
                ]);

                $dati = 0;

                foreach ($bloccata->premi as $premio) {
                    $dati += match ($premio->tipo) {
                        TipoDiPremio::GettoniRegalo, TipoDiPremio::GettoniRicorrenti => $this->dai($bloccata, $premio, $utente, $assegnazione, (int) $premio->gettoni),
                        default => 0,
                    };
                }

                /*
                 * ⛔ **Niente da dare, niente assegnazione**: col budget finito un
                 * regalo da zero gettoni non è un regalo, e contarlo esaurirebbe
                 * il massimo per persona di qualcuno che non ha avuto niente.
                 */
                if ($dati === 0 && ! $bloccata->haPremiDAcquisto()) {
                    throw new NienteDaConsegnare;
                }

                $ricorrenteAttivo = $ricorrente !== null && (int) $ricorrente->volte > 1;

                $assegnazione->forceFill([
                    'scade_il' => $ricorrente !== null ? now()->addMonthsNoOverflow((int) $ricorrente->volte) : null,
                    'prossimo_accredito_il' => $ricorrenteAttivo ? now()->addMonthNoOverflow() : null,
                    'stato' => $ricorrenteAttivo || $assegnazione->acquisto_in_sospeso
                        ? StatoAssegnazione::Assegnata
                        : StatoAssegnazione::Usata,
                ])->save();

                $bloccata->increment('usata_volte');

                return $assegnazione;
            });
        } catch (UniqueConstraintViolationException|NienteDaConsegnare) {
            return null;
        }
    }

    /**
     * Perché questa persona **non** può ricevere i regali — o `null` se può.
     *
     * 💡 Serve alla pagina dell'offerta: «l'hai già presa» e «non è per te»
     * sono due risposte diverse.
     */
    public function percheNo(Promozione $promozione, User $utente): ?string
    {
        $promozione->loadMissing(['premi', 'bersagli']);

        return match (true) {
            ! $promozione->haRegali() => 'Questa offerta non regala niente: si usa pagando.',
            ($motivo = $promozione->perchePiuNonVale()) !== null => $motivo,
            ! $this->chiLaRiceve->prende($promozione, $utente) => 'Questa offerta non è per il tuo account.',
            ! $promozione->laPuoPrendereAncora($utente) => 'Hai già ricevuto questa offerta.',
            $promozione->budget_gettoni !== null && $promozione->gettoniRestantiNelBudget() === 0 => 'Questa offerta è esaurita.',
            default => null,
        };
    }

    /**
     * 🔗 «Riscatta» dalla pagina dell'offerta.
     *
     * Il riferimento conta le volte (`link:<user>:<n>`), così il massimo per
     * persona decide quante, e un doppio clic non ne dà due.
     */
    public function riscatta(Promozione $promozione, User $utente): ?PromozioneAssegnazione
    {
        $volta = $promozione->usiDi($utente) + 1;

        return $this->consegna($promozione, $utente, OrigineAssegnazione::Link, "link:{$utente->getKey()}:{$volta}");
    }

    /**
     * 🚀 **«Esegui»: a tutti i destinatari, adesso** — opzione 31.
     *
     * @return int quante persone l'hanno ricevuta in questo giro
     */
    public function eseguiPerTutti(Promozione $promozione): int
    {
        $giro = now();
        $promozione->forceFill(['eseguita_il' => $giro])->save();

        $quante = 0;

        foreach ($this->chiLaRiceve->destinatari($promozione) as $utente) {
            if ($this->consegna($promozione, $utente, OrigineAssegnazione::Massa, "massa:{$giro->timestamp}:{$utente->getKey()}") !== null) {
                $quante++;
            }
        }

        return $quante;
    }

    /**
     * ⏱️ **Le automatiche con regali, a chi rientra** — ogni 15 minuti.
     *
     * 🚨 Riferimento `auto:<user>`: **una volta per persona**, qualunque sia il
     * massimo per persona. ⛔ Altrimenti una automatica senza limite
     * regalerebbe gettoni a ogni giro del comando.
     *
     * @return int quante consegne
     */
    public function consegnaLeAutomatiche(): int
    {
        $quante = 0;

        $promozioni = Promozione::query()
            ->spendibili()
            ->where('attivazione', ComeSiAttiva::Automatica->value)
            ->with(['premi', 'bersagli'])
            ->get()
            ->filter(fn (Promozione $p): bool => $p->haRegali());

        foreach ($promozioni as $promozione) {
            $gia = $promozione->assegnazioni()->pluck('user_id')->flip();

            foreach ($this->chiLaRiceve->destinatari($promozione) as $utente) {
                if ($gia->has($utente->getKey())) {
                    continue;
                }

                if ($this->consegna($promozione, $utente, OrigineAssegnazione::Automatica, "auto:{$utente->getKey()}") !== null) {
                    $quante++;
                }
            }
        }

        return $quante;
    }

    /**
     * 📅 **I gettoni ricorrenti del mese** — ogni notte. Opzione 5.
     *
     * ⚠️ Continuano anche se la promozione nel frattempo è stata sospesa: il
     * regalo è stato fatto, e toglierlo è una **revoca** (P7), non un effetto
     * collaterale di un interruttore.
     *
     * @return int quanti accrediti
     */
    public function accreditaIRicorrenti(?Carbon $quando = null): int
    {
        $quando ??= now();
        $fatti = 0;

        $dovute = PromozioneAssegnazione::query()
            ->where('stato', StatoAssegnazione::Assegnata->value)
            ->whereNotNull('prossimo_accredito_il')
            ->where('prossimo_accredito_il', '<=', $quando)
            ->with(['promozione.premi', 'utente'])
            ->get();

        foreach ($dovute as $assegnazione) {
            try {
                DB::transaction(function () use ($assegnazione, &$fatti): void {
                    $promozione = Promozione::query()->with('premi')->lockForUpdate()->findOrFail($assegnazione->promozione_id);
                    $utente = $assegnazione->utente;

                    if ($utente === null) {
                        return;
                    }

                    $dati = 0;

                    foreach ($promozione->premi as $premio) {
                        if ($premio->tipo === TipoDiPremio::GettoniRicorrenti) {
                            $dati += $this->dai($promozione, $premio, $utente, $assegnazione, (int) $premio->gettoni);
                        }
                    }

                    $prossimo = $assegnazione->prossimo_accredito_il->copy()->addMonthNoOverflow();
                    $finito = $assegnazione->scade_il === null || $prossimo->gte($assegnazione->scade_il);

                    // ⚠️ `gettoni` l'ha già aggiornato `dai()`, riga per riga.
                    $assegnazione->forceFill([
                        'prossimo_accredito_il' => $finito ? null : $prossimo,
                        'stato' => $finito && ! $assegnazione->acquisto_in_sospeso
                            ? StatoAssegnazione::Usata
                            : StatoAssegnazione::Assegnata,
                    ])->save();

                    $fatti += $dati > 0 ? 1 : 0;
                });
            } catch (Throwable $e) {
                Log::error('Promozioni: accredito ricorrente fallito.', [
                    'assegnazione' => $assegnazione->getKey(),
                    'errore' => $e->getMessage(),
                ]);
            }
        }

        return $fatti;
    }

    /**
     * 🪙 **Il bonus di un acquisto di gettoni** — opzione 2.
     *
     * Chiamato dal webhook dopo l'accredito dei gettoni comprati. I gettoni in
     * più vanno a chi riceve l'acquisto (l'allievo, se ha pagato la palestra).
     *
     * @param  list<PromozioneAssegnazione>  $assegnazioni  quelle segnate da `LePromozioni::segnaUsate()` per questa sessione
     * @param  list<string>  $codiciBonus  quelli scritti in `metadata['bonus']`
     * @return int i gettoni di bonus accreditati
     */
    public function bonusDellAcquisto(
        array $assegnazioni,
        array $codiciBonus,
        User $chiRiceve,
        int $gettoniComprati,
        ?int $pacchettoId,
    ): int {
        $totale = 0;

        foreach ($assegnazioni as $assegnazione) {
            $promozione = Promozione::query()->with('premi')->find($assegnazione->promozione_id);

            if ($promozione === null || ! in_array($promozione->codice, $codiciBonus, true)) {
                continue;
            }

            // 💡 Idempotenza: un'assegnazione che ha già avuto il bonus non lo riceve di nuovo.
            if ($assegnazione->gettoni > 0) {
                continue;
            }

            $bonus = $this->promozioni->bonusDi($promozione, $gettoniComprati, $pacchettoId);
            $restanti = $promozione->gettoniRestantiNelBudget();
            $bonus = $restanti === null ? $bonus : min($bonus, $restanti);

            if ($bonus <= 0) {
                continue;
            }

            $premio = $promozione->premi->first(fn (PromozionePremio $p): bool => $p->tipo === TipoDiPremio::GettoniBonus);

            $this->portafoglio->accredita(
                $chiRiceve,
                $bonus,
                nota: 'Bonus «'.$promozione->nome.'»',
                causale: AiCreditMovement::PROMOZIONE,
                scadeIl: $premio?->giorni ? now()->addDays((int) $premio->giorni) : null,
                assegnazioneId: $assegnazione->getKey(),
            );

            $assegnazione->forceFill(['gettoni' => $assegnazione->gettoni + $bonus])->save();
            $totale += $bonus;
        }

        return $totale;
    }

    /**
     * Accredita i gettoni di un premio, **tagliati dal budget**.
     *
     * @return int quanti ne ha dati davvero
     */
    private function dai(Promozione $promozione, PromozionePremio $premio, User $utente, PromozioneAssegnazione $assegnazione, int $gettoni): int
    {
        $restanti = $promozione->gettoniRestantiNelBudget();
        $gettoni = $restanti === null ? $gettoni : min($gettoni, $restanti);

        if ($gettoni <= 0) {
            return 0;
        }

        $this->portafoglio->accredita(
            $utente,
            $gettoni,
            nota: 'Promozione «'.$promozione->nome.'»',
            causale: AiCreditMovement::PROMOZIONE,
            scadeIl: $premio->giorni ? now()->addDays((int) $premio->giorni) : null,
            assegnazioneId: $assegnazione->getKey(),
        );

        // 💡 Tenuta aggiornata subito: il budget del premio dopo si legge da qui.
        $assegnazione->forceFill(['gettoni' => $assegnazione->gettoni + $gettoni])->save();

        return $gettoni;
    }
}
