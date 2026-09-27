<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\TenantKind;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * 🏠 **Chi appartiene a chi, e da quando** — F2.3/F2.4 di
 * `memory/plan_spazi_personali.md`, 27/09/2026.
 *
 * ══ ⚖️ COSA FA, E COSA NON FA ═════════════════════════════════════════════
 *
 * 🔑 Tiene allineata la tabella `appartenenze` quando qualcuno entra in una
 * palestra o ne esce. ⛔ **Non sposta dati e non tocca `users.tenant_id`**: il
 * trasloco resta acceso, e questo servizio gli cammina accanto.
 *
 * 💡 È la fase intermedia di una migrazione sicura: **si scrive nella tabella
 * nuova** mentre la vecchia strada funziona ancora. ⚠️ Solo quando *tutto*
 * legge da qui si potrà spegnere il trasloco — e quello sarà un cambio suo,
 * isolato e reversibile, invece di un salto nel vuoto.
 *
 * ══ 🚨 PERCHE' UN SERVIZIO E NON UN EVENTO DEL MODELLO ════════════════════
 *
 * ⛔ Agganciarsi al salvataggio di `users.tenant_id` sarebbe stato più corto e
 * molto peggio: un pezzo di logica che scatta da solo, invisibile a chi legge
 * `UnisciAUnaPalestra`, e che parte **anche** nelle migrazioni e nei seeder.
 * 💡 Qui invece si vede chi chiama, e quando.
 */
class LeAppartenenze
{
    public function __construct(private readonly TenantContext $context) {}

    /**
     * Apre l'appartenenza di questa persona a questo spazio.
     *
     * ⚠️ **Idempotente**: se ce n'è già una viva non fa niente. ⛔ Aprirne una
     * seconda vorrebbe dire due righe vive per lo stesso legame, e il
     * conteggio di «da quante palestre dipendi» comincerebbe a mentire.
     *
     * 🔑 Il `tipo` si ricava dallo spazio: una palestra è `gym`, tutto il
     * resto (lo spazio di un free trainer) è `trainer`.
     */
    public function entra(User $utente, Tenant $spazio): void
    {
        $tipo = $spazio->kind === TenantKind::Gym ? 'gym' : 'trainer';

        $this->context->runWithoutTenant(function () use ($utente, $spazio, $tipo): void {
            $gia = DB::table('appartenenze')
                ->where('user_id', $utente->getKey())
                ->where('tenant_id', $spazio->getKey())
                ->where('tipo', $tipo)
                ->whereNull('finita_il')
                ->exists();

            if ($gia) {
                return;
            }

            /*
             * 🚨 **Se sta entrando in una palestra, quella di prima si
             * chiude.** ⛔ Senza, il vincolo `una_palestra_sola` del database
             * rifiuterebbe la riga nuova — e avrebbe ragione: non si può
             * appartenere a due palestre insieme. 💡 Il vincolo qui non è un
             * ostacolo da aggirare: è quello che ricorda la regola.
             */
            if ($tipo === 'gym') {
                DB::table('appartenenze')
                    ->where('user_id', $utente->getKey())
                    ->where('tipo', 'gym')
                    ->whereNull('finita_il')
                    ->update(['finita_il' => now(), 'updated_at' => now()]);
            }

            DB::table('appartenenze')->insert([
                'user_id' => $utente->getKey(),
                'tenant_id' => $spazio->getKey(),
                'tipo' => $tipo,
                'iniziata_il' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * Chiude l'appartenenza di questa persona a questo spazio.
     *
     * ⛔ **Non cancella la riga**: la storia serve, ed è quella che fa
     * ripartire l'abbonamento di prima quando se ne vanno tutti. 💡 Chiudere e
     * cancellare sembrano la stessa cosa il giorno in cui si scrive il codice,
     * e non lo sono mai il giorno in cui si va a cercare cos'è successo.
     */
    public function esci(User $utente, Tenant $spazio): void
    {
        $this->context->runWithoutTenant(function () use ($utente, $spazio): void {
            DB::table('appartenenze')
                ->where('user_id', $utente->getKey())
                ->where('tenant_id', $spazio->getKey())
                ->whereNull('finita_il')
                ->update(['finita_il' => now(), 'updated_at' => now()]);
        });
    }
}
