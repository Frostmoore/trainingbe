<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `trainer_member.quota_assegnata_il` — U.2.1.
 *
 * ── 🎯 A cosa serve, e perche' una data e non un numero ────────────────────
 *
 * 📌 *«Quando a un trainer scade l'abbonamento, gli allievi del trainer
 * mantengono l'uso della quota ai riservata a loro dal trainer fino a un mese
 * dopo il giorno in cui gli e' stata assegnata»*.
 *
 * 🚨 Il perno di quella regola e' **un giorno del mese**, non una durata: il
 * committente l'ha sciolta con un esempio — *«se la sua quota e' stata messa il
 * 12 febbraio e il trainer smette di pagare il 25 ottobre, all'allievo resta
 * fino al 12 novembre»*. Cioe' la quota si rinnova **ogni mese il giorno 12**, e
 * chi perde il trainer **finisce il ciclo gia' pagato**. Senza questa colonna
 * quel giorno non esiste da nessuna parte.
 *
 * ── ⚠️ Erano tre colonne, ne resta una ────────────────────────────────────
 *
 * Il disegno di U.2.1 prevedeva anche `ai_monthly_call_cap` e
 * `ai_monthly_photo_call_cap` sul pivot, perche' si dava per scontato che il
 * trainer scegliesse **quanta** AI dare. ⛔ La decisione del 28/08 le ha tolte
 * di mezzo: 📌 *«all'allievo arriva la stessa quota che gli arriverebbe se si
 * abbonasse»* — 150 chiamate e 15 con foto, sempre, per tutti.
 *
 * 💡 E toglie da sola il rischio economico: un trainer non puo' regalare piu' di
 * quello che quella persona avrebbe comunque abbonandosi, quindi non c'e'
 * niente da abusare e non serve nessun tetto da inventare.
 *
 * ── 🚨 Perche' sul pivot e non su `users` ─────────────────────────────────
 *
 * `users.ai_monthly_call_cap` e' il **livello 1** della catena: l'eccezione
 * decisa dalla piattaforma. Lasciandola scrivere a un trainer, quel trainer
 * scavalcherebbe anche il tetto di una **palestra** — e la decisione D3 dice
 * l'esatto contrario, che la palestra viene prima.
 *
 * 💡 Sul pivot la quota e' **del rapporto**: nasce con lui e muore con lui.
 *
 * ── ⚠️ `nullable`, e chi e' gia' in tabella non si tocca ──────────────────
 *
 * 🚨 **Non si riempie con `now()`.** Farlo darebbe a ogni rapporto gia'
 * esistente un anniversario al 29 agosto, cioe' sposterebbe la scadenza di
 * persone che il rapporto ce l'hanno da mesi.
 *
 * 💡 Resta `null`, e chi legge ricade su `assigned_at` — la data in cui il
 * rapporto e' nato, che per tutti i rapporti di oggi **e'** il giorno in cui ha
 * cominciato a dare la quota. La lettura sta in
 * `App\Services\Ai\Quota\QuotaDelTrainer::assegnataIl()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trainer_member', function (Blueprint $tabella): void {
            $tabella->timestamp('quota_assegnata_il')->nullable()->after('disattivato_il');
        });
    }

    public function down(): void
    {
        Schema::table('trainer_member', function (Blueprint $tabella): void {
            $tabella->dropColumn('quota_assegnata_il');
        });
    }
};
