<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Il listino a tre livelli, e la quota che passa da token a chiamate — G1.
 *
 * ── 🚨 Perche' NON si riusa `max_members` per le palestre ──────────────────
 *
 * Per un piano trainer `max_members` vuol dire «quanti allievi seguo io». Per un
 * piano palestra servirebbe «quanti per **ciascun** trainer»: stessa colonna,
 * due significati. E' la classe di errore che questo progetto ha gia' pagato
 * tre volte (§14 di `plan_parte_b.md`) — e questa non si vedrebbe finche' non
 * fattura, perche' un numero sbagliato in un limite non da' errore: da' un
 * limite sbagliato.
 *
 * ✅ Da qui la decisione D5: `max_members` conserva l'unico significato che ha
 * oggi, e nascono `max_trainers` e `max_members_per_trainer`.
 *
 * ── 🚨 Perche' le chiamate e non i token (D6) ─────────────────────────────
 *
 * I token sono l'unita' del fornitore, non del cliente: nessuno compra «due
 * milioni di token», e nessuno sa dire se gli bastano. Le chiamate le si conta
 * da soli.
 *
 * ⚠️ **Ma una chiamata non vale una chiamata.** `STIMA-COSTI-AI.md` misura
 * 0,0146 $ per una stima da foto contro 0,0013 $ per le calorie di un
 * allenamento: **undici volte**. Con un contatore solo, chi usa la foto costa
 * undici volte gli altri pagando uguale.
 *
 * ✅ Da qui D7: **due numeri**, `ai_monthly_calls_per_member` e
 * `ai_monthly_photo_calls_per_member`. Il secondo e' un sotto-limite del primo,
 * non un budget a parte.
 *
 * ── ⚠️ Le convenzioni NON cambiano ────────────────────────────────────────
 *
 * `null` = «non lo decide questo livello, scendi al prossimo», `0` =
 * illimitato. Sono le stesse dei cinque livelli di `MemberAiQuota`, e cambiarle
 * **qui soltanto** vorrebbe dire avere due significati per lo stesso valore
 * nella stessa catena.
 */
return new class extends Migration
{
    /**
     * Token per chiamata, ricavato dai consumi misurati.
     *
     * `STIMA-COSTI-AI.md` §4: l'utente medio fa ≈ 10,6 chiamate al giorno per
     * ≈ 18.361 token, cioe' ≈ 1.730 token a chiamata.
     *
     * ⚠️ **Non e' una conversione esatta e non finge di esserlo**: e' una
     * taratura che parte dai consumi veri. Il numero definitivo lo dira' lo
     * staging, ed e' per questo che togliere le colonne vecchie (`G2.5`) e' un
     * passo separato e successivo.
     */
    private const TOKEN_PER_CHIAMATA = 1730;

    /** Le foto sono ≈ 1 su 10,6 delle chiamate: si arrotonda al 10%, per eccesso. */
    private const QUOTA_FOTO = 0.10;

    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            // D5 — quanti trainer puo' avere una palestra. `null` = senza limite.
            $table->unsignedInteger('max_trainers')->nullable()->after('max_members');

            // 🚨 D5 — quanti allievi per CIASCUN trainer della palestra.
            // NON e' `max_members`: vedi la nota in testa.
            $table->unsignedInteger('max_members_per_trainer')->nullable()->after('max_trainers');

            // D6/D7 — la quota in chiamate, col sotto-limite sulle foto.
            $table->unsignedInteger('ai_monthly_calls_per_member')
                ->nullable()->after('ai_monthly_tokens_per_member');
            $table->unsignedInteger('ai_monthly_photo_calls_per_member')
                ->nullable()->after('ai_monthly_calls_per_member');
        });

        $this->portaDietroLeQuoteEsistenti();
    }

    /**
     * Da token a chiamate, per i piani gia' venduti.
     *
     * 🚨 **Senza questo passo, al primo deploy ogni cliente pagante si
     * troverebbe la quota a `null`** — quindi scenderebbe al default di sistema,
     * che e' tarato su un utente singolo e non su una palestra. Il servizio non
     * darebbe errore: darebbe di meno, e lo direbbe solo a chi lo esaurisce.
     */
    private function portaDietroLeQuoteEsistenti(): void
    {
        $daConvertire = DB::table('plans')
            ->whereNotNull('ai_monthly_tokens_per_member')
            ->get(['id', 'code', 'ai_monthly_tokens_per_member']);

        foreach ($daConvertire as $piano) {
            $token = (int) $piano->ai_monthly_tokens_per_member;

            /*
             * 🚨 **`0` significa illimitato, e deve restare illimitato.**
             *
             * `intdiv(0, 1730)` fa 0 e sarebbe la risposta giusta per caso. Ma
             * per caso non basta: se un giorno qualcuno cambiasse la
             * convenzione, o il divisore, questa riga continuerebbe a
             * funzionare solo finche' i conti tornano da soli. Scritto
             * esplicitamente, resta vero comunque.
             */
            if ($token === 0) {
                DB::table('plans')->where('id', $piano->id)->update([
                    'ai_monthly_calls_per_member' => 0,
                    'ai_monthly_photo_calls_per_member' => 0,
                ]);

                continue;
            }

            $chiamate = intdiv($token, self::TOKEN_PER_CHIAMATA);

            /*
             * ⚠️ **Almeno una chiamata.** Un piano con pochi token diventerebbe
             * `0` — che in questa catena vuol dire **illimitato**, cioe'
             * l'opposto. E' il tipo di errore che regala l'AI gratis a un
             * cliente e non se ne accorge nessuno finche' non arriva la
             * fattura del fornitore.
             */
            $chiamate = max(1, $chiamate);

            DB::table('plans')->where('id', $piano->id)->update([
                'ai_monthly_calls_per_member' => $chiamate,
                'ai_monthly_photo_calls_per_member' => (int) ceil($chiamate * self::QUOTA_FOTO),
            ]);
        }

        Log::info('G1 — quote convertite da token a chiamate', [
            'piani_convertiti' => $daConvertire->count(),
            'token_per_chiamata' => self::TOKEN_PER_CHIAMATA,
        ]);
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table): void {
            $table->dropColumn([
                'max_trainers',
                'max_members_per_trainer',
                'ai_monthly_calls_per_member',
                'ai_monthly_photo_calls_per_member',
            ]);
        });
    }
};
