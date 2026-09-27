<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Gym\Pages\Cruscotto;
use App\Filament\Gym\Pages\IlTuoPiano;
use App\Support\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 🔒 **Chi non ha pagato entra, vede l'avviso, e non può fare nulla** — 63.D, 27/09/2026.
 *
 * ══ 📌 LA DECISIONE ═══════════════════════════════════════════════════════
 *
 * 📌 Il committente, 27/09: *«chi non ha pagato … sul pannello vede solo
 * l'avviso e non può fare nulla»*. ⛔ Chiude la **63.D**, aperta dal 26/09.
 *
 * ⚖️ Ed è **più stretta** della «sola lettura» a cui si era pensato: non *«può
 * guardare ma non scrivere»*, ma *«c'è l'avviso, il tasto per pagare, e
 * nient'altro»*.
 *
 * ══ 🚨 IL CONFINE SOTTILE: BLOCCARE NON E' CHIUDERE FUORI ═════════════════
 *
 * ⛔ Questo middleware cammina sul filo della regola più importante del
 * progetto: *«se uno non può entrare, non mi può pagare»*. 🔑 Per questo **non
 * risponde mai 403 e non chiude nessuna porta**: rimanda al cruscotto, dove c'è
 * l'avviso e il tasto per pagare.
 *
 * ⚠️ **La differenza fra 403 e rimando non è cosmetica.** Un 403 è un vicolo
 * cieco: la persona non sa dove andare, e il giorno in cui una pagina venisse
 * dimenticata nella lista delle consentite resterebbe **chiusa fuori davvero**.
 * 💡 Con il rimando, l'errore peggiore che questo codice può fare è **mandarti
 * sulla pagina in cui paghi**.
 *
 * ══ ⚠️ PERCHE' STA ANCHE FRA I `persistentMiddleware` ═════════════════════
 *
 * 🚨 Senza, chi aveva **una scheda già aperta** quando è arrivata la sospensione
 * continuerebbe a lavorare: i bottoni di Filament passano da Livewire, e le
 * richieste di Livewire non attraversano `authMiddleware`. ⛔ Sarebbe un blocco
 * che si aggira ricaricando... anzi, **non** ricaricando.
 */
class SoloLAvvisoSeNonHaiPagato
{
    public function __construct(private readonly TenantContext $contesto) {}

    public function handle(Request $request, Closure $next): Response
    {
        $spazio = $this->contesto->get();

        /*
         * ⚠️ **Contesto vuoto: si passa.** ⛔ Qui non si decide chi entra — lo
         * fa `canAccessPanel()` — e un middleware che negasse su un contesto
         * assente chiuderebbe fuori il super admin, che non ne ha mai uno.
         */
        if ($spazio === null || $spazio->isActive()) {
            return $next($request);
        }

        if ($request->user()?->isSuperAdmin() === true) {
            return $next($request);
        }

        if ($this->eConsentita($request)) {
            return $next($request);
        }

        return redirect()->to(Cruscotto::getUrl(panel: 'admin'));
    }

    /**
     * 🚪 Le sole cose raggiungibili con il servizio sospeso.
     *
     * 💡 **Tre, e ognuna ha una ragione precisa:**
     *
     * | Cosa | Perché |
     * |---|---|
     * | il **cruscotto** | è dove sta l'avviso: senza, il rimando girerebbe a vuoto |
     * | **«Il tuo piano»** | è la pagina da cui si paga. ⛔ Bloccarla renderebbe il blocco definitivo |
     * | **uscire** | ⛔ chi non può nemmeno disconnettersi è in trappola |
     *
     * ⚠️ **Si confrontano i percorsi, non i nomi delle rotte**: le pagine di
     * Filament hanno nomi generati, e un confronto per nome si rompe in silenzio
     * al primo cambio di versione — cioè il blocco diventerebbe totale senza che
     * nessun test lo dica.
     */
    private function eConsentita(Request $request): bool
    {
        $percorso = $this->normalizza('/'.$request->path());

        /*
         * 🚨 **Confronto ESATTO, non per prefisso** — e non è pignoleria.
         *
         * ⛔ Il primo giro di questo middleware usava `str_starts_with()`. Il
         * percorso del cruscotto è `/admin`, che è **prefisso di ogni pagina del
         * pannello**: `/admin/members`, `/admin/trainers`, tutto. 🚨 Risultato: il
         * blocco non bloccava **niente**, e sembrava funzionare.
         *
         * 💡 L'ha trovato `SoloLAvvisoSeNonHaiPagatoTest::le_altre_pagine_
         * rimandano_al_cruscotto`, che si aspettava un rimando e ha visto un 200.
         * ⚠️ È lo stesso genere di difetto della `v36.29.0`: un controllo che
         * c'è, si legge bene, e non controlla.
         */
        $consentiti = array_map(
            fn (string $url): string => $this->normalizza($this->soloIlPercorso($url)),
            [
                Cruscotto::getUrl(panel: 'admin'),
                IlTuoPiano::getUrl(panel: 'admin'),
            ],
        );

        if (in_array($percorso, $consentiti, true)) {
            return true;
        }

        /*
         * 🚪 L'uscita. ⚠️ Filament la serve in POST sotto il percorso del
         * pannello, e il nome della rotta cambia fra le versioni: si guarda la
         * coda del percorso.
         */
        return str_ends_with($percorso, '/logout');
    }

    /** Da un URL assoluto tiene solo il percorso, che è quello che si confronta. */
    private function soloIlPercorso(string $url): string
    {
        return (string) (parse_url($url, PHP_URL_PATH) ?? '');
    }

    /**
     * Toglie la barra finale, così `/admin` e `/admin/` sono la stessa cosa.
     *
     * ⚠️ Serve perché il confronto è **esatto**: senza, `/admin/` finirebbe fra
     * le pagine bloccate e il rimando girerebbe su se stesso.
     */
    private function normalizza(string $percorso): string
    {
        $pulito = rtrim($percorso, '/');

        return $pulito === '' ? '/' : $pulito;
    }
}
