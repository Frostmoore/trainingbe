<?php

declare(strict_types=1);

namespace App\Services\Chat;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Billing\PianoAttivo;
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
         * Il canale chiuso dal trainer — F6.4.
         *
         * 🚨 Viene **prima** del limite dei tre: una conversazione sospesa e'
         * chiusa e basta, e dire a qualcuno «ti restano due messaggi» in un filo
         * che non accetta piu' niente sarebbe una bugia.
         */
        if ($this->canaleChiuso($c)) {
            return Permesso::no(
                Permesso::CANALE_CHIUSO,
                'Questa conversazione e\' stata chiusa.',
            );
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
    private function canaleChiuso(Conversation $c): bool
    {
        return DB::table('trainer_member')
            ->where('trainer_id', $c->trainer_id)
            ->where('member_id', $c->member_id)
            ->whereNotNull('disattivato_il')
            ->exists();
    }
}
