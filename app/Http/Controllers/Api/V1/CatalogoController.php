<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ProfiloPubblico;
use App\Models\User;
use App\Services\Scoperta\CatalogoPubblico;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Il catalogo di palestre e trainer — 18/08/2026. **M2.4.**
 *
 * ── 🚨 Aperto a tutti, anche a chi non ha un account ───────────────────────
 *
 * *(correzione del committente, 18/08)*. Non e' una svista di configurazione:
 * chiuderlo agli abbonati lo nasconderebbe a **tutti quelli che non sono ancora
 * clienti**, cioe' alle persone che deve raggiungere — e ridurrebbe il pubblico
 * di chi paga la pubblicita' ai soli abbonati, che sono gia' dentro.
 *
 * 💡 L'abbonamento vende **un'altra cosa**: scrivere senza limite a chi non ti
 * segue. Quello lo decide `CancelloDellaChat` (M3), non questo controller.
 *
 * ⚠️ Chi e' autenticato ottiene comunque **un catalogo migliore**: le schede
 * ordinate per vicinanza alla propria citta'. Chi non lo e' le riceve in ordine
 * alfabetico — meno utile, ma non chiuso.
 */
class CatalogoController extends Controller
{
    /**
     * `GET /api/v1/catalogo?q=functional&limite=20`
     */
    public function index(Request $request, CatalogoPubblico $catalogo): JsonResponse
    {
        $dati = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'limite' => ['nullable', 'integer', 'min:1', 'max:'.CatalogoPubblico::LIMITE_MASSIMO],
        ]);

        $trovati = $catalogo->cerca(
            $this->chiGuarda($request),
            $dati['q'] ?? null,
            (int) ($dati['limite'] ?? 20),
        );

        return response()->json([
            'data' => $trovati->map(fn (ProfiloPubblico $p): array => $this->scheda($p))->all(),
        ]);
    }

    /**
     * 🚨 **Chi sta guardando, se c'e' — e perche' NON basta `$request->user()`.**
     *
     * ⚠️ Questa rotta e' fuori da `auth:sanctum`, quindi il guard predefinito
     * resta `web`, cioe' **sessione con cookie**. Un'app che si presenta con un
     * token `Bearer` non ha nessuna sessione: `$request->user()` risponderebbe
     * `null` **anche a un utente perfettamente autenticato**.
     *
     * 🚨 Il guasto sarebbe stato invisibile: il catalogo avrebbe funzionato —
     * restituendo risultati, senza errori — solo **sempre in ordine alfabetico**,
     * mai per vicinanza. Nessuna eccezione, nessun log: solo una funzione che
     * non c'e' e sembra che ci sia.
     *
     * 💡 Chiedendo esplicitamente il guard `sanctum` il token viene letto quando
     * c'e', e quando non c'e' si ottiene `null` — che qui e' un caso legittimo,
     * non un errore.
     */
    private function chiGuarda(Request $request): ?User
    {
        /** @var User|null $utente */
        $utente = $request->user('sanctum');

        return $utente;
    }

    /** `GET /api/v1/catalogo/{profilo}` — la scheda per esteso. */
    public function show(ProfiloPubblico $profilo): JsonResponse
    {
        /*
         * 🚨 Una scheda non visibile risponde **404 e non 403**.
         *
         * ⚠️ Un 403 confermerebbe che quella scheda esiste — cioe' permetterebbe
         * di scoprire, provando gli identificativi uno per uno, quali palestre
         * sono iscritte ma non pubblicate. E' un'informazione commerciale di
         * qualcun altro.
         */
        abort_unless($profilo->visibile, 404);

        return response()->json(['data' => $this->scheda($profilo, esteso: true)]);
    }

    /**
     * 🚨 **Non esce nessun `user_id`.**
     *
     * Per aprire una conversazione l'app manda **l'id della scheda**, e il
     * server risolve da solo chi e' il destinatario (`ProfiloPubblico::
     * destinatario()`). ⚠️ Pubblicare qui l'identificativo del proprietario
     * vorrebbe dire dare a chiunque, senza autenticazione, l'elenco degli id di
     * tutti i titolari di palestra — un pezzo che non serve a niente all'app e
     * che serve a chi vuole provare a indovinare qualcos'altro.
     *
     * @return array<string, mixed>
     */
    private function scheda(ProfiloPubblico $p, bool $esteso = false): array
    {
        $base = [
            'id' => $p->id,
            'titolo' => $p->titolo,
            'tipo' => $p->ePalestra() ? 'palestra' : 'trainer',
            'comune' => $p->comune?->esteso(),
            'distanza_km' => $p->getAttribute('distanza_km'),

            /*
             * ⚠️ **L'etichetta, sempre presente anche quando e' `false`.**
             *
             * 🚨 Un campo che compare solo quando vale `true` e' un campo che
             * l'app puo' dimenticarsi di leggere senza che niente si rompa —
             * finche' un giorno qualcuno paga e nessuno lo etichetta. Presente
             * sempre, e' una casella che si vede vuota.
             */
            'sponsorizzato' => (bool) $p->getAttribute('sponsorizzato'),

            /*
             * 💡 Se la scheda e' contattabile. Puo' non esserlo: una palestra
             * senza proprietario attivo non ha nessuno a cui recapitare, e l'app
             * deve poterlo dire **prima** che qualcuno scriva un messaggio che
             * non arriverebbe da nessuna parte.
             */
            'contattabile' => $p->destinatario() !== null,
        ];

        if (! $esteso) {
            /*
             * 💡 Nell'elenco la descrizione si taglia: e' testo libero, e una
             * scheda con duemila parole occuperebbe da sola tutta la risposta.
             */
            $base['descrizione'] = $p->descrizione !== null
                ? mb_strimwidth($p->descrizione, 0, 200, '…')
                : null;

            return $base;
        }

        $base['descrizione'] = $p->descrizione;

        return $base;
    }
}
