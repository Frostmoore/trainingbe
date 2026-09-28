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

    /*
     * ⛔ **`SchedaFinita` NON c'è più, e non è una dimenticanza** — 28/09/2026.
     *
     * 📌 Il committente, quando gli ho chiesto cosa volesse dire: *«Che cazzo
     * significa scheda finita?? Le schede non finiscono. Al massimo finisce
     * l'allenamento. Non lo so, vedi tu»*.
     *
     * 🔑 **È una risposta, non un non-so**: nel prodotto quel fatto **non
     * esiste**. E infatti non esiste nemmeno nel modello — `SchedaAllenamento`
     * ha dei giorni e **nessuna durata**, e `SettimanaProgrammata` mappa giorno
     * → scheda ed è **ricorrente**.
     *
     * ⛔ Tenere in catalogo un caso che non si potrà mai implementare è un
     * elenco che mente: `daFare()` avrebbe detto «da fare» per sempre, e prima
     * o poi qualcuno ci avrebbe provato.
     *
     * ⚠️ **«Allenamento finito» non è il sostituto.** Quando chiudi una seduta
     * hai il telefono in mano e la schermata davanti: una notifica lì è rumore.
     *
     * 💡 Il numero 8840 in `IPromemoria` resta libero, se un giorno una scheda
     * dovesse avere una fine.
     */

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
            /*
             * 🆕 ✅ **Le otto push sono state fatte il 27/09/2026** — voce 62.
             *
             * 📌 Il committente: *«Sì no vabbè implementale tutte subito»*.
             *
             * | Notifica | Dove parte |
             * |---|---|
             * | `IncassoFallito` | `Cassa::avvisaDelPagamentoFallito()`, accanto alla mail |
             * | `ServizioRiattivato` | `LaSospensioneAutomatica::diGlieloCheERiattivato()` |
             * | `AbbonamentoInScadenza` | `LAvvisoDellaScadenza`, giro di `abbonamenti:avvisa-delle-scadenze` |
             * | `SlotDeciso` | `LoSlotDelTrainer::diGlieloAllAllievo()` |
             * | `StimaPronta` | `StimaIlCibo::handle()`, dopo `completa()` |
             * | `ImportPronto` | `TrascriviIlDocumento::handle()`, dopo lo scalo dei gettoni |
             * | `RispostaARichiesta` | `ViewRichiestaDiContatto::avvisaSulTelefono()` |
             * | `Invito` | `InvitiDelTrainer::mandaIlLink()` e `InvitiInPalestra::mandaIlLink()` |
             *
             * ⚠️ `Invito` è fatta **a metà per scelta**: manda il richiamo ma non
             * porta alla schermata dell'invito, perché servirebbe il token (una
             * credenziale, che in una push non ci va) o una rotta «i miei inviti»
             * (impossibile finché le email non sono verificate). 🔑 Il perché per
             * esteso è nel commento dentro `mandaIlLink()`.
             *
             * 💡 Restano le quattro locali: le programma l'app, e la `FineRecupero`
             * è già fatta.
             */
            self::PromemoriaAllenamento,
            self::PromemoriaDiario,
            self::PromemoriaMisure,
        ], true);
    }

    /**
     * 🧭 **Dove porta, toccandola.**
     *
     * ══ 📌 PERCHE' STA NEL CATALOGO E NON LA PASSA CHI CHIAMA ══════════════
     *
     * 📌 Il committente, 27/09/2026: *«ci deve essere un altro parametro nelle
     * notifiche: dove rimanda. Cioè a che pagina dell'app o a che url»*.
     *
     * ⛔ Se la passasse chi manda, tornerebbe a essere sparsa: due notifiche
     * dello stesso tipo finirebbero su pagine diverse a seconda del punto del
     * codice da cui partono. 💡 Qui è una proprietà della notifica, come il
     * canale — e `LeNotifiche::manda()` la mette nel carico da sola.
     *
     * ══ 🚨 E' UN NOME, NON UN INDIRIZZO ═══════════════════════════════════
     *
     * ⛔ **Non** si restituisce un percorso dell'app. 🚨 Mandare un percorso
     * vorrebbe dire che chi controlla il server può far aprire all'app
     * **qualunque** schermata cambiando una stringa in una notifica.
     *
     * 🔑 Si manda un **nome** — `abbonamento`, `messaggi`, `casa` — e l'app lo
     * traduce con un elenco chiuso (`_leDestinazioni` in `notifiche_push.dart`).
     * ⚠️ Un nome che l'app non conosce non porta da nessuna parte: si apre e
     * basta. È la stessa regola che c'era già, scritta una volta sola.
     *
     * ══ ⚠️ E NON CI VA MAI UNA CREDENZIALE ════════════════════════════════
     *
     * 🚨 Alcune schermate vogliono un identificativo per essere utili — l'invito
     * vuole il suo token, l'importazione vuole il suo id. ⛔ Il token di un
     * invito **non** si mette in una push: è una credenziale monouso a scadenza,
     * e il carico di una push passa da Google e resta sul telefono. 💡 Quelle
     * notifiche portano alla casa, e il dato l'app se lo va a prendere da sola
     * con la sessione che ha già.
     *
     * 💡 `null` per le locali: quelle le programma l'app, che sa già dove sta.
     */
    public function destinazione(): ?string
    {
        return match ($this) {
            self::Messaggio => 'messaggi',

            /*
             * 📣 L'avviso non ha una pagina: è una **modale**, e la mostra
             * `AvvisoAllApertura` appena l'app torna in primo piano — cioè
             * esattamente quello che succede toccando la notifica.
             */
            self::Avviso => 'casa',

            self::Promozione => 'offerte',

            /*
             * 💳 Le tre del denaro portano tutte dove si paga. ⚠️ Lato app è
             * `/acquisti`, **non** il pannello: chi riceve queste notifiche è
             * sul telefono.
             */
            self::IncassoFallito,
            self::AbbonamentoInScadenza,
            self::ServizioRiattivato => 'abbonamento',

            /*
             * ✉️ **Alla casa, e non alla pagina dell'invito** — e non è pigrizia.
             *
             * ⛔ `/invito-palestra/{token}` esiste, ma vuole il token, e il token
             * è una credenziale: vedi sopra. 🚨 E oggi non esiste nessuna rotta
             * che elenchi **gli inviti ricevuti** (ci sono solo quelli mandati:
             * `GET trainer/invites` e `GET gym/invites`), quindi la casa non ha
             * ancora niente da mostrare a chi arriva da qui.
             */
            self::Invito => 'casa',

            /*
             * 🎟️ **Chi la riceve è l'ALLIEVO**, non il trainer: è lui che scopre
             * se il posto gli è stato tenuto o liberato. ⛔ Quindi non «i miei
             * utenti», che è la schermata di chi ha deciso.
             *
             * 💡 Porta alla chat perché è lì che la decisione si vede: liberando
             * il posto la conversazione si limita a tre messaggi (Q-A5-2).
             */
            self::SlotDeciso => 'messaggi',

            /*
             * 💬 Chi ha scritto dal sito **può non avere un account**: la
             * richiesta di contatto ha solo un'email. ⛔ Non c'è nessuna pagina
             * dell'app che mostri una richiesta, quindi casa.
             */
            self::RispostaARichiesta => 'casa',

            /*
             * 📄 ⚠️ **Debito noto**: la bozza si rivede riga per riga (N20.3), ma
             * `/schede/importa` e `/diario/importa-piano` **non prendono l'id**
             * dell'importazione — cominciano un caricamento nuovo. ⛔ Mandare
             * lì chi ha appena finito di importare lo farebbe ricominciare.
             * 💡 Finché non c'è una rotta che riapre **quella** bozza, casa.
             */
            self::ImportPronto => 'casa',

            self::StimaPronta => 'diario',

            /*
             * 📱 Le locali non hanno destinazione qui: le programma l'app, e
             * l'app sa già da dove le ha fatte partire.
             */
            self::FineRecupero,
            self::PromemoriaAllenamento,
            self::PromemoriaDiario,
            self::PromemoriaMisure => null,
        };
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
