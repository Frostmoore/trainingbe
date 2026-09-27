<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\TenantKind;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * 🏠 **Chi non abita più a casa propria** — F4.1 di
 * `memory/plan_spazi_personali.md`, 27/09/2026.
 *
 * ══ 🚨 COSA SI STA GUARDANDO ══════════════════════════════════════════════
 *
 * ⛔ Entrare in una palestra o accettare un free trainer è sempre stato un
 * **trasloco**: `UnisciAUnaPalestra` riscrive `tenant_id` su ogni riga di ogni
 * tabella, e la persona finisce a vivere **dentro lo spazio di qualcun altro**.
 *
 * 📌 Il committente: *«il mio account è e sarà sempre mio»*.
 *
 * 💡 Questo comando **elenca** chi è in quella condizione, così si può
 * guardare prima di muovere qualunque cosa.
 *
 * ══ ⚠️ PER COSTRUZIONE NON SCRIVE MAI ═════════════════════════════════════
 *
 * 🔑 **Non esiste una modalità che scrive.** Lo spostamento vero sarà un
 * comando **suo**, separato, da lanciare solo dopo aver letto questo. ⛔ Un
 * comando solo con un `--force` è il modo in cui qualcuno, di notte, lancia
 * la cosa sbagliata.
 */
class RiportaACasa extends Command
{
    protected $signature = 'spazi:riporta-a-casa';

    protected $description = 'Elenca chi è stato traslocato nello spazio di una palestra o di un trainer';

    public function handle(TenantContext $context): int
    {
        $this->info('🏠 Chi non abita più a casa propria');
        $this->newLine();

        $traslocati = $this->traslocati($context);

        if ($traslocati === []) {
            $this->info('✅ Nessuno: abitano tutti a casa propria.');

            return self::SUCCESS;
        }

        $righe = [];
        $senzaCasa = 0;

        foreach ($traslocati as $p) {
            $suo = $context->runWithoutTenant(
                fn (): ?Tenant => Tenant::withoutGlobalScopes()
                    ->where('proprietario_id', $p->id)
                    ->where('kind', TenantKind::Personal->value)
                    ->first(),
            );

            if ($suo === null) {
                $senzaCasa++;
            }

            $righe[] = [
                $p->id,
                mb_strimwidth((string) ($p->email ?? $p->name), 0, 30, '…'),
                mb_strimwidth((string) $p->spazio, 0, 20, '…'),
                $p->kind,
                $suo === null ? '⛔ NON CE L\'HA' : '#'.$suo->getKey(),
                $this->quanteRighe($context, (int) $p->id, (int) $p->tenant_id),
            ];
        }

        $this->table(['id', 'chi', 'abita in', 'tipo', 'casa sua', 'righe sue'], $righe);

        $this->newLine();
        $this->warn('Traslocati: '.count($righe));

        if ($senzaCasa > 0) {
            $this->error('⛔ '.$senzaCasa.' non hanno più uno spazio personale: va ricreato PRIMA di spostare.');
        }

        $this->line('⚠️ Questo comando non ha scritto niente.');

        return self::SUCCESS;
    }

    /**
     * Le persone il cui `tenant_id` punta a uno spazio che **non è il loro**.
     *
     * ⚠️ «Suo» si riconosce da `tenants.proprietario_id`, **non dal `kind`**: anche
     * lo spazio di un free trainer è `personal`, ⛔ ma non è casa **tua**.
     *
     * @return list<object>
     */
    private function traslocati(TenantContext $context): array
    {
        return $context->runWithoutTenant(
            fn (): array => DB::table('users')
                ->join('tenants', 'tenants.id', '=', 'users.tenant_id')
                ->whereNull('users.deleted_at')
                ->whereNull('tenants.deleted_at')
                /*
                 * 🚨 Il raggruppamento è obbligatorio: senza le parentesi,
                 * l'`orWhereNull` scavalcherebbe **tutti** i filtri di sopra e
                 * l'elenco comprenderebbe anche i cancellati.
                 */
                ->where(function ($q): void {
                    $q->whereColumn('tenants.proprietario_id', '!=', 'users.id')
                        ->orWhereNull('tenants.proprietario_id');
                })
                ->select(
                    'users.id',
                    'users.name',
                    'users.email',
                    'users.tenant_id',
                    'tenants.name as spazio',
                    'tenants.kind',
                )
                ->orderBy('users.id')
                ->get()
                ->all(),
        );
    }

    /**
     * Quante righe, nelle tabelle principali, sono marcate con lo spazio
     * altrui **e** appartengono a questa persona.
     *
     * 💡 Un numero, non un elenco di mille righe che nessuno leggerebbe:
     * serve a dare la misura di cosa verrebbe spostato.
     */
    private function quanteRighe(TenantContext $context, int $userId, int $tenantId): int
    {
        $totale = 0;
        $schema = DB::getSchemaBuilder();

        foreach (['workout_plans', 'diary_entries', 'conversations'] as $tabella) {
            if (! $schema->hasTable($tabella) || ! $schema->hasColumn($tabella, 'user_id')) {
                continue;
            }

            $totale += $context->runWithoutTenant(
                fn (): int => DB::table($tabella)
                    ->where('tenant_id', $tenantId)
                    ->where('user_id', $userId)
                    ->count(),
            );
        }

        return $totale;
    }
}
