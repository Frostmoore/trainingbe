<?php

declare(strict_types=1);

namespace App\Services\Promozioni;

use App\Enums\TipoBersaglio;
use App\Enums\TipoDiCondizione;
use App\Enums\TipoDiPremio;
use App\Models\AiCreditMovement;
use App\Models\Contracts\HaUnPubblico;
use App\Models\PlanSubscription;
use App\Models\Promozione;
use App\Models\PromozioneAssegnazione;
use App\Models\PromozioneBersaglio;
use App\Models\PromozioneCondizione;
use App\Models\PromozionePremio;
use App\Models\User;
use App\Services\Billing\PianoAttivo;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * 🎯 **Chi riceve una promozione** — 24/09/2026, fase P1 di `plan_promozioni.md`.
 *
 * Il codice dei bersagli viene da `LePromozioni::loPrende()` (F2), spostato qui
 * perché da P2 non lo chiede più solo il conto dello sconto: lo chiedono anche
 * la consegna dei regali, l'accredito di massa e l'anteprima «quante persone
 * prende».
 *
 * ══ ⚠️ L'ORDINE DELLA DOMANDA (§4.4 del piano) ═══════════════════════════
 *
 *     almeno un bersaglio incluso  →  nessun bersaglio escluso  →  tutte le condizioni vere
 *
 * 🆕 P5: i bersagli con `escludi = true` tolgono, e le **condizioni** (tabella
 * `promozione_condizioni`) filtrano in AND. ⚠️ Una promozione con **solo**
 * esclusioni vale per «tutti tranne…»: è il modo in cui la si scrive.
 */
class ChiLaRiceve
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly PianoAttivo $piano,
    ) {}

    /**
     * 🎯 **Questa persona rientra?**
     *
     * ⚠️ I bersagli sono in **OR**: «per i trainer e per gli allievi di Tizio»
     * vuol dire «chi è trainer **oppure** allievo di Tizio». ⛔ In AND sarebbe
     * «i trainer che sono anche allievi di Tizio», cioè quasi nessuno.
     */
    /*
     * 🆕 ⚠️ **`HaUnPubblico` e non `Promozione`** — blocco B4, 24/09/2026.
     *
     * 💡 Dal 24/09 la stessa domanda serve anche agli **avvisi in-app**: i
     * bersagli in OR, le esclusioni che vincono, le condizioni in AND sono
     * **identici**. ⛔ Duplicare questo servizio vorrebbe dire vederlo divergere
     * al primo ritocco — e una divergenza qui non dà errore: manda una cosa
     * alle persone sbagliate.
     */
    public function prende(HaUnPubblico $promozione, User $utente): bool
    {
        $inclusi = $promozione->bersagli->reject(fn (PromozioneBersaglio $b): bool => (bool) $b->escludi);
        $esclusi = $promozione->bersagli->filter(fn (PromozioneBersaglio $b): bool => (bool) $b->escludi);

        // Nessun bersaglio affatto: nessuno. Solo esclusioni: tutti tranne loro.
        $dentro = $inclusi->isEmpty()
            ? $esclusi->isNotEmpty()
            : $inclusi->contains(fn (PromozioneBersaglio $b): bool => $this->rientra($b, $utente));

        if (! $dentro) {
            return false;
        }

        // 🆕 P5 — ⛔ un'esclusione batte qualunque inclusione.
        if ($esclusi->contains(fn (PromozioneBersaglio $b): bool => $this->rientra($b, $utente))) {
            return false;
        }

        // 🆕 P5 — le condizioni, tutte vere.
        foreach ($promozione->condizioni as $condizione) {
            if (! $this->soddisfa($condizione, $utente)) {
                return false;
            }
        }

        return true;
    }

    /**
     * 🆕 🔎 **Questa condizione è vera per questa persona?** — P5.
     *
     * ⚠️ «Inattivo» guarda `users.last_login_at`, che scrive **l'accesso
     * all'app** (e il social): l'accesso al pannello non lo aggiorna. Per chi
     * usa solo il pannello, «inattivo» vuol dire «non apre l'app».
     */
    public function soddisfa(PromozioneCondizione $condizione, User $utente): bool
    {
        $giorni = (int) $condizione->parametro('giorni');

        return match ($condizione->tipo) {
            TipoDiCondizione::Abbonamento => $this->statoDellAbbonamento($utente, (string) $condizione->parametro('stato')),

            TipoDiCondizione::RegistratoDaMenoDi => $utente->created_at !== null
                && $utente->created_at->gt(now()->subDays($giorni)),

            TipoDiCondizione::RegistratoDopo => $utente->created_at !== null
                && $condizione->parametro('data') !== null
                && $utente->created_at->gte(Carbon::parse((string) $condizione->parametro('data'))->startOfDay()),

            TipoDiCondizione::ClienteDaPiuDi => ($primo = $this->primoAbbonamentoPagato($utente)) !== null
                && $primo->lte(now()->subMonthsNoOverflow((int) $condizione->parametro('mesi'))),

            TipoDiCondizione::PianoAttuale => in_array(
                $this->piano->per($utente)->getKey(),
                array_map('intval', (array) $condizione->parametro('plan_ids')),
                true,
            ),

            TipoDiCondizione::InattivoDa => $utente->last_login_at === null
                || $utente->last_login_at->lt(now()->subDays($giorni)),

            TipoDiCondizione::MaiCompratoGettoni => ! AiCreditMovement::withoutGlobalScopes()
                ->where('user_id', $utente->getKey())
                ->where('causale', AiCreditMovement::ACQUISTO)
                ->exists(),

            TipoDiCondizione::GettoniFiniti => (int) $utente->ai_credits === 0,

            TipoDiCondizione::GettoniComprati => (int) AiCreditMovement::withoutGlobalScopes()
                ->where('user_id', $utente->getKey())
                ->where('causale', AiCreditMovement::ACQUISTO)
                ->sum('delta') >= (int) $condizione->parametro('gettoni'),
        };
    }

    private function statoDellAbbonamento(User $utente, string $stato): bool
    {
        $abbonato = $this->piano->eAbbonato($utente);

        return match ($stato) {
            'abbonato' => $abbonato,
            'non_abbonato' => ! $abbonato,
            'ex_abbonato' => ! $abbonato && $this->primoAbbonamentoPagato($utente) !== null,
            'pagamento_fallito' => $this->context->runWithoutTenant(fn (): bool => $utente->tenant_id !== null
                && PlanSubscription::query()
                    ->where('tenant_id', $utente->tenant_id)
                    ->attivi()
                    ->whereNotNull('pagamento_fallito_il')
                    ->exists()),
            'in_prova' => $this->eInProva($utente),
            default => false,
        };
    }

    /** Quando ha cominciato a pagare un abbonamento, se l'ha mai fatto. */
    private function primoAbbonamentoPagato(User $utente): ?Carbon
    {
        if ($utente->tenant_id === null) {
            return null;
        }

        $inizio = $this->context->runWithoutTenant(fn (): mixed => PlanSubscription::query()
            ->where('tenant_id', $utente->tenant_id)
            ->whereNotNull('stripe_subscription_id')
            ->min('starts_at'));

        return $inizio === null ? null : Carbon::parse($inizio);
    }

    /**
     * «In prova»: ha usato una promozione con la prova gratuita, e i suoi giorni
     * non sono ancora finiti. 💡 Le prove nascono **solo** dalle promozioni (P3),
     * quindi è da lì che si leggono.
     */
    private function eInProva(User $utente): bool
    {
        return PromozioneAssegnazione::query()
            ->cheContano()
            ->where('user_id', $utente->getKey())
            ->whereNotNull('usata_il')
            ->with('promozione.premi')
            ->get()
            ->contains(function (PromozioneAssegnazione $a): bool {
                $giorni = (int) $a->promozione?->premi
                    ->filter(fn (PromozionePremio $p): bool => $p->tipo === TipoDiPremio::ProvaGratuita)
                    ->max('giorni');

                return $giorni > 0 && $a->usata_il->copy()->addDays($giorni)->isFuture();
            });
    }

    /**
     * Tutte le persone attive che questa promozione prende.
     *
     * ⚠️ Si filtra in PHP e non con una join: i bersagli sono **domande** («gli
     * allievi di questo trainer»), non righe — la stessa ragione per cui F2
     * non li ha fatti polimorfici. `lazyById` tiene la memoria piatta anche su
     * migliaia di utenti.
     *
     * @return LazyCollection<int, User>
     */
    public function destinatari(HaUnPubblico $promozione): LazyCollection
    {
        $promozione->loadMissing(['bersagli', 'condizioni']);

        return User::withoutGlobalScopes()
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->lazyById(500)
            ->filter(fn (User $utente): bool => $this->prende($promozione, $utente));
    }

    private function rientra(PromozioneBersaglio $bersaglio, User $utente): bool
    {
        return match ($bersaglio->tipo) {
            TipoBersaglio::Tutti => true,

            TipoBersaglio::Utente => $bersaglio->user_id === $utente->getKey(),

            /*
             * ⚠️ La palestra è quella **in cui la persona sta**, non quella che
             * possiede: un trainer con un portfolio suo lavora in una palestra
             * senza avere lì il proprio `tenant_id`, e il conteggio dei ruoli è
             * il posto in cui quel legame è scritto.
             */
            TipoBersaglio::Palestra => $bersaglio->tenant_id !== null
                && $this->staNellaPalestra($utente, (int) $bersaglio->tenant_id),

            TipoBersaglio::Ruolo => $bersaglio->ruolo !== null
                && $this->haIlRuolo($utente, $bersaglio->ruolo),

            TipoBersaglio::AllieviDiTrainer => $bersaglio->user_id !== null
                && $this->eAllievoDi($utente, (int) $bersaglio->user_id),
        };
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
}
