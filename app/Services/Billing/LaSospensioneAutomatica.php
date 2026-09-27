<?php

declare(strict_types=1);

namespace App\Services\Billing;

use App\Enums\PlanKind;
use App\Enums\TenantStatus;
use App\Models\AddebitoMensile;
use App\Models\PlanSubscription;
use App\Models\Tenant;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 🤖 **Chi non paga si sospende da sé, e chi ripaga torna su da sé** — 27/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * 📌 Il committente, 27/09: *«per prima cosa ovviamente facciamo che chi non ha
 * pagato si sospende in automatico»*.
 *
 * ⛔ **Prima non lo faceva nessuno.** `Cassa::abbonamentoFinito()` scriveva
 * `ends_at = now()` sulla riga dell'abbonamento e **si fermava lì**: lo stato
 * del tenant restava `active` per sempre. 🚨 Effetto: una palestra morosa
 * continuava a vedere un pannello normale, l'AI le rispondeva «no» senza
 * spiegazioni, e `AvvisoDaPagare` — che guarda `isActive()` — **non compariva**.
 * ⛔ Cioè il cliente non aveva **nessun modo di sapere** che doveva pagare.
 *
 * 💡 Era un buco nell'incasso travestito da gentilezza.
 *
 * ══ 🚨 QUESTA CLASSE TOGLIE L'ACCESSO: VA TENUTA STRETTISSIMA ═════════════
 *
 * ⛔ È l'unico pezzo di codice del progetto che **sospende uno spazio senza che
 * una persona lo abbia deciso**. Ogni condizione qui sotto è una garanzia, non
 * una precauzione, e va letta ricordando la prima regola: *«NIENTE AL MONDO DEVE
 * IMPEDIRE A UN UTENTE DI ACCEDERE»*.
 *
 * ⚠️ **Sospendere non è chiudere fuori.** Dal 27/09 uno spazio sospeso si apre
 * comunque: il pannello mostra `AvvisoDaPagare` con il tasto per pagare (§162), e
 * sull'app la persona torna a vedere l'interfaccia di chi non è abbonato. 🔑 Se
 * un giorno quella catena si rompesse, **questa classe diventerebbe una macchina
 * che chiude fuori i clienti ogni notte**.
 *
 * ══ ⚖️ LE QUATTRO CONDIZIONI, E COSA SUCCEDEREBBE SENZA CIASCUNA ══════════
 *
 * | Condizione | Se mancasse |
 * |---|---|
 * | era un **cliente a canone** | si sospenderebbe lo spazio personale di chi non doveva pagare niente |
 * | **nessun** abbonamento attivo adesso | si sospenderebbe chi ha appena rinnovato |
 * | stato `active` o `trial` | si riscriverebbe sopra una decisione già presa |
 * | `ends_at` passato da più di un giorno | si sospenderebbe per il ritardo di un webhook |
 *
 * ══ 💶 E IL CONSUMO? STESSA REGOLA, ALTRO SEGNALE ═══════════════
 *
 * 📌 Il committente, 27/09: *«quelli che tu chiami piano a consumo non è
 * nient'altro che un abbonamento dal prezzo variabile in base agli iscritti,
 * quindi può funzionare esattamente come l'abbonamento normale: quando fallisce
 * il pagamento si sospende, stessa cosa di tutti gli abbonamenti»*.
 *
 * 🚨 **La prima versione di questa classe lo lasciava fuori, e la premessa era
 * sbagliata**: avevo scritto che «là non c'è un abbonamento che finisce» e da quello
 * avevo dedotto che servisse una decisione commerciale nuova. ⛔ Non serviva.
 *
 * 🔑 Cambia solo **da dove arriva il segnale**:
 *
 * | | Il segnale di «non paga» |
 * |---|---|
 * | **canone** | `ends_at` passato — la grazia di Stripe è già dentro |
 * | **consumo** | la fattura del mese insolvente da 8 giorni (`addebiti_mensili.fallito_il`) |
 *
 * ⚖️ Due strade, **una regola**: *se non paga, si sospende*. 💡 E la domanda
 * unica è `staPagando()`, che le mette insieme.
 *
 * ⚠️ **Un piano a consumo ha `price_cents = 0`** — il prezzo è per iscritto — e
 * per un pelo `eraUnClientePagante()` lo escludeva: sarebbe rimasto fuori dai
 * candidati e non si sarebbe sospeso mai. 💡 Trovato guardando i piani veri a
 * database invece di fidarsi di come «dovrebbero» essere fatti.
 */
class LaSospensioneAutomatica
{
    /**
     * ⏳ **Quanti giorni si aspetta DOPO la fine dell'abbonamento.**
     *
     * ══ 🚨 PERCHE' NON E' ZERO ════════════════════════════════════════════
     *
     * ⚠️ I sette giorni di grazia di Stripe sono **già dentro `ends_at`**
     * (`Cassa::conLaGrazia()`), quindi qui non si sta regalando un'altra
     * settimana: quando `ends_at` è passato, Stripe ha già riprovato per giorni e
     * ha smesso.
     *
     * 💡 Questo giorno in più copre **una cosa sola**: il ritardo di un webhook.
     * Se `invoice.paid` arriva con qualche ora di ritardo e il giro notturno
     * passa nel frattempo, senza questo margine sospenderemmo **un cliente che
     * ha pagato**. ⛔ Ed è l'errore peggiore che questa classe può fare: il
     * contrario del suo scopo.
     *
     * ⚖️ Un giorno di servizio regalato costa niente; un cliente sospeso per
     * sbaglio costa una telefonata e la fiducia.
     */
    public const GIORNI_DI_ATTESA = 1;

    /**
     * ⏳ **E quanti si aspetta per il CONSUMO, dalla prima fattura insolvente.**
     *
     * ⚠️ Qui sono **otto** e non uno, e la differenza non è arbitraria: per un
     * canone i sette giorni di grazia di Stripe sono **già dentro `ends_at`**
     * (`Cassa::conLaGrazia()`), quindi là resta solo il giorno di margine per il
     * ritardo di un webhook.
     *
     * 🔑 Per il consumo quella grazia **non è dentro niente**: la fattura del
     * mese resta lì, insolvente, mentre Stripe riprova. 💡 Quindi i sette giorni
     * si contano qui, più lo stesso giorno di margine: **7 + 1**.
     *
     * ⚖️ È la stessa finestra reale, scritta in un posto diverso perché il
     * segnale arriva da un'altra strada.
     */
    public const GIORNI_PRIMA_DI_SOSPENDERE_IL_CONSUMO = 8;

    public function __construct(private readonly TenantContext $context) {}

    // ───────────────────────── il giro ─────────────────────────

    /**
     * Fa il giro completo: sospende i morosi e riattiva chi ha ripagato.
     *
     * ⚠️ **Le due metà stanno nello stesso metodo** perché sono la stessa
     * regola letta nei due versi. ⛔ Separarle vorrebbe dire poterne
     * programmare una sola — e la metà che si dimentica è sempre la
     * riattivazione, cioè quella che restituisce il servizio a chi ha pagato.
     *
     * @param  bool  $scrivi  `false` guarda e non tocca niente
     * @return array{sospesi: list<string>, riattivati: list<string>}
     */
    public function giro(bool $scrivi = true): array
    {
        return $this->context->runWithoutTenant(function () use ($scrivi): array {
            $sospesi = [];
            $riattivati = [];

            foreach ($this->daSospendere() as $spazio) {
                $sospesi[] = $spazio->name.' (#'.$spazio->getKey().')';

                if ($scrivi) {
                    $this->sospendi($spazio);
                }
            }

            foreach ($this->daRiattivare() as $spazio) {
                $riattivati[] = $spazio->name.' (#'.$spazio->getKey().')';

                if ($scrivi) {
                    $this->riattiva($spazio);
                }
            }

            return ['sospesi' => $sospesi, 'riattivati' => $riattivati];
        });
    }

    // ───────────────────────── chi ─────────────────────────

    /**
     * 🔎 Gli spazi che vanno sospesi adesso.
     *
     * @return list<Tenant>
     */
    public function daSospendere(): array
    {
        /*
         * ⚡ **La query restringe, `vaSospeso()` decide.**
         *
         * ⚠️ Il `whereHas` qui è una **ottimizzazione, non la regola**: la regola
         * vive in `vaSospeso()`, e questa query si limita a non tirare su dal
         * database gli spazi che sicuramente non la soddisfano.
         *
         * 🚨 Senza, questo giro caricherebbe **ogni tenant della piattaforma** e
         * poi farebbe due o tre query per ognuno: con mille clienti sono
         * tremila query ogni notte, e la maggior parte su spazi personali che non
         * pagano niente e non si toccano mai.
         *
         * ⛔ **E non è una copia della regola**: se un giorno `vaSospeso()`
         * cambiasse idea su cosa sia «un cliente a canone», questa riga
         * mostrerebbe *più* candidati del necessario — non meno. 💡 Sbagliare in
         * quella direzione fa perdere tempo; sbagliare nell'altra sospenderebbe
         * qualcuno di nascosto.
         */
        $candidati = $this->context->runWithoutTenant(
            fn (): array => Tenant::withoutGlobalScopes()
                ->whereIn('status', [TenantStatus::Active->value, TenantStatus::Trial->value])
                ->whereNull('sospensione_automatica_dal')
                ->whereExists(fn ($q) => $q
                    ->from('plan_subscriptions as ps')
                    ->join('plans as pl', 'pl.id', '=', 'ps.plan_id')
                    ->whereColumn('ps.tenant_id', 'tenants.id')
                    ->whereIn('pl.kind', [PlanKind::Gym->value, PlanKind::Trainer->value])
                    /*
                     * 🚨 **Anche i piani a CONSUMO, e per un pelo non era così.**
                     *
                     * ⛔ Qui c'era solo `price_cents > 0`. I piani a consumo hanno
                     * `price_cents = 0` — il prezzo è per iscritto — quindi
                     * sarebbero stati **esclusi dai candidati** e non si sarebbero
                     * sospesi mai, nemmeno con la regola nuova sull'insolvenza.
                     *
                     * 💡 Trovato guardando i piani veri a database invece di
                     * fidarsi di come «dovrebbero» essere fatti.
                     */
                    ->where(fn ($q) => $q
                        ->where('pl.price_cents', '>', 0)
                        ->orWhereNotNull('pl.prezzo_iscritto_cent')
                    )
                    ->selectRaw('1')
                )
                ->get()
                ->all(),
        );

        return array_values(array_filter(
            $candidati,
            fn (Tenant $spazio): bool => $this->vaSospeso($spazio),
        ));
    }

    /**
     * 🔎 Gli spazi sospesi **dalla macchina** che adesso hanno ripagato.
     *
     * 🚨 `whereNotNull('sospensione_automatica_dal')` è la riga che protegge le
     * decisioni delle persone: una sospensione messa a mano da `/god` non ha
     * quella data, quindi **non viene mai toccata qui**.
     *
     * @return list<Tenant>
     */
    public function daRiattivare(): array
    {
        return $this->context->runWithoutTenant(
            fn (): array => array_values(array_filter(
                Tenant::withoutGlobalScopes()
                    ->where('status', TenantStatus::Suspended->value)
                    ->whereNotNull('sospensione_automatica_dal')
                    ->get()
                    ->all(),
                fn (Tenant $spazio): bool => $this->staPagando($spazio),
            )),
        );
    }

    // ───────────────────────── le regole ─────────────────────────

    /**
     * ⚖️ **La domanda, tutta intera.** Quattro condizioni in `and`: basta che
     * una sia falsa perché lo spazio non si tocchi.
     */
    public function vaSospeso(Tenant $spazio): bool
    {
        if (! in_array($spazio->status, [TenantStatus::Active, TenantStatus::Trial], true)) {
            return false;
        }

        if ($spazio->sospensione_automatica_dal !== null) {
            return false;
        }

        if (! $this->eraUnClientePagante($spazio)) {
            return false;
        }

        /*
         * 💶 **Il consumo è un abbonamento a prezzo variabile** — 27/09/2026.
         *
         * 📌 Il committente: *«quelli che tu chiami piano a consumo non è
         * nient'altro che un abbonamento dal prezzo variabile in base agli
         * iscritti, quindi può funzionare esattamente come l'abbonamento normale:
         * quando fallisce il pagamento si sospende, stessa cosa di tutti gli
         * abbonamenti»*.
         *
         * 🚨 **La mia distinzione era sbagliata.** Avevo lasciato il consumo fuori
         * dicendo che «là non c'è un abbonamento che finisce», e da quella premessa
         * avevo dedotto che servisse una decisione commerciale nuova. ⛔ Non
         * serviva: la regola è la stessa, cambia solo **da dove arriva il segnale**.
         *
         * 🔑 Per un canone il segnale è `ends_at` passato; per il consumo è la
         * fattura del mese insolvente da abbastanza tempo. ⚠️ Due strade, **una
         * regola**: *se non paga, si sospende*.
         */
        $insolvenza = $this->insolvenzaDelConsumo($spazio);

        if ($insolvenza !== null) {
            return $insolvenza
                ->copy()
                ->addDays(self::GIORNI_PRIMA_DI_SOSPENDERE_IL_CONSUMO)
                ->isPast();
        }

        if ($this->haUnAbbonamentoVivo($spazio)) {
            return false;
        }

        return $this->finitoDaAbbastanzaTempo($spazio);
    }

    /**
     * 💶 Da quando la fattura del consumo non è pagata, se non è pagata.
     *
     * ⚠️ **La più vecchia fra quelle insolventi**: se ne restano due, la domanda è
     * *«da quanto non paga»*, non *«qual è l'ultima che ha saltato»*.
     *
     * 💡 `fallito_il` si scrive alla **prima** insolvenza e non si riscrive ai
     * ritentativi di Stripe, altrimenti la sospensione slitterebbe in avanti a ogni
     * tentativo e non arriverebbe mai.
     */
    public function insolvenzaDelConsumo(Tenant $spazio): ?Carbon
    {
        $quando = $this->context->runWithoutTenant(
            fn (): mixed => AddebitoMensile::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $spazio->getKey())
                ->where('stato', AddebitoMensile::FALLITO)
                ->whereNotNull('fallito_il')
                ->min('fallito_il'),
        );

        return $quando === null
            ? null
            : Carbon::parse((string) $quando);
    }

    /**
     * ✅ **Questo spazio sta pagando?** — la domanda unica, usata nei due versi.
     *
     * ⚠️ **Asimmetrica, e apposta**: per **sospendere** si pretende che
     * l'insolvenza sia vecchia (vedi `vaSospeso()`); per **riattivare** basta che
     * non ci sia più. 🔑 Si sospende piano e si riattiva subito — l'errore in
     * quella direzione costa un giorno di servizio regalato, nell'altra un cliente.
     */
    public function staPagando(Tenant $spazio): bool
    {
        return $this->haUnAbbonamentoVivo($spazio)
            && $this->insolvenzaDelConsumo($spazio) === null
            && ! $this->canoneNonIncassato($spazio);
    }

    /**
     * 💳 **C'è un canone che non siamo riusciti a incassare?**
     *
     * ══ ⚠️ PERCHE' NON BASTA `haUnAbbonamentoVivo()` ═══════════════════════
     *
     * 🚨 Quando Stripe non riesce a incassare un canone, `Cassa` segna
     * `pagamento_fallito_il` e **non spegne niente**: l'abbonamento resta vivo,
     * `ends_at` è nel futuro, e Stripe riprova per giorni. ⛔ È voluto — spegnere
     * al primo rifiuto punirebbe chi ha cambiato carta di venerdì.
     *
     * 💡 Ma *«il servizio è ancora acceso»* e *«il mese è pagato»* sono due cose
     * diverse, e per **cambiare piano** conta la seconda.
     *
     * 📌 Il committente, 27/09: *«quando clicchi su rinnova ora, deve solo farmi
     * pagare, non mi deve permettere di cambiare il piano. Quello devo poterlo fare
     * solo dopo che il pagamento è stato effettuato per il mese corrente»*.
     *
     * ⚠️ **Questo NON fa sospendere nessuno**: la sospensione ha le sue condizioni
     * di tempo, e questa domanda non ne fa parte. 💡 Entra in `staPagando()`, che
     * governa la **riattivazione** e il **cambio di piano** — dove la risposta
     * giusta è «no, prima paga il mese».
     */
    public function canoneNonIncassato(Tenant $spazio): bool
    {
        return $this->context->runWithoutTenant(
            fn (): bool => PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $spazio->getKey())
                ->attivi()
                ->whereNotNull('pagamento_fallito_il')
                ->exists(),
        );
    }

    /**
     * 💶 **Ha mai pagato un canone da palestra o da trainer?**
     *
     * ══ 🚨 E' LA CONDIZIONE PIU' IMPORTANTE DEL FILE ══════════════════════
     *
     * ⛔ Senza, questo giro sospenderebbe **lo spazio personale di chiunque**:
     * di un iscritto che non ha mai comprato niente, di un utente che si è
     * appena registrato, del super admin. 🚨 Cioè romperebbe la prima regola del
     * progetto ogni notte, in automatico, su tutti.
     *
     * 💡 «Cliente a canone» vuol dire: esiste **almeno una riga** di abbonamento,
     * anche finita, verso un piano di tipo `gym` o `trainer` **a pagamento**.
     *
     * ⚠️ **Anche finita, e apposta**: è proprio la riga finita che dice *«questo
     * era un cliente che pagava»*. Guardare solo gli abbonamenti vivi darebbe
     * sempre `false` — e non si sospenderebbe mai nessuno.
     *
     * ⚠️ **`price_cents > 0`**: il piano trainer gratuito è un piano, non un
     * debito. Chi ci sta sopra non è in ritardo con niente.
     */
    public function eraUnClientePagante(Tenant $spazio): bool
    {
        return $this->context->runWithoutTenant(
            fn (): bool => PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $spazio->getKey())
                ->whereHas('plan', fn ($q) => $q
                    ->whereIn('kind', [PlanKind::Gym->value, PlanKind::Trainer->value])
                    ->where(fn ($p) => $p
                        ->where('price_cents', '>', 0)
                        // 💶 A consumo il prezzo è per iscritto: `price_cents` è 0.
                        ->orWhereNotNull('prezzo_iscritto_cent')
                    )
                )
                ->exists(),
        );
    }

    /** ✅ C'è un abbonamento che vale adesso? (di qualunque tipo) */
    public function haUnAbbonamentoVivo(Tenant $spazio): bool
    {
        return $this->context->runWithoutTenant(
            fn (): bool => PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $spazio->getKey())
                ->attivi()
                ->exists(),
        );
    }

    /**
     * ⏳ L'ultimo abbonamento è finito da più di [GIORNI_DI_ATTESA] giorni?
     *
     * ⚠️ **Si guarda la fine più RECENTE**, non la più vecchia: chi ha una
     * storia di abbonamenti ne ha molte finite, e la domanda è *«da quanto non
     * paga»*, non *«quando ha cominciato»*.
     */
    private function finitoDaAbbastanzaTempo(Tenant $spazio): bool
    {
        $ultimaFine = $this->context->runWithoutTenant(
            fn (): mixed => PlanSubscription::withoutGlobalScopes()
                ->where('tenant_id', $spazio->getKey())
                ->whereNotNull('ends_at')
                ->max('ends_at'),
        );

        if ($ultimaFine === null) {
            /*
             * ⚠️ **Nessuna data di fine e nessun abbonamento vivo.**
             *
             * 🚨 Non si sospende, ed è deliberato. La convenzione di questo
             * progetto è che `ends_at = null` voglia dire **«non scade mai»**
             * (vedi `RinnovaGliAbbonamentiRegalati`): una riga senza fine che
             * non risulta attiva è uno **stato che non dovrebbe esistere** — una
             * pausa mal chiusa, un `starts_at` nel futuro, un dato sporco.
             *
             * ⛔ Davanti a un dato che non si capisce, una macchina che toglie
             * l'accesso deve **fermarsi**, non indovinare.
             */
            return false;
        }

        return now()->greaterThan(
            Carbon::parse((string) $ultimaFine)
                ->addDays(self::GIORNI_DI_ATTESA)
        );
    }

    // ───────────────────────── le azioni ─────────────────────────

    /**
     * 🔒 Sospende, **segnando che è stata la macchina**.
     *
     * ⚠️ Le due scritture stanno in una transazione: uno stato `suspended` senza
     * la data accanto sarebbe indistinguibile da una sospensione decisa da una
     * persona, e non tornerebbe più su da sé.
     */
    public function sospendi(Tenant $spazio): void
    {
        DB::transaction(function () use ($spazio): void {
            $spazio->forceFill([
                'status' => TenantStatus::Suspended,
                'sospensione_automatica_dal' => now(),
            ])->save();
        });
    }

    /**
     * 🔓 Riattiva — **solo** se l'aveva sospesa la macchina.
     *
     * 🚨 Il controllo è ripetuto qui e non lasciato a chi chiama: questo metodo
     * è pubblico e lo chiama anche `Cassa`, sul pagamento. ⛔ Un controllo che
     * sta solo nel chiamante è un controllo che il secondo chiamante non ha.
     *
     * @return bool se ha fatto qualcosa
     */
    public function riattiva(Tenant $spazio): bool
    {
        if ($spazio->sospensione_automatica_dal === null) {
            return false;
        }

        if ($spazio->status !== TenantStatus::Suspended) {
            /*
             * 💡 Non è sospesa: si pulisce comunque la data, che a questo punto
             * è un residuo. ⚠️ Lasciarla vorrebbe dire che una sospensione
             * futura, fatta a mano, verrebbe letta come automatica.
             */
            $spazio->forceFill(['sospensione_automatica_dal' => null])->save();

            return false;
        }

        DB::transaction(function () use ($spazio): void {
            $spazio->forceFill([
                'status' => TenantStatus::Active,
                'sospensione_automatica_dal' => null,
            ])->save();
        });

        return true;
    }

    /**
     * 💳 **Chiamato quando arriva un pagamento** — la metà che si dimentica.
     *
     * 🚨 Una macchina che sospende e non riattiva **prende i soldi e non ridà il
     * servizio**: è il risultato peggiore possibile, peggiore del buco che questa
     * classe è venuta a chiudere.
     *
     * ⚠️ E la riattivazione va **sul pagamento**, non solo nel giro notturno:
     * chi ha appena pagato con la carta in mano non deve aspettare la notte.
     * 💡 Il giro notturno resta come rete di sicurezza, per i pagamenti che non
     * passano da un webhook.
     */
    public function seHaRipagato(?int $tenantId): void
    {
        if ($tenantId === null) {
            return;
        }

        $spazio = $this->context->runWithoutTenant(
            fn (): ?Tenant => Tenant::withoutGlobalScopes()->find($tenantId),
        );

        if ($spazio === null || $spazio->sospensione_automatica_dal === null) {
            return;
        }

        if (! $this->staPagando($spazio)) {
            return;
        }

        $this->riattiva($spazio);
    }
}
