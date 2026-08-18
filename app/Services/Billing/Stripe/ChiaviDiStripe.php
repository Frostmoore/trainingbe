<?php

declare(strict_types=1);

namespace App\Services\Billing\Stripe;

use RuntimeException;

/**
 * Le chiavi di Stripe, e la guardia che impedisce l'incidente — 18/08/2026.
 *
 * ── 🚨 Perche' una classe per leggere due stringhe ─────────────────────────
 *
 * Perche' su questa macchina convivono le chiavi **live** e quelle di **prova**,
 * e la differenza fra le due e' denaro vero. ⚠️ La configurazione sceglie gia'
 * — chiavi vere solo con `APP_ENV=production` **e** `APP_DEBUG=false` insieme,
 * vedi `config/services.php` — ma una configurazione e' una convenzione: basta
 * una variabile dimenticata, un `.env` copiato da un'altra macchina, o un
 * `APP_ENV=production` messo per provare una cosa, e si comincia a muovere soldi
 * senza accorgersene.
 *
 * 💡 **Qui la convenzione diventa un controllo che si fa sentire.** Una chiave
 * `sk_live_` fuori dalla produzione fa fallire la chiamata **subito e a voce
 * alta**, invece di riuscire in silenzio.
 *
 * ── ⚠️ Il verso del controllo ──────────────────────────────────────────────
 *
 * Si vieta la chiave **vera** fuori produzione, non si pretende quella di prova.
 * Sono due cose diverse: il secondo controllo passerebbe anche con una chiave
 * vuota, e una chiave vuota fallisce piu' tardi, in un punto che non parla di
 * chiavi.
 */
class ChiaviDiStripe
{
    private const PREFISSO_VERO = 'sk_live_';

    private const PREFISSO_VERO_PUBBLICO = 'pk_live_';

    /**
     * 🚨 **La regola, e sta scritta qui una volta sola.**
     *
     * Chiavi vere **solo** con `APP_ENV=production` **e** `APP_DEBUG=false`
     * insieme. Testuale dal committente (18/08): *«finche' il sito e' in debug
     * mode deve usare le chiavi staging e il webhook staging»*.
     *
     * ── ⚠️ Perche' e' `static` e la chiama `config/services.php` ────────────
     *
     * Perche' altrimenti esisterebbe **due volte**: una nella configurazione e
     * una qui, e un test scritto contro la copia resterebbe verde mentre il
     * codice sbaglia. 🚨 E' la differenza fra un test che protegge e un test che
     * rassicura.
     *
     * 💡 Prende i valori come parametri invece di leggerli da sola: cosi' si
     * puo' interrogare su un caso qualsiasi senza dover riavviare
     * l'applicazione con altre variabili d'ambiente.
     *
     * ⚠️ `$debug` predefinito **acceso**: se la variabile manca si assume il
     * debug attivo, quindi sandbox. Il valore predefinito di un interruttore di
     * sicurezza deve stare dal lato prudente — «non lo so» e' un ottimo motivo
     * per non toccare i soldi di nessuno.
     */
    public static function usaChiaviVere(?string $ambiente, mixed $debug = true): bool
    {
        return $ambiente === 'production' && $debug === false;
    }

    /**
     * Il segreto da usare per parlare con Stripe.
     *
     * @throws RuntimeException se e' una chiave vera fuori dalla produzione
     */
    public function segreto(): string
    {
        return $this->controlla((string) config('services.stripe.secret'), self::PREFISSO_VERO, 'segreta');
    }

    /** La chiave pubblica, quella che finisce nell'app e nel browser. */
    public function pubblica(): string
    {
        return $this->controlla((string) config('services.stripe.key'), self::PREFISSO_VERO_PUBBLICO, 'pubblica');
    }

    /**
     * Il segreto con cui si verifica la firma dei webhook.
     *
     * 💡 Torna stringa vuota se non c'e': chi lo usa deve **rifiutare** la
     * richiesta, non provare a verificarla con niente. La decisione sta nel
     * controller, che e' il posto che sa cosa rispondere.
     */
    public function segretoDeiWebhook(): string
    {
        return (string) config('services.stripe.webhook_secret');
    }

    /**
     * Se stiamo parlando con la sandbox. Serve a dirlo nei pannelli.
     *
     * ⚠️ Legge la decisione presa in `config/services.php`, **non** il prefisso
     * della chiave. 🚨 Una chiave mancante non ha prefisso, e «nessuna chiave»
     * verrebbe letta come «sandbox» — cioe' un pannello che scrive «modalita' di
     * prova» in un'installazione che semplicemente non e' configurata.
     */
    public function eLaSandbox(): bool
    {
        return (bool) config('services.stripe.sandbox', true);
    }

    private function controlla(string $chiave, string $prefissoVero, string $quale): string
    {
        if ($chiave === '') {
            throw new RuntimeException(
                "Stripe: manca la chiave {$quale}. In sviluppo si usa la sandbox: ".
                'controlla `STRIPE_STAGING_PUBLIC_KEY` e `STRIPE_STAGING_SECRET_KEY` nel `.env`.',
            );
        }

        if (str_starts_with($chiave, $prefissoVero) && $this->eLaSandbox()) {
            /*
             * 🚨 Questo e' il messaggio che salva i soldi di qualcuno.
             *
             * Dice **cosa sta succedendo** e **cosa fare**, perche' chi lo legge
             * di solito non sta pensando a Stripe: sta provando tutt'altro e si
             * e' trovato davanti un errore.
             */
            throw new RuntimeException(sprintf(
                'Stripe: si sta per usare la chiave %s VERA, ma la configurazione dice '.
                'sandbox (APP_ENV=%s, APP_DEBUG=%s). Le chiavi live si usano solo con '.
                'APP_ENV=production E APP_DEBUG=false insieme: in sviluppo e su staging '.
                'vanno quelle della sandbox (`STRIPE_STAGING_*`).',
                $quale,
                (string) config('app.env'),
                config('app.debug') ? 'true' : 'false',
            ));
        }

        return $chiave;
    }
}
