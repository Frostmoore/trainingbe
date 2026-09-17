<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\UserRole;
use App\Models\InvitoInPalestra;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\ProfiloPubblico;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\ContoDegliIscritti;
use App\Support\Tenancy\CosaOttieniInPalestra;
use App\Support\Tenancy\TenantContext;
use Illuminate\Validation\ValidationException;

/**
 * Gli inviti di una palestra — 3b-V.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«Il link d'invito deve essere monouso, e a chi ci clicca si deve aprire l'app
 * in una pagina con la descrizione della palestra, il logo, i colori, un
 * messaggio di congratulazioni, le cose a cui avrà accesso e due tasti, uno per
 * accettare e uno per rifiutare»* — 29/08/2026.
 *
 * ══ 🚨 L'INVITO AUTORIZZA, `UnisciAUnaPalestra` ESEGUE ════════════════════
 *
 * ⛔ Qui dentro **non c'e' una riga** che sposti dati da un tenant a un altro, ed
 * e' deliberato: quello lo sa fare `UnisciAUnaPalestra`, con l'elenco delle
 * tabelle che restano indietro, la transazione e il controllo sull'email gia'
 * presa nella palestra nuova.
 *
 * ⚠️ Reimplementarlo qui vorrebbe dire **due modi di entrare in palestra**, e il
 * secondo si accorgerebbe dell'ultima tabella aggiunta solo quando qualcuno si
 * ritrova lo storico dimezzato.
 *
 * 💡 Per questo `accetta()` finisce con una chiamata sola. La differenza fra il
 * codice palestra e l'invito e' **chi ti autorizza**, non cosa succede dopo.
 */
class InvitiInPalestra
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly UnisciAUnaPalestra $unisci,
        private readonly CosaOttieniInPalestra $cosaOttieni,
        private readonly ContoDegliIscritti $conto,
    ) {}

    /**
     * Quanti posti restano in questa palestra. `null` = senza limite.
     *
     * ── 🚨 Occupati = iscritti **+ inviti ancora validi** ──────────────────
     *
     * ⛔ Contare solo gli iscritti lascerebbe creare venti inviti su un piano da
     * dieci. I primi dieci che rispondono riempiono la palestra, e gli altri
     * dieci — che un invito ce l'hanno, in mano, valido — trovano la porta
     * chiusa. ⚠️ Il difetto non si vedrebbe **il giorno in cui si crea**: si
     * vedrebbe settimane dopo, addosso a qualcun altro.
     *
     * 💡 E' la stessa forma di `InvitiDelTrainer::postiRimasti()`, e non e' una
     * coincidenza: e' la stessa domanda.
     */
    public function postiRimasti(Tenant $palestra): ?int
    {
        $massimo = $this->pianoDi($palestra)?->max_members;

        if ($massimo === null) {
            return null;
        }

        /*
         * ══ 🆕 I TRAINER NON CONSUMANO POSTI ALLIEVO — AH.1-quater ══════════
         *
         * ⛔ Qui si contavano **tutti** gli utenti del tenant, staff compreso.
         * 🚨 Da quando una palestra può invitare anche dei trainer, quel conto
         * direbbe che una palestra da cinquanta posti ne ha quarantasette
         * perché ha tre allenatori — cioè le farebbe pagare i propri dipendenti
         * come se fossero clienti.
         *
         * 💡 I due contatori si comprano insieme e si contano separati:
         * `max_members` le persone iscritte, `max_trainers` lo staff.
         */
        $occupati = $this->quanteNePorta($palestra, UserRole::Member)
            + $this->invitiValidiPer($palestra, UserRole::Member);

        return max(0, $massimo - $occupati);
    }

    /**
     * 🆕 **Quanti trainer può ancora prendere questa palestra** — AH.1-quater.
     *
     * 📌 *«una palestra può avere un numero limitato di trainer»*.
     *
     * 🚨 **`plans.max_trainers` esisteva dal 14/08 e non lo leggeva nessuno**:
     * un numero a listino senza nessuna guardia dietro. ⛔ È il caso peggiore,
     * perché sembra una regola e non lo è — `gym_5` prometteva cinque trainer e
     * non ne avrebbe fermati cinquanta.
     *
     * ⚠️ `null` = senza limite, come per `max_members`.
     */
    public function postiTrainerRimasti(Tenant $palestra): ?int
    {
        $massimo = $this->pianoDi($palestra)?->max_trainers;

        if ($massimo === null) {
            return null;
        }

        $occupati = $this->quanteNePorta($palestra, UserRole::Trainer)
            + $this->invitiValidiPer($palestra, UserRole::Trainer);

        return max(0, $massimo - $occupati);
    }

    /** Il piano in corso di questa palestra, `null` se non ne ha. */
    private function pianoDi(Tenant $palestra): ?Plan
    {
        return $this->context->runWithoutTenant(
            fn (): ?Plan => PlanSubscription::query()
                ->where('tenant_id', $palestra->id)
                ->attivi()
                ->with('plan')
                ->orderByDesc('starts_at')
                ->first()?->plan,
        );
    }

    /**
     * Quante persone con questo ruolo stanno dentro la palestra.
     *
     * 🆕 ⛔ **Il conteggio se n'è andato in `ContoDegliIscritti`** — AH.6.9,
     * 17/09/2026. 🚨 Non è un riordino: la query che stava qui **non guardava
     * mai `users`**, quindi contava anche gli iscritti spenti e quelli
     * cancellati — righe di `model_has_roles` che
     * `ChiEsceDallaPalestraOsservatore` non toglie, di proposito.
     *
     * ⚠️ Una palestra da venti che nel tempo ha cancellato quindici persone si
     * ritrovava **cinque posti**, e nel pannello quelle quindici non c'erano
     * più: un limite che scatta senza nessuno da indicare.
     *
     * 💡 Adesso il numero è **uno solo**, e lo stesso che finirà sulla fattura
     * del piano a consumo. ⛔ Due conteggi in due file divergono sempre, e qui
     * divergere vuol dire che il pannello dice «38» e la fattura addebita «41».
     */
    private function quanteNePorta(Tenant $palestra, UserRole $ruolo): int
    {
        return $this->conto->quanti($palestra, $ruolo);
    }

    /**
     * Gli inviti ancora spendibili per quel ruolo.
     *
     * ⚠️ **Contano come posti occupati.** Senza, una palestra con tre posti
     * potrebbe mandare trenta link e ritrovarsi trenta persone: il limite
     * verrebbe verificato quando è troppo tardi per dire di no a qualcuno che
     * ha già cliccato.
     */
    private function invitiValidiPer(Tenant $palestra, UserRole $ruolo): int
    {
        return $this->context->runWithoutTenant(
            fn (): int => InvitoInPalestra::withoutGlobalScopes()
                ->where('tenant_id', $palestra->getKey())
                ->where('ruolo', $ruolo->value)
                ->validi()
                ->count(),
        );
    }

    /**
     * Crea un invito per una persona.
     *
     * @throws ValidationException
     */
    public function invita(
        Tenant $palestra,
        User $chiInvita,
        ?string $email = null,
        UserRole $ruolo = UserRole::Member,
    ): InvitoInPalestra {
        if (! in_array($ruolo, [UserRole::Member, UserRole::Trainer], true)) {
            /*
             * 🆕 **Un elenco chiuso, non una validazione all'ingresso** —
             * AH.1-quater.
             *
             * 🚨 Un invito da `gym_admin` regalerebbe a chi lo riceve il comando
             * sull'abbonamento della palestra; uno da `super_admin` non è
             * nemmeno un ruolo spatie e `hasAppRole()` lancia. ⛔ Qui si rifiuta
             * invece di correggere: chi ha scritto quella riga voleva fare
             * qualcosa che non si fa, e un valore «aggiustato» glielo
             * nasconderebbe.
             */
            throw ValidationException::withMessages([
                'ruolo' => __('Da un invito si entra come iscritto o come trainer.'),
            ]);
        }

        if ($palestra->ePersonale()) {
            /*
             * ⛔ Un tenant personale non e' una palestra: non ha iscritti e non
             * puo' invitare nessuno. Senza questa riga, chiunque abbia un
             * account potrebbe generare inviti per «entrare in se stesso».
             */
            throw ValidationException::withMessages([
                'palestra' => __('Solo una palestra può invitare.'),
            ]);
        }

        /*
         * ⚠️ **I posti PRIMA, non al riscatto** — V.1.5.
         *
         * ⛔ Un invito creato oltre il limite fallisce **davanti alla persona
         * invitata**: ha gia' deciso di entrare, ha gia' installato l'app, e si
         * becca un errore che non la riguarda. 💡 Chi invita, invece, il
         * problema puo' risolverlo — comprando un posto o togliendo un invito.
         */
        $this->pretendiUnPosto($palestra, $ruolo);

        return $this->context->runAs($palestra, fn (): InvitoInPalestra => InvitoInPalestra::create([
            'tenant_id' => $palestra->id,
            'invitato_da' => $chiInvita->getKey(),
            'token' => InvitoInPalestra::generaToken(),
            'email' => $email,
            'ruolo' => $ruolo,
            'expires_at' => now()->addDays(InvitoInPalestra::GIORNI_DI_VITA),
        ]));
    }

    /**
     * 🚨 **Il posto si verifica DUE volte: alla creazione e all'uso.**
     *
     * ⚠️ Fra i due momenti possono passare giorni, e nel frattempo i posti
     * possono essere finiti. ⛔ **Solo il secondo protegge**; il primo è
     * cortesia — serve a non mandare un link che non potrà funzionare.
     *
     * @throws ValidationException
     */
    private function pretendiUnPosto(Tenant $palestra, UserRole $ruolo): void
    {
        $rimasti = $ruolo === UserRole::Trainer
            ? $this->postiTrainerRimasti($palestra)
            : $this->postiRimasti($palestra);

        if ($rimasti !== null && $rimasti <= 0) {
            throw ValidationException::withMessages([
                'posti' => $ruolo === UserRole::Trainer
                    ? __('Non ci sono piu\' posti trainer sul vostro piano.')
                    : __('Non ci sono piu\' posti sul vostro piano. Liberatene uno o passate a un piano piu\' grande.'),
            ]);
        }
    }

    /**
     * Cosa mostrare a chi tocca il link — **senza consumarlo**.
     *
     * 🚨 **L'anteprima non brucia l'invito**, e va detto perche' sembra una
     * sfumatura: la gente apre i link, li chiude, li riapre da un altro
     * telefono, li manda a sua moglie per chiederle se ha senso. ⛔ Un invito
     * che si consuma guardandolo e' un invito che si perde la prima volta che
     * qualcuno lo legge senza decidere.
     *
     * @return ?array{palestra: array<string, mixed>, descrizione: ?string, cosa_ottieni: list<array<string, string>>, scade_il: string}
     */
    public function anteprima(string $token): ?array
    {
        $invito = $this->trova($token);

        if ($invito === null) {
            return null;
        }

        $palestra = $invito->palestra;

        /*
         * ⚠️ Una palestra sospesa non deve poter far entrare nessuno, e non e'
         * pignoleria contabile: chi entrasse si troverebbe dentro un servizio
         * spento, con un messaggio che parla di un abbonamento non suo.
         */
        if ($palestra === null || ! $palestra->isActive() || $palestra->ePersonale()) {
            return null;
        }

        return [
            /*
             * 💡 `branding()` e non i campi a mano: e' lo stesso corpo che l'app
             * riceve gia' da `/branding/lookup`, quindi la schermata dell'invito
             * si veste con il codice che c'e' gia'.
             */
            'palestra' => $palestra->branding(),
            'descrizione' => $this->descrizioneDi($palestra),
            'cosa_ottieni' => $this->cosaOttieni->per($palestra),
            'scade_il' => $invito->expires_at->toIso8601String(),
        ];
    }

    /**
     * Accetta: la persona entra nella palestra, e l'invito si brucia.
     *
     * 🚨 **L'ordine e' entrare-poi-bruciare, non il contrario.** Se
     * `UnisciAUnaPalestra` fallisce — l'email e' gia' presa in quella palestra,
     * la persona sta gia' in un'altra — l'invito deve restare **valido**:
     * bruciarlo prima vorrebbe dire che un tentativo fallito per un motivo
     * risolvibile costa alla persona l'unico invito che aveva.
     *
     * @throws ValidationException
     */
    public function accetta(string $token, User $utente): Tenant
    {
        $invito = $this->trova($token);

        if ($invito === null) {
            throw ValidationException::withMessages([
                'token' => __('Questo invito non è più valido.'),
            ]);
        }

        $palestra = $invito->palestra;

        if ($palestra === null || ! $palestra->isActive() || $palestra->ePersonale()) {
            throw ValidationException::withMessages([
                'token' => __('Questo invito non è più valido.'),
            ]);
        }

        $ruolo = $invito->ruolo ?? UserRole::Member;

        /*
         * 🚨 **Il posto si ricontrolla ADESSO** — AH.1-quater.
         *
         * ⚠️ Fra la creazione dell'invito e questo momento possono passare
         * giorni, e nel frattempo i posti possono essere finiti. ⛔ **È questo
         * il controllo che protegge**: quello alla creazione è cortesia.
         *
         * 💡 Stesso ragionamento già scritto per le schede bloccate — *«un
         * controllo al momento di partire è l'unico che guarda lo stato di quel
         * momento»*.
         */
        $this->pretendiUnPosto($palestra, $ruolo);

        /*
         * ⚠️ **Si passa da `dentroQuestoTenant()`**, che è lo stesso trasloco
         * del `join_code` con il tenant già in mano.
         *
         * 💡 `UnisciAUnaPalestra` è l'unico posto che sa spostare una persona —
         * tabelle che restano indietro, transazione, email già presa. Dargli un
         * secondo ingresso vorrebbe dire due strade, e la seconda è sempre
         * quella meno provata.
         *
         * 🆕 Fino all'11/09 si passava dal `join_code` della palestra; da
         * `AH.1` esiste l'ingresso «per tenant», che serviva agli inviti dei
         * trainer, e questa chiamata diventa più diretta **e** porta il ruolo.
         */
        $entrata = $this->unisci->dentroQuestoTenant($utente, $palestra, $ruolo, 'token');

        $invito->forceFill([
            'used_at' => now(),
            'accepted_by' => $utente->getKey(),
        ])->save();

        return $entrata;
    }

    /**
     * Rifiuta: l'invito si brucia, e nessuno entra.
     *
     * ── 🚨 Perche' il rifiuto BRUCIA ───────────────────────────────────────
     *
     * Quell'invito era per **quella** persona, e quella persona ha detto no.
     * ⛔ Lasciarlo valido vorrebbe dire un invito che nessuno usera' mai e che
     * la palestra crede ancora in piedi — cioe' una persona che risulta
     * «invitata, in attesa» per sempre.
     *
     * 💡 Meglio che la palestra lo veda rifiutato: se e' stato un errore ne
     * manda un altro, che costa un secondo.
     *
     * ⚠️ **Non chiede l'autenticazione**, e non e' una dimenticanza: chi non ha
     * l'account deve poter dire di no **senza crearne uno per farlo**. Il link
     * e' la credenziale, come per l'anteprima.
     */
    public function rifiuta(string $token): void
    {
        $invito = $this->trova($token);

        /*
         * 💡 Silenzio se non c'e': rifiutare un invito gia' scaduto e rifiutarne
         * uno mai esistito devono essere indistinguibili, altrimenti questo
         * endpoint diventa un modo per sapere quali token sono validi.
         */
        $invito?->forceFill(['rifiutato_il' => now()])->save();
    }

    /**
     * Revoca: ci ha ripensato la palestra.
     *
     * ⚠️ Diverso dal rifiuto, e le due colonne restano separate: «non l'ho
     * voluto» e «non te lo do piu'» sono due cose che la palestra ha diritto di
     * distinguere quando guarda l'elenco.
     */
    public function revoca(InvitoInPalestra $invito): void
    {
        $invito->forceFill(['revoked_at' => now()])->save();
    }

    /**
     * La descrizione che la palestra ha scritto su di se'.
     *
     * ── ⚠️ Viene dal CATALOGO, e non da una colonna nuova ─────────────────
     *
     * 🚨 `tenants` non ha nessun campo «descrizione», e il primo tentativo di
     * questo metodo ne leggeva uno inesistente: avrebbe risposto `null` per
     * sempre, **senza nessun errore**. Una pagina d'invito con un buco al posto
     * della presentazione, e nessuno in grado di dire perche'.
     *
     * 💡 Il testo esiste gia' in `profili_pubblici.descrizione` (M2): e' quello
     * che la palestra ha scritto per il catalogo, cioe' letteralmente la sua
     * presentazione.
     *
     * ── 🚨 E si legge anche se il profilo non e' `visibile` ────────────────
     *
     * ⚠️ Sembra il contrario della prudenza di casa, e non lo e': `visibile`
     * vuol dire *«non mettermi nell'elenco pubblico»*, non *«nascondi chi sono
     * a chi invito io»*. 💡 Qui la palestra **sta scegliendo** di presentarsi a
     * quella persona: e' lei che ha generato il link.
     */
    private function descrizioneDi(Tenant $palestra): ?string
    {
        return $this->context->runWithoutTenant(
            fn (): ?string => ProfiloPubblico::query()
                ->where('tenant_id', $palestra->id)
                ->whereNull('user_id')
                ->value('descrizione'),
        );
    }

    /**
     * L'invito valido con questo token, o `null`.
     *
     * 🚨 **`withoutGlobalScopes()`, e va spiegato.** `InvitoInPalestra` e'
     * scopato per tenant, ma qui non c'e' nessun tenant: chi tocca il link e'
     * fuori da tutto, e spesso non ha nemmeno un account. Con lo scope attivo
     * questa query non troverebbe **mai** niente.
     *
     * ⛔ Non e' un bypass: il token e' l'autorizzazione, e un token di 32
     * caratteri identifica una riga sola. Il filtro per tenant qui non
     * proteggerebbe niente — proteggerebbe solo dal trovare l'invito giusto.
     */
    private function trova(string $token): ?InvitoInPalestra
    {
        return InvitoInPalestra::withoutGlobalScopes()
            ->validi()
            ->with('palestra')
            ->where('token', $token)
            ->first();
    }
}
