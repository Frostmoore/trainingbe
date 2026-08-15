<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * L'AI accesa (o spenta) per **una persona sola** — 15/08/2026.
 *
 * ── 🚨 Il buco che questa colonna chiude ──────────────────────────────────
 *
 * Dal 14/08 il pannello `/god` sa dare a una persona una quota AI illimitata.
 * ⚠️ **E non serviva a niente**: la quota dice *quante* chiamate, il **cancello**
 * (`RequirePlanWithAi` → `PianoAttivo::haLaAi()`) dice *se* l'AI spetti — e
 * quello leggeva solo `plans.ai_enabled` dell'abbonamento del tenant.
 *
 * Su dati veri: l'utente #13 dello staging aveva `ai_monthly_call_cap = 0`
 * (illimitata) e `ai=no`. Chi si registra da solo prende un tenant personale sul
 * piano `free`, che l'AI non ce l'ha, e **dal pannello non c'era modo di
 * accendergliela**. Il tetto era un rubinetto senza acqua.
 *
 * ── 💡 Perche' una colonna sull'utente e non un abbonamento vero ──────────
 *
 * L'alternativa sarebbe stata dare al tenant un abbonamento a un piano con
 * l'AI. E' la strada giusta per un **cliente**, ed e' quella che restera' per i
 * clienti. ⚠️ Ma per far provare l'app a cinque amici vorrebbe dire creare
 * cinque abbonamenti finti a un piano che nessuno ha comprato: il registro
 * degli abbonamenti finirebbe per raccontare vendite mai avvenute, e i conti di
 * fine mese lo leggerebbero come tale.
 *
 * 🎯 Questa colonna dice un'altra cosa, e la dice per quello che e':
 * **un'eccezione decisa a mano**. E' la gemella di `ai_monthly_call_cap`, che
 * per la quota esiste dal 13/08 con la stessa forma e per la stessa ragione.
 *
 * ── ⚠️ Tre valori, e il terzo serve piu' di quanto sembri ─────────────────
 *
 * | Valore | Significato |
 * |---|---|
 * | `null` | **decide il piano** — il comportamento di prima, e il default |
 * | `true` | AI accesa a questa persona, qualunque cosa dica il piano |
 * | `false` | AI **spenta** a questa persona, anche se il piano ce l'ha |
 *
 * 🚨 Il `false` non e' simmetria per bellezza: e' il solo modo di provare cosa
 * vede chi **non** ha l'AI senza smontare il piano di una palestra intera. Il
 * percorso «senza AI» e' meta' del prodotto — l'app deve funzionare
 * interamente senza — e finora si poteva guardare solo cambiando il listino.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $tabella): void {
            /*
             * ⚠️ `nullable()` **senza default**, e i due `null` non sono la
             * stessa cosa che altrove: qui `null` vuol dire «non decido io,
             * chiedi al piano». E' la stessa convenzione della catena delle
             * quote (`MemberAiQuota::capFor()`), e tenerla uguale e' cio' che
             * permette di leggere i due campi senza ricordarsi due regole.
             *
             * 🚨 Nessun backfill, ed e' voluto: **`null` per tutti** e' esatto.
             * Riempirla con `false` spegnerebbe l'AI a chi oggi ce l'ha dal
             * piano, e riempirla con `true` la regalerebbe a tutti. Il difetto
             * peggiore che questa migrazione potesse avere sarebbe scrivere
             * qualcosa.
             */
            $tabella->boolean('ai_enabled_override')
                ->nullable()
                ->after('ai_monthly_photo_call_cap');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $tabella): void {
            $tabella->dropColumn('ai_enabled_override');
        });
    }
};
