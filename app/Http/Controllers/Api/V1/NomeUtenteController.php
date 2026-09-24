<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\ChiPossoCercare;
use App\Services\Account\IlNomeUtente;
use App\Services\Chat\ChiHoBloccato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🆔 **Il nome utente come nome pubblico** — blocco B3, 24/09/2026.
 *
 * 📌 Il committente, il 23/09: *«bisognerà inventarsi un sistema di username
 * per poter scrivere a chiunque, così una palestra o un trainer possono
 * invitare direttamente un allievo o un trainer da app a app»*. Le cinque
 * decisioni che lo governano sono del 24/09 (`memory/decisioni.md`).
 *
 * ══ 🚨 TRE COSE CHE STANNO INSIEME, E NON PER COMODITA' ═════════════════════
 *
 * | Cosa | Decisione |
 * |---|---|
 * | scegliere e cambiare il nome | 53.5 — facoltativo, una volta ogni 30 giorni |
 * | **farsi trovare**, o no | 53.1 — ⛔ spento di serie |
 * | **cercare** qualcuno | 53.2 — un professionista lo trova chiunque, un iscritto **solo i suoi** |
 *
 * 💡 Stanno nello stesso posto perché sono **la stessa domanda vista da due
 * lati**: «mi faccio trovare?» e «chi trovo?». ⚠️ Separarle vorrebbe dire due
 * file che si rimandano a vicenda ogni volta che una regola cambia.
 */
class NomeUtenteController extends Controller
{
    public function __construct(
        private readonly IlNomeUtente $nomi,
        private readonly ChiPossoCercare $ricerca,
        private readonly ChiHoBloccato $blocchi,
    ) {}

    /**
     * Com'è messo il mio nome pubblico.
     *
     * 💡 Torna anche **quando potrò cambiarlo**: l'app deve poter spegnere il
     * campo *prima* che qualcuno scriva un nome nuovo, invece di rifiutarlo
     * dopo. ⛔ Scoprire un limite dopo aver compilato un modulo è il modo di far
     * sembrare rotto un vincolo.
     */
    public function mio(Request $request): JsonResponse
    {
        $utente = $request->user();
        $quando = $this->nomi->quandoPuoCambiare($utente);

        return response()->json(['data' => [
            'username' => $utente->username,
            'trovabile' => (bool) $utente->trovabile,
            'puo_cambiare' => $quando === null,
            'potra_cambiare_dal' => $quando?->toIso8601String(),
        ]]);
    }

    /**
     * Sceglie o cambia il nome utente.
     *
     * 🚨 Tutta la regola sta in `IlNomeUtente::cambia()`: la finestra dei 30
     * giorni, il formato, l'unicità e il fatto che il **vecchio si brucia**.
     * ⛔ Qui non si ripete niente, o il giorno che una di quelle cambia
     * resterebbe una copia a dire il contrario.
     */
    public function cambia(Request $request): JsonResponse
    {
        $utente = $request->user();

        $dati = $request->validate([
            'username' => array_merge(['required'], IlNomeUtente::regole($utente)),
        ]);

        $this->nomi->cambia($utente, $dati['username']);

        return $this->mio($request);
    }

    /**
     * 🔦 **L'interruttore «fatti trovare»** — decisione 53.1.
     *
     * ⛔ Nasce **spento**, e questa rotta è l'unico modo di accenderlo: chi si
     * allena in palestra non si è iscritto per farsi cercare.
     *
     * ⚠️ **Accendere senza un nome non si può**: sarebbe un interruttore che
     * non fa niente, e chi lo accende crederebbe di essere trovabile.
     */
    public function trovabile(Request $request): JsonResponse
    {
        $utente = $request->user();

        $dati = $request->validate(['trovabile' => ['required', 'boolean']]);

        if ($dati['trovabile'] === true && ($utente->username === null || $utente->username === '')) {
            return response()->json([
                'message' => __('Prima scegli un nome utente: è quello con cui ti trovano.'),
                'code' => 'serve_il_nome_utente',
            ], 422);
        }

        $utente->forceFill(['trovabile' => $dati['trovabile']])->save();

        return $this->mio($request);
    }

    /**
     * 🔎 **Cerca una persona per nome utente.**
     *
     * 🚨 Il cancello sta tutto in `ChiPossoCercare`, e non una riga qui: è la
     * stessa lezione di `ChiPossoSeguire` (atlante be §146.5), dove la stessa
     * domanda scritta in tre posti era sbagliata in due — e ogni volta spariva
     * qualcuno **in silenzio**. ⚠️ Qui sbagliare è peggio: non nasconde una
     * persona, ne **espone** una che non voleva essere trovata.
     */
    public function cerca(Request $request): JsonResponse
    {
        $utente = $request->user();

        $dati = $request->validate([
            'nome' => ['required', 'string', 'min:3', 'max:30'],
        ]);

        $trovati = $this->ricerca
            ->query($utente, $dati['nome'])
            ->orderBy('username')
            ->limit(ChiPossoCercare::QUANTI_AL_MASSIMO)
            ->get();

        return response()->json([
            'data' => $trovati->map(fn (User $u): array => [
                'id' => $u->getKey(),
                'username' => $u->username,
                'nome' => $u->name,

                /*
                 * ⚠️ **Niente email, niente telefono, niente palestra.** Una
                 * ricerca deve bastare a **riconoscere** una persona, non a
                 * saperne di più di quanto se ne sapesse prima.
                 */
                'avatar' => $u->avatarUrl(),
            ])->values(),
        ]);
    }

    /**
     * 🚫 **Blocca una persona** — decisione 53.3.
     *
     * ⛔ Fa **due** cose: non può più scriverti **e non ti trova più**. Senza la
     * seconda, il blocco sarebbe una porta chiusa accanto a una aperta.
     */
    public function blocca(Request $request, int $utente): JsonResponse
    {
        $io = $request->user();
        $altro = User::withoutGlobalScopes()->find($utente);

        if ($altro === null) {
            return response()->json(['message' => __('Questa persona non esiste.')], 404);
        }

        $this->blocchi->blocca($io, $altro, $request->string('motivo')->toString() ?: null);

        return response()->json(['data' => ['bloccato' => true]]);
    }

    /**
     * Sblocca.
     *
     * ⚠️ **Non riapre niente**: una conversazione chiusa resta chiusa e il
     * limite dei tre messaggi riparte da dove era. 💡 Sbloccare vuol dire «può
     * riprovare», non «ricominciamo».
     */
    public function sblocca(Request $request, int $utente): JsonResponse
    {
        $io = $request->user();
        $altro = User::withoutGlobalScopes()->find($utente);

        if ($altro === null) {
            return response()->json(['message' => __('Questa persona non esiste.')], 404);
        }

        $this->blocchi->sblocca($io, $altro);

        return response()->json(['data' => ['bloccato' => false]]);
    }

    /** Chi ho bloccato. */
    public function bloccati(Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->blocchi->elenco($request->user())->map(fn (User $u): array => [
                'id' => $u->getKey(),
                'nome' => $u->name,
                'username' => $u->username,
            ])->values(),
        ]);
    }
}
