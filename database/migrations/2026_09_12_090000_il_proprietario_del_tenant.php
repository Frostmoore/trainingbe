<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 🆕 **Chi paga per questo tenant, scritto invece che dedotto** — 3b-AH, Passo 0.
 *
 * ══ 📌 PERCHÉ ESISTE ══════════════════════════════════════════════════════
 *
 * Fino a oggi «chi possiede un tenant» era **implicito**, e si deduceva in due
 * modi diversi a seconda del tipo:
 *
 * | Tenant | Il proprietario era… |
 * |---|---|
 * | `personal` | l'unico che ci stava dentro |
 * | `gym` | il `gym_admin` |
 *
 * 🚨 **Quell'implicito si rompe con AH.1-quater**, e il committente l'ha chiesto
 * per esteso il 10/09/2026: *«un trainer di una palestra può avere un suo
 * portfolio senza alcun problema, basta che paga»*. Quella persona **lavora
 * dentro il tenant della palestra** e **possiede un tenant suo**, dove vivono i
 * suoi clienti — cioè il proprietario del suo tenant **non ci sta dentro**.
 *
 * ⛔ `users.tenant_id` è una colonna sola e risponde a una domanda sola: *«chi
 * paga per te»*. Non può rispondere anche a *«dove lavori»*, e non deve provarci.
 *
 * ══ ✅ COSA SEMPLIFICA ════════════════════════════════════════════════════
 *
 * `Cassa::loPuoGestire()` passa da due casi a una domanda:
 *
 *     prima →  tenant personale → chiunque ci stia dentro
 *              palestra         → solo il gym_admin
 *     dopo  →  sei tu il proprietario?
 *
 * 💡 Meno codice, e soprattutto **un posto solo** in cui quella regola può
 * essere sbagliata.
 *
 * ══ ⚠️ PERCHÉ È `nullable` ════════════════════════════════════════════════
 *
 * ⛔ Non è pigrizia: `CreaTenantPersonale` crea **prima** il tenant e **poi**
 * l'utente che ci sta dentro. Nell'istante dell'`INSERT` il proprietario non
 * esiste ancora, e una colonna NOT NULL renderebbe impossibile la sola strada
 * che crea tenant personali.
 *
 * 🚨 E una palestra nasce dal pannello **prima** del suo amministratore.
 *
 * 💡 Il prezzo del `null` si paga con il comando
 * `abbonamenti:tenant-senza-proprietario`, che dice se qualche tenant **che sta
 * pagando** è rimasto senza: quello non è tollerabile, perché è una persona che
 * non può più gestire ciò che paga.
 *
 * ══ ⛔ `nullOnDelete` E NON `cascadeOnDelete` ═════════════════════════════
 *
 * 🚨 Cancellare l'account del proprietario **non deve cancellare il tenant** con
 * dentro i dati dei suoi allievi. Un `cascade` qui sarebbe una cancellazione di
 * massa a una `DELETE` di distanza — e non darebbe nessun errore.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $tabella): void {
            $tabella->foreignId('proprietario_id')->nullable()->after('kind')
                ->constrained('users')->nullOnDelete();
        });

        $this->riempiIPersonali();
        $this->riempiLePalestre();
        $this->segnalaChiEResta();
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $tabella): void {
            $tabella->dropConstrainedForeignId('proprietario_id');
        });
    }

    /**
     * Un tenant personale con **un solo** utente dentro: quello è il proprietario.
     *
     * ⛔ **Con zero o più di uno si lascia `null`**, e non si sceglie il più
     * vecchio: un tenant personale con due persone dentro è uno stato che non
     * dovrebbe esistere, e indovinare quale delle due paga vorrebbe dire dare a
     * qualcuno il comando sull'abbonamento di qualcun altro. 💡 Il comando di
     * guardia lo dirà, e lo si sistemerà guardandolo.
     */
    private function riempiIPersonali(): void
    {
        $conteggi = DB::table('users')
            ->select('tenant_id', DB::raw('COUNT(*) as quanti'), DB::raw('MIN(id) as primo'))
            ->whereNotNull('tenant_id')
            ->groupBy('tenant_id')
            ->havingRaw('COUNT(*) = 1')
            ->pluck('primo', 'tenant_id');

        foreach ($conteggi as $tenantId => $userId) {
            DB::table('tenants')
                ->where('id', $tenantId)
                ->where('kind', 'personal')
                ->update(['proprietario_id' => $userId]);
        }
    }

    /**
     * Una palestra: il suo `gym_admin`, il più vecchio se sono più d'uno.
     *
     * 🚨 **I ruoli si leggono da `model_has_roles` a mano, non da `hasAppRole()`.**
     * Quel metodo passa da `TenantContext::runAs()` e dalla cache dei permessi di
     * spatie: dentro una migration il contesto non c'è e la cache non è calda —
     * risponderebbe `false` a tutti, in silenzio, e questa colonna nascerebbe
     * vuota su ogni palestra.
     *
     * ⚠️ La `JOIN` su `roles` deve legare **anche** il `tenant_id`: in modalità
     * teams lo stesso nome di ruolo esiste una volta per tenant, e senza quella
     * condizione si prenderebbe il `gym_admin` di un'altra palestra.
     */
    private function riempiLePalestre(): void
    {
        $morph = (new User)->getMorphClass();

        $palestre = DB::table('tenants')->where('kind', 'gym')->pluck('id');

        foreach ($palestre as $tenantId) {
            $admin = DB::table('model_has_roles as mhr')
                ->join('roles as r', function ($join): void {
                    $join->on('r.id', '=', 'mhr.role_id')
                        ->on('r.tenant_id', '=', 'mhr.tenant_id');
                })
                ->where('mhr.tenant_id', $tenantId)
                ->where('mhr.model_type', $morph)
                ->where('r.name', 'gym_admin')
                ->orderBy('mhr.model_id')
                ->value('mhr.model_id');

            if ($admin === null) {
                continue;
            }

            DB::table('tenants')->where('id', $tenantId)->update(['proprietario_id' => $admin]);
        }
    }

    /**
     * Chi è rimasto senza, messo a registro.
     *
     * 💡 Non fallisce: una migration che si rifiuta di girare perché i dati di
     * oggi sono disordinati lascia il database a metà. ⚠️ Il posto dove questo
     * diventa un guasto è il comando `abbonamenti:tenant-senza-proprietario`,
     * che guarda **anche** se quel tenant sta pagando.
     */
    private function segnalaChiEResta(): void
    {
        $orfani = DB::table('tenants')
            ->whereNull('proprietario_id')
            ->whereNull('deleted_at')
            ->pluck('id')
            ->all();

        if ($orfani === []) {
            return;
        }

        Log::warning('Tenant senza proprietario dopo la migration di AH Passo 0', [
            'quanti' => count($orfani),
            'ids' => array_slice($orfani, 0, 50),
        ]);
    }
};
