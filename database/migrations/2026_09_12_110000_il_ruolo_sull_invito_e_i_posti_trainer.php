<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🎫 **Una palestra può invitare anche un trainer** — 3b-AH, AH.1-quater
 * (12/09/2026).
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * - *«un trainer di palestra non paga il suo abbonamento da trainer perché tanto
 *   c'è già la palestra che paga per tutti gli allievi»*;
 * - *«una palestra può avere un numero limitato di trainer»*;
 * - *«anche il trainer dovrà essere invitato dalla palestra tramite link di
 *   invito»*.
 *
 * ══ 🎫 `tenant_invites.ruolo` ═════════════════════════════════════════════
 *
 * ⛔ `tenant_invites` non aveva **nessuna** colonna per il ruolo: l'invito di una
 * palestra creava sempre un **iscritto**.
 *
 * ✅ **`member` come valore di serie**, e non è un dettaglio: i link già mandati
 * continuano a funzionare esattamente come prima, senza che nessuno debba
 * toccarli.
 *
 * ⚠️ **Stringa e non enum di database**: i ruoli vivono in `App\Enums\UserRole`
 * e in spatie, e un terzo elenco dentro lo schema sarebbe il terzo posto da
 * tenere allineato. Il cast sul modello fa il resto.
 *
 * ══ 💺 E IL TETTO DEI TRAINER C'ERA GIÀ ═══════════════════════════════════
 *
 * ✅ **`plans.max_trainers` esiste dal 14/08/2026** (`il_listino_a_livelli`), ed
 * è già popolato: `gym_5` → 5, `gym_15` → 15, il piano `gym` ritirato → `null`.
 * ⚠️ Questa migration **non lo aggiunge**: sarebbe stato un doppione, ed è il
 * genere di cosa che si scopre guardando invece che ricordando.
 *
 * 🚨 **Ma nessuno lo leggeva.** Era un numero a listino senza nessuna guardia
 * dietro — il caso peggiore, perché sembra una regola e non lo è. Da
 * `AH.1-quater` lo legge `InvitiInPalestra::postiTrainerRimasti()`.
 *
 * 💡 **Due contatori diversi, e non vanno confusi:**
 *
 * | Contatore | Cosa conta |
 * |---|---|
 * | `max_members` | le persone **iscritte** |
 * | `max_trainers` | le persone con ruolo `trainer` in quel tenant |
 *
 * ⛔ **Un trainer non consuma un posto allievo, e un allievo non consuma un posto
 * trainer.** Sembra ovvio scritto così, ed è esattamente il genere di cosa che si
 * somma per sbaglio quando i due numeri finiscono nella stessa query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_invites', function (Blueprint $tabella): void {
            $tabella->string('ruolo', 30)->default('member')->after('invitato_da');
        });
    }

    public function down(): void
    {
        Schema::table('tenant_invites', function (Blueprint $tabella): void {
            $tabella->dropColumn('ruolo');
        });
    }
};
