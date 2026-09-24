<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Billing\PianoAttivo;
use App\Services\Tenancy\ChiSiVede;
use App\Services\Tenancy\LoSlotDelTrainer;
use App\Support\Impersonation\Impersonator;
use Illuminate\Support\Facades\DB;

/**
 * Chi puo' scrivere a chi — 18/08/2026. **M3.1.**
 *
 * ── 🚨 Il cancello sta QUI e in nessun altro posto ─────────────────────────
 *
 * La tabella §4.6 del piano e' **una regola sola**. ⚠️ Ripetuta nel controller,
 * nella policy e nell'app divergerebbe: basta che una delle tre copie non venga
 * aggiornata perche' il prodotto dica due cose diverse a seconda di dove si
 * guarda — e la copia che sbaglia e' sempre quella che nessuno rilegge.
 *
 * 💡 Da qui discende anche cosa mostra l'app: `Permesso` porta il **motivo**,
 * non solo il si'/no, cosi' l'app spiega invece di mostrare un errore (M3.4).
 *
 * ── La tabella, per intero ─────────────────────────────────────────────────
 *
 * | Io sono                     | Lui e'                        | Posso? |
 * |-----------------------------|-------------------------------|--------|
 * | iscritto a una palestra     | trainer di **quella** palestra| ✅ sempre |
 * | seguito da un trainer       | **quel** trainer              | ✅ sempre |
 * | **abbonato**                | free trainer che non mi segue | ✅ illimitato |
 * | **abbonato**                | proprietario di una palestra  | ✅ illimitato |
 * | **non** abbonato            | free trainer che non mi segue | ⚠️ 3 per parte |
 * | **non** abbonato            | proprietario di una palestra  | ⚠️ 3 per parte |
 * | chiunque                    | trainer **dipendente** altrui | ❌ mai |
 *
 * ── 🚨 Leggere si puo' SEMPRE ──────────────────────────────────────────────
 *
 * Questo cancello decide **solo la scrittura**. ⚠️ Se l'abbonamento scade e le
 * conversazioni diventassero illeggibili, una persona perderebbe l'accesso a
 * quello che il suo trainer le ha scritto — che oltretutto e' **gia' sul suo
 * telefono**, cifrato. Sarebbe punitivo e inutile: nasconderebbe qualcosa che
 * quella persona possiede gia'.
 */
class CancelloDellaChat
{
    public function __construct(
        private readonly LimiteDeiTreMessaggi $limite,
        private readonly PianoAttivo $piani,
        private readonly Impersonator $impersonatore,
        private readonly ChiHoBloccato $blocchi,
    ) {}

    /**
     * Puo' scrivere in questo filo?
     *
     * 💡 Torna sempre un `Permesso`, anche quando la risposta e' si': cosi' chi
     * chiama ottiene **anche** quanti messaggi restano, che serve a dirlo prima
     * che la persona prema invio.
     */
    public function puoScrivere(User $chi, Conversation $c): Permesso
    {
        /*
         * 🚨 Prima di ogni altra cosa: nessuno scrive nei panni di un altro.
         *
         * ⚠️ Il controllo sta **in cima** e non in fondo, perche' tutti i rami
         * qui sotto direbbero di si' guardando la persona impersonata — che e'
         * indistinguibile da quella vera.
         */
        if ($this->impersonatore->isImpersonating()) {
            return Permesso::no(
                Permesso::IMPERSONAZIONE,
                'Durante un accesso come un altro utente non si possono scrivere messaggi.',
            );
        }

        if (! $c->includes($chi)) {
            return Permesso::no(
                Permesso::NESSUN_LEGAME,
                'Non fai parte di questa conversazione.',
            );
        }

        /*
         * ══ 🆕 🚫 IL BLOCCO — decisione 53.3, 24/09/2026 ═════════════
         *
         * 📌 Il committente: *«max 3 messaggi e si possono bloccare le
         * persone»*.
         *
         * 🚨 **Sta prima del limite dei tre**, come il canale chiuso: dire
         * «ti restano due messaggi» a chi è bloccato sarebbe una bugia, e per
         * di piu' una bugia che lo invita a riprovare.
         *
         * ⚠️ **Vale nei due versi** (`fraLoroCeUnBlocco`): non importa chi ha
         * bloccato chi, quei due non devono incontrarsi. ⛔ E il messaggio è lo
         * stesso per tutti e due, di proposito: chi è stato bloccato non deve
         * poterlo dedurre dalla risposta del server.
         */
        $altro = $c->otherParty($chi);

        if ($altro !== null && $this->blocchi->fraLoroCeUnBlocco($chi, $altro)) {
            return Permesso::no(
                Permesso::BLOCCATO,
                'Non puoi scrivere in questa conversazione.',
            );
        }

        /*
         * Il canale chiuso dal trainer — F6.4.
         *
         * 🚨 Viene **prima** del limite dei tre: una conversazione sospesa e'
         * chiusa e basta, e dire a qualcuno «ti restano due messaggi» in un filo
         * che non accetta piu' niente sarebbe una bugia.
         */
        if ($this->canaleChiuso($c)) {
            return Permesso::no(
                Permesso::CANALE_CHIUSO,
                'Questa conversazione è stata chiusa.',
            );
        }

        /*
         * ══ 🆕 🔒 NON PIU' LEGATI: SI RILEGGE, NON SI SCRIVE — D9, 19/09 ═════
         *
         * 💡 Da D9 un filo resta visibile a chi ne fa parte anche dopo essere
         * uscito dalla palestra (`Conversation::scopeForUser`). ⛔ Senza questa
         * riga, un filo di tipo «iscritto» — che non ha limiti — avrebbe
         * continuato ad accettare messaggi fra due persone che non hanno piu'
         * niente in comune.
         *
         * 📌 *«al massimo non gli posso più scrivere ma i messaggi vecchi ce li
         * devo avere»*.
         */
        if (! $c->eDiInformazioni() && ! $this->ancoraLegati($c)) {
            /*
             * ══ 🆕 IL POSTO LIBERATO: SI TORNA AI TRE MESSAGGI — 22/09/2026 ══
             *
             * 📌 Il committente (Q-A5-2): *«la chat si deve bloccare (max 3
             * messaggi, come sempre) e se vuole reinvitarlo gli deve mandare un
             * link di invito»*. Il filo torna **di presentazione**, con il
             * contatore da zero: tre messaggi ciascuno, quanto basta a mandare
             * l'invito. Accettandolo, `InvitiDelTrainer::riscattaPerChiEsiste`
             * lo rende di nuovo illimitato.
             *
             * 💡 Succede **qui**, alla prima scrittura dopo che i due hanno
             * smesso di essere legati, e non quando il trainer libera il posto:
             * finché l'allievo è iscritto nella palestra dove lui allena, lo
             * segue da trainer della palestra e la chat resta libera.
             */
            if ($this->slot()->haLiberatoIlPosto((int) $c->trainer_id, (int) $c->member_id)) {
                $this->limite->tornaAllaPresentazione($c);
            } else {
                return Permesso::no(
                    Permesso::CANALE_CHIUSO,
                    'Non vi seguite più: puoi rileggere la conversazione, ma non scrivere.',
                );
            }
        }

        /*
         * 💡 Un filo di tipo `iscritto` non ha limiti, e non ne avra' mai: e' il
         * rapporto per cui il prodotto esiste. Ci si arriva perche' i due sono
         * gia' collegati — il legame l'ha verificato chi ha aperto la
         * conversazione.
         */
        if (! $c->eDiInformazioni()) {
            return Permesso::si();
        }

        /*
         * 🚨 **L'abbonamento toglie il limite, e questo e' cio' che vende.**
         *
         * Il catalogo lo vedono tutti (M2); quello che si compra e' **scrivere
         * senza limite a chi non ti segue**.
         */
        if ($this->eAbbonato($chi)) {
            return Permesso::si();
        }

        $restanti = $this->limite->restanti($chi, $c) ?? 0;

        if ($restanti <= 0) {
            /*
             * 🚨 Qui — e **solo** qui — si propone l'abbonamento (M4.3-bis).
             *
             * ⚠️ Non i gettoni: un gettone vuol dire **una chiamata all'AI**,
             * dietro cui c'e' un costo che paghiamo. Un messaggio non ci costa
             * niente. Facendo valere alla stessa unita' anche «permesso di
             * parlare», il giorno che se ne cambia il prezzo si muoverebbero due
             * leve credendo di muoverne una.
             */
            return Permesso::no(
                Permesso::TRE_ESAURITI,
                'Hai usato i tre messaggi di presentazione. Con l\'abbonamento scrivi senza limiti.',
                restanti: 0,
            );
        }

        return Permesso::si(restanti: $restanti);
    }

    /**
     * Puo' **aprire** un filo di informazioni con questa persona? — M3.2.
     *
     * 🚨 Diverso da `puoScrivere()`: qui il filo non esiste ancora, e la domanda
     * e' se quella persona sia contattabile **dal catalogo**.
     *
     * ⚠️ Un trainer **dipendente** non lo e', mai — §4.7 del piano. Non e' una
     * restrizione arbitraria: un dipendente non decide lui chi seguire, e
     * riceverebbe richieste che non puo' accettare. Chi vuole quella palestra
     * scrive alla palestra.
     *
     * 💡 Il controllo qui e' **strutturale e non di ruolo**: si arriva a questo
     * metodo solo passando da una scheda del catalogo, e una scheda ha
     * `tenant_id` (la palestra) **oppure** `user_id` (il trainer indipendente).
     * Un dipendente non e' ne' l'uno ne' l'altro, quindi non ha una scheda da
     * cui partire — e questo metodo non viene nemmeno chiamato per lui.
     */
    public function puoAprireInformazioni(User $chi, User $destinatario): Permesso
    {
        if ($this->impersonatore->isImpersonating()) {
            return Permesso::no(
                Permesso::IMPERSONAZIONE,
                'Durante un accesso come un altro utente non si possono aprire conversazioni.',
            );
        }

        if ($chi->is($destinatario)) {
            return Permesso::no(
                Permesso::NESSUN_LEGAME,
                'Non puoi scrivere a te stesso.',
            );
        }

        if (! $destinatario->is_active) {
            return Permesso::no(
                Permesso::NESSUN_LEGAME,
                'Questa persona non e\' raggiungibile.',
            );
        }

        return Permesso::si(
            restanti: $this->eAbbonato($chi) ? null : LimiteDeiTreMessaggi::QUANTI,
        );
    }

    /**
     * 🚨 «Abbonato» vuol dire **piano a pagamento**, non «ha un piano».
     *
     * ⚠️ `PianoAttivo::per()` non torna mai `null`: chi non ha nessun
     * abbonamento ricade sul piano gratuito. Controllare `!== null` direbbe
     * quindi che sono abbonati **tutti**, e il limite dei tre non scatterebbe
     * mai per nessuno — senza nessun errore, e con l'abbonamento che smette di
     * vendere quello che vende.
     */
    private function eAbbonato(User $chi): bool
    {
        return ! $this->piani->per($chi)->eGratuito();
    }

    /**
     * Il rapporto fra i due capi e' stato sospeso? — F6.4.
     *
     * 💡 `withoutGlobalScopes()` sul pivot: il legame appartiene al tenant del
     * trainer, quindi letto dal contesto dell'utente non si troverebbe. Non e'
     * un bypass — la coppia di id e' gia' la chiave piu' stretta possibile.
     */
    /**
     * I due capi si vedono ancora? — D9.
     *
     * 💡 La stessa regola dell'elenco dei trainer (`ChiSiVede::trainerDi`): i
     * suoi trainer personali, piu' i trainer della palestra in cui sta **ora**.
     * Chi e' uscito dalla palestra non ci rientra per via di un filo vecchio.
     */
    private function ancoraLegati(Conversation $c): bool
    {
        $membro = User::query()->withoutGlobalScopes()->find($c->member_id);

        if ($membro === null) {
            return false;
        }

        return app(ChiSiVede::class)->trainerDi($membro)
            ->contains(fn (User $t): bool => (int) $t->getKey() === (int) $c->trainer_id);
    }

    private function canaleChiuso(Conversation $c): bool
    {
        return DB::table('trainer_member')
            ->where('trainer_id', $c->trainer_id)
            ->where('member_id', $c->member_id)
            ->whereNotNull('disattivato_il')
            /*
             * 🆕 ⚠️ **Non il posto liberato** (22/09/2026): quello non è «il
             * trainer ha chiuso la conversazione». Finché sono legati da una
             * palestra si scrivono; poi tornano ai tre messaggi (sopra).
             */
            ->where(fn ($q) => LoSlotDelTrainer::nonLiberati($q))
            ->exists();
    }

    private function slot(): LoSlotDelTrainer
    {
        return app(LoSlotDelTrainer::class);
    }
}
