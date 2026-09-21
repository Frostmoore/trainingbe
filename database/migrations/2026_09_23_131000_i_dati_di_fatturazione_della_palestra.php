<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 🧾 **I dati di fatturazione della palestra** — 23/09/2026, fasi F6.4 e F8.1.
 *
 * 📌 Il committente, sulle impostazioni: *«servono tutte le info per la
 * fatturazione (p.iva, pec, codice SDI, nome legale, sede legale)»*. E sul
 * suo piano: *«ogni acquisto delle palestre - incluso l'abbonamento ogni mese -
 * deve generare una vera fattura xml»*.
 *
 * ══ 🚨 PERCHÉ QUESTE COLONNE E NON ALTRE ═════════════════════════════════
 *
 * Sono **esattamente** i campi che la FatturaPA chiede per il
 * `CessionarioCommittente` — chi riceve la fattura. ⛔ Uno in meno e l'XML non
 * è valido; uno in più è un dato che teniamo senza sapere perché.
 *
 * | Colonna | Nel tracciato FatturaPA | Obbligatoria |
 * |---|---|---|
 * | `fatt_ragione_sociale` | `Anagrafica/Denominazione` | ✅ |
 * | `fatt_partita_iva` | `IdFiscaleIVA/IdCodice` | ✅ per un'impresa |
 * | `fatt_codice_fiscale` | `CodiceFiscale` | se diverso dalla P.IVA |
 * | `fatt_indirizzo` · `fatt_cap` · `fatt_comune` · `fatt_provincia` · `fatt_nazione` | `Sede` | ✅ (la provincia solo in Italia) |
 * | `fatt_codice_sdi` | `CodiceDestinatario` | ✅, oppure `0000000` + PEC |
 * | `fatt_pec` | `PECDestinatario` | se il codice è `0000000` |
 *
 * ── ⚠️ Perché sui tenant e non in una tabella a parte ──────────────────────
 *
 * Perché sono **uno per palestra**, e una palestra ne ha sempre esattamente
 * uno. ⛔ Una tabella a parte vorrebbe dire una relazione da caricare a ogni
 * fattura e un caso «la palestra non ha la riga» da gestire ovunque.
 *
 * 💡 Il prefisso `fatt_` non è estetica: `tenants` ha già `name` (il nome
 * **commerciale**, quello che vedono gli iscritti) e la sede legale non
 * coincide quasi mai con la sede della palestra. Senza prefisso, prima o poi
 * qualcuno userebbe `name` in una fattura.
 *
 * ── 🔒 Non sono dati personali, quasi mai ─────────────────────────────────
 *
 * Sono i dati di un'impresa, pubblici per legge (visura camerale). ⚠️ Con
 * un'eccezione: la ditta individuale, dove il codice fiscale **è** quello della
 * persona. Per questo nell'export dei dati personali escono con la palestra, e
 * non girano in nessun'altra API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $tabella): void {
            $tabella->string('fatt_ragione_sociale', 160)->nullable();
            $tabella->string('fatt_partita_iva', 20)->nullable();
            $tabella->string('fatt_codice_fiscale', 20)->nullable();
            $tabella->string('fatt_indirizzo', 200)->nullable();
            $tabella->string('fatt_cap', 12)->nullable();
            $tabella->string('fatt_comune', 120)->nullable();
            $tabella->char('fatt_provincia', 2)->nullable();
            $tabella->char('fatt_nazione', 2)->default('IT');
            $tabella->string('fatt_codice_sdi', 7)->nullable();
            $tabella->string('fatt_pec', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $tabella): void {
            $tabella->dropColumn([
                'fatt_ragione_sociale', 'fatt_partita_iva', 'fatt_codice_fiscale',
                'fatt_indirizzo', 'fatt_cap', 'fatt_comune', 'fatt_provincia',
                'fatt_nazione', 'fatt_codice_sdi', 'fatt_pec',
            ]);
        });
    }
};
