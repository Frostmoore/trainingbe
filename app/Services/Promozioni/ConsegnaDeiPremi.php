<?php

declare(strict_types=1);

namespace App\Services\Promozioni;

use App\Enums\ComeSiAttiva;
use App\Enums\OrigineAssegnazione;
use App\Enums\PlanKind;
use App\Enums\StatoAssegnazione;
use App\Enums\TipoDiPremio;
use App\Models\AiCreditMovement;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Promozione;
use App\Models\PromozioneAssegnazione;
use App\Models\PromozionePremio;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\LePromozioni;
use App\Services\Billing\PortafoglioGettoni;
use App\Support\Tenancy\TenantContext;
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
 * | link | «Attiva» dal link (app o pannello) | `link:<user>:<n>` → fino al massimo per persona |
 * | massa | «Esegui» nel pannello → `ConsegnaLaPromozioneATutti` | `massa:<ts>:<user>` → due «Esegui» sono due giri |
 * | evento | P6 | |
 *
 * 🚨 **Il riferimento unico è la difesa contro i doppioni**: un comando che
 * gira due volte, un doppio clic su «Attiva», un job ritentato. ⛔ Senza,
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
        string $aChi = 'destinatario',
    ): ?PromozioneAssegnazione {
        $promozione->loadMissing(['premi', 'bersagli']);

        if ($this->percheNo($promozione, $utente, $aChi) !== null) {
            return null;
        }

        try {
            return DB::transaction(function () use ($promozione, $utente, $origine, $riferimento, $aChi): ?PromozioneAssegnazione {
                /*
                 * 🚨 La riga della promozione si blocca: due consegne insieme
                 * leggerebbero lo stesso `usata_volte` e lo stesso budget, e
                 * l'ultimo posto andrebbe a tutte e due.
                 */
                $bloccata = Promozione::query()->with('premi')->lockForUpdate()->findOrFail($promozione->getKey());

                if (! $bloccata->eSpendibile() || ! $bloccata->laPuoPrendereAncora($utente)) {
                    return null;
                }

                $ricorrente = $bloccata->premiPer($aChi)->first(fn (PromozionePremio $p): bool => $p->tipo === TipoDiPremio::GettoniRicorrenti);

                $assegnazione = PromozioneAssegnazione::query()->create([
                    'promozione_id' => $bloccata->getKey(),
                    'user_id' => $utente->getKey(),
                    'tenant_id' => $utente->tenant_id,
                    'origine' => $origine,
                    'riferimento' => $riferimento,
                    'stato' => StatoAssegnazione::Usata,
                    'usata_il' => now(),
                    // P2 — se ha anche uno sconto o un bonus, resta da usare pagando.
                    'acquisto_in_sospeso' => $bloccata->haPremiDAcquisto($aChi),
                ]);

                $dati = 0;

                $altri = 0;

                // 🆕 P6 — solo i premi di chi riceve questa consegna (porta un amico).
                foreach ($bloccata->premiPer($aChi) as $premio) {
                    $dati += match ($premio->tipo) {
                        TipoDiPremio::GettoniRegalo, TipoDiPremio::GettoniRicorrenti => $this->dai($bloccata, $premio, $utente, $assegnazione, (int) $premio->gettoni),
                        default => 0,
                    };

                    // 🆕 🎀 P4 — abbonamenti, upgrade e posti.
                    $altri += match ($premio->tipo) {
                        TipoDiPremio::AbbonamentoRegalato => $this->regalaLAbbonamento($premio, $utente, $assegnazione),
                        TipoDiPremio::Upgrade => $this->faiLUpgrade($premio, $utente, $assegnazione),
                        TipoDiPremio::PostiExtra => $this->aggiungiIPosti($premio, $utente),
                        default => 0,
                    };
                }

                /*
                 * ⛔ **Niente da dare, niente assegnazione**: col budget finito un
                 * regalo da zero gettoni non è un regalo, e contarlo esaurirebbe
                 * il massimo per persona di qualcuno che non ha avuto niente.
                 */
                if ($dati === 0 && $altri === 0 && ! $bloccata->haPremiDAcquisto($aChi)) {
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

                /*
                 * 🆕 📣 P8 — l'avviso parte **dopo il commit**: un avviso di un
                 * regalo che poi torna indietro sarebbe una bugia.
                 */
                if ((array) $bloccata->avviso !== []) {
                    DB::afterCommit(fn () => app(AvvisaIDestinatari::class)->dopoLaConsegna($assegnazione));
                }

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
    public function percheNo(Promozione $promozione, User $utente, string $aChi = 'destinatario'): ?string
    {
        $promozione->loadMissing(['premi', 'bersagli']);

        return match (true) {
            /*
             * 🆕 P6 — uno sconto sbloccato da un evento si «consegna» anche senza
             * regali. ⛔ Da quando i codici non esistono più (24/09/2026) anche uno
             * sconto col link si **attiva**: l'assegnazione resta in sospeso, e il
             * pagamento dopo lo usa da solo.
             */
            ! $promozione->haRegali($aChi) && ! $promozione->haPremiDAcquisto($aChi)
                && ! ($aChi === 'destinatario' && $promozione->haSblocchi()) => 'Questa offerta non dà niente.',
            ($motivo = $promozione->perchePiuNonVale()) !== null => $motivo,
            // ⚠️ Chi ha invitato non deve rientrare nei destinatari: i destinatari sono chi arriva.
            $aChi === 'destinatario' && ! $this->chiLaRiceve->prende($promozione, $utente) => 'Questa offerta non è per il tuo account.',
            ! $promozione->laPuoPrendereAncora($utente) => 'Hai già ricevuto questa offerta.',
            $promozione->budget_gettoni !== null && $promozione->gettoniRestantiNelBudget() === 0 => 'Questa offerta è esaurita.',
            default => $this->percheNoAgliAbbonamenti($promozione, $utente, $aChi),
        };
    }

    /**
     * 🆕 🔁 **I gettoni a ogni pagamento dell'abbonamento** — P6 (opzione 16).
     *
     * Chiamato dal webhook al primo pagamento e a ogni rinnovo. Vale per le
     * promozioni **automatiche** con un premio `gettoni_a_ogni_rinnovo` (sul
     * piano giusto, se ne indica uno). Il massimo per persona dice **quanti
     * pagamenti** vengono premiati (vuoto = tutti); il riferimento è la
     * sessione o la fattura, così un evento ripetuto non paga due volte.
     *
     * @return int i gettoni accreditati
     */
    public function gettoniDelPagamento(User $utente, ?int $planId, string $riferimento): int
    {
        $totale = 0;

        $promozioni = Promozione::query()
            ->spendibili()
            ->where('attivazione', ComeSiAttiva::Automatica->value)
            ->with(['premi', 'bersagli', 'condizioni'])
            ->get()
            ->filter(fn (Promozione $p): bool => $p->premi->contains(
                fn (PromozionePremio $r): bool => $r->tipo === TipoDiPremio::GettoniAOgniRinnovo && $r->valeSulPiano($planId),
            ));

        foreach ($promozioni as $promozione) {
            if (! $this->chiLaRiceve->prende($promozione, $utente) || ! $promozione->laPuoPrendereAncora($utente)) {
                continue;
            }

            try {
                $totale += DB::transaction(function () use ($promozione, $utente, $planId, $riferimento): int {
                    $assegnazione = PromozioneAssegnazione::query()->create([
                        'promozione_id' => $promozione->getKey(),
                        'user_id' => $utente->getKey(),
                        'tenant_id' => $utente->tenant_id,
                        'origine' => OrigineAssegnazione::Automatica,
                        'riferimento' => 'pagamento:'.$riferimento,
                        'stato' => StatoAssegnazione::Usata,
                        'usata_il' => now(),
                    ]);

                    $dati = 0;

                    foreach ($promozione->premi as $premio) {
                        if ($premio->tipo === TipoDiPremio::GettoniAOgniRinnovo && $premio->valeSulPiano($planId)) {
                            $dati += $this->dai($promozione, $premio, $utente, $assegnazione, (int) $premio->gettoni);
                        }
                    }

                    if ($dati === 0) {
                        throw new NienteDaConsegnare;
                    }

                    $promozione->increment('usata_volte');

                    return $dati;
                });
            } catch (UniqueConstraintViolationException|NienteDaConsegnare) {
                // Già premiato per questo pagamento, o budget finito.
            }
        }

        return $totale;
    }

    /**
     * 🆕 🎀 **I regali di abbonamento hanno regole in più** — P4.
     *
     * 🚨 Un abbonamento sta su uno **spazio**, non su una persona: regalarlo a
     * un iscritto vorrebbe dire regalarlo alla sua palestra intera. Per questo
     * lo spazio deve essere **suo** (`Tenant::eDi()`), e del tipo giusto per il
     * piano: un piano palestra su uno spazio palestra, un piano personale o da
     * trainer su uno spazio personale.
     */
    private function percheNoAgliAbbonamenti(Promozione $promozione, User $utente, string $aChi = 'destinatario'): ?string
    {
        foreach ($promozione->premiPer($aChi) as $premio) {
            $motivo = match ($premio->tipo) {
                TipoDiPremio::AbbonamentoRegalato => $this->percheNoAlRegalo($premio, $utente),
                TipoDiPremio::Upgrade => $this->percheNoAllUpgrade($premio, $utente),
                TipoDiPremio::PostiExtra => $this->spazioDi($utente) === null
                    ? 'I posti si regalano a chi ha uno spazio suo: una palestra o i propri allievi.'
                    : null,
                default => null,
            };

            if ($motivo !== null) {
                return $motivo;
            }
        }

        return null;
    }

    private function percheNoAlRegalo(PromozionePremio $premio, User $utente): ?string
    {
        $piano = $premio->plan;

        if ($piano === null || $this->spazioDi($utente, $piano) === null) {
            return 'Questo abbonamento non si può regalare al tuo account: lo spazio in cui stai non è tuo, o non è del tipo giusto.';
        }

        // ⛔ Un regalo sopra un abbonamento pagato: Stripe continuerebbe a incassare.
        if ($this->abbonamentoPagato($utente->tenant_id) !== null) {
            return 'Hai già un abbonamento a pagamento: il regalo si sommerebbe a quello che paghi.';
        }

        return null;
    }

    private function percheNoAllUpgrade(PromozionePremio $premio, User $utente): ?string
    {
        $verso = $premio->plan;
        $attuale = $this->abbonamentoPagato($utente->tenant_id);

        if ($verso === null || $this->spazioDi($utente, $verso) === null) {
            return 'Questo upgrade non si può dare al tuo account: lo spazio in cui stai non è tuo, o non è del tipo giusto.';
        }

        if ($attuale === null) {
            return 'L\'upgrade è per chi ha già un abbonamento a pagamento.';
        }

        if ($premio->da_plan_id !== null && $attuale->plan_id !== $premio->da_plan_id) {
            return 'Questo upgrade è per chi ha un altro piano.';
        }

        if ($attuale->plan_id === $verso->getKey()) {
            return 'Hai già questo piano.';
        }

        return null;
    }

    /**
     * Lo spazio della persona, **se è suo** e (con un piano) del tipo giusto.
     */
    private function spazioDi(User $utente, ?Plan $piano = null): ?Tenant
    {
        $spazio = app(TenantContext::class)->runWithoutTenant(
            fn (): ?Tenant => $utente->tenant_id !== null ? Tenant::query()->find($utente->tenant_id) : null,
        );

        if ($spazio === null || ! $spazio->eDi($utente)) {
            return null;
        }

        if ($piano !== null && ($piano->kind === PlanKind::Gym) === $spazio->ePersonale()) {
            return null;
        }

        return $spazio;
    }

    private function abbonamentoPagato(?int $tenantId): ?PlanSubscription
    {
        if ($tenantId === null) {
            return null;
        }

        return app(TenantContext::class)->runWithoutTenant(fn (): ?PlanSubscription => PlanSubscription::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('stripe_subscription_id')
            ->attivi()
            ->orderByDesc('starts_at')
            ->first());
    }

    /**
     * 🎀 **N periodi del piano, senza carta** — opzione 10.
     *
     * È una riga come quelle di «Regala abbonamento» (nessun id di Stripe), ma
     * con `rinnova = false`: alla fine del regalo lo spazio torna com'era.
     * ⛔ Con `rinnova = true` la prenderebbe `abbonamenti:rinnova-i-regali` e il
     * regalo non finirebbe più.
     */
    private function regalaLAbbonamento(PromozionePremio $premio, User $utente, PromozioneAssegnazione $assegnazione): int
    {
        $piano = $premio->plan;

        if ($piano === null) {
            return 0;
        }

        $fine = now();

        for ($i = 0; $i < max(1, (int) $premio->volte); $i++) {
            $fine = $piano->finePeriodo($fine);
        }

        $riga = PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $utente->tenant_id,
            'plan_id' => $piano->getKey(),
            'starts_at' => now(),
            'ends_at' => $fine->addDay(),
            'rinnova' => false,
            'promozione_assegnazione_id' => $assegnazione->getKey(),
        ]);

        $assegnazione->forceFill(['plan_subscription_id' => $riga->getKey(), 'scade_il' => $riga->ends_at])->save();

        return 1;
    }

    /**
     * ⬆️ **Il piano superiore per N mesi** — opzione 11.
     *
     * 💡 Si **aggiunge** una riga del piano superiore, più recente: `PianoAttivo::per()`
     * prende l'abbonamento attivo più recente, quindi finché dura vale lui. Quello
     * pagato resta sotto e Stripe continua a incassare **il suo** prezzo — è
     * esattamente il regalo: paghi il Basic, hai l'Advanced. Alla scadenza la riga
     * regalata esce da `attivi()` e torna in cima quella pagata, senza comandi.
     */
    private function faiLUpgrade(PromozionePremio $premio, User $utente, PromozioneAssegnazione $assegnazione): int
    {
        $verso = $premio->plan;

        if ($verso === null) {
            return 0;
        }

        $riga = PlanSubscription::withoutGlobalScopes()->create([
            'tenant_id' => $utente->tenant_id,
            'plan_id' => $verso->getKey(),
            'starts_at' => now(),
            'ends_at' => now()->addMonthsNoOverflow(max(1, (int) $premio->volte))->addDay(),
            'rinnova' => false,
            'promozione_assegnazione_id' => $assegnazione->getKey(),
        ]);

        $assegnazione->forceFill(['plan_subscription_id' => $riga->getKey(), 'scade_il' => $riga->ends_at])->save();

        return 1;
    }

    /**
     * ➕ **Posti in più per N mesi** — opzione 13.
     *
     * ⚠️ Sullo spazio, non sull'abbonamento (vedi la migration di P4). Due
     * promozioni di posti si **sommano**, e vale la scadenza più lontana; posti
     * già scaduti ripartono da zero.
     */
    private function aggiungiIPosti(PromozionePremio $premio, User $utente): int
    {
        $spazio = $this->spazioDi($utente);

        if ($spazio === null || (int) $premio->posti <= 0) {
            return 0;
        }

        $scaduti = $spazio->posti_extra_fino_al === null || $spazio->posti_extra_fino_al->isPast();
        $fino = now()->addMonthsNoOverflow(max(1, (int) $premio->volte));
        $colonna = $premio->posti_di === 'trainer' ? 'posti_trainer_extra' : 'posti_extra';

        $spazio->forceFill([
            'posti_extra' => $scaduti ? 0 : $spazio->posti_extra,
            'posti_trainer_extra' => $scaduti ? 0 : $spazio->posti_trainer_extra,
        ]);

        $spazio->forceFill([
            $colonna => (int) $spazio->{$colonna} + (int) $premio->posti,
            'posti_extra_fino_al' => $scaduti || $fino->gt($spazio->posti_extra_fino_al) ? $fino : $spazio->posti_extra_fino_al,
        ])->save();

        return 1;
    }

    /**
     * 🔗 **«Attiva» dal link** — nell'app o nel pannello.
     *
     * I regali arrivano subito; lo sconto (o il bonus) resta **in sospeso**
     * sull'assegnazione e il prossimo pagamento lo usa da solo — ⛔ niente
     * codice da riscrivere. Il riferimento conta le volte (`link:<user>:<n>`),
     * così il massimo per persona decide quante, e un doppio clic non ne dà due.
     */
    public function attiva(Promozione $promozione, User $utente): ?PromozioneAssegnazione
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
