<?php

declare(strict_types=1);

namespace App\Services\Ai\Quota;

use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * La quota AI che un trainer indipendente riserva ai suoi allievi — U.2, U.3.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«all'allievo arriva la stessa quota che gli arriverebbe se si abbonasse.
 * Quindi 150 chiamate»* — e, sulla scadenza: *«Quando a un trainer scade
 * l'abbonamento, gli allievi del trainer mantengono l'uso della quota ai
 * riservata a loro dal trainer fino a un mese dopo il giorno in cui gli e'
 * stata assegnata»*.
 *
 * ══ 🚨 QUELLO CHE NON FUNZIONAVA, E NON DAVA NESSUN ERRORE ════════════════
 *
 * `MemberAiQuota` ha da sempre un **livello 3** che legge il tetto del trainer,
 * e c'e' pure un test che lo prova (`without_a_gym_the_trainer_cap_applies`).
 * ⛔ **Non e' mai servito a niente**, e il motivo e' due righe piu' su:
 * `hasQuotaLeft()` comincia con `if (! haLaAi($utente)) return false;`.
 *
 * L'allievo di un trainer indipendente sta in un **tenant personale** con il
 * piano `free`, e il piano `free` non comprende l'AI. Quindi:
 *
 *     haLaAi(allievo)  →  false  →  nessuna quota, mai
 *     capFor(allievo)  →  il tetto del trainer  →  un numero che nessuno legge
 *
 * 🚨 Il livello 3 rispondeva alla domanda **quanta**, quando la domanda vera era
 * **se**. Un test verde su un numero che il cancello non raggiunge e' esattamente
 * il difetto ricorrente di questo progetto: nessun errore, e la regola applicata
 * altrove.
 *
 * 💡 Per questo il servizio risponde a **due** domande e non a una:
 * `copre()` (l'AI esiste per questa persona) e `tetto()` (quanta).
 *
 * ══ ⚖️ LA SCADENZA: L'ANNIVERSARIO MENSILE ════════════════════════════════
 *
 * 🚨 **Non e' «data + un mese»**, ed e' il committente ad averlo sciolto:
 * *«se la sua quota e' stata messa il 12 febbraio e il trainer smette di pagare
 * il 25 ottobre, all'allievo resta fino al 12 novembre»*.
 *
 *     quota assegnata      12/02
 *     cicli                12/02 → 12/03 → … → 12/10 → 12/11
 *     trainer smette       25/10          ╰──── ciclo in corso ────╯
 *     l'allievo tiene fino al  12/11
 *
 * 💡 L'allievo **finisce il ciclo che il trainer aveva gia' pagato**: non tiene
 * un mese pieno dalla scadenza, e non perde tutto il giorno stesso.
 *
 * ══ ⛔ SI APPLICA IN LETTURA, NON CON UN LAVORO CHE CANCELLA ══════════════
 *
 * Non esiste nessun job che alla scadenza azzeri qualcosa, ed e' deliberato: un
 * job del genere distruggerebbe **anche la data**, e il giorno in cui il trainer
 * rinnova non ci sarebbe piu' niente da ripristinare. 💡 Il dato resta dov'e', e
 * ogni lettura decide se vale ancora — quindi «scaduto e poi rinnovato» rimette
 * il rapporto in piedi da solo, senza nessun ripristino (U.3.3).
 */
class QuotaDelTrainer
{
    public function __construct(
        private readonly TenantContext $context,
    ) {}

    /**
     * C'e' un trainer che sta coprendo l'AI di questa persona?
     *
     * ⚠️ **Solo per chi non ha una palestra** — decisione D3. Se un iscritto di
     * una palestra si fa seguire **anche** da un trainer indipendente, non deve
     * poter drenare quello che il trainer paga per i **suoi** utenti: chi paga
     * per quella persona e' la palestra, e il tetto e' suo.
     */
    public function copre(User $allievo): bool
    {
        return $this->trainerCheCoprono($allievo) !== [];
    }

    /**
     * Il tetto che arriva dal trainer. `null` se nessuno lo copre.
     *
     * 🚨 **E' la quota del piano `PLUS`, sempre** — 📌 *«la stessa quota che gli
     * arriverebbe se si abbonasse»*. ⛔ Non e' il tetto del trainer: quello e'
     * il **suo**, e un trainer con l'AI illimitata non regala l'illimitato a
     * cinquanta persone.
     *
     * ⚠️ **Le fasce trainer limitano quanti allievi, non quanta AI**: tier 1 =
     * 10 allievi, tier 2 = 20, tier 3 = 50. Il numero di chiamate non cambia da
     * una fascia all'altra, e infatti qui la fascia non si guarda nemmeno.
     *
     * 💡 Si legge dal **listino** e non da una costante: il giorno che il piano
     * `PLUS` passa da 150 a 200 chiamate, gli allievi dei trainer seguono senza
     * che nessuno debba ricordarsi di questo file.
     */
    public function tetto(User $allievo, bool $conFoto): ?int
    {
        if (! $this->copre($allievo)) {
            return null;
        }

        $plus = $this->context->runWithoutTenant(
            fn (): ?Plan => Plan::query()->where('code', Plan::PLUS)->first(),
        );

        if ($plus === null) {
            /*
             * ⚠️ Listino non seminato: si nega invece di inventare un numero.
             * E' la stessa direzione di `PianoAttivo::piadinoDiRiserva()` —
             * un'installazione mal configurata non deve **concedere**.
             */
            return null;
        }

        return $conFoto
            ? $plus->ai_monthly_photo_calls_per_member
            : $plus->ai_monthly_calls_per_member;
    }

    /**
     * Fino a che giorno vale la quota, dato quando e' stata assegnata e quando
     * il trainer ha smesso di pagare.
     *
     * 🚨 **`public static` perche' e' la regola, e va provata da sola.** E' il
     * pezzo che il committente ha specificato con un esempio, ed e' l'unico
     * punto in cui un errore di calendario diventa un mese di AI regalato o
     * tolto a qualcuno.
     *
     * ── ⚠️ La trappola dei mesi corti, che e' gia' costata una volta ───────
     *
     * ⛔ `Carbon::addMonth()` sul **31 gennaio** da' **3 marzo**, non il 28: la
     * data trabocca. Una quota assegnata il 31 non ha un anniversario a
     * febbraio, e il mese corto va **accorciato**, non superato.
     *
     * 💡 Per questo l'anniversario non si calcola sommando mesi ma
     * **costruendo la data**: `min(giorno, giorni del mese)`. Sommare
     * ripetutamente sarebbe sbagliato anche con `addMonthNoOverflow()`, che
     * fa derivare il giorno per sempre — 31/01 → 28/02 → 28/03, e da marzo in
     * poi l'anniversario e' il 28 invece del 31.
     */
    public static function valeFinoA(CarbonInterface $assegnataIl, CarbonInterface $scadenza): Carbon
    {
        $giorno = $assegnataIl->day;

        $candidato = self::anniversarioNelMeseDi($scadenza, $giorno);

        /*
         * 💡 Se l'anniversario di questo mese e' gia' passato quando il trainer
         * smette, il ciclo in corso finisce il mese dopo. E' il caso
         * dell'esempio del committente: assegnata il 12, scaduto il 25 → il 12
         * di ottobre e' alle spalle, quindi vale fino al 12 di novembre.
         */
        if ($candidato->lt($scadenza->copy()->startOfDay())) {
            $candidato = self::anniversarioNelMeseDi(
                $scadenza->copy()->addMonthNoOverflow(),
                $giorno,
            );
        }

        return $candidato;
    }

    /** L'anniversario dentro il mese di `$quando`, accorciato se il mese e' corto. */
    private static function anniversarioNelMeseDi(CarbonInterface $quando, int $giorno): Carbon
    {
        return Carbon::create(
            $quando->year,
            $quando->month,
            min($giorno, $quando->daysInMonth),
        )->startOfDay();
    }

    /**
     * I trainer che in questo momento coprono l'AI di questa persona.
     *
     * @return list<int> gli id, che servono solo a sapere se ce n'e' almeno uno
     */
    private function trainerCheCoprono(User $allievo): array
    {
        $tenant = $allievo->tenant;

        /*
         * ⚠️ La condizione di D3, scritta **qui** e non solo in `MemberAiQuota`:
         * il giorno in cui qualcuno riordinasse i livelli, il vincolo non deve
         * dipendere dall'ordine.
         */
        if ($tenant === null || ! $tenant->ePersonale()) {
            return [];
        }

        $coprono = [];

        foreach ($allievo->assignedTrainers()->get() as $trainer) {
            $pivot = $trainer->pivot;

            /*
             * ⛔ Un rapporto disattivato non copre piu' niente — D5: *«il legame
             * resta, la storia si conserva, il canale si chiude»*. La riga non
             * si cancella mai, quindi la disattivazione e' l'unico segno che il
             * rapporto non e' piu' in piedi.
             */
            if ($pivot?->disattivato_il !== null) {
                continue;
            }

            if ($this->questoTrainerCopre($trainer, $this->assegnataIl($trainer))) {
                $coprono[] = (int) $trainer->getKey();
            }
        }

        return $coprono;
    }

    /**
     * Quando questo rapporto ha cominciato a dare la quota.
     *
     * 💡 **`quota_assegnata_il`, e in mancanza `assigned_at`.** I rapporti nati
     * prima di U.2 hanno la colonna vuota, e riempirla con `now()` alla
     * migrazione avrebbe spostato l'anniversario di gente che il trainer ce
     * l'ha da mesi. La data di nascita del rapporto **e'**, per tutti loro, il
     * giorno in cui ha cominciato a dare la quota.
     */
    private function assegnataIl(User $trainer): Carbon
    {
        $pivot = $trainer->pivot;

        $quando = $pivot?->quota_assegnata_il ?? $pivot?->assigned_at;

        return $quando !== null
            ? Carbon::parse($quando)
            : Carbon::parse($trainer->created_at ?? Carbon::now());
    }

    /**
     * Questo trainer sta pagando, o ha pagato abbastanza di recente?
     *
     * ── 🚨 Si guarda l'ABBONAMENTO, non `haLaAi($trainer)` ─────────────────
     *
     * ⛔ Chiamare `PianoAttivo::haLaAi()` sul trainer aprirebbe una ricorsione:
     * `haLaAi` (un giorno) potrebbe passare di qui, e un trainer che segue un
     * trainer che segue il primo girerebbe all'infinito. Qui serve un fatto
     * commerciale — **ha un abbonamento** — e quello si legge dalla tabella.
     */
    private function questoTrainerCopre(User $trainer, Carbon $assegnataIl): bool
    {
        $abbonamento = $this->ultimoAbbonamentoVero($trainer);

        if ($abbonamento === null) {
            /*
             * ⛔ Un trainer che non si e' **mai** abbonato non copre nessuno: la
             * fascia gratuita e' per provare l'app, non per regalare l'AI a
             * dieci persone. Senza questa riga, `free_trainer` sarebbe il modo
             * piu' economico per avere quaranta account `plus`.
             */
            return false;
        }

        if ($abbonamento->eAttivo()) {
            return true;
        }

        /*
         * ⏰ Scaduto: vale il ciclo gia' pagato. ⚠️ `ends_at` non puo' essere
         * `null` qui — `eAttivo()` avrebbe detto di si' — ma il controllo resta
         * perche' un `null` inatteso non deve diventare «copre per sempre».
         */
        if ($abbonamento->ends_at === null) {
            return false;
        }

        $fine = self::valeFinoA($assegnataIl, $abbonamento->ends_at);

        /*
         * 💡 **Si confrontano i giorni, non gli istanti.** 📌 *«all'allievo resta
         * fino al 12 novembre»*: il 12 e' compreso, il 13 no. Confrontando gli
         * istanti, chi guarda alle 9 del 12 novembre lo troverebbe gia' scaduto
         * — e sarebbe un giorno in meno di quello che gli e' stato detto.
         */
        return Carbon::now()->startOfDay()->lte($fine);
    }

    /**
     * L'ultimo abbonamento **che comprende l'AI** del tenant di questa persona.
     *
     * ── 🚨 «Comprende l'AI», e non «non e' il piano free» ──────────────────
     *
     * ⛔ La prima versione escludeva solo `Plan::FREE`, e lasciava passare
     * `trainer_free` — che **costa zero** (`price_cents = 0`) e **non ha l'AI**
     * (`ai_enabled = false`). Un trainer su quella fascia avrebbe coperto i suoi
     * tre allievi con la quota `PLUS` senza pagare niente: il buco che questa
     * classe dichiara di chiudere, riaperto due metodi piu' sotto.
     *
     * 💡 Il criterio giusto e' una frase sola: **chi l'AI non ce l'ha non puo'
     * regalarla**. E si legge dal listino, quindi vale automaticamente per
     * qualunque fascia che qualcuno aggiunga domani.
     *
     * ⚠️ `ai_enabled` e non `price_cents > 0`: un giorno potremmo dare a un
     * cliente una fascia a prezzo zero per accordo, e quella deve funzionare. Il
     * prezzo e' una trattativa, l'AI e' cosa c'e' dentro.
     */
    private function ultimoAbbonamentoVero(User $trainer): ?PlanSubscription
    {
        $tenantId = $trainer->tenant_id;

        if ($tenantId === null) {
            return null;
        }

        /*
         * ⚠️ `runWithoutTenant` e il filtro rimesso a mano: questo servizio gira
         * anche da un job o da un comando, dove il contesto e' vuoto e la query
         * scopata non troverebbe niente. E' la stessa nota che sta su
         * `PianoAttivo::per()`.
         */
        return $this->context->runWithoutTenant(
            fn (): ?PlanSubscription => PlanSubscription::query()
                ->where('tenant_id', $tenantId)
                ->whereHas('plan', fn ($q) => $q->where('ai_enabled', true))
                ->with('plan')
                ->orderByDesc('starts_at')
                ->first(),
        );
    }
}
