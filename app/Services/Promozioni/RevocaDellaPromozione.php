<?php

declare(strict_types=1);

namespace App\Services\Promozioni;

use App\Enums\AuditAction;
use App\Enums\StatoAssegnazione;
use App\Enums\TipoDiPremio;
use App\Models\AiCreditMovement;
use App\Models\LottoDiGettoni;
use App\Models\PlanSubscription;
use App\Models\Promozione;
use App\Models\PromozioneAssegnazione;
use App\Models\PromozionePremio;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PortafoglioGettoni;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ↩️ **Revocare una promozione già data** — 24/09/2026, P7 di `plan_promozioni.md`
 * (opzione 41).
 *
 * 📌 *«Revoca: si possono togliere anche i gettoni regalati e non ancora spesi»*.
 *
 * ══ 🚨 COSA SI TOGLIE, E COSA NO ═════════════════════════════════════════
 *
 * | Premio | Revocando |
 * |---|---|
 * | gettoni con scadenza | il **residuo esatto** dei suoi lotti (quelli spesi restano spesi) |
 * | gettoni senza scadenza | fino ai gettoni dati, **nel limite del saldo**: ⚠️ il saldo non sa quali sono regalati, quindi se la persona ne ha spesi alcuni si tolgono anche gettoni suoi — la finestra lo dice prima |
 * | gettoni ricorrenti | smettono di arrivare |
 * | abbonamento regalato / upgrade | finisce **adesso** |
 * | posti extra | tolti dallo spazio (mai sotto zero) |
 * | sconti già incassati | ⛔ **niente**: il pagamento è avvenuto, e un rimborso si fa da Stripe |
 * | sconto o bonus in sospeso | non vale più |
 *
 * ⛔ **Non si cancella niente**: l'assegnazione diventa `revocata`, e i gettoni
 * tolti sono un movimento nuovo (`revoca_promozione`). Il budget torna
 * disponibile (conta solo le assegnazioni che contano); `usata_volte` no — è la
 * storia di quante volte è stata data.
 */
class RevocaDellaPromozione
{
    public function __construct(
        private readonly PortafoglioGettoni $portafoglio,
        private readonly TenantContext $context,
        private readonly AuditLogger $registro,
    ) {}

    /**
     * ↩️ Revoca una assegnazione.
     *
     * @return int i gettoni tolti
     */
    public function revoca(PromozioneAssegnazione $assegnazione, ?User $operatore = null): int
    {
        if (! $assegnazione->stato->conta()) {
            return 0;
        }

        $tolti = DB::transaction(function () use ($assegnazione, $operatore): int {
            $bloccata = PromozioneAssegnazione::query()->with('promozione.premi')->lockForUpdate()->findOrFail($assegnazione->getKey());

            if (! $bloccata->stato->conta()) {
                return 0;
            }

            $utente = User::withoutGlobalScopes()->find($bloccata->user_id);
            $tolti = $utente !== null ? $this->togliIGettoni($bloccata, $utente) : 0;

            $this->chiudiLAbbonamento($bloccata);
            $this->togliIPosti($bloccata);

            $bloccata->forceFill([
                'stato' => StatoAssegnazione::Revocata,
                'revocata_il' => now(),
                'revocata_da_id' => $operatore?->getKey(),
                'acquisto_in_sospeso' => false,
                'prossimo_accredito_il' => null,
            ])->save();

            return $tolti;
        });

        $persona = User::withoutGlobalScopes()->find($assegnazione->user_id);

        // ⚠️ Soggetto = la persona: `AuditLog` vuole una palestra, e la promozione non ne ha.
        if ($persona !== null && $persona->tenant_id !== null) {
            $this->registro->log(AuditAction::PromozioneRevocata, $persona, [
                'promozione' => $assegnazione->promozione?->codice,
                'gettoni_tolti' => $tolti,
            ], $operatore, $persona->tenant_id);
        }

        return $tolti;
    }

    /**
     * ↩️ Revoca **a tutti** quelli che l'hanno.
     *
     * @return array{persone: int, gettoni: int}
     */
    public function revocaATutti(Promozione $promozione, ?User $operatore = null): array
    {
        $persone = 0;
        $gettoni = 0;

        $promozione->assegnazioni()->cheContano()->orderBy('id')->each(function (PromozioneAssegnazione $a) use ($operatore, &$persone, &$gettoni): void {
            $gettoni += $this->revoca($a, $operatore);
            $persone++;
        });

        return ['persone' => $persone, 'gettoni' => $gettoni];
    }

    private function togliIGettoni(PromozioneAssegnazione $assegnazione, User $utente): int
    {
        if ($assegnazione->gettoni <= 0) {
            return 0;
        }

        $lotti = LottoDiGettoni::query()->aperti()->where('promozione_assegnazione_id', $assegnazione->getKey())->get();

        // Con i lotti si sa il residuo esatto; senza, al massimo i gettoni dati.
        $daTogliere = $lotti->isNotEmpty() || $this->haSoloGettoniCheScadono($assegnazione)
            ? (int) $lotti->sum('gettoni_restanti')
            : $assegnazione->gettoni;

        $saldo = (int) User::withoutGlobalScopes()->whereKey($utente->getKey())->value('ai_credits');
        $daTogliere = min($daTogliere, $saldo);

        // ⚠️ Prima si chiudono i lotti, poi il movimento: `allineaILotti()` non deve scalarli di nuovo.
        foreach ($lotti as $lotto) {
            $lotto->forceFill(['gettoni_restanti' => 0, 'chiuso_il' => now()])->save();
        }

        if ($daTogliere > 0) {
            $this->portafoglio->accredita(
                $utente,
                -$daTogliere,
                nota: 'Revoca «'.$assegnazione->promozione?->nome.'»',
                causale: AiCreditMovement::REVOCA_PROMOZIONE,
            );
        }

        return $daTogliere;
    }

    /** Tutti i gettoni di questa promozione avevano una scadenza (e quindi un lotto)? */
    private function haSoloGettoniCheScadono(PromozioneAssegnazione $assegnazione): bool
    {
        $conGettoni = $assegnazione->promozione?->premi
            ->filter(fn (PromozionePremio $p): bool => in_array($p->tipo, [
                TipoDiPremio::GettoniRegalo, TipoDiPremio::GettoniRicorrenti, TipoDiPremio::GettoniBonus,
                TipoDiPremio::GettoniAOgniRinnovo,
            ], true));

        return $conGettoni !== null && $conGettoni->isNotEmpty() && $conGettoni->every(fn (PromozionePremio $p): bool => (int) $p->giorni > 0);
    }

    private function chiudiLAbbonamento(PromozioneAssegnazione $assegnazione): void
    {
        if ($assegnazione->plan_subscription_id === null) {
            return;
        }

        $this->context->runWithoutTenant(function () use ($assegnazione): void {
            PlanSubscription::query()
                ->whereKey($assegnazione->plan_subscription_id)
                ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()))
                ->update(['ends_at' => now()]);
        });
    }

    private function togliIPosti(PromozioneAssegnazione $assegnazione): void
    {
        $premi = $assegnazione->promozione?->premi->filter(fn (PromozionePremio $p): bool => $p->tipo === TipoDiPremio::PostiExtra);

        if ($premi === null || $premi->isEmpty() || $assegnazione->tenant_id === null) {
            return;
        }

        $this->context->runWithoutTenant(function () use ($premi, $assegnazione): void {
            $spazio = Tenant::query()->find($assegnazione->tenant_id);

            if ($spazio === null) {
                return;
            }

            foreach ($premi as $premio) {
                $colonna = $premio->posti_di === 'trainer' ? 'posti_trainer_extra' : 'posti_extra';
                $spazio->forceFill([$colonna => max(0, (int) $spazio->{$colonna} - (int) $premio->posti)]);
            }

            $spazio->save();
        });
    }
}
