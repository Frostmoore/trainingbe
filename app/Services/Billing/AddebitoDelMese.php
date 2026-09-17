<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Models\AddebitoMensile;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Stripe\StripeClient;
use Throwable;

/**
 * 💶 **L'addebito mensile del piano a consumo** — 3b-AH.6.9 (17/09/2026).
 *
 * ══ 📌 COSA MANCAVA ═══════════════════════════════════════════════════════
 *
 * Dal 13/09 esistevano il prezzo, la soglia e `Plan::costoDelMese()`. ⛔ Non
 * esisteva **nessuno che chiedesse i soldi**: il piano a consumo era a listino,
 * comprabile, e non avrebbe mai emesso una fattura. 🚨 E' il difetto che non da'
 * errore per definizione — un incasso che non arriva non lancia niente.
 *
 * ══ 🚨 DUE PASSI SEPARATI, E NON E' PIGNOLERIA ════════════════════════════
 *
 * [calcola] scrive quanto si deve; [incassa] lo manda a Stripe. ⛔ Fonderli
 * vorrebbe dire che un errore di rete a meta' strada lascia il mese **senza
 * riga**: al giro dopo si ricalcola su iscritti diversi, e il numero cambia
 * senza che nessuno abbia cambiato niente.
 *
 * 💡 Cosi' invece il conteggio e' **congelato** appena fatto, e il tentativo con
 * Stripe si puo' ripetere quante volte serve senza toccarlo.
 *
 * ══ ⚠️ DOVE FINISCONO I SOLDI OGGI ════════════════════════════════════════
 *
 * ⛔ **Nessuna palestra ha ancora un `stripe_customer_id`**: entrano a mano, e
 * `Cassa` sa fare pagare solo le persone (l'abbonamento AI, i gettoni). Quelle
 * righe nascono quindi in stato `senza_cliente` — calcolate, visibili,
 * recuperabili — invece di non nascere.
 *
 * 🚨 Questa e' una **scelta dichiarata, non un buco**: il giorno che una
 * palestra paga con Stripe, i mesi arretrati stanno gia' scritti e si incassano
 * senza ricostruire niente. Il contrario — fatturare nel vuoto e scoprirlo dopo
 * — non si recupera.
 */
class AddebitoDelMese
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ContoDegliIscritti $conto,
    ) {}

    /**
     * Il periodo di una data, nella forma che usa la tabella.
     *
     * ⚠️ **`YYYY-MM` e non una data**: e' l'identita' dell'addebito. Vedi la
     * migration per il perche' un confronto con `>=` qui sarebbe una trappola.
     */
    public static function periodoDi(?Carbon $quando = null): string
    {
        return ($quando ?? Carbon::now())->format('Y-m');
    }

    /**
     * Le palestre che stavano su un piano a consumo **in quel mese**.
     *
     * ══ 🚨 «IN QUEL MESE» E NON «ADESSO», ED E' UNA DIFFERENZA DI SOLDI ═════
     *
     * ⛔ Questo giro fattura il mese **appena chiuso**, e gira il primo del mese
     * dopo. Chiedere «chi e' a consumo adesso» vorrebbe dire che una palestra
     * che ha cambiato piano il 31 **non viene fatturata per il mese che ha
     * appena consumato** — e quella che e' passata al consumo il 1° si vede
     * addebitare un mese in cui era a canone.
     *
     * 🚨 Nessuno dei due casi da' errore: e' un cliente che non paga e un altro
     * che paga due volte, e si scoprono leggendo l'estratto conto.
     *
     * ⚠️ Si guarda la **storia** degli abbonamenti e non `tenants.plan_id`:
     * quella colonna non esiste apposta, ed e' scritto su `PlanSubscription`
     * perche' *«la domanda "perche' a marzo questo cliente aveva l'AI e ad
     * aprile no?" deve avere una risposta»*.
     *
     * @return Collection<int, Tenant>
     */
    public function palestreAConsumo(?string $periodo = null): Collection
    {
        $periodo ??= self::periodoDi();

        return $this->context->runWithoutTenant(function () use ($periodo): Collection {
            $piani = Plan::query()
                ->whereNotNull('prezzo_iscritto_cent')
                ->pluck('id');

            if ($piani->isEmpty()) {
                return collect();
            }

            $tenantIds = PlanSubscription::withoutGlobalScopes()
                ->whereIn('plan_id', $piani)
                ->where(fn ($q) => $this->vivoNelPeriodo($q, $periodo))
                ->pluck('tenant_id')
                ->unique();

            return Tenant::query()->whereIn('id', $tenantIds)->get();
        });
    }

    /**
     * Un abbonamento che **ha toccato** quel mese, anche per un giorno solo.
     *
     * ⚠️ **Si sovrappone, non «e' iniziato dentro»**: chi ha firmato a gennaio
     * e non ha mai disdetto va fatturato a settembre, e un `starts_at` dentro il
     * mese lo lascerebbe fuori da tutti i mesi tranne il primo.
     *
     * 🚨 `ends_at IS NULL` significa **«non scade»**, non «scaduto» — e'
     * la convenzione di tutta la tabella, e leggerla al contrario qui non
     * fatturerebbe **nessuno** di quelli che pagano regolarmente.
     *
     * 💡 Un addebito parziale — mezzo mese a consumo, mezzo a canone — oggi non
     * si fa: si fattura il mese intero a chi c'era. ⏳ E' un debito dichiarato,
     * e si affronta quando esistera' un cliente vero che cambia piano a meta'
     * mese; prima sarebbe una divisione inventata su un caso che non c'e'.
     */
    private function vivoNelPeriodo(mixed $query, string $periodo): mixed
    {
        $inizio = Carbon::createFromFormat('Y-m-d H:i:s', $periodo.'-01 00:00:00')->startOfMonth();
        $fine = $inizio->copy()->endOfMonth();

        return $query
            ->where('starts_at', '<=', $fine)
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>=', $inizio));
    }

    /**
     * Calcola — e **congela** — quanto deve questa palestra per questo mese.
     *
     * ⚠️ Torna la riga che c'e' gia' se il mese e' stato calcolato: non e' un
     * errore, e' la difesa che serve. 🚨 Il vincolo `unique(tenant_id, periodo)`
     * e' quello vero; questo controllo evita solo di arrivarci ogni volta.
     *
     * ⛔ Torna `null` se questa palestra **non e' a consumo**: non e' una
     * condizione d'errore, e' la risposta giusta per i piani a canone, dove il
     * prezzo e' `price_cents` e non dipende da quanti sono.
     */
    public function calcola(Tenant $palestra, ?string $periodo = null): ?AddebitoMensile
    {
        $periodo ??= self::periodoDi();

        $piano = $this->pianoDi($palestra, $periodo);

        if ($piano === null || ! $piano->aConsumo()) {
            return null;
        }

        $gia = $this->context->runWithoutTenant(
            fn (): ?AddebitoMensile => AddebitoMensile::withoutGlobalScopes()
                ->where('tenant_id', $palestra->getKey())
                ->delPeriodo($periodo)
                ->first(),
        );

        if ($gia !== null) {
            return $gia;
        }

        $contati = $this->conto->quanti($palestra);

        /*
         * 🚨 **Il minimo si applica al CONTEGGIO, non al totale** — e la regola
         * sta in `Plan::costoDelMese()`, da dove non si muove. ⛔ Rifarla qui
         * sarebbe il secondo posto in cui vive lo stesso calcolo, e il giorno
         * che il prezzo a iscritto cambia ne resterebbe indietro uno.
         */
        $fatturati = max($contati, $piano->minimo_iscritti ?? 0);
        $importo = $piano->costoDelMese($contati);

        try {
            return $this->context->runWithoutTenant(
                fn (): AddebitoMensile => AddebitoMensile::withoutGlobalScopes()->create([
                    'tenant_id' => $palestra->getKey(),
                    'plan_id' => $piano->getKey(),
                    'periodo' => $periodo,
                    'iscritti_contati' => $contati,
                    'iscritti_fatturati' => $fatturati,
                    'prezzo_iscritto_cent' => (int) $piano->prezzo_iscritto_cent,
                    'importo_cent' => (int) $importo,
                    'stato' => AddebitoMensile::DA_INCASSARE,
                ]),
            );
        } catch (QueryException $e) {
            /*
             * ⛔ **Due giri insieme.** Il vincolo ha fatto il suo mestiere: la
             * riga esiste gia' e va restituita quella, non rilanciato l'errore.
             * 🚨 Rilanciarlo farebbe fallire l'intero giro mensile per una
             * palestra su cui invece e' andato tutto bene.
             */
            $riga = $this->context->runWithoutTenant(
                fn (): ?AddebitoMensile => AddebitoMensile::withoutGlobalScopes()
                    ->where('tenant_id', $palestra->getKey())
                    ->delPeriodo($periodo)
                    ->first(),
            );

            if ($riga === null) {
                throw $e;
            }

            return $riga;
        }
    }

    /**
     * Manda a Stripe un addebito gia' calcolato.
     *
     * ── ⚠️ Perche' una fattura e non un abbonamento con la quantita' ───────
     *
     * Un abbonamento di Stripe con `quantity` sincronizzata ogni mese farebbe
     * il rinnovo da solo, ed e' la strada elegante. ⛔ Ma la quantita' andrebbe
     * aggiornata **prima** che Stripe emetta, cioe' su una data che decide lui:
     * sbagliare quella finestra vuol dire fatturare il numero del mese scorso,
     * senza nessun errore da nessuna parte.
     *
     * 💡 Qui invece si emette **dopo** aver contato, con un numero che e' gia'
     * scritto in una riga nostra: l'ordine e' quello giusto e la fattura si puo'
     * sempre confrontare con cio' che l'ha generata.
     *
     * 🚨 **Non lancia.** Un giro mensile che si ferma sulla prima palestra
     * difficile lascia le altre non fatturate.
     */
    public function incassa(AddebitoMensile $addebito): AddebitoMensile
    {
        if ($addebito->stato === AddebitoMensile::INCASSATO) {
            return $addebito;
        }

        $cliente = $this->clienteDi((int) $addebito->tenant_id);

        if ($cliente === null) {
            $addebito->forceFill([
                'stato' => AddebitoMensile::SENZA_CLIENTE,
                'errore' => 'Questa palestra non ha un cliente Stripe: non c\'e\' a chi mandarla.',
            ])->save();

            return $addebito;
        }

        try {
            $stripe = $this->stripe();

            /*
             * ⚠️ **La voce prima, la fattura dopo.** Stripe raccoglie le voci
             * in sospeso del cliente e le chiude dentro la fattura che si crea
             * subito dopo: invertire i due passi emette una fattura vuota e
             * lascia la voce in giro, che finira' su quella del mese prossimo.
             */
            $stripe->invoiceItems->create([
                'customer' => $cliente,
                'currency' => 'eur',
                'amount' => $addebito->importo_cent,
                'description' => $this->descrizione($addebito),
                'metadata' => $this->metadata($addebito),
            ]);

            $fattura = $stripe->invoices->create([
                'customer' => $cliente,
                'collection_method' => 'charge_automatically',
                'auto_advance' => true,
                'metadata' => $this->metadata($addebito),
            ]);

            $addebito->forceFill([
                'stato' => AddebitoMensile::INCASSATO,
                'stripe_invoice_id' => (string) $fattura->id,
                'incassato_il' => Carbon::now(),
                'errore' => null,
            ])->save();

            Log::info('Addebito a consumo emesso.', [
                'tenant' => $addebito->tenant_id,
                'periodo' => $addebito->periodo,
                'importo_cent' => $addebito->importo_cent,
                'fattura' => $fattura->id,
            ]);
        } catch (Throwable $e) {
            $addebito->forceFill([
                'stato' => AddebitoMensile::FALLITO,
                'errore' => $e->getMessage(),
            ])->save();

            Log::error('Addebito a consumo fallito.', [
                'tenant' => $addebito->tenant_id,
                'periodo' => $addebito->periodo,
                'errore' => $e->getMessage(),
            ]);
        }

        return $addebito;
    }

    /**
     * Il giro completo di un mese: conta tutti, poi prova a incassare.
     *
     * @return array{calcolati: int, incassati: int, senza_cliente: int, falliti: int}
     */
    public function giroDelMese(?string $periodo = null, bool $incassa = true): array
    {
        $periodo ??= self::periodoDi();

        $esito = ['calcolati' => 0, 'incassati' => 0, 'senza_cliente' => 0, 'falliti' => 0];

        foreach ($this->palestreAConsumo($periodo) as $palestra) {
            $addebito = $this->calcola($palestra, $periodo);

            if ($addebito === null) {
                continue;
            }

            $esito['calcolati']++;

            if (! $incassa) {
                continue;
            }

            $addebito = $this->incassa($addebito);

            match ($addebito->stato) {
                AddebitoMensile::INCASSATO => $esito['incassati']++,
                AddebitoMensile::SENZA_CLIENTE => $esito['senza_cliente']++,
                AddebitoMensile::FALLITO => $esito['falliti']++,
                default => null,
            };
        }

        return $esito;
    }

    /**
     * La descrizione che la palestra leggera' sulla fattura.
     *
     * 🚨 **Dice il minimo quando c'e'**, per esteso. ⛔ «100 iscritti × 5,99»
     * su una palestra che ne ha 40 e' un numero che non torna con niente di
     * quello che ha sotto gli occhi, e la prima telefonata sarebbe su questo.
     */
    private function descrizione(AddebitoMensile $addebito): string
    {
        $prezzo = number_format($addebito->prezzo_iscritto_cent / 100, 2, ',', '.');

        $base = "Piano a consumo {$addebito->periodo} — "
            ."{$addebito->iscritti_fatturati} iscritti × {$prezzo} €";

        if ($addebito->haPagatoIlMinimo()) {
            return $base." (iscritti effettivi: {$addebito->iscritti_contati}, minimo del piano: {$addebito->iscritti_fatturati})";
        }

        return $base;
    }

    /**
     * 🚨 **I metadata sono il contratto fra le due meta'** — la stessa regola
     * di `Cassa`. Quello che non e' scritto qui, quando la fattura torna
     * indietro come evento, non esiste.
     *
     * @return array<string, string>
     */
    private function metadata(AddebitoMensile $addebito): array
    {
        return [
            'tipo' => 'addebito_a_consumo',
            'addebito_id' => (string) $addebito->getKey(),
            'tenant_id' => (string) $addebito->tenant_id,
            'periodo' => $addebito->periodo,
        ];
    }

    /**
     * Il piano che questa palestra aveva **in quel mese**, `null` se nessuno.
     *
     * ⚠️ Stessa ragione di [palestreAConsumo]: il prezzo che finisce sulla
     * fattura di agosto dev'essere quello di agosto, non quello di oggi. ⛔ E
     * `orderByDesc('starts_at')` con la sovrapposizione prende **l'ultimo
     * cominciato dentro quel mese**, che e' quello sotto cui il mese si e'
     * chiuso.
     */
    private function pianoDi(Tenant $palestra, ?string $periodo = null): ?Plan
    {
        $periodo ??= self::periodoDi();

        return $this->context->runWithoutTenant(
            fn (): ?Plan => PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $palestra->getKey())
                ->where(fn ($q) => $this->vivoNelPeriodo($q, $periodo))
                ->with('plan')
                ->orderByDesc('starts_at')
                ->first()?->plan,
        );
    }

    /**
     * Il cliente Stripe di questa palestra.
     *
     * ⚠️ Si legge dall'abbonamento e non dall'utente: qui si fattura a un
     * **tenant**, e la persona che ha aperto il pagamento potrebbe non esserci
     * piu'.
     */
    private function clienteDi(int $tenantId): ?string
    {
        return $this->context->runWithoutTenant(
            fn (): ?string => PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereNotNull('stripe_customer_id')
                ->orderByDesc('starts_at')
                ->value('stripe_customer_id'),
        );
    }

    private function stripe(): StripeClient
    {
        return new StripeClient((string) config('services.stripe.secret'));
    }
}
