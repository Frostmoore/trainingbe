<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\Notifica;
use App\Enums\UserRole;
use App\Mail\InvitoInPalestraMail;
use App\Models\InvitoInPalestra;
use App\Models\Plan;
use App\Models\PlanSubscription;
use App\Models\ProfiloPubblico;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\ContoDegliIscritti;
use App\Services\Notifiche\LeNotifiche;
use App\Services\Tenancy\Exceptions\SeiGiaInUnaPalestraException;
use App\Support\Tenancy\CosaOttieniInPalestra;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Throwable;

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
        return $this->iPosti($palestra, UserRole::Member)['rimasti'];
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
        return $this->iPosti($palestra, UserRole::Trainer)['rimasti'];
    }

    /**
     * 🆕 **I posti, per intero: occupati, totali e liberi** — 23/09/2026, F5.1.
     *
     * 📌 Il committente vuole nel cruscotto della palestra gli **slot allievo e
     * trainer, occupati su totali**.
     *
     * ⛔ `postiRimasti()` da solo non bastava: dice **quanti ne restano**, e
     * «restano 3» non si capisce se è tanto o poco finché non si sa se il piano
     * ne ha 5 o 500. 💡 Le due risposte vengono dallo stesso conto, e questo è
     * il motivo per cui stanno in un metodo solo — due conti separati sarebbero
     * due occasioni di dire numeri che non tornano fra loro.
     *
     * ⚠️ **Occupati = iscritti + inviti ancora validi**, come dappertutto qui
     * dentro: un invito in mano a qualcuno è un posto già promesso.
     *
     * @return array{occupati: int, totali: ?int, rimasti: ?int} `totali`/`rimasti`
     *                                                           a `null` quando il piano non mette limiti
     */
    public function iPosti(Tenant $palestra, UserRole $ruolo = UserRole::Member): array
    {
        $piano = $this->pianoDi($palestra);

        $totali = $ruolo === UserRole::Trainer
            ? $piano?->max_trainers
            : $piano?->max_members;

        /*
         * 🆕 🎀 P4 di `plan_promozioni.md` — i posti regalati da una promozione,
         * finché valgono. ⚠️ Solo se il piano ha un limite: «senza limite» più
         * cinque resta senza limite.
         */
        if ($totali !== null) {
            $totali += $palestra->postiRegalati($ruolo === UserRole::Trainer ? 'trainer' : 'iscritti');
        }

        $occupati = $this->quanteNePorta($palestra, $ruolo)
            + $this->invitiValidiPer($palestra, $ruolo);

        return [
            'occupati' => $occupati,
            'totali' => $totali,

            /*
             * 🚨 **Mai sotto zero.** I posti si possono superare per davvero —
             * un piano che si rimpicciolisce, un invito riscattato al limite —
             * e un «-2 posti liberi» in un cruscotto sembra un difetto di
             * conto invece che una palestra piena.
             */
            'rimasti' => $totali === null ? null : max(0, $totali - $occupati),
        ];
    }

    /**
     * 📧 **Manda il link d'invito per email** — 23/09/2026, fase F5.5.
     *
     * 📌 Il committente: l'invito deve **generare un link nuovo ogni volta** e
     * poter essere **spedito**.
     *
     * ══ ⚠️ PERCHÉ FINO A IERI IL LINK SI COPIAVA A MANO ══════════════════
     *
     * Era scritto, e la ragione era vera: *«mandare le email d'invito vorrebbe
     * dire un mittente, un template, la reputazione del dominio e la gestione
     * dei rimbalzi»*. 💡 Adesso quel pezzo c'è — è lo stesso che manda il link
     * della password (F4.0) — e resta solo da usarlo.
     *
     * 🚨 **Non fa fallire l'invito.** Oggi `MAIL_MAILER=log`: la mail «parte»
     * finendo in un file. ⛔ Se il trasporto è rotto, il link è comunque già
     * valido e chi l'ha creato lo vede a schermo: si copia, come prima.
     *
     * @return bool se la mail è partita davvero (`false` = copialo a mano)
     */
    public function mandaIlLink(InvitoInPalestra $invito): bool
    {
        if ($invito->email === null) {
            return false;
        }

        try {
            Mail::to($invito->email)->send(new InvitoInPalestraMail($invito));

            /*
             * 🆕 📧 **L'invio si segna in tabella** — B2, 24/09/2026, gemello
             * esatto di quello che B1 ha fatto per gli inviti dei trainer.
             *
             * ⛔ Prima il risultato dell'invio viveva solo nel valore di
             * ritorno, cioè per la durata di una richiesta. 🚨 La schermata
             * «Inviti in sospeso» dell'app arriva dopo, magari il giorno dopo:
             * senza una data in tabella dovrebbe scrivere «mandata» sempre —
             * mentendo quando la mail non è partita — oppure non scriverlo mai,
             * e chi invita non saprebbe se deve rimandarla.
             *
             * ⚠️ `forceFill`: non è un campo che si scrive da un modulo.
             */
            $invito->forceFill(['email_inviata_il' => now()])->save();

            /*
             * 🆕 📲 **E un richiamo sul telefono** — voce 62, 27/09/2026.
             *
             * ══ 📌 A COSA SERVE, VISTO CHE LA MAIL E' GIA' PARTITA ═════════
             *
             * ⛔ Un invito che finisce nello spam è un cliente che non arriva, e
             * il DMARC del dominio non c'è ancora (voce 66). 💡 Chi **ha già
             * l'app** — perché è iscritto altrove, o lo era — riceve un richiamo
             * che arriva subito e non si perde.
             *
             * ══ 🚨 PERCHE' NON PORTA ALL'INVITO, E NON E' PIGRIZIA ═════════
             *
             * La schermata dell'invito è `/invito-palestra/{token}`, e vorrebbe
             * **il token**. ⛔ Due strade per dargliela, e sono chiuse entrambe:
             *
             *   1. 🚨 **il token nel carico della push**: è una credenziale
             *      monouso a scadenza, e il carico passa da Google e resta sul
             *      telefono. ⛔ Non ci va.
             *   2. 🚨 **una rotta «i miei inviti»** che l'app interroga da sola:
             *      sarebbe la strada giusta, ⛔ ma le email **non sono
             *      verificate** in questa applicazione (`User` non implementa
             *      `MustVerifyEmail`, e non ci sono rotte di verifica). Chi si
             *      registra con l'indirizzo di un altro si prenderebbe i suoi
             *      inviti — e oggi per rubarne uno serve l'accesso alla casella.
             *      ⚠️ Sarebbe una **scala nuova**, non un fastidio.
             *
             * 💡 Quindi la notifica dice «guarda la posta» e porta alla casa. ⚖️ È
             * meno di quello che si vorrebbe, ed è tutto quello che si può dare
             * senza aprire un buco. 🔑 Si sblocca con la verifica delle email:
             * quel giorno si aggiunge la rotta e la destinazione diventa l'invito.
             *
             * ══ ⚠️ E IL TESTO NON NOMINA CHI INVITA ════════════════════════
             *
             * 🚨 Passa da `aQuestaEmail()`, e a un'email non verificata può
             * rispondere chi non è il destinatario. ⛔ «La palestra X ti ha
             * invitato» direbbe a un estraneo dove sta andando quella persona.
             * 💡 Si dice che c'è un invito, e basta.
             */
            try {
                app(LeNotifiche::class)->aQuestaEmail(
                    Notifica::Invito,
                    (string) $invito->email,
                    'Hai un invito',
                    'Ti hanno invitato su Training Companion: trovi il link nella posta.',
                );
            } catch (Throwable $e) {
                Log::warning('Invito in palestra: push non partita.', [
                    'invito' => $invito->getKey(),
                    'errore' => $e->getMessage(),
                ]);
            }

            return true;
        } catch (Throwable $e) {
            Log::error('Invito in palestra: mail non partita.', [
                'invito' => $invito->getKey(),
                'errore' => $e->getMessage(),
            ]);

            return false;
        }
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

            /*
             * 🆕 🖼️ 📍 **La testata e l'indirizzo** — 23/09/2026, F6.2.
             *
             * 📌 *«mettiamoci anche un'immagine di testata (si dovrà vedere
             * nell'app)»* · *«serve anche l'indirizzo preciso (con cap
             * provincia e tutto)»*.
             *
             * 💡 **Qui e non (solo) nel catalogo**, perché è qui che oggi una
             * persona incontra la scheda di una palestra: il catalogo, ha detto
             * il committente, *«proprio non esiste ancora»*.
             *
             * ⚠️ Campi **in più**, non al posto di niente: un'app vecchia che
             * non li conosce continua a mostrare l'invito come prima.
             */
            ...$this->schedaDi($palestra),
            'cosa_ottieni' => $this->cosaOttieni->per($palestra, $invito->ruolo ?? UserRole::Member),

            /*
             * 🆕 **Per chi e' l'invito** — giro del 18/09/2026.
             *
             * ⛔ Senza, l'app mostrava a un trainer la conferma dell'iscritto:
             * *«la palestra copre il tuo abbonamento, e ti vesti dei suoi
             * colori»* — tutte e due false per chi allena (il trainer tiene il
             * suo piano e non si veste della palestra).
             */
            'ruolo' => ($invito->ruolo ?? UserRole::Member)->value,
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
    public function accetta(string $token, User $utente, bool $lasciaLaPrecedente = false): Tenant
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
        /*
         * ══ 🆕 🏋️ UN TRAINER NON TRASLOCA: PRENDE UN RUOLO — 8.1.1, 18/09 ════
         *
         * 📌 *«assicurati che un trainer possa lavorare per infinite palestre»*
         * e, decisione del committente, *«ogni trainer ha sempre uno spazio
         * suo»*.
         *
         * 🚨 **Prima anche il trainer passava dal trasloco**, che rifiuta
         * chiunque abbia qualcun altro nel proprio spazio: un trainer con un
         * solo allievo suo non poteva entrare in **nessuna** palestra, e uno
         * già dentro una non poteva entrare nella seconda.
         *
         * 💡 Adesso resta a casa sua e guadagna un ruolo. ⚠️ Gli **iscritti**
         * invece traslocano ancora, ed è giusto: un iscritto sta in **una**
         * palestra, e quella palestra è la sua casa.
         */
        $entrata = $ruolo === UserRole::Trainer
            ? $this->assumiSenzaTraslocare($utente, $palestra)
            : $this->entraComeIscritto($utente, $palestra, $ruolo, $lasciaLaPrecedente);

        $invito->forceFill([
            'used_at' => now(),
            'accepted_by' => $utente->getKey(),
        ])->save();

        return $entrata;
    }

    /**
     * 🆕 🏛️ **Un iscritto entra — e se è già in un'altra palestra, si chiede**
     * — regola 8.1.4, 18/09/2026.
     *
     * 📌 *«Assicurati che un allievo possa essere iscritto a una sola palestra.
     * Nel caso di invito a una seconda palestra, la pagina dell'invito gli deve
     * chiedere se desidera lasciare la palestra precedente.»*
     *
     * ══ ⚠️ COSA CONTA COME «UN'ALTRA PALESTRA» ═══════════════════════════
     *
     * Solo uno spazio **non personale**. ⛔ Lo spazio di un trainer **non** è una
     * palestra: il committente, 18/09, *«un allievo deve poter essere iscritto a
     * infiniti free_trainer e una sola palestra, anche contemporaneamente»*.
     * Chi viene da lì entra senza domande — ci pensa il ramo di
     * `UnisciAUnaPalestra` che estrae le sue righe.
     *
     * ══ 🚨 LASCIARE + ENTRARE, IN UNA TRANSAZIONE SOLA ═══════════════════
     *
     * ⛔ Due transazioni separate vorrebbero dire che un errore a metà lascia la
     * persona **fuori da tutte e due**: uscita dalla vecchia, non entrata nella
     * nuova, con un invito ancora valido che non ha più dove portarla.
     *
     * 💡 E l'uscita passa da `EsciDaUnaPalestra`, che è quella vera: riprende
     * l'abbonamento personale se era in pausa (8.1.6) e chiude le sovrapposizioni
     * con i trainer di quella palestra (AH.1-ter.6). ⚠️ Poi l'ingresso le rimette
     * in pausa e le riapre per la palestra nuova — che è giusto: è un altro
     * posto, con altri trainer.
     *
     * @throws SeiGiaInUnaPalestraException se è in un'altra palestra e non ha detto di sì
     */
    private function entraComeIscritto(
        User $utente,
        Tenant $palestra,
        UserRole $ruolo,
        bool $lasciaLaPrecedente,
    ): Tenant {
        $attuale = $utente->tenant;

        $eInUnAltraPalestra = $attuale !== null
            && ! $attuale->is($palestra)
            && ! $attuale->ePersonale();

        if (! $eInUnAltraPalestra) {
            return $this->unisci->dentroQuestoTenant($utente, $palestra, $ruolo, 'token');
        }

        if (! $lasciaLaPrecedente) {
            throw new SeiGiaInUnaPalestraException((string) $attuale->name);
        }

        return DB::transaction(function () use ($utente, $palestra, $ruolo): Tenant {
            app(EsciDaUnaPalestra::class)($utente);

            return $this->unisci->dentroQuestoTenant($utente->fresh(), $palestra, $ruolo, 'token');
        });
    }

    /**
     * 🆕 Il trainer comincia a lavorare qui **restando a casa sua** — 8.1.1.
     *
     * ⚠️ Torna la **palestra**, come il ramo del trasloco: chi chiama riceve
     * sempre «il tenant in cui sei entrato», e non deve sapere quale delle due
     * strade è stata presa.
     */
    private function assumiSenzaTraslocare(User $utente, Tenant $palestra): Tenant
    {
        app(AllenareInUnaPalestra::class)->assumi($utente, $palestra);

        return $palestra;
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
    /**
     * 🖼️ 📍 Testata, indirizzo e collegamento alle mappe della palestra — F6.2.
     *
     * ⚠️ Letti **anche se il profilo non è `visibile`**, per la stessa ragione
     * della descrizione qui sotto: è la palestra che ha scelto di presentarsi a
     * questa persona, generando il link.
     *
     * @return array{immagine_testata: ?string, indirizzo: ?string, mappa: ?string}
     */
    private function schedaDi(Tenant $palestra): array
    {
        $scheda = $this->context->runWithoutTenant(
            fn (): ?ProfiloPubblico => ProfiloPubblico::query()
                ->with('comune')
                ->where('tenant_id', $palestra->id)
                ->whereNull('user_id')
                ->first(),
        );

        return [
            'immagine_testata' => $scheda?->testataUrl(),
            'indirizzo' => $scheda?->indirizzoPerEsteso(),
            'mappa' => $scheda?->mappaUrl(),
        ];
    }

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
