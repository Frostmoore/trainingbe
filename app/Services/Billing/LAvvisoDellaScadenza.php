<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\Notifica;
use App\Enums\TenantStatus;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Services\Notifiche\LeNotifiche;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ⏳ **«Il tuo abbonamento sta per scadere»** — voce 62, 27/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * 🔑 È la notifica che **evita** le altre due. ⛔ Senza, la prima cosa che una
 * persona sa del suo abbonamento è che è finito: l'AI spenta, il pannello
 * ridotto all'avviso, e la sensazione — legittima — di essere stata buttata
 * fuori senza preavviso.
 *
 * 💡 `IncassoFallito` arriva quando il danno è fatto; questa arriva prima, ed è
 * l'unica delle tre che può far finire la storia bene.
 *
 * ══ 🚨 CHI VIENE AVVISATO, E CHI NO — LE QUATTRO ESCLUSIONI ═══════════════
 *
 * ⚠️ Ognuna esiste perché avvisare quella persona sarebbe **sbagliato**, non
 * perché è comodo escluderla:
 *
 * | Chi | Perché no |
 * |---|---|
 * | 🔁 chi ha `rinnova = true` | non sta scadendo: **si rinnova**. ⛔ Dirgli «sta per scadere» lo manda a cercare un pulsante che non deve premere — e se la carta poi fallisse, quello è `IncassoFallito`, che è un'altra notifica con un'altra pagina |
 * | ⏸️ chi è in pausa (`in_pausa_dal`) | un abbonamento in pausa **riprenderà**: `ends_at` non è stato toccato, quindi sembra vicino e non lo è |
 * | ⛔ chi è già sospeso | per lui la notizia non è la scadenza, è che il servizio è già spento: gliel'ha detto `IncassoFallito` |
 * | ✅ chi è già stato avvisato | `scadenza_avvisata_il` è la memoria: 🚨 senza, il giro notturno manderebbe la stessa notifica ogni notte della finestra |
 *
 * ══ ⚖️ PERCHE' UN SERVIZIO E NON SOLO UN COMANDO ══════════════════════════
 *
 * 💡 Il comando è un guscio: legge le opzioni e stampa. ⛔ Mettendo la regola
 * dentro il comando, l'unico modo di provarla sarebbe eseguire la console — e le
 * quattro esclusioni qui sopra sono esattamente il genere di cosa che va provata
 * **caso per caso**, non a valle di una stampa.
 *
 * 🔑 È la stessa divisione di `LaSospensioneAutomatica` e `SospendiIMorosi`.
 */
class LAvvisoDellaScadenza
{
    /**
     * ⏰ **Quanti giorni prima.**
     *
     * ⚖️ Sette perché è il tempo che serve a fare qualcosa: ⛔ con due giorni un
     * avviso è una constatazione, e con trenta si dimentica. 💡 E coincide con
     * una settimana intera, quindi chi paga di persona ha comunque un giorno
     * utile per farlo.
     */
    public const GIORNI_PRIMA = 7;

    public function __construct(private TenantContext $contesto) {}

    /**
     * 🤖 Il giro: chi va avvisato, e — con `$scrivi` — glielo dice.
     *
     * @return list<int> gli id degli abbonamenti avvisati (o da avvisare)
     */
    public function giro(bool $scrivi = false): array
    {
        $fatti = [];

        foreach ($this->daAvvisare() as $abbonamento) {
            $fatti[] = (int) $abbonamento->getKey();

            if ($scrivi) {
                $this->avvisa($abbonamento);
            }
        }

        return $fatti;
    }

    /**
     * 🔎 **Gli abbonamenti che scadono entro la finestra e non lo sanno.**
     *
     * ⚠️ `runWithoutTenant`: è un giro di piattaforma, e gira da un comando dove
     * il contesto è vuoto comunque. 🚨 Lo si dice **esplicitamente** perché
     * `TenantScope` a contesto vuoto non filtra: affidarsi a quello vorrebbe dire
     * che il giorno che qualcuno chiama questo metodo da dentro una richiesta,
     * troverebbe gli abbonamenti di una palestra sola e nessuno lo noterebbe.
     *
     * @return Collection<int, PlanSubscription>
     */
    public function daAvvisare(): Collection
    {
        $entro = Carbon::now()->addDays(self::GIORNI_PRIMA);

        return $this->contesto->runWithoutTenant(
            fn (): Collection => PlanSubscription::query()
                ->withoutGlobalScopes()
                ->whereNotNull('ends_at')
                ->where('ends_at', '>', Carbon::now())
                ->where('ends_at', '<=', $entro)

                /*
                 * 🔁 **Chi si rinnova non sta scadendo.** ⚠️ `where('rinnova', false)`
                 * e non `whereNot`: la colonna ha `default(true)` e non è nullable,
                 * quindi i due sarebbero uguali — ma questo dice cosa si cerca.
                 */
                ->where('rinnova', false)
                ->whereNull('in_pausa_dal')
                ->whereNull('scadenza_avvisata_il')
                ->orderBy('ends_at')
                ->get(),
        );
    }

    /**
     * 📲 Avvisa chi paga, e **segna** che è stato avvisato.
     *
     * ══ ⚠️ L'ORDINE FRA LA NOTIFICA E LA COLONNA ══════════════════════════
     *
     * 🔑 Prima si **segna**, poi si manda. ⛔ Al contrario, un guasto fra le due
     * righe lascerebbe la colonna vuota e la notifica partita: la notte dopo
     * ripartirebbe, e la persona la riceverebbe due volte.
     *
     * 💡 Nel verso giusto il caso peggiore è **una notifica persa**, che è meno
     * peggio di una ripetuta: chi non la riceve vede comunque la scadenza nel
     * pannello, chi la riceve due volte spegne le notifiche.
     */
    public function avvisa(PlanSubscription $abbonamento): bool
    {
        $spazio = $this->contesto->runWithoutTenant(
            fn (): ?Tenant => Tenant::withoutGlobalScopes()->find($abbonamento->tenant_id),
        );

        if ($spazio === null || $spazio->status === TenantStatus::Suspended) {
            return false;
        }

        $chi = $spazio->aChiScrivere();

        if ($chi === null) {
            Log::warning('Abbonamento in scadenza: nessuno a cui scrivere.', [
                'tenant' => $spazio->getKey(),
            ]);

            return false;
        }

        $abbonamento->forceFill(['scadenza_avvisata_il' => Carbon::now()])->save();

        $giorni = (int) Carbon::now()->startOfDay()->diffInDays($abbonamento->ends_at->startOfDay());

        try {
            app(LeNotifiche::class)->manda(
                Notifica::AbbonamentoInScadenza,
                $chi,
                'Il tuo abbonamento sta per scadere',
                $this->corpo($spazio, $giorni),
            );
        } catch (Throwable $e) {
            Log::warning('Abbonamento in scadenza: push non partita.', [
                'tenant' => $spazio->getKey(),
                'errore' => $e->getMessage(),
            ]);
        }

        return true;
    }

    /**
     * ✍️ **Il testo, e perché non dice «paga».**
     *
     * 📌 Il committente sull'avviso del pannello, 27/09: era *«troppo "sono uno
     * sciacallo e voglio farmi pagare"»*. ⚖️ Lo stesso vale qui, e a maggior
     * ragione: qui **non è ancora successo niente**. 💡 Si dice quanto tempo
     * resta e cosa smette di funzionare — che è l'informazione — e si lascia
     * decidere a chi legge.
     */
    private function corpo(Tenant $spazio, int $giorni): string
    {
        $quando = match (true) {
            $giorni <= 0 => 'scade oggi',
            $giorni === 1 => 'scade domani',
            default => "scade fra {$giorni} giorni",
        };

        return "L'abbonamento di {$spazio->name} {$quando}. Se vuoi continuare, puoi rinnovarlo quando ti è comodo.";
    }
}
