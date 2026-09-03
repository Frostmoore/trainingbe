<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Limiti di frequenza per gli endpoint pubblici.
 *
 * ⚠️ **Perché non un semplice `throttle:6,1` per IP.**
 *
 * Gli iscritti si collegano spessissimo **dal wi-fi della palestra**, quindi
 * decine di persone condividono lo stesso indirizzo IP. Un limite per solo IP
 * farebbe sì che chi sbaglia la password blocchi l'accesso a tutti gli altri
 * presenti in sala — un disservizio provocato da un utente qualsiasi, senza che
 * nessuno capisca perché.
 *
 * Si usano quindi due limiti sovrapposti:
 *  - uno **stretto per (email + IP)**: rallenta chi tenta password contro un
 *    account preciso, senza toccare gli altri utenti sulla stessa rete;
 *  - uno **largo per solo IP**: ferma comunque chi prova molti account diversi
 *    dalla stessa provenienza, che è il caso che il primo limite non copre.
 */
class RateLimitServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Accesso: 5 tentativi al minuto sullo stesso account dalla stessa rete,
        // ma non più di 30 richieste al minuto dalla stessa rete in totale.
        RateLimiter::for('auth-login', function (Request $request): array {
            $email = strtolower((string) $request->input('email'));

            return [
                Limit::perMinute(5)->by($email.'|'.$request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });

        // Iscrizione: più stretta. Una persona si registra una volta sola, e
        // questo è anche il punto da cui si potrebbero provare codici palestra.
        RateLimiter::for('auth-register', fn (Request $request): array => [
            Limit::perMinute(3)->by($request->ip()),
            Limit::perHour(10)->by($request->ip()),
        ]);

        // Ricerca del branding (B1.7): pubblica e senza autenticazione, quindi
        // è il modo più comodo per provare codici palestra a tappeto.
        RateLimiter::for('branding-lookup', fn (Request $request): array => [
            Limit::perMinute(10)->by($request->ip()),
            Limit::perHour(60)->by($request->ip()),
        ]);

        /*
        | Ricerca dei comuni (M1.2): parte **mentre si scrive**, quindi il limite
        | va misurato su quello, non su un'azione deliberata.
        |
        | 💡 Larga di proposito: chi digita «bologna» in un campo con il
        | completamento automatico manda cinque o sei richieste in pochi secondi,
        | e un limite stretto trasformerebbe un campo che funziona in un campo
        | che a meta' parola smette di rispondere.
        |
        | ⚠️ Serve lo stesso: l'endpoint e' pubblico, e senza tetto e' un modo
        | comodo per tenere occupato il server a costo zero. Il tetto orario esiste
        | per quello — 🚨 non per proteggere i dati, che sono pubblici (ISTAT) e
        | scaricabili da chiunque dalla fonte originale.
        */
        RateLimiter::for('comuni', fn (Request $request): array => [
            Limit::perMinute(60)->by($request->ip()),
            Limit::perHour(600)->by($request->ip()),
        ]);

        /*
        | Il catalogo (M2.4): pubblico, e piu' stretto della ricerca dei comuni.
        |
        | ⚠️ La differenza e' cosa restituisce. I comuni sono un elenco che
        | chiunque scarica dall'ISTAT; il catalogo e' **l'elenco dei nostri
        | clienti** — chi sono, dove stanno, come si presentano. 🚨 E' un dato
        | commerciale, e un endpoint pubblico senza freno e' il modo piu' comodo
        | per copiarselo tutto.
        |
        | 💡 Trenta al minuto restano larghi per una persona che cerca (anche
        | scrivendo nel campo), e stretti per chi vuole scaricare tutto: con
        | `limite` a 50 servirebbero ore per una copia che comunque si nota.
        */
        RateLimiter::for('catalogo', fn (Request $request): array => [
            Limit::perMinute(30)->by($request->ip()),
            Limit::perHour(300)->by($request->ip()),
        ]);

        /*
        | AI (B6.6 / B10.2): 20 chiamate all'ora **per utente**, non per IP.
        |
        | 🚨 Per utente e' obbligatorio qui, per il motivo opposto a quello del
        | login: queste richieste sono autenticate, e limitarle per IP
        | significherebbe che venti persone sul wi-fi della palestra esauriscono
        | il limite a testa in un'ora sola. Chi non e' autenticato non arriva a
        | questi endpoint, quindi l'IP resta solo come rete di sicurezza.
        |
        | Questo limite protegge dal singolo utente che martella. Il conto di
        | fine mese lo protegge la quota per iscritto (`MemberAiQuota`), che e' un
        | controllo diverso e serve comunque: cento iscritti educati bruciano il
        | budget senza che nessuno superi il proprio limite orario.
        */
        /*
        | Allegati della chat (N14): 20 al minuto e 200 all'ora **per utente**.
        |
        | 🚨 Per utente e non per IP, per la stessa ragione dell'AI: sono
        | richieste autenticate, e limitarle per IP vorrebbe dire che venti
        | persone sul wi-fi della palestra si mangiano il limite a vicenda.
        |
        | 💡 Venti al minuto sono larghi per chi manda qualche foto di fila e
        | stretti per chi volesse usare la chat come deposito: ogni allegato
        | pesa fino a 2 MB e vive 24 ore, quindi il tetto vero e' quanto spazio
        | puo' occupare una persona in un giorno — qui sotto il mezzo giga.
        */
        RateLimiter::for('allegati-chat', function (Request $request): array {
            $utente = $request->user();

            return $utente !== null
                ? [
                    Limit::perMinute(20)->by('allegati|u'.$utente->getAuthIdentifier()),
                    Limit::perHour(200)->by('allegati|u'.$utente->getAuthIdentifier()),
                ]
                : [Limit::perMinute(5)->by($request->ip())];
        });

        /*
        | Importazione dei piani da PDF (N20): 3 all'ora **per utente**.
        |
        | 🚨 Stretto, e apposta. Ogni importazione costa 50 gettoni e
        | fa leggere al modello un PDF multipagina intero: e' la chiamata piu'
        | cara che abbiamo. Tre all'ora sono larghe per chi sbaglia file e
        | riprova, strette abbastanza da non far bruciare a nessuno un
        | portafoglio intero in cinque minuti per un doppio tocco sul pulsante.
        |
        | ⚠️ Il tetto vero e' comunque il cancello dei gettoni: questo limite
        | serve a proteggere dal caso in cui il cancello venga aperto tante volte
        | di fila prima che il primo job abbia scalato qualcosa.
        */
        RateLimiter::for('importazioni', function (Request $request): array {
            $utente = $request->user();

            return $utente !== null
                ? [Limit::perHour(3)->by('piani|u'.$utente->getAuthIdentifier())]
                : [Limit::perHour(1)->by('piani|ip'.$request->ip())];
        });

        RateLimiter::for('ai', function (Request $request): array {
            $utente = $request->user();

            return $utente !== null
                ? [
                    Limit::perHour(20)->by('ai|u'.$utente->getAuthIdentifier()),
                    Limit::perMinute(6)->by('ai|u'.$utente->getAuthIdentifier()),
                ]
                : [Limit::perHour(5)->by('ai|ip'.$request->ip())];
        });

        /*
         * ⚠️ Qui c'era `health-ingest`, il limite dell'ingest dell'orologio.
         * Cancellato in S1.7 insieme all'endpoint: i dati del sensore non
         * arrivano piu' al server.
         *
         * 🚨 Un limitatore senza rotta non e' inerte: e' una riga che dice a chi
         * legge che quell'ingresso esiste ancora, e prima o poi qualcuno ci
         * riattacca una rotta.
         */
    }
}
