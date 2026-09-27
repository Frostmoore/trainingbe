<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * 🔔 **IL CATALOGO DELLE NOTIFICHE. Si aggiungono QUI, e solo qui.**
 *
 * ══ 📌 PERCHE' ESISTE ═════════════════════════════════════════════════════
 *
 * 📌 Il committente, 27/09/2026: *«il sistema delle notifiche fallo
 * autoconsistente. Voglio un solo punto dove posso aggiungere notifiche, almeno
 * non impazziamo se ne dobbiamo aggiungere altre»*.
 *
 * ⛔ Prima erano tre chiamate sparse a `PushSender::toUser()`, ognuna col suo
 * titolo scritto sul posto e il suo `tipo` inventato lì. 🚨 Per sapere **quante
 * notifiche esistono** bisognava cercarle; per aggiungerne una bastava copiare
 * una chiamata — e nessuno controllava che il canale fosse quello giusto.
 *
 * ══ 🔑 COME SI AGGIUNGE UNA NOTIFICA ══════════════════════════════════════
 *
 * 1. si aggiunge un `case` qui, con il suo **canale** e il suo **perché**;
 * 2. se è `Push`, la si manda con `LeNotifiche::manda()` — ⛔ non esiste altra
 *    strada, e il tipo del parametro non accetta niente che non sia in questo
 *    elenco;
 * 3. se è `Locale`, la implementa **l'app**, e il caso resta qui come contratto:
 *    ⚠️ `IlCatalogoDelleNotificheTest` pretende che l'app le conosca tutte.
 *
 * 🚨 **E il canale non è una scelta libera**: vedi `CanaleDellaNotifica`. È push
 * solo se l'evento che la genera è **già** sul server.
 *
 * ══ ⏳ QUELLE NON ANCORA FATTE STANNO QUI LO STESSO ═══════════════════════
 *
 * 💡 `daFare()` dice quali sono dichiarate e non ancora implementate. ⚖️ Stanno
 * nel catalogo apposta: così l'elenco delle notifiche del prodotto è **uno**,
 * non «quelle che ci sono» più «quelle che volevamo» scritte in un documento a
 * parte che nessuno riapre.
 */
enum Notifica: string
{
    // ═══════════════════════ 📡 PUSH — l'evento è sul server ═══════════════════════

    /**
     * 💬 Un messaggio in chat.
     *
     * 🚨 **Il server vede arrivare una busta cifrata e non sa cosa c'è dentro.**
     * ⛔ Quindi il testo dice *«hai un messaggio»* e il nome di chi scrive, mai
     * niente del contenuto: non perché sia delicato, ma perché **non lo
     * conosciamo**.
     */
    case Messaggio = 'messaggio';

    /** 📣 Un avviso in-app mandato dal pannello di piattaforma. */
    case Avviso = 'avviso';

    /** 🎁 Una promozione assegnata o offerta. */
    case Promozione = 'promozione';

    /**
     * 💳 **Non siamo riusciti a incassare l'addebito.**
     *
     * 🚨 Oggi è **solo una email**, e le email finiscono nello spam — tanto più
     * che il DMARC del dominio non esiste (voce 66). ⛔ È l'unica notifica di
     * questo elenco che, se manca, **costa clienti**: chi non paga rischia di
     * non saperlo per giorni, e intanto il servizio gli si spegne.
     */
    case IncassoFallito = 'incasso_fallito';

    /** ⏳ L'abbonamento sta per scadere. */
    case AbbonamentoInScadenza = 'abbonamento_in_scadenza';

    /** ✅ Il servizio è tornato attivo: rinnovo riuscito, o palestra riattivata. */
    case ServizioRiattivato = 'servizio_riattivato';

    /** ✉️ Un invito da una palestra o da un trainer. */
    case Invito = 'invito';

    /** 🎟️ Il trainer ha deciso sul posto che gli tenevi occupato. */
    case SlotDeciso = 'slot_deciso';

    /** 💬 Una risposta a una richiesta mandata dal sito o dal pannello. */
    case RispostaARichiesta = 'risposta_a_richiesta';

    /**
     * 📄 Il documento che hai caricato è stato letto.
     *
     * ⚠️ Il lavoro gira **in coda sul server**, quindi l'evento è suo. ⛔ Ma il
     * testo non nomina il contenuto della scheda: dice che è pronta.
     */
    case ImportPronto = 'import_pronto';

    /** 🍽️ La stima del cibo dalla foto è pronta. */
    case StimaPronta = 'stima_pronta';

    // ═══════════════════ 📱 LOCALI — il server non sa, e non deve ═══════════════════

    /**
     * ⏱️ Fine del recupero fra le serie.
     *
     * ✅ **L'unica locale già fatta**, ed è fatta bene: la notifica si
     * **programma** all'inizio del riposo invece di suonare alla fine, così
     * funziona col telefono in tasca e lo schermo spento.
     */
    case FineRecupero = 'fine_recupero';

    /**
     * 🏋️ «Oggi tocca a te», «sono tre giorni che non ti alleni».
     *
     * 🚨 **Il server non sa quando ti alleni**: la scheda e lo storico stanno sul
     * telefono. ⛔ Mandarla in push vorrebbe dire dirglielo.
     */
    case PromemoriaAllenamento = 'promemoria_allenamento';

    /** 🍎 «Non hai segnato la cena». ⛔ Il diario ha lasciato il server il 22/09. */
    case PromemoriaDiario = 'promemoria_diario';

    /** 🏁 Hai finito la scheda, o il ciclo. Il conteggio delle sedute è locale. */
    case SchedaFinita = 'scheda_finita';

    /** ⚖️ Promemoria di peso e misure: i progressi stanno sul telefono. */
    case PromemoriaMisure = 'promemoria_misure';

    // ═════════════════════════════ le proprietà ═════════════════════════════

    /**
     * 🔑 **Il canale, e non è una scelta libera.**
     *
     * ⚠️ `match` **esaustivo e senza `default`**: aggiungere un caso all'enum
     * senza decidere qui produce un `UnhandledMatchError`. ⛔ Non è scortesia —
     * è l'unico modo perché la domanda *«questa notifica può passare dal
     * server?»* non si possa **dimenticare**.
     *
     * 💡 È la stessa forma di `UserRole::canAccessAnyPanel()`, e per la stessa
     * ragione: lì una lista di esclusione aveva ammesso un ruolo nuovo in
     * silenzio.
     */
    public function canale(): CanaleDellaNotifica
    {
        return match ($this) {
            self::Messaggio,
            self::Avviso,
            self::Promozione,
            self::IncassoFallito,
            self::AbbonamentoInScadenza,
            self::ServizioRiattivato,
            self::Invito,
            self::SlotDeciso,
            self::RispostaARichiesta,
            self::ImportPronto,
            self::StimaPronta => CanaleDellaNotifica::Push,

            self::FineRecupero,
            self::PromemoriaAllenamento,
            self::PromemoriaDiario,
            self::SchedaFinita,
            self::PromemoriaMisure => CanaleDellaNotifica::Locale,
        };
    }

    /**
     * ⏳ **Dichiarata ma non ancora implementata.**
     *
     * ⚖️ Sta qui e non in un documento a parte perché l'elenco delle notifiche
     * del prodotto deve essere **uno**. 💡 Chi ne implementa una toglie il suo
     * caso da questa lista, e il catalogo resta l'unica cosa da guardare.
     */
    public function daFare(): bool
    {
        return in_array($this, [
            self::IncassoFallito,
            self::AbbonamentoInScadenza,
            self::ServizioRiattivato,
            self::Invito,
            self::SlotDeciso,
            self::RispostaARichiesta,
            self::ImportPronto,
            self::StimaPronta,
            self::PromemoriaAllenamento,
            self::PromemoriaDiario,
            self::SchedaFinita,
            self::PromemoriaMisure,
        ], true);
    }

    /** Quelle che partono dal server. @return list<self> */
    public static function push(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $n): bool => $n->canale() === CanaleDellaNotifica::Push,
        ));
    }

    /** Quelle che le programma l'app. @return list<self> */
    public static function locali(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $n): bool => $n->canale() === CanaleDellaNotifica::Locale,
        ));
    }
}
