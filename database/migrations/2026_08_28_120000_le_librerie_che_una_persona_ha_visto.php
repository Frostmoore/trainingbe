<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Le librerie esercizi che una persona ha visto, e che non perde piu' — 3b-M.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«gli utenti che sono stati iscritti con questi non devono piu' perdere quegli
 * esercizi»*.
 *
 * ══ 🚨 PERCHE' NON BASTAVA `trainer_member` ═══════════════════════════════
 *
 * Per un **trainer indipendente** basterebbe: quella riga non si cancella mai —
 * `disattiva()` scrive `disattivato_il` e la lascia dov'e' (decisione D5).
 *
 * ⛔ **Ma uscendo da una palestra la riga viene cancellata davvero**
 * (`EsciDaUnaPalestra::sciogliIlLegameConLaPalestra`), e gli esercizi «restano
 * alla palestra». 🚨 Quella persona si ritroverebbe lo storico pieno di
 * esercizi che non sa piu' leggere: non cancellati — muti.
 *
 * 💡 E per chi e' stato **creato dentro** una palestra dal pannello non c'e'
 * mai stato nessun momento di «ingresso» da cui accorgersene. L'unico istante
 * sicuro in cui si sa che quella libreria le e' appartenuta e' **quando esce**.
 *
 * ══ ⚠️ PERCHE' UNA COLONNA E NON UNA TABELLA ══════════════════════════════
 *
 * ⛔ Una tabella con `tenant_id` avrebbe dovuto dichiararsi in
 * `TenantIsolationTest`, in `UnisciAUnaPalestra` e nei tre elenchi di
 * `EsciDaUnaPalestra` — e in nessuno di quei posti avrebbe avuto un senso
 * onesto: quel `tenant_id` **non e' il proprietario della riga**, e' un
 * puntatore a una libreria. Dichiararlo come proprieta' avrebbe insegnato una
 * cosa falsa a tre meccanismi diversi.
 *
 * 💡 Sta sull'utente perche' e' una proprieta' **della persona**, si legge
 * insieme a lei senza una query in piu', e segue automaticamente la sua riga in
 * ogni trasloco fra tenant.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->json('librerie_viste')->nullable()->after('tenant_id');
        });

        /*
         * ══ 🚨 IL RIEMPIMENTO E' LA META' DEL LAVORO ══════════════════════
         *
         * ⛔ Senza, la regola varrebbe **solo per chi uscira' da domani**: chi
         * e' gia' passato per una palestra ha gia' perso quella libreria, e
         * nessuno se ne accorgerebbe — perche' non ha mai visto quegli esercizi
         * comparire, quindi non nota che mancano.
         *
         * 💡 Si riempie da `trainer_member`, che e' l'unica traccia di «questa
         * persona e' stata sotto quel tenant» rimasta a database. ⚠️ Non copre
         * chi e' gia' uscito da una palestra — quelle righe sono state
         * cancellate e non tornano — ma copre tutti quelli ancora legati, che
         * sono il caso vero oggi.
         */
        $per = DB::table('trainer_member')
            ->select('member_id', 'tenant_id')
            ->get()
            ->groupBy('member_id');

        foreach ($per as $membro => $righe) {
            $tenant = $righe->pluck('tenant_id')->filter()->unique()->values()->all();

            if ($tenant === []) {
                continue;
            }

            DB::table('users')
                ->where('id', $membro)
                ->update(['librerie_viste' => json_encode($tenant)]);
        }
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('librerie_viste');
        });
    }
};
