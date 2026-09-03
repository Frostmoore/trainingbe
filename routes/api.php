<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Account\AvatarController;
use App\Http\Controllers\Api\V1\Account\CittaController;
use App\Http\Controllers\Api\V1\Account\ConsentController;
use App\Http\Controllers\Api\V1\Account\RecoveryKeyController;
use App\Http\Controllers\Api\V1\Account\TimezoneController;
use App\Http\Controllers\Api\V1\AccountController;
use App\Http\Controllers\Api\V1\Ai\AiController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\Billing\BillingController;
use App\Http\Controllers\Api\V1\BrandingController;
use App\Http\Controllers\Api\V1\CatalogoController;
use App\Http\Controllers\Api\V1\Chat\AllegatoCifratoController;
use App\Http\Controllers\Api\V1\Chat\ChatKeyController;
use App\Http\Controllers\Api\V1\Chat\ConversationController;
use App\Http\Controllers\Api\V1\Chat\DeviceTokenController;
use App\Http\Controllers\Api\V1\ComuneController;
use App\Http\Controllers\Api\V1\DashboardController;
use App\Http\Controllers\Api\V1\InvitoController;
use App\Http\Controllers\Api\V1\InvitoInPalestraController;
use App\Http\Controllers\Api\V1\Nutrition\CatalogoAlimentiController;
use App\Http\Controllers\Api\V1\Nutrition\DiaryController;
use App\Http\Controllers\Api\V1\Nutrition\FoodFavoriteController;
use App\Http\Controllers\Api\V1\Nutrition\ImportazioneDaDocumentoController;
use App\Http\Controllers\Api\V1\Nutrition\NutritionPlanController;
use App\Http\Controllers\Api\V1\Nutrition\TraslocoDelDiarioController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\SocialAuthController;
use App\Http\Controllers\Api\V1\TrainerController;
use App\Http\Controllers\Api\V1\Training\CalendarController;
use App\Http\Controllers\Api\V1\Training\ExerciseController;
use App\Http\Controllers\Api\V1\Training\SeriesController;
use App\Http\Controllers\Api\V1\Training\WorkoutPlanController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Il prefisso `/api` lo aggiunge bootstrap/app.php: qui si parte da `v1`.
|
| Ordine dei middleware: auth:sanctum -> tenant -> tenant.active
| `tenant` ha bisogno dell'utente autenticato; `tenant.active` del contesto che
| `tenant` imposta. Invertirli non darebbe errore: darebbe **nessun filtro**.
|
*/

Route::prefix('v1')->group(function (): void {

    /*
     * 🆕 **Che versione devo avere?** — FASE 10.
     *
     * 🚨 Sta **fuori dal cancello che descrive** (`VersioneMinima::SEMPRE_APERTE`):
     * un'app bloccata deve poter chiedere «sono ancora vecchia?», o il pulsante
     * «Riprova» della schermata di blocco non avrebbe niente da interrogare.
     *
     * 💡 Serve quando il blocco è stato **un errore nostro**: senza, per
     * toglierlo servirebbe un'altra pubblicazione sullo store.
     *
     * ⚠️ Pubblica e senza autenticazione: la domanda arriva anche da chi non ha
     * fatto l'accesso, e la risposta non contiene niente di personale.
     */
    Route::get('versione', function (Request $r) {
        $piattaforma = strtolower((string) $r->header('X-App-Platform', 'android'));

        return response()->json(['data' => [
            'minima' => (int) config("app_versione.minima.$piattaforma", 0),
            'store' => config("app_versione.store.$piattaforma") ?: null,
        ]]);
    })->middleware('throttle:60,1');

    // ── Pubblica, senza autenticazione ───────────────────────────────────
    // L'app la chiama PRIMA del login per vestirsi dei colori della palestra.
    // Throttle stretto: senza credenziali da indovinare, è il modo più comodo
    // per provare codici palestra a tappeto.
    Route::get('branding/lookup', [BrandingController::class, 'lookup'])
        ->middleware('throttle:branding-lookup');

    /*
     * ── 🔗 L'invito di una palestra — 3b-V.2 ──────────────────────────────
     *
     * 📌 *«Il link d'invito deve essere monouso, e a chi ci clicca si deve
     * aprire l'app in una pagina con la descrizione della palestra, il logo, i
     * colori […] e due tasti, uno per accettare e uno per rifiutare»*.
     *
     * 🚨 **Pubbliche, e non e' una dimenticanza.** Chi tocca il link **non ha
     * ancora l'app**: se l'anteprima chiedesse l'accesso, la prima cosa che
     * quella persona vedrebbe sarebbe un modulo di registrazione invece
     * dell'invito che le e' stato mandato — cioe' esattamente il muro che
     * questo link esiste per togliere.
     *
     * ⚠️ E **anche il rifiuto e' pubblico**: chi non vuole entrare non deve
     * crearsi un account per dire di no.
     *
     * 💡 Il token e' la credenziale: 32 caratteri casuali, monouso, a scadenza.
     * Chi ce l'ha e' chi l'ha ricevuto, ed e' la stessa forma degli inviti dei
     * trainer (F6.2).
     *
     * 🚨 **Sotto `throttle:branding-lookup`**, che e' il piu' stretto che
     * abbiamo per le rotte senza credenziali: senza niente da indovinare, e' il
     * modo piu' comodo per provare token a tappeto.
     */
    Route::middleware('throttle:branding-lookup')->group(function (): void {
        Route::get('inviti-palestra/{token}', [InvitoInPalestraController::class, 'anteprima']);
        Route::post('inviti-palestra/{token}/rifiuta', [InvitoInPalestraController::class, 'rifiuta']);
    });

    /*
     * ── I comuni italiani — M1.2 ──────────────────────────────────────────
     *
     * 🚨 **Pubblica di proposito.** L'elenco dei comuni lo pubblica l'ISTAT e lo
     * scarica chiunque: proteggerlo con l'autenticazione non proteggerebbe
     * niente, e servirebbe solo a impedire al modulo di iscrizione sul sito di
     * chiedere la citta' — che serve **prima** che esista un account.
     *
     * ⚠️ Il tetto c'e' lo stesso, ma per un motivo diverso dai dati: parte a
     * ogni tasto premuto, quindi e' largo (60/minuto), e serve a non lasciare un
     * endpoint pubblico senza limite.
     */
    Route::middleware('throttle:comuni')->group(function (): void {
        Route::get('comuni', [ComuneController::class, 'index']);
        Route::get('comuni/{comune}', [ComuneController::class, 'show']);
    });

    /*
     * ── Il catalogo di palestre e trainer — M2.4 ──────────────────────────
     *
     * 🚨 **Aperto a tutti, e non e' una svista di configurazione.**
     * *(correzione del committente, 18/08)*. Chiuderlo agli abbonati lo
     * nasconderebbe a tutti quelli che non sono ancora clienti — cioe' alle
     * persone che deve raggiungere — e ridurrebbe il pubblico di chi paga la
     * pubblicita' ai soli abbonati, che sono gia' dentro.
     *
     * 💡 L'abbonamento vende **un'altra cosa**: scrivere senza limite a chi non
     * ti segue. Quello lo decide `CancelloDellaChat` (M3), non queste rotte.
     *
     * 🚨 **Fuori da `auth:sanctum`, e per questo il controller chiede il guard
     * `sanctum` a mano.** Il guard predefinito e' `web` (sessione con cookie):
     * `$request->user()` risponderebbe `null` anche a un'app autenticata con un
     * token `Bearer`. ⚠️ Il catalogo avrebbe funzionato lo stesso — senza mai
     * ordinare per vicinanza, e senza dirlo a nessuno.
     *
     * 💡 Chi e' autenticato ottiene quindi **un catalogo migliore** (per
     * distanza dalla propria citta'); chi non lo e' lo riceve in ordine
     * alfabetico. Meno utile, ma non chiuso.
     */
    Route::middleware('throttle:catalogo')->group(function (): void {
        Route::get('catalogo', [CatalogoController::class, 'index']);
        Route::get('catalogo/{profilo}', [CatalogoController::class, 'show']);
    });

    Route::prefix('auth')->group(function (): void {

        // ── Pubbliche ────────────────────────────────────────────────────
        // I limiti sono in RateLimitServiceProvider e NON sono per solo IP:
        // gli iscritti si collegano dal wi-fi della palestra, dove decine di
        // persone condividono un indirizzo.
        Route::post('register', [AuthController::class, 'register'])
            ->middleware('throttle:auth-register');

        Route::post('login', [AuthController::class, 'login'])
            ->middleware('throttle:auth-login');

        /*
         * 🆕 **Iscrizione con l'invito di un trainer indipendente** — F6.2/F6.3.
         *
         * ⚠️ Sotto `auth-register` e non `auth-login`: è a tutti gli effetti una
         * registrazione, e il limite deve essere quello stretto.
         *
         * 🚨 **Non serve un `join_code`, e non è una scorciatoia**: il token
         * dell'invito è più forte di un codice palestra — è monouso, scade, ed è
         * revocabile una persona per volta. Il codice palestra non è nessuna
         * delle tre cose.
         */
        Route::post('register-with-invite', [AuthController::class, 'registerWithInvite'])
            ->middleware('throttle:auth-register');

        /*
         * Accesso con Google o Apple — C17.
         *
         * ⚠️ Sotto `auth-login` e non `auth-register`, anche quando crea un
         * account: dal punto di vista di chi la usa e' un **accesso**, e la
         * distinzione fra prima volta e volte successive la fa il server, non
         * l'utente. Metterla sul limite della registrazione bloccherebbe al
         * terzo tentativo chi sta solo riprovando ad entrare.
         *
         * ⏸️ Risponde **501** finche' `services.social.*.client_ids` e' vuoto.
         */
        Route::post('social', [SocialAuthController::class, 'store'])
            ->middleware('throttle:auth-login');

        // ── Autenticate, ma raggiungibili anche a palestra SOSPESA ───────
        //
        // Perché senza `tenant.active`: se l'abbonamento della palestra scade,
        // l'iscritto deve comunque potersene andare e mettere in sicurezza il
        // proprio account. Chiudere anche queste significherebbe intrappolare
        // una persona in una sessione che non può né usare né terminare, per
        // una decisione commerciale che non la riguarda.
        Route::middleware(['auth:sanctum', 'tenant'])->group(function (): void {
            Route::post('logout', [AuthController::class, 'logout']);
            Route::get('devices', [AuthController::class, 'devices']);
            Route::delete('devices/{tokenId}', [AuthController::class, 'revokeDevice'])
                ->whereNumber('tokenId');
        });

        // ── Autenticate e riservate alle palestre ATTIVE ─────────────────
        //
        // `me` è l'endpoint con cui l'app si avvia: rispondendo 403 con
        // `code: tenant_inactive` le dice subito di mostrare la schermata di
        // servizio sospeso, invece di lasciarla proseguire e fallire più avanti
        // in modo confuso.
        Route::middleware(['auth:sanctum', 'tenant', 'tenant.active'])->group(function (): void {
            Route::get('me', [AuthController::class, 'me']);
        });
    });

    /*
    | ── Dominio (B4, B5, B6) ──────────────────────────────────────────────
    |
    | TUTTI con la catena completa ['auth:sanctum','tenant','tenant.active']:
    | sono dati della palestra, e senza `tenant` il global scope non filtra —
    | l'iscritto vedrebbe le schede di tutti.
    |
    | 🚨 Non aggiungere endpoint di dominio senza quella catena.
    */
    Route::middleware(['auth:sanctum', 'tenant', 'tenant.active'])->group(function (): void {

        // ── Profilo (C1) ─────────────────────────────────────────────────
        //
        // Nessun id: si legge e si scrive sempre e solo il proprio profilo.
        // È una PATCH perché l'app salva un campo alla volta mentre la persona
        // compila: una PUT costringerebbe a rimandare tutto ogni volta, e il
        // primo campo dimenticato azzererebbe gli altri.
        // ── La schermata principale (D4) ─────────────────────────────────
        //
        // Una chiamata sola per calorie, allenamenti, peso, sonno e parametri:
        // con sei richieste separate basta che una sia lenta perché la
        // schermata compaia a pezzi. Il consiglio del giorno resta a parte
        // (`/ai/advice`) perché è l'unico che può costare una chiamata AI.
        Route::get('dashboard', [DashboardController::class, 'index']);

        Route::get('profile', [ProfileController::class, 'show']);
        Route::patch('profile', [ProfileController::class, 'update']);

        // ── Eliminazione dell'account (C6) ───────────────────────────────
        //
        // 🚨 Apple la pretende per ogni app con registrazione: dev'essere
        // raggiungibile dall'app, senza scrivere a nessuno. `preview` esiste
        // perché un'azione irreversibile va spiegata dove si compie, e la
        // spiegazione deve venire dal server: scritta solo nell'app, diventa
        // falsa il giorno che il server cambia politica.
        Route::get('account/deletion-preview', [AccountController::class, 'preview']);

        /*
         * 🆕 **Un utente senza palestra entra in una palestra** — requisito B4.
         *
         * 🚨 **Non è un `PATCH` su una colonna: è una migrazione di dati.**
         * Diario, allenamenti, piani, foto e conversazioni sono tutti marcati
         * con il tenant personale; spostando solo l'utente, il `TenantScope`
         * gli renderebbe invisibile **tutta la sua storia** — non cancellata,
         * invisibile, che è peggio perché non se ne accorge nessuno.
         *
         * ⚠️ Sotto `throttle:auth-login`: prova a prova, è un modo per
         * indovinare codici palestra validi.
         */
        /*
         * 🆕 **I dettagli della palestra** — 3b-P.13.1, 23/08/2026.
         *
         * 💡 Sola lettura, e risponde `null` a chi non ne ha una: non e' un
         * errore non avere una palestra.
         */
        Route::get('account/gym', [AccountController::class, 'gym']);

        /*
         * 🆕 **Uscire da una palestra** — 3b-P.13.3, 23/08/2026.
         *
         * ⛔ Nessun limite di frequenza qui: non è un'operazione che si possa
         * ripetere: dopo la prima non si è più in nessuna palestra, e la
         * seconda chiamata rifiuta da sola.
         */
        Route::post('account/leave-gym', [AccountController::class, 'leaveGym']);

        Route::post('account/join-gym', [AccountController::class, 'joinGym'])
            ->middleware('throttle:auth-login');
        Route::delete('account', [AccountController::class, 'destroy']);

        // G8 — email e password si cambiano dall'app. Entrambe chiedono la
        // password attuale: un telefono lasciato sbloccato non deve bastare a
        // spostare l'account su un indirizzo altrui.
        Route::patch('account/email', [AccountController::class, 'updateEmail']);
        Route::patch('account/password', [AccountController::class, 'updatePassword']);

        // ── Allenamento (B4.5) ───────────────────────────────────────────
        Route::get('workout-plans', [WorkoutPlanController::class, 'index']);

        // 🚨 S7 — i modelli della palestra, per chi allena.
        //
        // L'assegnazione è uscita dal pannello: il trainer manda la scheda
        // **dalla chat, dall'app**, perché è l'unico posto in cui esistono le
        // chiavi per cifrarla. I modelli però li scrive nel pannello, e da lì
        // l'app deve poterli leggere.
        //
        // ⚠️ **Prima di `{plan}`**: `whereNumber` protegge già la rotta con
        // l'id, ma l'ordine rende esplicito che `templates` non è un id.
        Route::get('workout-plans/templates', [WorkoutPlanController::class, 'templates']);
        Route::get('workout-plans/{plan}', [WorkoutPlanController::class, 'show'])->whereNumber('plan');

        // C2 — l'iscritto si scrive le proprie schede (decisione D1). Quelle del
        // trainer restano in sola lettura: la distinzione è in WorkoutPlanPolicy,
        // e la risposta di `index` la riporta nel campo `editable`.
        Route::post('workout-plans', [WorkoutPlanController::class, 'store']);
        Route::put('workout-plans/{plan}', [WorkoutPlanController::class, 'update'])->whereNumber('plan');
        Route::delete('workout-plans/{plan}', [WorkoutPlanController::class, 'destroy'])->whereNumber('plan');

        /*
        | ⛔ **`workout-sessions` e `daily-burn` NON esistono piu'** — FASE 11.6,
        | 21/08/2026.
        |
        | 📌 Il committente: *«Nessun allenamento deve risiedere sul server,
        | devono stare tutti nell'app»*. Era gia' scritto in
        | `plan_tutto_sul_telefono.md` §2.1 dal 16/08.
        |
        | 🚨 Le sedute, le serie e le calorie bruciate dichiarate a mano vivono
        | nell'archivio del telefono. Chi non ha ancora traslocato lo fa da
        | `migrazione/allenamenti`, che sta qui sopra.
        |
        | ⚠️ Restano **le schede** (`workout-plans`): sono il patrimonio del
        | trainer, sono gia' anonime (D4), e non dicono se e quando qualcuno le
        | ha eseguite.
        */

        /*
        | 🏋️ FASE 11.3 — il trasloco degli allenamenti sul telefono.
        |
        | 🚨 In due passi, e il secondo e' un contratto: l'app scarica, scrive,
        | conta, e dichiara. Il server **verifica** e segna «fatto» solo se i
        | conteggi tornano. ⛔ Meglio non segnare che segnare per sbaglio: un
        | «fatto» accettato a torto diventa una perdita di dati il giorno che le
        | tabelle cadranno.
        */
        /*
         * ⛔ **Le rotte del trasloco non esistono piu'** — 11.6.3, 23/08/2026.
         *
         * 🚨 Consegnavano al telefono gli allenamenti che stavano sul server, e
         * le tabelle da cui leggevano **sono cadute**. Tenerle vive vorrebbe
         * dire tre endpoint che rispondono 500 a ogni avvio dell'app, per i
         * tredici utenti che non avevano ancora traslocato.
         *
         * 📌 La decisione, del committente: *«sono tutti allenamenti mock fatti
         * da un seeder, l'unico altro utente e' un mio amico che ha l'app di
         * tipo 10 versioni fa, non fa niente rasa tutto»*.
         *
         * ⚠️ Prima di cancellare e' stata fatta una copia SQL delle tre tabelle
         * (`memory/scripts/salva-e-lascia-cadere.sh`): ventuno righe costano
         * nulla da tenere, e sono l'unica cosa che le riporterebbe indietro.
         */

        Route::get('exercises', [ExerciseController::class, 'index']);

        // C2.3 — l'esercizio che la libreria non ha. Nasce **della palestra**
        // (D3): il vocabolario dev'essere comune, o lo storico di due iscritti
        // non è confrontabile. 200 se esisteva già, 201 solo se è nato adesso.
        Route::post('exercises', [ExerciseController::class, 'store']);

        /*
        | ⚠️ `body-metrics` non esiste piu' — S5.4.
        |
        | Peso e misure sono dati del corpo e restano sul telefono di chi li
        | produce (decisione D9-bis). L'app li tiene in `ArchivioSalute` e il
        | server non li vede mai.
        */

        // C3 — le serie per i grafici. Un endpoint solo, stessa forma di
        // risposta per entrambe le metriche: l'app ha un parser unico.
        Route::get('series', [SeriesController::class, 'index']);

        // C4 — il calendario. Mese e settimana restituiscono la stessa forma di
        // celle: l'app disegna due layout, non due modelli di dati.
        Route::get('calendar', [CalendarController::class, 'month']);
        Route::get('calendar/week', [CalendarController::class, 'week']);
        Route::get('calendar/{date}', [CalendarController::class, 'day'])
            ->where('date', '\d{4}-\d{2}-\d{2}');

        /*
        | ⚠️ Le rotte `photos` non esistono piu' — S5.4.
        |
        | Le foto dei progressi sono file sul telefono di chi le scatta
        | (decisione D9-bis). Non c'e' piu' niente da caricare, da servire o da
        | autenticare — ed e' sparito con loro anche il difetto G12, che nasceva
        | proprio dal dover autenticare a mano il download di un'immagine.
        */

        // ── Nutrizione (B5.5) ────────────────────────────────────────────
        Route::get('diary', [DiaryController::class, 'index']);
        Route::get('targets', [DiaryController::class, 'targets']);
        Route::get('nutrition-plan', [DiaryController::class, 'plan']);

        /*
         * ⛔ **`POST /nutrition-plan/meals/{meal}/eaten` — RITIRATO in G9.4.**
         *
         * Leggeva il piano **assegnato sul server** (`NutritionPlan::activeFor`)
         * e ne scriveva i pasti nel diario in un tocco. Scritto il 13/08/2026,
         * ritirato il giorno dopo.
         *
         * 🚨 **Non e' stato tolto perche' funzionasse male: perche' D4 gli ha
         * tolto il dato sotto i piedi.** I piani si consegnano via chat cifrata
         * e restano anonimi — `member_id` non viene piu' valorizzato — quindi
         * `activeFor()` non trova niente per nessuno, e l'endpoint avrebbe
         * risposto `404 Non hai un piano attivo` per sempre.
         *
         * 💡 Il gesto **esiste ancora**, ma sul telefono: `DalPianoTab` legge il
         * piano dall'archivio locale e scrive con `POST /food-entries`. Vedi
         * §29 dell'atlante app.
         *
         * ⚠️ La ragione per cui era un endpoint solo — cinque richieste separate
         * possono fallire alla terza e lasciare mezzo pasto in diario — resta
         * vera, ed e' un debito che il ritiro riapre: §7.6 del piano.
         */

        Route::post('food-entries', [DiaryController::class, 'store']);
        Route::patch('food-entries/{entry}', [DiaryController::class, 'update'])->whereNumber('entry');
        Route::delete('food-entries/{entry}', [DiaryController::class, 'destroy'])->whereNumber('entry');
        Route::post('food-entries/{entry}/favorite', [FoodFavoriteController::class, 'fromEntry'])->whereNumber('entry');

        /*
         * I suggerimenti mentre si scrive il nome di un alimento — Parte L.
         *
         * 🚨 Con un **limite di frequenza stretto**: parte a ogni tasto premuto
         * (l'app aspetta un attimo, ma resta una richiesta ogni poche lettere),
         * e senza freno un solo telefono con un campo di testo impazzito
         * basterebbe a tenere occupato il server.
         *
         * 💡 Sessanta al minuto sono abbondanti per chi scrive davvero e
         * strette per chiunque altro.
         */
        Route::get('foods/search', [CatalogoAlimentiController::class, 'search'])
            ->middleware('throttle:60,1');

        Route::get('food-favorites', [FoodFavoriteController::class, 'index']);
        Route::post('food-favorites/meal', [FoodFavoriteController::class, 'storeMeal']);
        Route::post('food-favorites/{favorite}/add', [FoodFavoriteController::class, 'add'])->whereNumber('favorite');
        Route::delete('food-favorites/{favorite}', [FoodFavoriteController::class, 'destroy'])->whereNumber('favorite');

        /*
         * 📦 **Il diario trasloca sul telefono** — Parte I, I3.
         *
         * 🚨 Sola lettura: consegna e non cancella. Il server si svuota in I4,
         * con una migration, **dopo** che il trasloco e' stato verificato sul
         * telefono vero. ⛔ Un endpoint che consegna e cancella nella stessa
         * richiesta perde tutto il giorno che la risposta non arriva.
         */
        Route::get('trasloco/diario', [TraslocoDelDiarioController::class, 'pacchetto']);

        /*
        | ⛔ `daily-burn` e' andato con gli altri — FASE 11.6. La dichiarazione
        | «oggi ho bruciato 800» adesso si scrive in `BruciateDichiarate`,
        | sull'archivio del telefono.
        */

        // ── AI (B6.6) ────────────────────────────────────────────────────
        //
        // 🚨 Doppio limite, e servono entrambi: `throttle:ai` protegge dal
        // singolo utente che martella, la quota di palestra (dentro il
        // controller) protegge il conto di fine mese. Il primo senza il secondo
        // lascerebbe cento iscritti educati bruciare il budget in un giorno.
        // 🚨 `ai.consent` prima di `throttle`: senza il consenso esplicito
        // (art. 9(2)(a)) niente esce verso Anthropic. È un middleware e non un
        // `if` nel controller perché valga anche sulle rotte AI che non esistono
        // ancora — la prossima partirebbe scoperta, e funzionerebbe benissimo.
        //
        // 🆕 **`ai.plan` prima di tutto** — F4.2, decisione D2. L'ordine è
        // deliberato e va letto da sinistra: prima *hai diritto* (il piano), poi
        // *hai acconsentito* (il consenso), poi *quanto ne resta* (la quota,
        // dentro il controller). Un utente gratuito non deve arrivare nemmeno a
        // vedersi chiedere il consenso per una funzione che non ha comprato.
        /*
         * 🆕 **`ai.tetto` per ultimo, dopo il `throttle`** — FASE 8.2.
         *
         * L'ordine e' deliberato e va letto da sinistra: prima *hai diritto* (il
         * piano), poi *hai acconsentito*, poi *non stai insistendo tu*
         * (`throttle:ai`, 6 al minuto **per utente**), e infine *c'e' posto per
         * tutti* (`ai.tetto`, quante chiamate insieme in tutto).
         *
         * 🚨 Le ultime due sembrano la stessa cosa e non lo sono: il `throttle`
         * ferma una persona che insiste, il tetto ferma **mille persone che ne
         * fanno una a testa**. ⚠️ E il tetto va per ultimo perche' occupa uno
         * slot: darlo a chi verrebbe respinto per il consenso vorrebbe dire
         * togliere posto a una richiesta buona.
         */
        Route::middleware(['ai.plan', 'ai.consent', 'throttle:ai', 'ai.tetto'])->group(function (): void {
            Route::post('ai/food/text', [AiController::class, 'foodFromText']);
            Route::post('ai/food/photo', [AiController::class, 'foodFromPhoto']);
            Route::get('ai/advice', [AiController::class, 'advice']);

            // 3b-I.A — l'analisi della progressione: solo abbonati, il gate
            // e' dentro il metodo (403) perche' l'app lo traduce in modale.
            Route::post('ai/scheda/progresso', [AiController::class, 'progressoScheda']);

            /*
             * 🥣 **Il setaccio sulla stima** — I2.5, 03/09/2026.
             *
             * ⛔ Si chiamava `ai/food/confirm` e **scriveva in diario**. Adesso
             * il diario sta sul telefono: questa rotta valida le voci con
             * `MealValidator` e le restituisce, e a scrivere e' l'app.
             *
             * 🚨 **Dentro `ai.consent` anche se non chiama nessun modello e non
             * consuma quota**: chi ha revocato il consenso non deve poter far
             * passare di qui una risposta del modello. Era il difetto #3 del
             * 12/08, ed e' la stessa ragione di prima.
             *
             * ⚠️ Sotto `throttle:ai` anche se non costa token: e' un giro di
             * validazione su trenta voci, e il tetto lo protegge dall'app che
             * ripete la stessa richiesta perche' la risposta e' andata persa.
             */
            Route::post('ai/food/valida', [AiController::class, 'valida']);
        });

        /*
         * 🆕 **Chiedere «e' pronta?» non chiama nessun modello** — FASE 9.4.
         *
         * 🚨 Fuori da `ai.tetto` di proposito: occupare uno degli slot del
         * semaforo per un controllo di stato vorrebbe dire **togliere posto a
         * una stima vera**, cioè rallentare proprio la cosa che si sta
         * aspettando.
         *
         * ⚠️ Ma sotto un `throttle` suo sì, e largo: l'app lo chiede **ogni
         * secondo e mezzo** finché la stima non è pronta. Con `throttle:ai` (6 al
         * minuto) si sarebbe bloccata da sola dopo nove secondi d'attesa.
         *
         * 💡 E resta sotto `ai.consent`: chi ha revocato il consenso non deve
         * poter leggere una stima che quel consenso ha prodotto.
         */
        Route::middleware(['ai.plan', 'ai.consent', 'throttle:60,1'])->group(function (): void {
            Route::get('ai/food/stime/in-corso', [AiController::class, 'stimaInCorso']);
            Route::get('ai/food/stime/{stima}', [AiController::class, 'statoStima']);
        });

        Route::get('ai/usage', [AiController::class, 'usage']);

        /*
         * ── 💳 Il pagamento — 3b-H, 26/08/2026 ────────────────────────────
         *
         * 🚨 **Fuori da `ai.plan`, ed e' il punto.** Queste due rotte servono
         * proprio a chi il piano NON ce l'ha: metterle dietro il cancello
         * dell'AI vorrebbe dire che per comprare l'abbonamento bisogna gia'
         * averlo.
         *
         * ⚠️ `throttle` stretto su `checkout`: ogni chiamata apre una sessione
         * vera su Stripe, e una raffica riempirebbe il loro pannello di
         * sessioni mai pagate.
         */
        Route::get('billing/listino', [BillingController::class, 'listino']);
        Route::post('billing/checkout', [BillingController::class, 'checkout'])
            ->middleware('throttle:10,1');

        // 🔁 Disdetta, carta, ricevute: si va da Stripe. Vedi `Cassa::portale`.
        Route::post('billing/portale', [BillingController::class, 'portale'])
            ->middleware('throttle:10,1');

        /*
         * ── «I miei utenti» — F5.1 / F6 ──────────────────────────────────
         *
         * ⚠️ Sta nell'app e non nel pannello web per la decisione D6: il
         * **modello** di scheda si compone sul web, l'**assegnazione** a una
         * persona passa dal telefono, perché è l'unico momento che tocca dati
         * personali e deve viaggiare sul canale cifrato.
         *
         * 🚨 Il controllo di ruolo è **dentro il controller** e non qui: un
         * middleware in più su queste cinque rotte sarebbe una seconda sede
         * della stessa regola, e due sedi divergono.
         */
        Route::get('trainer/members', [TrainerController::class, 'members']);
        Route::post('trainer/invites', [TrainerController::class, 'invite']);
        Route::delete('trainer/invites/{invite}', [TrainerController::class, 'revokeInvite'])->whereNumber('invite');
        Route::post('trainer/members/{member}/toggle', [TrainerController::class, 'toggleMember'])->whereNumber('member');

        /*
         * ── I piani alimentari scritti da un trainer — G5.3 ───────────────
         *
         * 🚨 **Plurale**, e non e' un dettaglio: `/nutrition-plan` (singolare)
         * esiste gia' ed e' il piano **dell'iscritto**. Singolare e plurale a un
         * carattere di distanza sono un modo garantito di sbagliare rotta e non
         * capire perche' — annotato qui perche' chi legge le rotte se ne
         * accorga prima di scrivere il client.
         *
         * ⚠️ Il controllo di ruolo e' **dentro il controller**, come per
         * `/trainer/*`: due sedi della stessa regola divergono.
         */
        /*
         * D13 — la stima AI di un alimento, **a carico del trainer**.
         *
         * 🚨 Passa da `AiController` e non dal controller dei piani: il cancello
         * commerciale (quota → gettoni → 402) deve avere **una sede sola**.
         *
         * ⚠️ E dalla catena `ai.plan` + `ai.consent` + `throttle:ai` come ogni
         * altra chiamata: l'ordine non si cambia per una rotta.
         */
        Route::post('nutrition-plans/stima-alimento', [AiController::class, 'planFood'])
            ->middleware(['ai.plan', 'ai.consent', 'throttle:ai']);

        Route::get('nutrition-plans', [NutritionPlanController::class, 'index']);
        Route::get('nutrition-plans/templates', [NutritionPlanController::class, 'templates']);
        Route::get('nutrition-plans/{plan}', [NutritionPlanController::class, 'show'])->whereNumber('plan');
        Route::post('nutrition-plans', [NutritionPlanController::class, 'store']);
        Route::put('nutrition-plans/{plan}', [NutritionPlanController::class, 'update'])->whereNumber('plan');
        Route::delete('nutrition-plans/{plan}', [NutritionPlanController::class, 'destroy'])->whereNumber('plan');

        // ── L'importazione di un piano da PDF (N20) ────────────
        //
        // 🚨 **Sono rotte della PERSONA, non del trainer.** Il piano lo ha
        // redatto un professionista abilitato fuori di qui, e chi lo importa e'
        // l'interessato: nessuna di queste rotte e' raggiungibile da un
        // trainer, da una palestra o da un amministratore, nemmeno
        // impersonando. A chi non e' il proprietario si risponde 404 e non
        // 403, perche' su un piano alimentare anche solo l'esistenza dice
        // qualcosa sulla salute di qualcuno.
        //
        // ⚠️ Costa 50 gettoni: il cancello si apre nel `store`, i gettoni si
        // scalano nel job e **solo se la trascrizione riesce**.
        //
        /*
         * ══ 🔴 `ai.consent` — AGGIUNTO IL 03/09/2026, ED ERA UN BUCO ═════════
         *
         * ⛔ Questa rotta manda un PDF ad **Anthropic** e non aveva il cancello
         * del consenso: dal 19/08 (N20) chi non aveva mai acconsentito all'AI —
         * o chi l'aveva **revocato** — poteva caricare il proprio piano
         * alimentare e vederselo trasferire negli Stati Uniti.
         *
         * 🚨 Un piano alimentare e' art. 9: senza consenso esplicito quel
         * trasferimento non ha nessuna base giuridica.
         *
         * 💡 Ed e' esattamente il difetto che il docblock di `RequireAiConsent`
         * descrive parola per parola: *«deve valere su ogni rotta AI, comprese
         * quelle che non esistono ancora. Il prossimo endpoint AI che qualcuno
         * aggiungera' partira' scoperto, e non se ne accorgera' nessuno — la
         * chiamata funzionerebbe benissimo»*. E' successo.
         *
         * ⚠️ **Solo qui, e non sulle altre tre.** `show`, `pdf` e `destroy` non
         * mandano niente a nessuno: metterci il cancello vorrebbe dire che chi
         * revoca il consenso non puo' piu' riprendersi ne' cancellare la propria
         * bozza. ⛔ Una revoca che chiude fuori dai propri dati e' l'opposto di
         * quello che una revoca deve fare.
         */
        Route::post('importazioni', [ImportazioneDaDocumentoController::class, 'store'])
            ->middleware(['ai.consent', 'throttle:importazioni']);
        Route::get('importazioni/{importazione}', [ImportazioneDaDocumentoController::class, 'show'])
            ->whereNumber('importazione');
        Route::get('importazioni/{importazione}/pdf', [ImportazioneDaDocumentoController::class, 'pdf'])
            ->whereNumber('importazione');
        Route::delete('importazioni/{importazione}', [ImportazioneDaDocumentoController::class, 'destroy'])
            ->whereNumber('importazione');

        // ── Chat (B8.4) ──────────────────────────────────────────────────
        //
        // Il contratto regge anche il polling: `messages?after=` restituisce
        // solo il nuovo. L'app ricade su polling a 15s quando il socket non si
        // apre, e su rete mobile capita.
        Route::get('conversations', [ConversationController::class, 'index']);
        Route::post('conversations', [ConversationController::class, 'open']);

        /*
         * 🚨 Apre un filo **dal catalogo** — M3.2.
         *
         * Vuole `profilo_id`, cioe' l'id della **scheda**, non quello della
         * persona: chi sia il destinatario lo decide il server. ⚠️ Un `user_id`
         * in ingresso avrebbe voluto dire pubblicare nel catalogo gli
         * identificativi di tutti i titolari di palestra.
         *
         * 💡 Sotto `throttle:catalogo`: e' il punto in cui un elenco pubblico
         * diventa la possibilita' di scrivere a degli sconosciuti, e va tenuto
         * piu' stretto delle altre rotte della chat.
         */
        Route::post('conversations/informazioni', [ConversationController::class, 'informazioni'])
            ->middleware('throttle:catalogo');

        /*
         * 🚨 Riscattare un invito **avendo gia' un account** — M6.2.
         *
         * Diversa da `auth/register-with-invite`, che invece **crea** la persona.
         * ⚠️ Qui chi arriva ha gia' l'app, magari ha appena finito i tre
         * messaggi con quel trainer: mandarlo su un modulo di registrazione gli
         * direbbe di crearsi un secondo account.
         *
         * 💡 Sotto `throttle:auth-login`: un token per volta, e provarli a
         * tappeto non deve essere comodo.
         */
        Route::post('inviti/{token}/riscatta', [InvitoController::class, 'riscatta'])
            ->middleware('throttle:auth-login');

        /*
         * 🔗 **Accettare l'invito di una PALESTRA** — 3b-V.2.4.
         *
         * ⚠️ Diversa dalla riga qui sopra, che riguarda l'invito di un
         * **trainer**: quello crea un legame fra due persone, questo sposta
         * l'iscritto dentro un'altra organizzazione. 🚨 Sembrano la stessa cosa
         * e non lo sono, e l'unica ragione per cui stanno vicine e' che chi
         * legge le veda tutte e due.
         *
         * 💡 L'anteprima e il rifiuto sono **pubblici** e stanno in cima al
         * file: qui serve una persona, perche' e' lei che entra.
         */
        Route::post('inviti-palestra/{token}/accetta', [InvitoInPalestraController::class, 'accetta'])
            ->middleware('throttle:auth-login');
        Route::get('conversations/contacts', [ConversationController::class, 'contacts']);
        Route::get('conversations/{conversation}/messages', [ConversationController::class, 'messages'])->whereNumber('conversation');
        Route::post('conversations/{conversation}/messages', [ConversationController::class, 'store'])->whereNumber('conversation');

        // ── L'usa e getta (N16) ──────────────────────────────────────────
        //
        // 🚨 **E' una rotta a parte, e non `read`.** `read` vuol dire «ho
        // guardato la lista», e la lista si guarda aprendo la conversazione:
        // legarci l'usa e getta avrebbe bruciato ogni messaggio effimero
        // nell'istante in cui la chat si apre, prima che qualcuno lo leggesse.
        // Questa la chiama l'app quando si CHIUDE il visualizzatore.
        Route::post('conversations/{conversation}/messages/{message}/vista', [ConversationController::class, 'vista'])
            ->whereNumber('conversation')
            ->whereNumber('message');
        Route::post('conversations/{conversation}/read', [ConversationController::class, 'read'])->whereNumber('conversation');

        // ── Le foto della chat, in transito (N14) ────────────────────────
        //
        // 🚨 Byte gia' cifrati, che il server non sa aprire: la chiave viaggia
        // dentro il messaggio, che e' gia' una busta `crypto_box` fra i due.
        //
        // 💡 Non stanno dentro il messaggio perche' una conversazione si carica
        // tutta insieme: con le foto dentro, aprire una chat con venti foto
        // vorrebbe dire scaricare otto megabyte ogni volta, anche solo per
        // rileggere una riga.
        //
        // ⚠️ Lo scarico e' per **token** e non per id: un id progressivo si
        // indovina, e chi ne conosce uno conosce anche il precedente.
        // Restituito il file, l'allegato viene **cancellato**.
        Route::post('conversations/{conversation}/allegati', [AllegatoCifratoController::class, 'store'])
            ->whereNumber('conversation')
            ->middleware('throttle:allegati-chat');
        Route::get('allegati/{token}', [AllegatoCifratoController::class, 'show'])
            ->where('token', '[A-Za-z0-9]{48}');

        // ── Le chiavi della chat (S6.3) ──────────────────────────────────
        //
        // 🚨 La chiave dell'altra persona si prende **attraverso la
        // conversazione**, non per id utente. Le chiavi sono pubbliche, ma
        // «chi ha una chiave in questo sistema» e' gia' un'informazione: dice
        // chi e' iscritto e chi ha aperto l'app. Una rubrica aperta la
        // regalerebbe.
        Route::put('chat-key', [ChatKeyController::class, 'store']);
        Route::get('conversations/{conversation}/key', [ChatKeyController::class, 'show'])->whereNumber('conversation');

        // ── Il pacchetto incartato della chiave maestra (S6.2) ────────────
        //
        // 🚨 Il server conserva byte che non sa leggere. La password di
        // recupero **non passa mai di qui**: la derivazione avviene sul
        // telefono, e se la password toccasse il server anche per un istante
        // l'intero schema non servirebbe a niente.
        Route::get('account/recovery-key', [RecoveryKeyController::class, 'show']);
        Route::put('account/recovery-key', [RecoveryKeyController::class, 'store']);

        // ── I consensi facoltativi (S9.1) ────────────────────────────────
        //
        // 🚨 Si danno **dopo** l'iscrizione, uno per uno. Chiederli al momento
        // della registrazione li trasformerebbe in condizioni per usare il
        // servizio, e un consenso che serve per iscriversi non è «liberamente
        // dato» (art. 7(4)) — cioè non è consenso.
        //
        // 🚨 Revocare costa esattamente quanto concedere: stessa chiamata,
        // `false` invece di `true` (art. 7(3)).
        Route::get('account/consents', [ConsentController::class, 'show']);
        Route::patch('account/consents', [ConsentController::class, 'update']);

        // 🆕 FASE 2-bis — «gliel'ho chiesto», che non e' «ha accettato».
        //
        // 🚨 Senza questa data, «non gliel'ho mai chiesto» e «ha detto no a
        // tutto» sono lo stesso stato — tre `null` — e chi rifiuta si vede
        // riproporre la domanda a ogni reinstallazione.
        Route::post('account/consents/chiesti', [ConsentController::class, 'segnaChiesti']);

        // ── Il fuso orario della persona (A3) ─────────────────────────────
        //
        // 🚨 Il server non può indovinarlo: l'IP dice dov'è la rete, l'offset
        // non distingue Roma d'estate da Helsinki d'inverno. Lo sa solo il
        // telefono, e lo manda a ogni avvio.
        //
        // `PUT` perché è idempotente: è lo stato del dispositivo, non una
        // modifica a qualcosa.
        Route::put('account/timezone', [TimezoneController::class, 'update']);

        /*
        | ── La citta' della persona (M1.2) ────────────────────────────────
        |
        | 🚨 Sta su `users` e non su `profiles`: `profiles` e' sotto consenso
        | per dati sanitari (art. 9), e la citta' non lo e'. Metterla li'
        | avrebbe voluto dire che chi revoca il consenso ai dati di salute
        | perde anche il campo con cui trova una palestra.
        |
        | ⚠️ `PUT` e non `PATCH` perche' si deve poter **azzerare**: la citta'
        | non e' obbligatoria e non lo diventera'. Con `PATCH`, «assente» e
        | «vuoto» sarebbero indistinguibili.
        */
        Route::get('account/citta', [CittaController::class, 'show']);
        Route::put('account/citta', [CittaController::class, 'update']);

        /*
        | ── L'immagine del profilo (M7.2) ─────────────────────────────────
        |
        | 🚨 **È l'unica immagine di una persona che sta sul server**, e la
        | ragione è che serve a farsi riconoscere da qualcun altro: un trainer
        | deve vedere la faccia di chi gli scrive. ⚠️ Le foto dei **progressi**
        | sono un'altra cosa e restano sul telefono (S5.4) — le rotte `photos`
        | sono state cancellate apposta.
        |
        | 💡 La differenza in una riga: questa la mostri, quelle le nascondi.
        */
        Route::post('account/avatar', [AvatarController::class, 'store']);
        Route::delete('account/avatar', [AvatarController::class, 'destroy']);

        Route::post('device-tokens', [DeviceTokenController::class, 'store']);
        Route::delete('device-tokens', [DeviceTokenController::class, 'destroy']);

        /*
        | ── Sonno e parametri vitali: NON CI SONO PIU' ────────────────────
        |
        | 🚨 Qui vivevano `GET health/sleep` e `POST health/ingest-token`.
        | Cancellate in S1.1 di `plan_security_and_retention.md`.
        |
        | Sonno, HRV e battito **restano sul telefono di chi li produce**
        | (decisione D9): il server non li riceve, non li conserva e non li
        | manda a nessun modello. Non e' una funzione persa, e' una funzione
        | traslocata nell'app — `lib/src/features/health/`.
        |
        | ⚠️ Chi volesse riaprirle deve prima leggere §C11 e §C12 di
        | `todo-2026-08-11.md`: il motivo per cui non ci sono non e' tecnico.
        */

        /*
        | Autorizzazione dei canali privati per l'APP (B8.2).
        |
        | 🚨 Serve una rotta a parte. Quella di serie — `POST /broadcasting/auth`
        | — sta nel gruppo `web`, cioe' sessione con cookie e protezione CSRF:
        | un client che si autentica con un **token bearer** non la supera, e la
        | chat via socket non si aprirebbe mai. Non darebbe un errore chiaro,
        | darebbe un 419 che sembra un problema di rete.
        |
        | Il gruppo qui e' gia' `auth:sanctum`, quindi l'utente c'e'; le callback
        | in `routes/channels.php` sono le stesse, e restano l'unico controllo
        | vero su chi ascolta cosa.
        */
        Route::post('broadcasting/auth', fn (Request $request) => Broadcast::auth($request));
    });

    /*
    | ── L'ingest dall'orologio non esiste piu' ────────────────────────────
    |
    | Qui c'era `POST health/ingest`, **l'unico endpoint scrivente fuori da
    | `auth:sanctum`**: si autenticava con `users.health_ingest_token` perche'
    | chi mandava i dati era un'automazione senza sessione.
    |
    | 🚨 Cancellato in S1.1 insieme alla colonna del token. Ed e' un guadagno
    | doppio: oltre a togliere i dati sanitari dal server, **toglie l'unica
    | superficie scrivente non autenticata da Sanctum** che il progetto avesse.
    */

});
