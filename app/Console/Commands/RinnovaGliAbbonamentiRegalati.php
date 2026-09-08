<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantKind;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * 🎁 Rinnova gli abbonamenti regalati che devono rinnovarsi — 08/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * Il committente: *«deve funzionare anche con gli abbonamenti che regalo»*, del
 * toggle «Rinnova in automatico».
 *
 * ⛔ **Per un abbonamento di Stripe il rinnovo lo fa Stripe.** Per un regalo non
 * lo fa nessuno: senza questo comando, `rinnova = true` su un regalo sarebbe una
 * spunta che non governa niente — 🚨 il difetto peggiore di tutti, un
 * interruttore che sembra funzionare e non e' collegato a niente.
 *
 * ══ ⛔ COSA NON TOCCA, E PERCHE' E' LA RIGA PIU' IMPORTANTE DEL FILE ═══════
 *
 * 🚨 **Le palestre.** Il loro abbonamento ha `ends_at = null` — la convenzione
 * «non scade mai» che vale da sempre — e **nessun id di Stripe**, esattamente
 * come un regalo. ⚠️ Distinguerli guardando solo Stripe vorrebbe dire toccare le
 * righe delle palestre con un comando che gira ogni notte.
 *
 * 💡 La differenza sta nel **tipo del tenant**: un regalo vive su un tenant
 * `personal`, l'abbonamento di una palestra su uno `gym`. ⛔ E in piu' si
 * pretende `ends_at IS NOT NULL`: una riga senza scadenza non ha niente da
 * rinnovare, e allungarla sarebbe scrivere una data dove la convenzione dice
 * che non deve essercene nessuna.
 */
class RinnovaGliAbbonamentiRegalati extends Command
{
    protected $signature = 'abbonamenti:rinnova-i-regali';

    protected $description = 'Allunga di un mese i regali con il rinnovo automatico acceso';

    public function handle(): int
    {
        /*
         * ⚠️ **Si guarda avanti di un giorno, non «scaduti».** Un comando che
         * rinnova solo cio' che e' gia' scaduto lascia un buco: fra la
         * mezzanotte e il giro delle 3:40 quella persona **non e' abbonata**, e
         * l'app le mostra la modale «il tuo abbonamento e' scaduto» a chi il
         * rinnovo non l'ha mai spento.
         *
         * 🚨 E' il tipo di difetto che si vede una volta ogni trenta giorni,
         * per tre ore, e nessuno riesce mai a riprodurlo.
         */
        $righe = PlanSubscription::withoutGlobalScopes()
            ->whereNull('stripe_subscription_id')
            ->whereNotNull('ends_at')
            ->where('rinnova', true)
            ->where('ends_at', '<=', now()->addDay())
            ->whereIn(
                'tenant_id',
                Tenant::query()
                    ->where('kind', TenantKind::Personal->value)
                    ->select('id'),
            )
            ->get();

        foreach ($righe as $riga) {
            /*
             * 💡 **Un mese e un giorno dalla scadenza, non da adesso.** Sommare
             * a `now()` farebbe scivolare in avanti la data a ogni giro — dopo
             * un anno un regalo del primo del mese scadrebbe a meta'. ⚠️ Il
             * giorno in piu' e' lo stesso margine che si da' a Stripe: un
             * rinnovo che arriva con qualche ora di ritardo non deve lasciare
             * nessuno fuori.
             */
            $riga->update([
                'ends_at' => $riga->ends_at->addMonth()->addDay(),
            ]);
        }

        if ($righe->isNotEmpty()) {
            Log::info('Regali rinnovati.', ['quanti' => $righe->count()]);
        }

        $this->info("Regali rinnovati: {$righe->count()}");

        return self::SUCCESS;
    }
}
