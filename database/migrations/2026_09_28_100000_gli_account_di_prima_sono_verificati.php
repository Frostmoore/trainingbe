<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * ✅ **Gli account che c'erano già risultano verificati** — voce 62.6, 28/09/2026.
 *
 * ══ 📌 LA DECISIONE, E DI CHI E' ══════════════════════════════════════════
 *
 * 📌 Il committente, 28/09: *«per la data di autenticazione possiamo
 * tranquillamente mettere tutti oggi, tanto a parte me sono tutti account di
 * prova»*.
 *
 * ⚖️ **È una decisione sua e va scritta qui**, perché una migrazione che marca
 * come verificati degli indirizzi **che nessuno ha verificato** è una scorciatoia
 * che, in un altro momento della vita del prodotto, non si potrebbe prendere.
 *
 * ══ 🚨 PERCHE' SERVE, E COSA SUCCEDEREBBE SENZA ═══════════════════════════
 *
 * Da oggi `User` implementa `MustVerifyEmail`, e `email_verified_at` è `null` su
 * **tutti** gli account esistenti (95 su 95, in sviluppo). ⛔ Senza questa
 * migrazione, ogni persona già iscritta si troverebbe fuori dalle funzioni che
 * chiedono la verifica — cioè un accesso che smette di funzionare per una cosa
 * che nessuno le ha chiesto.
 *
 * 🚨 Ed è precisamente quello che la **voce 63** vieta: *«NIENTE AL MONDO DEVE
 * IMPEDIRE A UN UTENTE DI ACCEDERE»*.
 *
 * ══ ⚠️ QUANDO QUESTA SCELTA NON SI POTREBBE RIFARE ════════════════════════
 *
 * 💡 Vale **adesso** perché gli account sono di prova, tranne uno. ⛔ Con utenti
 * veri, marcare verificati indirizzi mai confermati vorrebbe dire dare per buono
 * che ognuno di loro possieda davvero la casella scritta — e su quella
 * convinzione poi si appoggia la rotta che mostra gli inviti ricevuti.
 *
 * 🔑 Se un giorno servisse di nuovo una migrazione così, la domanda da rifarsi è
 * **una**: cosa si sblocca con l'email verificata, e chi lo otterrebbe senza
 * averla verificata davvero.
 *
 * ══ ⛔ E NON HA UN `down()` CHE DISFA ═════════════════════════════════════
 *
 * 🚨 Tornando indietro non si può distinguere chi è stato marcato qui da chi ha
 * verificato dopo, per davvero: `down()` azzererebbe **anche** le verifiche vere.
 * 💡 Quindi non fa niente, e lo dice.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * ⚠️ `whereNull`, non «tutti»: chi ha già una data — nessuno oggi, ma
         * domani sì — la tiene. ⛔ Sovrascriverla cancellerebbe una verifica vera
         * sostituendola con una di comodo.
         */
        DB::table('users')
            ->whereNull('email_verified_at')
            ->whereNull('deleted_at')
            ->update(['email_verified_at' => now()]);
    }

    public function down(): void
    {
        /*
         * ⛔ **Di proposito non fa niente.** Vedi la nota in testa: qui non si
         * può distinguere chi è stato marcato dalla migrazione da chi ha
         * verificato davvero, e azzerare tutto chiuderebbe fuori delle persone.
         */
    }
};
