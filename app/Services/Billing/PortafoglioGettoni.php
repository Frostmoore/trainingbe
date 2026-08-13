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
 * ── ⚠️ Qui il monte torna condiviso, e va detto ───────────────────────────
 *
 * D8 aveva scartato il monte condiviso perche' «il primo entusiasta lascia gli
 * altri a secco, e a sentirsi dire *non funziona* e' il trainer». I gettoni
 * comprati **sono** un monte condiviso, ed e' voluto: la quota inclusa resta un
 * tetto per persona, quindi il servizio di base non si puo' prosciugare. I
 * gettoni sono un extra che il trainer ha comprato e di cui decide lui.
 *
 * 🚨 Ma la conseguenza va **mostrata** nell'interfaccia (`G11.3`): quanto ne ha
 * consumati ciascun allievo. Senza quel numero, il trainer sa solo che sono
 * finiti.
 */
class PortafoglioGettoni
{
    /**
     * Il saldo del tenant che paga per questa persona.
     *
     * 🚨 **Il tenant dell'utente, non quello del trainer.** Chi paga per un
     * iscritto e' la sua palestra; per chi non ne ha una, il suo tenant
     * personale — dove il saldo e' zero, perche' i gettoni li comprano trainer e
     * palestre, non i singoli (D16).
     */
    public function saldo(User $utente): int
    {
        return (int) ($utente->tenant?->ai_credits ?? 0);
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
     * insieme dello stesso allievo — l'app che manda due stime mentre la rete
     * arranca — leggerebbero lo stesso saldo e lo scriverebbero due volte: il
     * secondo consumo sovrascrive il primo, e il cliente si ritrova gettoni che
     * ha gia' speso. Su un saldo di soldi, «capita di rado» non e' una risposta.
     *
     * @param  ?int  $usageLogId  la chiamata che li ha consumati, se gia' scritta
     *
     * @throws GettoniEsauritiException
     */
    public function consuma(User $utente, AiFeature $funzione, ?int $usageLogId = null): AiCreditMovement
    {
        $tenant = $utente->tenant;

        if ($tenant === null) {
            /*
             * 🚨 Un utente senza tenant e' un super admin (R1). Non ha un
             * portafoglio perche' non e' un cliente — e arrivare qui vorrebbe
             * dire che la catena della quota l'ha lasciato passare fin qui: si
             * rifiuta, invece di creargli un portafoglio dal nulla.
             */
            throw new GettoniEsauritiException(saldo: 0, servivano: $funzione->costoInGettoni());
        }

        $costo = $funzione->costoInGettoni();

        return DB::transaction(function () use ($tenant, $costo, $funzione, $usageLogId): AiCreditMovement {
            $bloccato = Tenant::withoutGlobalScopes()
                ->whereKey($tenant->getKey())
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

            // 💡 Il tenant in memoria dell'utente e' una copia: senza questo,
            // un `saldo()` chiamato subito dopo direbbe ancora il valore vecchio.
            $tenant->setAttribute('ai_credits', $dopo);

            return AiCreditMovement::withoutGlobalScopes()->create([
                'tenant_id' => $bloccato->getKey(),
                'delta' => -$costo,
                'saldo_dopo' => $dopo,
                'causale' => AiCreditMovement::CONSUMO,
                'ai_usage_log_id' => $usageLogId,
                'nota' => $funzione->label(),
            ]);
        });
    }

    /**
     * Accredita gettoni comprati — `G11.2`, per ora a mano.
     *
     * ⛔ **Non c'e' nessun incasso qui dentro**, e non e' una dimenticanza: il
     * fornitore di pagamento non e' stato scelto (D12). Il giorno che esiste,
     * chiamera' questo metodo dopo aver incassato, e non cambiera' nient'altro.
     */
    public function accredita(
        Tenant $tenant,
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

        return DB::transaction(function () use ($tenant, $gettoni, $operatore, $nota, $causale): AiCreditMovement {
            $bloccato = Tenant::withoutGlobalScopes()
                ->whereKey($tenant->getKey())
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
            $tenant->setAttribute('ai_credits', $dopo);

            return AiCreditMovement::withoutGlobalScopes()->create([
                'tenant_id' => $bloccato->getKey(),
                'delta' => $gettoni,
                'saldo_dopo' => $dopo,
                'causale' => $causale,
                'operatore_id' => $operatore?->getKey(),
                'nota' => $nota,
            ]);
        });
    }
}
