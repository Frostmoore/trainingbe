<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

/**
 * La palestra come oggetto amministrabile — B3.6.
 *
 * 🚨 **Solo il super admin crea, sospende e cancella una palestra.** Il
 * gym_admin puo' modificare la **propria**, e solo negli aspetti che lo
 * riguardano (nome, colori, codice d'invito): stato dell'abbonamento, piano e
 * tetto AI restano fuori dalla sua portata, perche' sono i termini del contratto
 * che ha con noi. Un cliente che si toglie da solo la sospensione o si alza il
 * tetto di consumo non e' un cliente, e' un problema di fatturazione.
 */
class TenantPolicy
{
    public function before(User $utente, string $ability): ?bool
    {
        return $utente->isSuperAdmin() ? true : null;
    }

    public function viewAny(User $utente): bool
    {
        return false;
    }

    public function view(User $utente, Tenant $palestra): bool
    {
        return $utente->tenant_id === $palestra->getKey()
            && ($utente->isGymAdmin() || $utente->isTrainer());
    }

    public function create(User $utente): bool
    {
        return false;
    }

    /**
     * Il proprio spazio, e solo chi ci comanda. Quali campi, lo decide il modulo.
     *
     * ══ 🆕 🔑 ANCHE IL TRAINER INDIPENDENTE, A CASA SUA — F10, 23/09/2026 ═════
     *
     * 📌 Il committente: *«un free trainer potrà agire sostanzialmente come una
     * palestra»*.
     *
     * ⛔ Con `isGymAdmin()` la pagina delle impostazioni si sarebbe **aperta e
     * poi rifiutata di salvare**: il peggiore dei due mondi, perché il modulo
     * compila, il tasto risponde, e l'errore arriva dopo il lavoro.
     *
     * 🚨 **Questo non riapre il vestito.** La regola 8.1.3 — *«un free trainer
     * non crea un "vestito" per l'app dei suoi allievi»* — la applicano due
     * cose che restano dove sono: `Tenant::branding()`, che su un tenant
     * personale azzera nome, logo e colori, e la sezione «Aspetto nell'app»,
     * che lì **non si mostra**. 💡 Qui si autorizza a scrivere il proprio nome,
     * la propria quota e i propri dati fiscali — non a vestire l'app di
     * qualcun altro.
     */
    public function update(User $utente, Tenant $palestra): bool
    {
        return $utente->tenant_id === $palestra->getKey() && $utente->comandaQui();
    }

    public function delete(User $utente, Tenant $palestra): bool
    {
        return false;
    }
}
