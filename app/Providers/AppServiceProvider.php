<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\ResponseFactory;
use App\Http\Responses\RoleAwareLoginResponse;
use App\Listeners\GliEventiDellePromozioni;
use App\Services\Ai\AiManager;
use App\Services\Auth\Social\JwksSocialTokenVerifier;
use App\Services\Auth\Social\SocialTokenVerifier;
use App\Support\Tenancy\TenantContext;
use Filament\Auth\Http\Responses\Contracts\LoginResponse;
use Filament\Support\Assets\Css;
use Filament\Support\Facades\FilamentAsset;
use Illuminate\Contracts\Routing\ResponseFactory as ResponseFactoryContract;
use Illuminate\Support\ServiceProvider;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        /**
         * Singleton, non scoped: deve essere UNA sola istanza per richiesta,
         * altrimenti TenantScope e il middleware ResolveTenant leggerebbero
         * oggetti diversi e il filtro non si applicherebbe.
         *
         * Sotto Octane/queue il container viene ripulito tra una richiesta e
         * l'altra, quindi il contesto non trapela al job successivo; i job che
         * girano nello stesso worker devono comunque usare `runAs()`.
         */
        $this->app->singleton(TenantContext::class);

        /*
         * ══ 🆕 💳 STRIPE PASSA DA QUI — §9.1, 18/09/2026 ══════════════════════
         *
         * ⛔ **Prima ogni servizio si faceva il suo client**, con
         * `new StripeClient(...)` dentro un metodo privato: `Cassa`,
         * `LaPausaDellAbbonamento`, `AddebitoDelMese`. 🚨 Conseguenza: il lato
         * **in uscita** verso Stripe — aprire un pagamento, creare un cliente,
         * mettere in pausa, emettere una fattura — non si poteva provare in
         * automatico. I test si fermavano ai rifiuti, e la strada buona non
         * l'aveva mai percorsa nessuno fuori dalla produzione.
         *
         * 💡 Adesso il client sta nel container, e un test lo sostituisce con un
         * finto che registra le chiamate.
         *
         * ⚠️ **`bind` e non `singleton`**: la chiave si legge ogni volta dalla
         * configurazione, così un ambiente che la cambia al volo — i test che
         * provano `ChiaviDiStripe` — non si ritrova con un client vecchio.
         */
        $this->app->bind(
            StripeClient::class,
            fn (): StripeClient => new StripeClient((string) config('services.stripe.secret')),
        );

        /**
         * Le immagini del sito — 16/08/2026.
         *
         * 💡 **`scoped` e non `singleton`**: tiene in memoria quali file
         * esistono per la durata **della richiesta**, e la stessa immagine
         * viene chiesta più volte nella stessa pagina (`is_file()` tocca il
         * disco ogni volta). ⚠️ Un singleton sotto Octane si porterebbe dietro
         * la risposta anche dopo che qualcuno ha caricato l'immagine, e
         * continuerebbe a mostrare il riempimento vuoto finché non si riavvia.
         */
        $this->app->scoped(ImmaginiDelSito::class);

        /**
         * La verifica dei token social — C17.
         *
         * Legato al contratto e non alla classe concreta perche' i test lo
         * sostituiscono con un doppio: un test che deve produrre un token
         * firmato da Google per provare la **logica di collegamento** starebbe
         * provando la crittografia invece del proprio codice. La verifica vera
         * ha un test suo, che parte da una chiave generata al volo.
         */
        $this->app->bind(
            SocialTokenVerifier::class,
            JwksSocialTokenVerifier::class,
        );

        /**
         * 🚨 Singleton, e non e' un'ottimizzazione.
         *
         * `AiManager` tiene il registro dei fornitori (`extend()`) e le istanze
         * gia' costruite. Con un binding normale ogni iniezione ne creerebbe uno
         * nuovo: un fornitore registrato in un punto — un test, un comando, un
         * driver alternativo per una palestra — non esisterebbe per chi lo
         * risolve altrove, **senza nessun errore**. Se ne accorgerebbe solo chi
         * nota che una sostituzione non ha avuto effetto.
         *
         * E' anche cio' che evita di ricostruire il client HTTP del fornitore a
         * ogni chiamata.
         */
        $this->app->singleton(AiManager::class);

        /**
         * I numeri con la virgola non devono diventare interi quando sono tondi.
         *
         * Il perche' — un crash in Dart che compare solo sui valori tondi — sta
         * per esteso in `App\Http\ResponseFactory`. Va sostituita la fabbrica e
         * non aggiunto un middleware: dopo la codifica l'informazione e' gia'
         * persa.
         */
        $this->app->singleton(ResponseFactoryContract::class, ResponseFactory::class);

        /**
         * Dopo il login, ognuno al suo pannello.
         *
         * Il form di accesso e' uno solo: e' il ruolo a decidere dove si
         * atterra, non una scelta chiesta all'utente prima di entrare.
         */
        $this->app->bind(LoginResponse::class, RoleAwareLoginResponse::class);
    }

    public function boot(): void
    {
        $this->registraLoStileDelleSchede();

        // 🆕 ⚡ P6 di plan_promozioni.md — registrazioni e inviti accettati fanno scattare le promozioni a evento.
        GliEventiDellePromozioni::registra();
    }

    /**
     * Il foglio di stile delle schede dei pannelli -- 09/09/2026.
     *
     * == PERCHE' STA QUI E NON IN UN PANNELLO ==
     *
     * I pannelli sono due (god e gym) e la regola vale per tutti e due:
     * registrarla in ognuno vorrebbe dire due righe da tenere allineate, e il
     * giorno che se ne aggiunge un terzo se ne dimenticherebbe una.
     *
     * ATTENZIONE: il file lo copia in `public/` il comando
     * `filament:assets`, che il deploy gia' esegue a ogni giro. Senza quel
     * comando il foglio non esce, e le azioni tornano in fila senza che nulla
     * segnali l'errore.
     */
    private function registraLoStileDelleSchede(): void
    {
        FilamentAsset::register([
            Css::make('pannello-a-schede', resource_path('css/pannello-a-schede.css')),

            /*
             * 🆕 23/09/2026 — le pagine su misura (cruscotto della palestra,
             * documento accanto all'import). ⚠️ Il CSS di Filament v5 è
             * precompilato e **non contiene** le classi generiche di Tailwind:
             * scritte in una vista non fanno niente, senza errori. Il perché
             * per esteso sta in cima al foglio.
             */
            Css::make('pannello-palestra', resource_path('css/pannello-palestra.css')),
        ]);
    }

    /*
     * ⛔ **QUI C'ERA LA FASCIA ROSSA DELL'IMPERSONAZIONE** — tolta il 27/09/2026,
     * voce 64.
     *
     * 🚨 Serviva a ricordare a chi stava usando l'account di un cliente che non
     * era nei propri panni. ⛔ Non serve più, perché **non si può più entrare**.
     *
     * ⚠️ Il metodo `registraBannerImpersonazione()` e la sua chiamata sono andati
     * via insieme: un metodo che nessuno chiama è la trappola già pagata con
     * `RunnerDebug.entitlements` — un file che c'era e che nessuno leggeva.
     *
     * 📌 *«è un data breach costruito apposta per farmi mandare in galera»*.
     * `divieti.md`, 27/09/2026.
     */
}
