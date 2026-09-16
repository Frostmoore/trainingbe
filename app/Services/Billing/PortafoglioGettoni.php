<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\AiFeature;
use App\Models\AiCreditMovement;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Exceptions\GettoniEsauritiException;
use Illuminate\Support\Facades\DB;

/**
 * Il portafoglio dei gettoni AI — G2, D16.
 *
 * ── 🎯 A cosa serve, in una riga ──────────────────────────────────────────
 *
 * La quota mensile **inclusa** nel piano e' un tetto per allievo (D8) e non si
 * ricarica. I gettoni sono l'extra che un trainer o una palestra **comprano**
 * quando quella quota finisce.
 *
 * ── 🚨 L'ordine di consumo, che e' tutto ──────────────────────────────────
 *
 *     prima la quota inclusa del mese  →  poi i gettoni del portafoglio
 *
 * ⚠️ **Un gettone speso mentre la quota e' ancora piena e' un gettone rubato**,
 * e non se ne accorge nessuno: il servizio funziona, la chiamata riesce, il
 * saldo cala. E' il tipo di errore che si scopre solo dalla fattura di qualcun
 * altro, mesi dopo. Per questo `MemberAiQuota` viene interrogato **per primo** e
 * questo servizio entra in gioco **solo** quando quello ha detto di no.
 *
 * ── 🆕 🚨 IL MONTE CONDIVISO NON C'E' PIU' — 16/09/2026 ──────────
 *
 * ⛔ **Fino al 15/09 il saldo stava su `tenants.ai_credits`**, e la decisione
 * D16 era scritta e motivata: *«i gettoni sono un extra che il trainer ha
 * comprato e di cui decide lui»*, spesi dai suoi allievi.
 *
 * 🚨 **Ma la porta d'acquisto non la sorvegliava nessuno.** Per l'abbonamento
 * c'e' una guardia dal 10/09 — *«un iscritto non compra per la sua palestra»* —
 * e per i gettoni **no**: chiunque comprava, e il saldo finiva nel portafoglio
 * della palestra, dove lo spendeva chiunque altro. ⚠️ Chi aveva pagato poteva
 * ritrovarsi a zero senza aver chiesto niente.
 *
 * 📌 Il committente, scegliendo fra tre strade: **un solo portafoglio, quello
 * personale**. ✅ Da adesso ogni gettone ha **un proprietario solo**, e lo spende
 * solo lui.
 *
 * ⚖️ **Cosa questo NON toglie a nessuno**: l'accesso all'AI. Il portafoglio e'
 * solo l'extra oltre la quota inclusa — chi ha l'AI la ha da
 * `PianoAttivo::haLaAi()`, che guarda il piano, l'override personale e il
 * rapporto col trainer, mai il saldo. E «AI illimitata» non arriva mai qui:
 * `CancelloDeiGettoni::apri()` la guarda per prima.
 *
 * ⚠️ **E cosa toglie davvero**: una palestra non puo' piu' comprare gettoni per
 * i suoi iscritti. 💡 Era una conseguenza dichiarata della scelta, non una
 * sorpresa: il giorno che servisse, la strada e' un gesto per **regalarli**, non
 * un monte da cui pescano tutti.
 */
class PortafoglioGettoni
{
    /**
     * 🆕 **Il saldo di questa persona** — 16/09/2026.
     *
     * ⛔ Fino al 15/09 era il saldo del **tenant**, e i gettoni di chiunque
     * finivano in un monte che spendeva chiunque altro. 📌 Il committente:
     * *«ovvio che i gettoni sono soltanto di chi li compra»*.
     */
    public function saldo(User $utente): int
    {
        return (int) ($utente->ai_credits ?? 0);
    }

    /**
     * Ci sono abbastanza gettoni per **questa** chiamata?
     *
     * ⚠️ Prende la funzione e non un numero: il costo dipende da quale chiamata
     * e', e un metodo che non lo chiede non puo' applicare D16.
     */
    public function bastanoPer(User $utente, AiFeature $funzione): bool
    {
        return $this->saldo($utente) >= $funzione->costoInGettoni();
    }

    /**
     * Scala il costo di questa chiamata dal portafoglio.
     *
     * 🚨 **Transazione e `lockForUpdate`, e non e' pignoleria.** Due chiamate
     * insieme della stessa persona — l'app che manda due stime mentre la rete
     * arranca — leggerebbero lo stesso saldo e lo scriverebbero due volte: il
     * secondo consumo sovrascrive il primo, e il cliente si ritrova gettoni che
     * ha gia' speso. Su un saldo di soldi, «capita di rado» non e' una risposta.
     *
     * 🆕 ⚠️ **Dal 16/09 si blocca la riga della PERSONA, non quella del tenant.**
     * Spostare il lock non e' un dettaglio: e' il lock a decidere quale riga
     * nessun altro puo' toccare mentre si scrive. Lasciarlo sul tenant vorrebbe
     * dire proteggere la riga sbagliata, cioe' non proteggere niente.
     *
     * @param  ?int  $usageLogId  la chiamata che li ha consumati, se gia' scritta
     *
     * @throws GettoniEsauritiException
     */
    public function consuma(User $utente, AiFeature $funzione, ?int $usageLogId = null): AiCreditMovement
    {
        $costo = $funzione->costoInGettoni();

        return DB::transaction(function () use ($utente, $costo, $funzione, $usageLogId): AiCreditMovement {
            /*
             * ⚠️ `withoutGlobalScopes()`: il `TenantScope` su `users` filtra sul
             * contesto, e questo metodo gira anche da una coda — dove un
             * contesto non c'e'. La chiave primaria e' gia' il filtro piu'
             * stretto possibile.
             */
            $bloccato = User::withoutGlobalScopes()
                ->whereKey($utente->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $saldo = (int) $bloccato->ai_credits;

            if ($saldo < $costo) {
                throw new GettoniEsauritiException(saldo: $saldo, servivano: $costo);
            }

            $dopo = $saldo - $costo;

            // ⚠️ `forceFill` e non `update`: `ai_credits` sta fuori da
            // `$fillable` di proposito, perche' il saldo si muove solo di qui.
            $bloccato->forceFill(['ai_credits' => $dopo])->save();

            // 💡 L'oggetto in mano a chi ha chiamato e' un'altra copia: senza
            // questo, un `saldo()` subito dopo direbbe ancora il valore vecchio.
            $utente->setAttribute('ai_credits', $dopo);

            return AiCreditMovement::withoutGlobalScopes()->create([
                /*
                 * 💡 **`tenant_id` resta, e dice dove e' successo.** Il
                 * proprietario dei gettoni e' `user_id`; il tenant serve a
                 * ricostruire la storia — in quale palestra stava quella persona
                 * quando li ha spesi.
                 */
                'tenant_id' => $bloccato->tenant_id,
                'user_id' => $bloccato->getKey(),
                'delta' => -$costo,
                'saldo_dopo' => $dopo,
                'causale' => AiCreditMovement::CONSUMO,
                'ai_usage_log_id' => $usageLogId,
                'nota' => $funzione->label(),
            ]);
        });
    }

    /**
     * Accredita gettoni comprati — `G11.2`.
     *
     * 🆕 🚨 **Si accredita a una PERSONA** — 16/09/2026.
     *
     * 📌 Il committente: *«ovvio che i gettoni sono soltanto di chi li compra»*.
     * ⛔ Prima il primo parametro era un `Tenant`, e chiunque comprasse riempiva
     * il portafoglio della **palestra**, dove li spendeva chiunque altro.
     */
    public function accredita(
        User $utente,
        int $gettoni,
        ?User $operatore = null,
        ?string $nota = null,
        string $causale = AiCreditMovement::ACQUISTO,
    ): AiCreditMovement {
        if ($gettoni === 0) {
            // ⚠️ Un movimento da zero sporca il registro senza dire niente: chi
            // lo legge cerca cosa e' cambiato e non trova niente.
            throw new \InvalidArgumentException('Un accredito da zero gettoni non e\' un movimento.');
        }

        return DB::transaction(function () use ($utente, $gettoni, $operatore, $nota, $causale): AiCreditMovement {
            $bloccato = User::withoutGlobalScopes()
                ->whereKey($utente->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            /*
             * ⚠️ **Una rettifica puo' essere negativa, un acquisto no.** Il
             * saldo non puo' andare sotto zero: un portafoglio in rosso e' un
             * credito verso il cliente, cioe' una cosa che nessuna parte di
             * questo sistema sa gestire. Meglio rifiutare la rettifica.
             */
            $dopo = (int) $bloccato->ai_credits + $gettoni;

            if ($dopo < 0) {
                throw new \InvalidArgumentException(
                    "La rettifica porterebbe il saldo a {$dopo}: un portafoglio non va sotto zero.",
                );
            }

            $bloccato->forceFill(['ai_credits' => $dopo])->save();
            $utente->setAttribute('ai_credits', $dopo);

            return AiCreditMovement::withoutGlobalScopes()->create([
                'tenant_id' => $bloccato->tenant_id,
                'user_id' => $bloccato->getKey(),
                'delta' => $gettoni,
                'saldo_dopo' => $dopo,
                'causale' => $causale,
                'operatore_id' => $operatore?->getKey(),
                'nota' => $nota,
            ]);
        });
    }
}
