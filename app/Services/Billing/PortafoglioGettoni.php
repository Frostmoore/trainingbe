<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\AiFeature;
use App\Models\AiCreditMovement;
use App\Models\LottoDiGettoni;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\Exceptions\GettoniEsauritiException;
use Illuminate\Support\Carbon;
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

            // 🆕 ⏳ P2 — prima i gettoni regalati che scadono prima.
            $this->scalaDaiLotti($bloccato, $costo);

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
    /**
     * @param  Tenant|int|null  $pagatoDa  🆕 F5.8 — la palestra che
     *                                     ha pagato, quando non è la persona stessa (**R1**). ⚠️ Non cambia
     *                                     di chi sono i gettoni: cambia solo cosa resta scritto.
     */
    public function accredita(
        User $utente,
        int $gettoni,
        ?User $operatore = null,
        ?string $nota = null,
        string $causale = AiCreditMovement::ACQUISTO,
        Tenant|int|null $pagatoDa = null,
        ?Carbon $scadeIl = null,
        ?int $assegnazioneId = null,
    ): AiCreditMovement {
        if ($gettoni === 0) {
            // ⚠️ Un movimento da zero sporca il registro senza dire niente: chi
            // lo legge cerca cosa e' cambiato e non trova niente.
            throw new \InvalidArgumentException('Un accredito da zero gettoni non e\' un movimento.');
        }

        return DB::transaction(function () use ($utente, $gettoni, $operatore, $nota, $causale, $pagatoDa, $scadeIl, $assegnazioneId): AiCreditMovement {
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

            /*
             * 🆕 ⏳ **I lotti** — P2 di `plan_promozioni.md`.
             *
             * Un accredito con scadenza apre un lotto; un movimento negativo
             * (rettifica, scadenza, revoca) può lasciare i lotti più grandi del
             * saldo, e allora si riallineano. ⛔ Senza, a scadenza si
             * toglierebbero gettoni che la persona non ha più.
             */
            if ($gettoni > 0 && $scadeIl !== null) {
                LottoDiGettoni::query()->create([
                    'user_id' => $bloccato->getKey(),
                    'promozione_assegnazione_id' => $assegnazioneId,
                    'gettoni_iniziali' => $gettoni,
                    'gettoni_restanti' => $gettoni,
                    'scade_il' => $scadeIl,
                ]);
            } elseif ($gettoni < 0) {
                $this->allineaILotti($bloccato, $dopo);
            }

            return AiCreditMovement::withoutGlobalScopes()->create([
                'tenant_id' => $bloccato->tenant_id,
                'user_id' => $bloccato->getKey(),
                'delta' => $gettoni,
                'saldo_dopo' => $dopo,
                'causale' => $causale,
                'operatore_id' => $operatore?->getKey(),
                'nota' => $nota,

                /*
                 * 🆕 🎁 F5.8 — **`tenant_id` resta quello di CHI LI RICEVE**, e
                 * questa è un'altra colonna. ⛔ Scrivere qui sopra la palestra
                 * che paga sposterebbe il movimento nel suo spazio, e da lì il
                 * `TenantScope` lo nasconderebbe alla persona che quei gettoni
                 * li ha davvero in tasca.
                 */
                'pagato_da_tenant_id' => $pagatoDa instanceof Tenant
                    ? $pagatoDa->getKey()
                    : $pagatoDa,
            ]);
        });
    }

    /**
     * 🆕 ⏳ **Quanti gettoni di questa persona scadono, e quando** — P2.
     *
     * @return list<array{gettoni: int, scade_il: Carbon}>
     */
    public function inScadenza(User $utente): array
    {
        return LottoDiGettoni::query()
            ->aperti()
            ->where('user_id', $utente->getKey())
            ->orderBy('scade_il')
            ->get()
            ->map(fn (LottoDiGettoni $l): array => ['gettoni' => $l->gettoni_restanti, 'scade_il' => $l->scade_il])
            ->all();
    }

    /**
     * 🆕 ⏳ **I lotti scaduti: si toglie il residuo** — P2, `gettoni:scadenza`.
     *
     * 🚨 Si chiude il lotto **prima** del movimento negativo: altrimenti
     * `allineaILotti()` lo vedrebbe ancora aperto e lo scalerebbe due volte.
     * Si toglie `min(residuo, saldo)`: un portafoglio non va sotto zero.
     *
     * @return int quanti gettoni sono scaduti in tutto
     */
    public function faiScadere(?Carbon $quando = null): int
    {
        $quando ??= now();
        $tolti = 0;

        $scaduti = LottoDiGettoni::query()
            ->aperti()
            ->where('scade_il', '<=', $quando)
            ->get();

        foreach ($scaduti as $lotto) {
            $tolti += DB::transaction(function () use ($lotto): int {
                $bloccato = LottoDiGettoni::query()->lockForUpdate()->find($lotto->getKey());
                $utente = User::withoutGlobalScopes()->lockForUpdate()->find($lotto->user_id);

                if ($bloccato === null || $bloccato->chiuso_il !== null || $utente === null) {
                    return 0;
                }

                $residuo = min($bloccato->gettoni_restanti, (int) $utente->ai_credits);
                $bloccato->forceFill(['gettoni_restanti' => 0, 'chiuso_il' => now()])->save();

                if ($residuo > 0) {
                    $this->accredita(
                        $utente,
                        -$residuo,
                        nota: 'Gettoni regalati scaduti',
                        causale: AiCreditMovement::SCADENZA,
                    );
                }

                return $residuo;
            });
        }

        return $tolti;
    }

    /**
     * Scala un consumo dai lotti aperti, **prima quelli che scadono prima**.
     *
     * 💡 È la scelta che conviene al cliente: spende per primi i gettoni che
     * perderebbe, e quelli comprati (che non scadono) restano per dopo.
     */
    private function scalaDaiLotti(User $utente, int $quanti): void
    {
        $lotti = LottoDiGettoni::query()
            ->aperti()
            ->where('user_id', $utente->getKey())
            ->orderBy('scade_il')
            ->lockForUpdate()
            ->get();

        foreach ($lotti as $lotto) {
            if ($quanti <= 0) {
                break;
            }

            $tolti = min($quanti, $lotto->gettoni_restanti);
            $quanti -= $tolti;
            $resta = $lotto->gettoni_restanti - $tolti;

            $lotto->forceFill([
                'gettoni_restanti' => $resta,
                'chiuso_il' => $resta === 0 ? now() : null,
            ])->save();
        }
    }

    /**
     * 🚨 **La somma dei lotti non supera mai il saldo.**
     *
     * Dopo un movimento negativo che non è un consumo (una rettifica a mano,
     * una revoca), si tolgono i gettoni in eccesso dai lotti che scadono
     * **più tardi**: sono quelli che la persona avrebbe speso per ultimi.
     */
    private function allineaILotti(User $utente, int $saldo): void
    {
        $lotti = LottoDiGettoni::query()
            ->aperti()
            ->where('user_id', $utente->getKey())
            ->orderByDesc('scade_il')
            ->lockForUpdate()
            ->get();

        $eccesso = (int) $lotti->sum('gettoni_restanti') - $saldo;

        foreach ($lotti as $lotto) {
            if ($eccesso <= 0) {
                break;
            }

            $tolti = min($eccesso, $lotto->gettoni_restanti);
            $eccesso -= $tolti;
            $resta = $lotto->gettoni_restanti - $tolti;

            $lotto->forceFill([
                'gettoni_restanti' => $resta,
                'chiuso_il' => $resta === 0 ? now() : null,
            ])->save();
        }
    }
}
