<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 🪙 **I gettoni sono della persona che li compra** — 3b-AH.8, 16/09/2026.
 *
 * ══ 📌 LA DECISIONE ═══════════════════════════════════════════════════════
 *
 * 📌 Il committente: *«Ovvio che i gettoni sono soltanto di chi li compra»*, e
 * poi, scegliendo fra tre strade: **un solo portafoglio, quello personale**.
 *
 * ══ ⛔ COM'ERA, E PERCHE' ERA SBAGLIATO ═══════════════════════════════════
 *
 * Il saldo stava su `tenants.ai_credits`, e la migrazione del 14/08 spiegava
 * perche': *«lo compra il trainer indipendente o la palestra, cioe' chi paga, e
 * lo consumano i suoi allievi»*. 💡 Era una decisione, non una svista.
 *
 * 🚨 **Ma la porta d'acquisto non la sorvegliava nessuno.** Per l'abbonamento
 * c'e' una guardia dal 10/09 — *«un iscritto non compra per la sua palestra»* —
 * e per i gettoni **no**: chiunque poteva comprare, e il saldo finiva nel
 * portafoglio della **palestra**, dove lo spendeva chiunque altro. ⚠️ Chi aveva
 * pagato poteva ritrovarsi a zero senza aver chiesto niente.
 *
 * ══ ⚖️ COSA QUESTA MIGRAZIONE NON TOCCA ═══════════════════════════════════
 *
 * 🚨 **Nessuno perde l'accesso all'AI.** Il portafoglio e' solo l'**extra**
 * oltre la quota inclusa: chi ha l'AI la ha da `PianoAttivo::haLaAi()`, che
 * guarda il piano, l'override personale e il rapporto col trainer
 * (`QuotaDelTrainer::copre()`) — mai il saldo.
 *
 * ✅ **E «AI illimitata» resta illimitata.** E' una concessione **personale**
 * (`users.ai_monthly_call_cap = 0`) che `CancelloDeiGettoni::apri()` guarda per
 * prima e che non arriva mai al portafoglio.
 *
 * ══ 🚨 A CHI VANNO I SALDI CHE CI SONO GIA' ══════════════════════════════
 *
 * Al **proprietario** del tenant: `tenants.proprietario_id`, cioe' chi quel
 * portafoglio l'ha pagato.
 *
 * ⚠️ **E se un portafoglio pieno non ha un proprietario, questa migrazione si
 * ferma.** 💡 Su dei soldi, «assegnali a qualcuno e vediamo» non e' una
 * risposta: perdere in silenzio i duemila gettoni di qualcuno e' peggio di un
 * deploy che non parte. Il messaggio dice quale tenant, cosi' si decide a mano.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            /*
             * ⚠️ Fuori da `$fillable` come lo era sul tenant: un saldo di soldi
             * non si muove con un `update()` di massa, si muove solo da
             * `PortafoglioGettoni`.
             */
            $table->unsignedBigInteger('ai_credits')->default(0)->after('ai_monthly_photo_call_cap');
        });

        Schema::table('ai_credit_movements', function (Blueprint $table): void {
            /*
             * 💡 **`tenant_id` resta**, e non e' un doppione: dice **dove** e'
             * successo il movimento, che serve a ricostruire una storia. Il
             * proprietario, da adesso, e' `user_id`.
             *
             * ⚠️ Nullable qui e valorizzata subito dopo: una colonna obbligatoria
             * su una tabella che ha gia' delle righe non si aggiunge.
             */
            $table->foreignId('user_id')->nullable()->after('tenant_id')
                ->constrained()->cascadeOnDelete();
        });

        $this->portaISaldiAlProprietario();

        Schema::table('ai_credit_movements', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('tenants', function (Blueprint $table): void {
            /*
             * 🚨 **La colonna si toglie, non si lascia lì a far finta.**
             *
             * ⚠️ Una colonna che nessuno legge piu' e' la trappola gia' pagata
             * con `plans.max_trainers`: sembrava una regola, non lo era, e
             * nessuno se n'e' accorto per un mese. Un saldo fantasma sarebbe
             * peggio — qualcuno lo leggerebbe credendo di sapere quanti gettoni
             * ha un cliente.
             */
            $table->dropColumn('ai_credits');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table): void {
            $table->unsignedBigInteger('ai_credits')->default(0)->after('ai_monthly_photo_calls_per_member');
        });

        /*
         * ⚠️ **Tornando indietro i saldi si risommano nel tenant.** Non e'
         * esattamente lo stato di prima — due persone dello stesso tenant
         * finiscono in un monte solo — ma e' l'unico verso possibile, ed e'
         * quello che non perde gettoni.
         */
        foreach (DB::table('users')->where('ai_credits', '>', 0)->get(['tenant_id', 'ai_credits']) as $u) {
            if ($u->tenant_id === null) {
                continue;
            }

            DB::table('tenants')->where('id', $u->tenant_id)->increment('ai_credits', (int) $u->ai_credits);
        }

        Schema::table('ai_credit_movements', function (Blueprint $table): void {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('ai_credits');
        });
    }

    /**
     * 🚨 Il saldo di ogni tenant va a chi quel tenant lo possiede.
     *
     * ⚠️ E i **movimenti** seguono lo stesso criterio: un registro con metà
     * righe senza proprietario non e' un registro.
     */
    private function portaISaldiAlProprietario(): void
    {
        foreach (DB::table('tenants')->get(['id', 'name', 'ai_credits', 'proprietario_id']) as $tenant) {
            $saldo = (int) $tenant->ai_credits;
            $movimenti = DB::table('ai_credit_movements')->where('tenant_id', $tenant->id)->count();

            if ($saldo === 0 && $movimenti === 0) {
                continue;
            }

            $chi = $this->diChiE($tenant);

            if ($chi === null) {
                throw new RuntimeException(
                    "Il tenant {$tenant->id} ({$tenant->name}) ha {$saldo} gettoni e {$movimenti} movimenti "
                    .'ma nessun proprietario: non so a chi intestarli. Valorizza `tenants.proprietario_id` '
                    .'(il comando `abbonamenti:tenant-senza-proprietario` li elenca) e rilancia la migrazione.'
                );
            }

            if ($saldo > 0) {
                DB::table('users')->where('id', $chi)->update(['ai_credits' => $saldo]);
            }

            DB::table('ai_credit_movements')->where('tenant_id', $tenant->id)->update(['user_id' => $chi]);
        }
    }

    /**
     * Di chi e' questo portafoglio.
     *
     * 💡 Tre strade, dalla piu' certa alla piu' ragionevole. ⚠️ La terza —
     * l'unico abitante — vale solo quando **e' davvero uno solo**: in un tenant
     * con due persone indovinare vorrebbe dire dare i soldi di uno all'altro.
     */
    private function diChiE(object $tenant): ?int
    {
        if ($tenant->proprietario_id !== null) {
            return (int) $tenant->proprietario_id;
        }

        $abitanti = DB::table('users')->where('tenant_id', $tenant->id)->pluck('id');

        return $abitanti->count() === 1 ? (int) $abitanti->first() : null;
    }
};
