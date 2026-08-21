<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Enums\TipoConversazione;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Tre messaggi per parte, poi fermo — 18/08/2026. **M4.2.**
 *
 * ── 🚨 «Per parte», e non tre in tutto ─────────────────────────────────────
 *
 * Tre miei e tre suoi. ⚠️ Con un contatore unico chi scrive per primo
 * consumerebbe anche la quota dell'altro: una palestra che riceve una domanda
 * non potrebbe rispondere, e chi ha chiesto non capirebbe perche' nessuno gli
 * risponde. Da qui il JSON `{"12": 3, "45": 1}` invece di un intero.
 *
 * ── 🚨 Il contatore si azzera SOLO diventando iscritti, mai col tempo ──────
 *
 * *(scelta del committente)*. Un contatore che si ricarica ogni mese trasforma
 * il limite in un'**attesa**: chi vuole insistere aspetta il primo del mese, e
 * la palestra riceve la stessa domanda dodici volte l'anno da chi non si
 * iscrivera' mai.
 *
 * 💡 Quando qualcuno diventa iscritto, la conversazione cambia **tipo** (M4.4)
 * e il limite smette di applicarsi: il contatore resta scritto, e semplicemente
 * nessuno lo guarda piu'. Non si cancella — dice quante volte quella persona ha
 * provato prima di iscriversi, ed e' un dato che un giorno vorra' sapere chi
 * guarda i numeri.
 */
class LimiteDeiTreMessaggi
{
    public const QUANTI = 3;

    /**
     * Quanti gliene restano. `null` = **senza limite**.
     *
     * 🚨 `null` e `0` sono cose diverse e non vanno mai confuse: `0` vuol dire
     * «finiti», `null` vuol dire «non c'e' nessun limite». Un `?? 0` scritto di
     * fretta da qualche parte trasformerebbe un abbonato in uno bloccato.
     */
    public function restanti(User $chi, Conversation $c): ?int
    {
        if (! $c->eDiInformazioni()) {
            return null;
        }

        return max(0, self::QUANTI - $this->quanti($chi, $c));
    }

    /** Quanti ne ha gia' scritti in questo filo. */
    public function quanti(User $chi, Conversation $c): int
    {
        $conteggi = $c->messaggi_di ?? [];

        return (int) ($conteggi[(string) $chi->getKey()] ?? 0);
    }

    /**
     * Segna un messaggio in piu'.
     *
     * 🚨 **Va chiamato dentro la stessa transazione del messaggio.** ⚠️ Se il
     * messaggio si salvasse e il contatore no, la persona avrebbe scritto senza
     * consumare nulla: il limite diventerebbe un suggerimento. E al contrario —
     * contatore aumentato e messaggio perso — avrebbe consumato senza scrivere,
     * che e' peggio ancora.
     *
     * 💡 `lockForUpdate()`: due messaggi mandati insieme da due dispositivi
     * leggerebbero lo stesso valore e ne scriverebbero uno solo. E' improbabile
     * e costa niente evitarlo.
     */
    public function consuma(User $chi, Conversation $c): void
    {
        if (! $c->eDiInformazioni()) {
            return;
        }

        DB::transaction(function () use ($chi, $c): void {
            /** @var Conversation $fresca */
            $fresca = Conversation::withoutGlobalScopes()
                ->lockForUpdate()
                ->findOrFail($c->getKey());

            $conteggi = $fresca->messaggi_di ?? [];
            $chiave = (string) $chi->getKey();
            $conteggi[$chiave] = (int) ($conteggi[$chiave] ?? 0) + 1;

            $fresca->messaggi_di = $conteggi;
            $fresca->save();

            // 💡 L'oggetto in mano a chi ha chiamato deve rispecchiare la
            // scrittura: senza, `restanti()` chiamato subito dopo mentirebbe.
            $c->messaggi_di = $conteggi;
        });
    }

    /**
     * 🚨 Il filo diventa illimitato: la persona si e' iscritta — M4.4.
     *
     * 💡 Non azzera il contatore, **cambia il tipo**. Il conteggio resta scritto
     * perche' dice quante volte quella persona ha provato prima di iscriversi, e
     * cancellarlo butterebbe via un dato senza guadagnarci niente.
     */
    public function sblocca(Conversation $c): void
    {
        if (! $c->eDiInformazioni()) {
            return;
        }

        $c->tipo = TipoConversazione::Iscritto;
        $c->save();
    }
}
