<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 🛡️ **Chi sta pagando e non comanda più niente** — 3b-AH, Passo 0 (12/09/2026).
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * Dal 12/09 `Cassa::loPuoGestire()` chiede una cosa sola: **sei tu il
 * proprietario di questo tenant?** La risposta sta in `tenants.proprietario_id`,
 * che e' `nullable` per forza — un tenant nasce **prima** di chi lo possiede.
 *
 * ⛔ **Il prezzo di quel `null` e' questo comando.** Un tenant senza
 * proprietario non e' di nessuno: `Tenant::eDi()` risponde `false` a chiunque, e
 * quindi **nessuno** puo' toccarne l'abbonamento.
 *
 * ══ 🚨 DUE SITUAZIONI DIVERSE, E SOLO UNA E' UN GUASTO ════════════════════
 *
 * | Tenant senza proprietario | Cos'e' |
 * |---|---|
 * | senza abbonamento a pagamento | 💡 **sopportabile**: una palestra appena creata dal pannello, che non ha ancora un amministratore |
 * | **con** un abbonamento a pagamento attivo | 🚨 **guasto**: qualcuno paga e non puo' piu' gestire cio' che paga |
 *
 * ⚠️ **Per questo l'uscita distingue**, e non si limita a contare le righe: un
 * comando che si lamenta anche del caso innocuo viene ignorato dopo tre giorni,
 * e il giorno che grida davvero non lo guarda nessuno.
 *
 * ══ ⛔ PERCHE' NON RIPARA DA SOLO ═════════════════════════════════════════
 *
 * 🚨 Riempire `proprietario_id` indovinando vorrebbe dire **dare a qualcuno il
 * comando sull'abbonamento di qualcun altro**. E' esattamente la regola 1 di AH:
 * *«nessuna scrittura su chi paga senza sapere chi paga»*. 💡 Questo comando
 * **dice**; a scrivere ci va una persona che sa.
 */
class TenantSenzaProprietario extends Command
{
    protected $signature = 'abbonamenti:tenant-senza-proprietario';

    protected $description = 'Elenca i tenant rimasti senza proprietario, e fallisce se uno di loro sta pagando';

    public function handle(): int
    {
        $orfani = Tenant::query()
            ->whereNull('proprietario_id')
            ->orderBy('id')
            ->get(['id', 'name', 'kind']);

        if ($orfani->isEmpty()) {
            $this->info('Ogni tenant ha un proprietario.');

            return self::SUCCESS;
        }

        $paganti = 0;
        $righe = [];

        foreach ($orfani as $tenant) {
            $paga = $this->staPagando((int) $tenant->getKey());

            if ($paga) {
                $paganti++;
            }

            $righe[] = [
                $tenant->getKey(),
                $tenant->kind->value,
                $tenant->name,
                $this->quantiCiStanno((int) $tenant->getKey()),
                $paga ? 'SI' : 'no',
            ];
        }

        $this->table(['id', 'tipo', 'nome', 'utenti', 'paga'], $righe);

        if ($paganti === 0) {
            $this->warn($orfani->count().' tenant senza proprietario, nessuno dei quali sta pagando.');

            return self::SUCCESS;
        }

        /*
         * 🚨 **Il messaggio dice cosa succede a una persona**, non cosa manca a
         * una colonna. Chi legge questo alle tre di notte deve capire in una
         * riga se deve alzarsi.
         */
        $this->error(
            $paganti.' tenant con un abbonamento a pagamento attivo non hanno un proprietario: '
            .'chi paga NON puo\' gestire il proprio abbonamento.'
        );

        return self::FAILURE;
    }

    /**
     * C'e' un abbonamento attivo e non e' quello gratuito?
     *
     * 💡 E' la stessa domanda di `PianoAttivo::eAbbonato()`, chiesta al tenant
     * invece che alla persona — qui la persona non c'e', ed e' il punto.
     *
     * ⚠️ `withoutGlobalScopes()`: gira da riga di comando, dove il contesto del
     * tenant e' vuoto e una query scopata non troverebbe mai niente. Il filtro
     * non si perde, e' rimesso a mano ed e' piu' stretto.
     */
    private function staPagando(int $tenantId): bool
    {
        return PlanSubscription::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->attivi()
            ->whereHas('plan', fn ($q) => $q->where('code', '!=', Plan::FREE))
            ->exists();
    }

    private function quantiCiStanno(int $tenantId): int
    {
        return (int) DB::table('users')->where('tenant_id', $tenantId)->count();
    }
}
