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
        | AI (B6.6 / B10.2): 20 chiamate all'ora **per utente**, non per IP.
        |
        | 🚨 Per utente e' obbligatorio qui, per il motivo opposto a quello del
        | login: queste richieste sono autenticate, e limitarle per IP
        | significherebbe che venti persone sul wi-fi della palestra esauriscono
        | il limite a testa in un'ora sola. Chi non e' autenticato non arriva a
        | questi endpoint, quindi l'IP resta solo come rete di sicurezza.
        |
        | Questo limite protegge dal singolo utente che martella. Il conto di
        | fine mese lo protegge la quota di palestra (`TenantAiQuota`), che e' un
        | controllo diverso e serve comunque: cento iscritti educati bruciano il
        | budget senza che nessuno superi il proprio limite orario.
        */
        RateLimiter::for('ai', function (Request $request): array {
            $utente = $request->user();

            return $utente !== null
                ? [
                    Limit::perHour(20)->by('ai|u'.$utente->getAuthIdentifier()),
                    Limit::perMinute(6)->by('ai|u'.$utente->getAuthIdentifier()),
                ]
                : [Limit::perHour(5)->by('ai|ip'.$request->ip())];
        });

        // Ingest dei dati dell'orologio (B9.1): fuori dalla sessione, autenticato
        // dal solo token per-utente. 60 all'ora basta per una sincronizzazione
        // ogni minuto, che e' gia' piu' del necessario per il sonno.
        RateLimiter::for('health-ingest', fn (Request $request): array => [
            Limit::perHour(60)->by('ingest|'.$request->ip()),
        ]);
    }
}
