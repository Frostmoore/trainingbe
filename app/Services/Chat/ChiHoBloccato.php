<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 🚫 **Il blocco fra due persone** — blocco B3, 24/09/2026.
 *
 * 📌 Il committente, decisione 53.3: *«max 3 messaggi e si possono bloccare le
 * persone»*.
 *
 * ══ 🚨 IL BLOCCO FA DUE COSE, E LA SECONDA SI DIMENTICA ═════════════════════
 *
 * | Effetto | Dove si applica |
 * |---|---|
 * | **non può scriverti** | `CancelloDellaChat` |
 * | **non ti trova più** | la ricerca per nome utente |
 *
 * ⛔ Senza il secondo, il blocco sarebbe **una porta chiusa accanto a una
 * aperta**: basterebbe cercarti di nuovo e aprire un'altra conversazione.
 *
 * ══ ⚠️ NON E' RECIPROCO, E LA DIREZIONE CONTA ═══════════════════════════════
 *
 * 🚨 Se A blocca B, **B non ha bloccato A**: A continua a vedere B e potrebbe
 * scrivergli. 💡 È voluto — bloccare è una difesa di chi la mette, non una
 * rottura del rapporto — ed è il motivo per cui qui ci sono due domande
 * diverse: `hoBloccato()` e `mihaBloccato()`.
 */
class ChiHoBloccato
{
    /** Blocca. ⚠️ Bloccare due volte non è un secondo blocco. */
    public function blocca(User $chi, User $chiunque, ?string $motivo = null): void
    {
        if ($chi->getKey() === $chiunque->getKey()) {
            return;
        }

        DB::table('blocchi')->insertOrIgnore([
            'bloccante_id' => $chi->getKey(),
            'bloccato_id' => $chiunque->getKey(),
            'motivo' => $motivo,
            'created_at' => Carbon::now(),
            'updated_at' => Carbon::now(),
        ]);
    }

    /**
     * Sblocca.
     *
     * ⚠️ **Non riapre niente da sola**: una conversazione chiusa resta chiusa e
     * il limite dei tre messaggi riparte da dove era. 💡 Sbloccare vuol dire
     * *«può riprovare»*, non *«ricominciamo»*.
     */
    public function sblocca(User $chi, User $chiunque): void
    {
        DB::table('blocchi')
            ->where('bloccante_id', $chi->getKey())
            ->where('bloccato_id', $chiunque->getKey())
            ->delete();
    }

    public function hoBloccato(User $chi, User $chiunque): bool
    {
        return DB::table('blocchi')
            ->where('bloccante_id', $chi->getKey())
            ->where('bloccato_id', $chiunque->getKey())
            ->exists();
    }

    public function miHaBloccato(User $chi, User $chiunque): bool
    {
        return $this->hoBloccato($chiunque, $chi);
    }

    /**
     * 🚨 **La domanda che serve ai cancelli**: fra questi due c'è un blocco, in
     * un verso **o** nell'altro?
     *
     * 💡 Per scrivere e per cercare non importa chi ha bloccato chi: in tutti e
     * due i casi quei due non devono incontrarsi. ⚠️ Importa invece negli
     * elenchi («chi ho bloccato io»), e per questo le due domande restano
     * separate.
     */
    public function fraLoroCeUnBlocco(User $uno, User $altro): bool
    {
        return DB::table('blocchi')
            ->where(function ($q) use ($uno, $altro): void {
                $q->where('bloccante_id', $uno->getKey())->where('bloccato_id', $altro->getKey());
            })
            ->orWhere(function ($q) use ($uno, $altro): void {
                $q->where('bloccante_id', $altro->getKey())->where('bloccato_id', $uno->getKey());
            })
            ->exists();
    }

    /**
     * Toglie dalla query tutte le persone con cui c'è un blocco, **nei due
     * versi**.
     *
     * 💡 Scritto qui e non nella ricerca: è la stessa condizione che servirà al
     * prossimo elenco di persone, e una regola che serve in due posti non va
     * scritta in nessuno dei due.
     *
     * @param  Builder<User>  $query
     */
    public function toglieiBloccati(Builder $query, User $chi): Builder
    {
        return $query
            ->whereNotIn('users.id', DB::table('blocchi')
                ->where('bloccante_id', $chi->getKey())
                ->select('bloccato_id'))
            ->whereNotIn('users.id', DB::table('blocchi')
                ->where('bloccato_id', $chi->getKey())
                ->select('bloccante_id'));
    }

    /**
     * Chi ho bloccato, per l'elenco nell'app.
     *
     * @return Collection<int, User>
     */
    public function elenco(User $chi): Collection
    {
        return User::withoutGlobalScopes()
            ->whereIn('id', DB::table('blocchi')
                ->where('bloccante_id', $chi->getKey())
                ->select('bloccato_id'))
            ->orderBy('name')
            ->get();
    }
}
