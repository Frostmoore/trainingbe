<?php

declare(strict_types=1);

use App\Enums\TenantKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 🏠 **Le appartenenze, riempite dallo stato attuale** — F2.2 di
 * `memory/plan_spazi_personali.md`, 27/09/2026.
 *
 * ⚠️ **Non sposta un solo dato**, e non cambia nessun comportamento: legge il
 * mondo com'è e lo descrive nella tabella nuova. 💡 Così, prima che qualcuno
 * ci si appoggi, si può verificare che la descrizione sia giusta.
 *
 * ══ 🔑 DA DOVE SI LEGGE ═══════════════════════════════════════════════════
 *
 * | Appartenenza | Si ricava da |
 * |---|---|
 * | `gym` | `users.tenant_id`, **se** quel tenant è una palestra |
 * | `trainer` | ogni riga viva di `trainer_member` |
 *
 * ⛔ **Il tenant personale NON diventa un'appartenenza**: è casa propria, non
 * un ente a cui si appartiene. 🚨 Scriverlo vorrebbe dire dire che una persona
 * «appartiene a sé stessa», e il conteggio di «quante palestre hai» darebbe 1
 * a chi non ne ha nessuna.
 *
 * ⚠️ **Idempotente**: si può rilanciare senza duplicare, perché
 * `appartenenza_unica` rifiuta i doppioni e qui si usa `insertOrIgnore`.
 */
return new class extends Migration
{
    public function up(): void
    {
        $adesso = now();

        /*
         * ── 🏋️ Le palestre ──────────────────────────────────────────────
         *
         * 🔑 Solo i tenant di tipo palestra. ⛔ Chi sta in un tenant personale
         * — il proprio, o quello di un free trainer dopo un trasloco — non
         * «appartiene» a una palestra.
         */
        $inPalestra = DB::table('users')
            ->join('tenants', 'tenants.id', '=', 'users.tenant_id')
            ->where('tenants.kind', TenantKind::Gym->value)
            ->whereNull('tenants.deleted_at')
            ->select('users.id as user_id', 'users.tenant_id')
            ->get();

        foreach ($inPalestra->chunk(500) as $blocco) {
            DB::table('appartenenze')->insertOrIgnore(
                $blocco->map(fn (object $r): array => [
                    'user_id' => $r->user_id,
                    'tenant_id' => $r->tenant_id,
                    'tipo' => 'gym',
                    'iniziata_il' => $adesso,
                    'created_at' => $adesso,
                    'updated_at' => $adesso,
                ])->all(),
            );
        }

        /*
         * ── 🧑‍🏫 I trainer ────────────────────────────────────────────────
         *
         * ⚠️ **Solo le assegnazioni vive**: un trainer che ti ha messo da
         * parte (`disattivato_il`) non ti segue più, e scriverlo come
         * appartenenza viva gli ridarebbe un posto che non ha.
         */
        $daiTrainer = DB::table('trainer_member')
            ->whereNull('disattivato_il')
            ->select('member_id as user_id', 'tenant_id')
            ->distinct()
            ->get();

        foreach ($daiTrainer->chunk(500) as $blocco) {
            DB::table('appartenenze')->insertOrIgnore(
                $blocco->map(fn (object $r): array => [
                    'user_id' => $r->user_id,
                    'tenant_id' => $r->tenant_id,
                    'tipo' => 'trainer',
                    'iniziata_il' => $adesso,
                    'created_at' => $adesso,
                    'updated_at' => $adesso,
                ])->all(),
            );
        }
    }

    /**
     * ⚠️ Si svuota, non si cancella la tabella: la struttura la toglie la
     * migrazione precedente, e mischiare le due responsabilità renderebbe
     * impossibile rifare **solo** il riempimento.
     */
    public function down(): void
    {
        DB::table('appartenenze')->delete();
    }
};
