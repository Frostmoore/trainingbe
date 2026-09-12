<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Di che natura è un tenant — F1.2 della Parte B.
 *
 * ── 🚨 Perché questa colonna esiste ──────────────────────────────────────────
 *
 * È la collisione più pericolosa dell'intera Parte B (C1), e vale la pena
 * riscriverla per esteso perché chi legge questo file è probabilmente lì per
 * capire se può toccarla.
 *
 * Oggi `users.tenant_id = null` significa **super admin**:
 *
 * - `ResolveTenant` imposta il contesto **solo se** `tenant_id !== null`;
 * - `TenantScope::apply()` fa `if ($tenantId === null) return;` — cioè **non
 *   filtra niente**.
 *
 * Messe insieme: un utente autenticato senza tenant **vede i dati di ogni
 * palestra**. Oggi è corretto, perché in quello stato c'è solo il super admin.
 * ⚠️ Il giorno in cui un utente gratuito nascesse così, la stessa identica riga
 * diventerebbe **un'elevazione di privilegi per chiunque si registri gratis**.
 *
 * ── 💡 La via d'uscita, e perché è questa ────────────────────────────────────
 *
 * Ogni utente gratuito ha **un tenant tutto suo**, con `kind = personal`. Così
 * `tenant_id` non è mai `null` per nessuno tranne il super admin, e
 * `TenantScope` e `ResolveTenant` **non si toccano**.
 *
 * Le alternative scartate, con il motivo (D1):
 *
 * | | Come | Perché no |
 * |---|---|---|
 * | Flag esplicito | Lo scoping guarda `users.is_super_admin` invece di `null` | Più pulito da leggere, ma va rivisto **ogni** punto che scopa per tenant — e **uno dimenticato è una fuga di dati fra palestre** |
 * | Tenant «zero» | Una riga di sistema che raccoglie tutti i gratuiti | `TenantScope` non li separerebbe **fra loro**: ogni gratuito vedrebbe i dati di ogni altro gratuito |
 *
 * 🚨 **Il ragionamento che regge la scelta è il costo asimmetrico dell'errore.**
 * Sbagliare il tenant personale rompe una funzione. Sbagliare il flag esplicito
 * espone i dati di una palestra a un'altra — e non si vede provando l'app, si
 * scopre da un cliente.
 *
 * ── ⚠️ Il prezzo, messo in conto fin da subito ──────────────────────────────
 *
 * `tenants` cresce come `users`: un utente gratuito è una riga in più. Va bene,
 * ma **ogni conteggio commerciale deve filtrare per `kind`**, o il numero delle
 * palestre mente. È il motivo per cui `Tenant::scopePalestre()` esiste ed è la
 * cosa che il pannello `/god` usa dappertutto (F1.4).
 */
enum TenantKind: string
{
    /** Una palestra cliente: paga, ha uno staff, ha un codice d'invito che serve. */
    case Gym = 'gym';

    /**
     * Lo spazio **di una persona**.
     *
     * ⚠️ Non è «una palestra piccola»: non ha staff, non compare nei conteggi,
     * e il suo `join_code` **non deve far entrare nessuno** (vedi
     * `AuthController::resolveActiveTenant()`).
     *
     * ══ 🆕 12/09/2026 — «di una persona», non «con una persona sola» ══════
     *
     * 🚨 Fino a `3b-AH` le due letture coincidevano, e questo commento diceva
     * *«il tenant di una persona sola»*. ⛔ **Non è più vero**: nello spazio di
     * un trainer indipendente ci stanno anche i suoi allievi, e resta
     * `personal` — perché il cliente è **lui**, non un'organizzazione.
     *
     * 💡 **E le tre regole di sopra restano giuste esattamente com'erano**, ed
     * è il motivo per cui si è scelto di non inventare un terzo `kind`:
     *
     * | Regola | Perché vale ancora |
     * |---|---|
     * | Niente staff | Un trainer indipendente non ha uno staff: ha degli allievi |
     * | Fuori dai conteggi commerciali | Non è una palestra cliente, e contarlo come tale direbbe un numero falso |
     * | `join_code` che non apre niente | ⛔ **Serve più di prima**: gli allievi di un trainer entrano per invito, e un codice che funziona sarebbe una porta sul loro spazio |
     *
     * ⛔ **Chi possiede lo spazio lo dice `tenants.proprietario_id`.** Questo
     * `case` non risponde a *«è tuo?»*: per quello c'è `Tenant::eDi()`.
     */
    case Personal = 'personal';

    public function label(): string
    {
        return match ($this) {
            self::Gym => 'Palestra',
            self::Personal => 'Personale',
        };
    }

    /**
     * Va mostrato nel pannello della piattaforma e contato come cliente?
     *
     * 💡 Sta qui e non sparso nei file di Filament perché la domanda «questo
     * tenant è un cliente?» avrà altre sedi (fatturazione, statistiche, export),
     * e due risposte diverse alla stessa domanda divergono sempre.
     */
    public function eUnCliente(): bool
    {
        return $this === self::Gym;
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
