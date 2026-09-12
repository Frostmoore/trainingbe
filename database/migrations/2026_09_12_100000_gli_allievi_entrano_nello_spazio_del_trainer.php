<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Tenancy\UnisciAUnaPalestra;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * 🚚 **Gli allievi che c'erano gia' entrano nello spazio del loro trainer** —
 * 3b-AH, AH.1.5 (12/09/2026).
 *
 * ══ 📌 PERCHE' ESISTE, E PERCHE' E' SEMPLICE ══════════════════════════════
 *
 * Da AH.1 un allievo di un trainer indipendente **nasce** dentro lo spazio del
 * trainer. Chi era gia' assegnato prima sta ancora in un tenant personale suo, e
 * senza questa migration resterebbe **fuori dall'abbonamento che il suo trainer
 * paga**: `PianoAttivo::eAbbonato()` non passa piu' da `QuotaDelTrainer`.
 *
 * ⛔ **Quindi non e' cosmetica**: senza, qualcuno che paga smette di risultare
 * abbonato, ed e' il lato caro dell'avvertenza in cima ad AH.
 *
 * 📌 Il committente, il 10/09: *«NON CI SONO ALLIEVI GIA'. Sono robaccia di
 * test. Se serve ai fini di test, quelli che ci sono adesso li puoi spostare
 * senza temere di perdere cose.»*
 *
 * 💡 Per questo **non c'e' nessun codice difensivo**: niente salvataggi, niente
 * ripristini, niente `down()` che prova a rimettere le cose com'erano. ⛔ Non si
 * scrive codice per conservare dati che nessuno vuole conservare.
 *
 * ══ 🚨 CHI SI SPOSTA, E CHI NO ════════════════════════════════════════════
 *
 * | Allievo | Si sposta? |
 * |---|---|
 * | Sta in uno spazio **suo**, senza nessun altro dentro | ✅ si |
 * | Sta in una **palestra** | ⛔ no: la palestra paga per lui (AH.1-ter) |
 * | Sta nello spazio di **un altro** | ⛔ no |
 * | Ha un rapporto **disattivato** | ⛔ no: quel legame non copre piu' niente (D5) |
 *
 * ⚠️ E se qualcuno ha **due** trainer indipendenti, si sposta sotto il primo e
 * basta: il secondo rapporto resta in piedi e attraversa i tenant. 🚨 Scegliere
 * "il piu' importante" vorrebbe dire inventare un criterio che non esiste.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->daSpostare() as $coppia) {
            $this->sposta((int) $coppia->allievo, (int) $coppia->da, (int) $coppia->a);
        }
    }

    /**
     * ⛔ **Non si torna indietro, ed e' deliberato.**
     *
     * Per rimettere ogni allievo nel suo tenant di prima bisognerebbe aver
     * conservato quale fosse, e ricrearlo: un tenant cancellato, con il suo
     * `slug` e il suo `join_code`. 🚨 Una `down()` che ci provasse sarebbe un
     * secondo posto in cui sbagliare, su dati che il committente ha gia' detto
     * di poter perdere.
     */
    public function down(): void {}

    /**
     * @return array<int, object{allievo: int, da: int, a: int}>
     */
    private function daSpostare(): array
    {
        return DB::table('trainer_member as tm')
            ->join('users as allievo', 'allievo.id', '=', 'tm.member_id')
            ->join('users as trainer', 'trainer.id', '=', 'tm.trainer_id')
            ->join('tenants as suo', 'suo.id', '=', 'allievo.tenant_id')
            ->join('tenants as delTrainer', 'delTrainer.id', '=', 'trainer.tenant_id')
            ->whereNull('tm.disattivato_il')
            ->where('suo.kind', 'personal')
            ->where('delTrainer.kind', 'personal')
            // 🚨 Lo spazio dev'essere **suo**: e' la stessa domanda di
            // `Tenant::eDi()`, fatta in SQL perche' qui non ci sono i modelli.
            ->whereColumn('suo.proprietario_id', 'allievo.id')
            ->whereColumn('delTrainer.proprietario_id', 'trainer.id')
            ->whereColumn('allievo.tenant_id', '!=', 'trainer.tenant_id')
            // ⚠️ Nessun altro dentro, o si sposterebbe roba di qualcun altro.
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('users as altri')
                ->whereColumn('altri.tenant_id', 'allievo.tenant_id')
                ->whereColumn('altri.id', '!=', 'allievo.id'))
            ->orderBy('tm.id')
            ->get([
                'allievo.id as allievo',
                'allievo.tenant_id as da',
                'trainer.tenant_id as a',
            ])
            ->unique('allievo')
            ->values()
            ->all();
    }

    private function sposta(int $allievo, int $da, int $a): void
    {
        /*
         * 🚨 **L'email e' unica per tenant.** Se nello spazio del trainer c'e'
         * gia' un account con la stessa email, l'`UPDATE` violerebbe il vincolo
         * e la migration si fermerebbe a meta'. ⛔ Si salta e si logga: e' un
         * caso che va guardato da una persona, non indovinato qui.
         */
        $email = DB::table('users')->where('id', $allievo)->value('email');

        $collide = DB::table('users')
            ->where('tenant_id', $a)
            ->where('email', $email)
            ->exists();

        if ($collide) {
            Log::warning('AH.1: allievo non spostato, email gia\' presente nello spazio del trainer', [
                'allievo' => $allievo, 'da' => $da, 'a' => $a,
            ]);

            return;
        }

        DB::transaction(function () use ($allievo, $da, $a): void {
            foreach ($this->tabelleDaSpostare() as $tabella) {
                DB::table($tabella)->where('tenant_id', $da)->update(['tenant_id' => $a]);
            }

            DB::table('users')->where('id', $allievo)->update(['tenant_id' => $a]);

            /*
             * 🚨 **I ruoli sono del tenant.** In modalita' teams spatie filtra
             * su `model_has_roles.tenant_id`: senza rifarli qui, questa persona
             * si ritroverebbe **senza nessun ruolo** nello spazio nuovo — un
             * account che esiste e non puo' fare niente.
             */
            DB::table('model_has_roles')
                ->where('model_id', $allievo)
                ->where('tenant_id', $da)
                ->delete();

            $member = DB::table('roles')
                ->where('tenant_id', $a)
                ->where('name', 'member')
                ->value('id');

            if ($member !== null) {
                DB::table('model_has_roles')->insertOrIgnore([
                    'role_id' => $member,
                    'model_type' => (new User)->getMorphClass(),
                    'model_id' => $allievo,
                    'tenant_id' => $a,
                ]);
            }

            /*
             * ⚠️ Il tenant vuoto si cancella logicamente, come fa
             * `UnisciAUnaPalestra`: restare in tabella lo farebbe contare fra i
             * tenant vivi, e cancellarlo davvero perderebbe la traccia di dove
             * quella persona stava prima.
             */
            DB::table('tenants')->where('id', $da)->update([
                'status' => 'suspended',
                'deleted_at' => now(),
            ]);
        });

        Log::info('AH.1: allievo spostato nello spazio del suo trainer', [
            'allievo' => $allievo, 'da' => $da, 'a' => $a,
        ]);
    }

    /**
     * 💡 **Si riusa l'elenco di `UnisciAUnaPalestra`**, non se ne scrive un
     * secondo: le eccezioni sono motivate una per una nel docblock di quella
     * classe, e due elenchi divergono al primo modello aggiunto.
     *
     * @return array<int, string>
     */
    private function tabelleDaSpostare(): array
    {
        $tabelle = [];

        foreach (Schema::getTableListing() as $tabella) {
            $nome = str_contains($tabella, '.') ? explode('.', $tabella)[1] : $tabella;

            if (in_array($nome, UnisciAUnaPalestra::RESTANO_INDIETRO, true)) {
                continue;
            }

            if (Schema::hasColumn($nome, 'tenant_id')) {
                $tabelle[] = $nome;
            }
        }

        return $tabelle;
    }
};
