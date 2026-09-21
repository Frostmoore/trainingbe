<?php

declare(strict_types=1);

namespace App\Services\Promozioni;

use App\Enums\TipoBersaglio;
use App\Models\Promozione;
use App\Models\PromozioneBersaglio;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
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
 * In P1 c'è solo il primo passo; P5 aggiunge esclusioni e condizioni.
 */
class ChiLaRiceve
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * 🎯 **Questa persona rientra?**
     *
     * ⚠️ I bersagli sono in **OR**: «per i trainer e per gli allievi di Tizio»
     * vuol dire «chi è trainer **oppure** allievo di Tizio». ⛔ In AND sarebbe
     * «i trainer che sono anche allievi di Tizio», cioè quasi nessuno.
     */
    public function prende(Promozione $promozione, User $utente): bool
    {
        foreach ($promozione->bersagli as $bersaglio) {
            if ($this->rientra($bersaglio, $utente)) {
                return true;
            }
        }

        return false;
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
    public function destinatari(Promozione $promozione): LazyCollection
    {
        $promozione->loadMissing('bersagli');

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
