<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Billing\Exceptions\AbbonamentoTrainerScadutoException;
use App\Services\Billing\PianoAttivo;
use App\Services\Tenancy\ChiSiVede;
use App\Services\Tenancy\InvitiDelTrainer;
use App\Services\Tenancy\LoSlotDelTrainer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * «I miei utenti» — F5.1 e F6 della Parte B.
 *
 * ── ⚠️ Perché sta nell'app e non nel pannello web ──────────────────────────
 *
 * Decisione D6, e la linea è la stessa di C13: **il modello sta sul web,
 * l'istanza assegnata sta sull'app.** Comporre una scheda o un piano a sei pasti
 * è lavoro da schermo grande; assegnarla a una persona è l'unico momento che
 * tocca dati personali, e deve passare dal canale cifrato — che vive sul
 * telefono.
 *
 * 💡 *Modello = server = web*, *istanza assegnata = telefono = app.* Una sola
 * regola per due domande.
 *
 * 🚨 **Ogni rotta qui dentro pretende un ruolo che gestisce utenti.** Non basta
 * `auth:sanctum`: senza il controllo, qualunque iscritto potrebbe invitare
 * persone nel proprio spazio e diventare un trainer di fatto.
 */
class TrainerController extends Controller
{
    public function __construct(
        private readonly InvitiDelTrainer $inviti,
        private readonly ChiSiVede $chiSiVede,
    ) {}

    /** Gli utenti che questo trainer segue. */
    public function members(Request $request): JsonResponse
    {
        $trainer = $this->trainerOrAbort($request);

        return response()->json([
            /*
             * ══ 🆕 NON SOLO I SUOI: ANCHE QUELLI DELLA PALESTRA — 15/09 ═════
             *
             * ⛔ **Qui c'era solo `assignedMembers()`**, cioe' i rapporti
             * uno-a-uno di `trainer_member`. Per un trainer di palestra quella
             * tabella e' vuota e resta vuota: nessuno crea quelle righe.
             *
             * 🚨 Il risultato, provato su staging il 15/09: in una palestra
             * con tre iscritti questa rotta rispondeva `[]`. **Un trainer che
             * non vede nessuno.**
             *
             * 💡 La regola sta tutta in `ChiSiVede`, insieme al suo perche'.
             */
            'data' => $this->chiSiVede->allieviDi($trainer)->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar_url' => $u->avatarUrl(),

                /*
                 * 💡 Il pivot dice se il rapporto è sospeso: l'app deve poter
                 * mostrare «disattivato» invece di far sparire la persona.
                 *
                 * 🆕 ⚠️ **Gli iscritti della palestra non hanno nessun
                 * pivot**: non c'è nessun rapporto uno-a-uno da sospendere, sono
                 * lì perché sono iscritti lì. ⛔ Senza questo controllo
                 * `$u->pivot` è `null` e la rotta lancia — cioè la schermata
                 * passerebbe da «vuota» a «rotta».
                 */
                'attivo' => $u->pivot?->disattivato_il === null,

                /*
                 * 🆕 **Il rapporto è tuo, o è della palestra?** — 16/09/2026.
                 *
                 * 🚨 L'app mette su ogni riga il pulsante «chiudi la
                 * conversazione», che chiama `toggleMember()` — e quello cerca
                 * il rapporto in `trainer_member`. ⛔ Per un iscritto della
                 * palestra quella riga non c'è: il pulsante rispondeva **404
                 * «Persona non trovata»** su una persona lì in elenco.
                 *
                 * ⚠️ E il rifiuto è **giusto**: quel rapporto non è suo da
                 * chiudere. Lo decide la palestra, che gliel'ha affidata. Ciò
                 * che era sbagliato è **offrire il gesto** e poi negarlo.
                 */
                'suo' => $u->pivot !== null,
            ])->all(),

            /*
             * ⚠️ **I posti rimasti stanno qui e non in un endpoint suo.**
             * L'app deve poter disabilitare il pulsante «invita» *prima* che
             * qualcuno lo prema: scoprire il limite dopo aver compilato un
             * modulo è il modo di far sembrare rotto un vincolo commerciale.
             */
            'meta' => [
                /*
                 * 🆕 **Se questa persona può avere allievi suoi** — 15/09/2026.
                 *
                 * 📌 Il committente: *«un trainer di una palestra non serve che
                 * veda il banner utenti illimitati, e un trainer indipendente
                 * (anche se lavora in una palestra) deve vederlo con il numero
                 * di allievi possibili per il suo piano»*.
                 *
                 * ⛔ Senza questo campo l'app non può distinguere i due casi:
                 * per un trainer di sola palestra il limite è `null`, che l'app
                 * leggeva come «illimitati» — mentre la verità è che di posti
                 * suoi non ne ha **nessuno**, perché gli iscritti li paga la
                 * palestra.
                 */
                'allievi_propri' => $this->inviti->puoAvereAllieviSuoi($trainer),
                'posti_rimasti' => $this->inviti->postiRimasti($trainer),
                'limite' => $this->inviti->limiteDelPiano($trainer),
                'piano' => $this->inviti->codicePiano($trainer),
            ],
        ]);
    }

    /**
     * 🆕 📋 **Gli inviti in sospeso** — B1.1, 24/09/2026.
     *
     * ══ 🚨 PERCHE' NON C'ERA, E COSA COSTAVA ════════════════════════════════
     *
     * 📌 Il committente, il 23/09: *«trainer e palestre possono proprio fare gli
     * inviti direttamente dall'app… devono poter creare il link di invito e
     * inviarlo per mail o copiarlo»*.
     *
     * ⛔ **Un invito creato dall'app spariva.** Il link finiva negli appunti e
     * da quel momento non esisteva più in nessuna schermata: non si rivedeva,
     * non si ricopiava, non si revocava. ⚠️ Se prima di incollarlo si copiava
     * qualcos'altro, **quel posto restava occupato sette giorni** — perché
     * `postiRimasti()` conta anche gli inviti validi, ed è giusto che lo faccia.
     * 🚨 Su un piano da 15 posti è il **7% del pagato**, perso per un tocco.
     *
     * ⚠️ **Solo i validi**, e non è pigrizia: la domanda che fa l'app è *«cosa
     * ho in sospeso?»*. ⛔ Usati e scaduti non servono a chi deve decidere se
     * revocare, e su un telefono riempirebbero la lista sopra le due righe che
     * contano.
     *
     * 💡 I posti tornano **in questa stessa risposta**: chi guarda l'elenco per
     * revocare sta guardando il contatore, e farglielo chiedere con una seconda
     * chiamata vorrebbe dire mostrargli per un istante il numero di prima.
     */
    public function invites(Request $request): JsonResponse
    {
        $trainer = $this->trainerOrAbort($request);

        /*
         * 🚨 `withoutGlobalScopes()` e filtro esplicito sul trainer: il modello
         * è scopato per tenant, e un trainer indipendente **vive in uno spazio
         * diverso** da quello di certi suoi allievi (F6.1). Con lo scope attivo
         * l'elenco sarebbe silenziosamente parziale.
         */
        $inviti = TrainerInvite::withoutGlobalScopes()
            ->where('trainer_id', $trainer->getKey())
            ->validi()
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $inviti->map(fn (TrainerInvite $i): array => [
                'id' => $i->getKey(),
                'token' => $i->token,
                'url' => self::linkDi($i),
                'email' => $i->email,

                /*
                 * 💡 **Una data, non un sì/no**: «mandata il 24/09 alle 14:03»
                 * è una risposta, «mandata: sì» è metà risposta — e quando un
                 * invito viene rimandato a un altro indirizzo, la data dice
                 * qual è l'ultimo invio.
                 */
                'email_inviata_il' => $i->email_inviata_il?->toIso8601String(),
                'creato_il' => $i->created_at?->toIso8601String(),
                'scade_il' => $i->expires_at->toIso8601String(),
            ])->values(),

            'meta' => $this->inviti->iPosti($trainer),
        ]);
    }

    /** Crea un invito monouso. */
    public function invite(Request $request): JsonResponse
    {
        $trainer = $this->trainerOrAbort($request);

        $dati = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $invito = $this->inviti->invita($trainer, $dati['email'] ?? null);

        /*
         * ══ 🚨 E LA MAIL? PARTE GIA', DENTRO `invita()` — B1.2, 24/09/2026 ══
         *
         * ⚠️ **Qui il 24/09 avevo scritto un secondo invio**, convinto che
         * l'API salvasse l'indirizzo senza mandare niente. ⛔ Falso: avevo letto
         * `InvitiDelTrainer::invita()` solo fino a metà, e in fondo chiama
         * `mandaIlLink()` dal 22/09. Il mio «rimedio» mandava **due mail** per
         * un invito solo, e l'ha beccato un test che pretendeva silenzio.
         *
         * 💡 **Quello che mancava davvero non era l'invio: era saperne
         * l'esito.** `mandaIlLink()` può fallire — e quando fallisce l'invito
         * resta valido di proposito — ma il risultato viveva solo nel valore di
         * ritorno, cioè per la durata di una richiesta.
         *
         * 🔑 Adesso l'invio si segna in `trainer_invites.email_inviata_il`, e
         * questa risposta si limita a **leggerlo**: una verità sola, in tabella,
         * che l'elenco può rileggere anche domani.
         */
        $invito->refresh();

        return response()->json([
            'data' => [
                'id' => $invito->getKey(),
                'token' => $invito->token,
                'email' => $invito->email,
                'email_inviata' => $invito->email_inviata_il !== null,
                'expires_at' => $invito->expires_at->toIso8601String(),
                'url' => self::linkDi($invito),
            ],
        ], 201);
    }

    /**
     * Il link da mandare, per questo invito.
     *
     * 🚨 **Si compone sul server, da `config('app.url')`.** Lasciarlo comporre
     * all'app vorrebbe dire che il giorno in cui cambia il dominio bisogna
     * pubblicare una versione nuova sugli store per farlo cambiare — e nel
     * frattempo ogni invito mandato punterebbe da nessuna parte.
     *
     * 💡 🆕 Ed è un metodo e non una riga ripetuta, da quando gli inviti si
     * **elencano** (B1.1): due composizioni dello stesso link divergono, e
     * divergendo mandano gente su un indirizzo che non esiste.
     */
    private static function linkDi(TrainerInvite $invito): string
    {
        return rtrim((string) config('app.url'), '/').'/invito/'.$invito->token;
    }

    /**
     * 🆕 📧 **Rimanda per email un invito già creato** — B1.3, 24/09/2026.
     *
     * 📌 Serve alla pagina «Inviti in sospeso»: un invito mandato a un indirizzo
     * sbagliato, o finito nello spam, oggi si poteva solo revocare e rifare —
     * e rifarlo **consuma un altro posto** finché il primo non scade.
     *
     * ⚠️ L'email è **facoltativa**: se manca si usa quella con cui l'invito era
     * nato. ⛔ Se non ce n'è nessuna delle due si risponde **422**: un
     * destinatario non si inventa.
     *
     * 🚨 **Solo su inviti validi.** Rimandare un invito revocato o scaduto
     * vorrebbe dire mandare a una persona un link che non funziona, e farle
     * credere che l'app sia rotta.
     */
    public function rimandaInvito(Request $request, int $invite): JsonResponse
    {
        $trainer = $this->trainerOrAbort($request);

        $dati = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $invito = TrainerInvite::withoutGlobalScopes()
            ->where('trainer_id', $trainer->getKey())
            ->whereKey($invite)
            ->first();

        if ($invito === null) {
            return response()->json(['message' => __('Invito non trovato.')], 404);
        }

        if (! $invito->eValido()) {
            return response()->json([
                'message' => __('Questo invito non è più valido: creane uno nuovo.'),
            ], 409);
        }

        $destinatario = $dati['email'] ?? $invito->email;

        if ($destinatario === null || $destinatario === '') {
            return response()->json([
                'message' => __('Scrivi a quale indirizzo mandarlo.'),
                'errors' => ['email' => [__('Scrivi a quale indirizzo mandarlo.')]],
            ], 422);
        }

        /*
         * ⚠️ L'indirizzo nuovo si **scrive sull'invito**, non si usa e basta:
         * altrimenti l'elenco continuerebbe a mostrare il vecchio, cioè una
         * riga che dice a chi è stato mandato l'invito — sbagliando.
         */
        if ($destinatario !== $invito->email) {
            $invito->forceFill(['email' => $destinatario])->save();
        }

        $inviata = $this->inviti->mandaIlLink($invito, $trainer);

        return response()->json([
            'data' => [
                'id' => $invito->getKey(),
                'email' => $invito->email,
                'email_inviata' => $inviata,
                'email_inviata_il' => $invito->fresh()?->email_inviata_il?->toIso8601String(),
            ],
        ]);
    }

    public function revokeInvite(Request $request, int $invite): JsonResponse
    {
        $trainer = $this->trainerOrAbort($request);

        $invito = TrainerInvite::withoutGlobalScopes()
            ->where('trainer_id', $trainer->getKey())
            ->whereKey($invite)
            ->first();

        if ($invito === null) {
            return response()->json(['message' => __('Invito non trovato.')], 404);
        }

        // 🆕 F10.1: la riga sta in `InvitiDelTrainer::revoca()`, perché dal
        //    23/09 revoca anche il pannello. Una regola, una sede.
        $this->inviti->revoca($invito);

        return response()->json(['message' => __('Invito revocato.')]);
    }

    /**
     * Sospende o riattiva il rapporto con un utente — F6.4, D5.
     *
     * 🚨 **Chiude solo i messaggi.** Il legame resta, la storia si conserva.
     * ⚠️ E i piani **già ricevuti** non si possono revocare: vivono sul telefono
     * e il server non può togliere ciò che non ha mai avuto.
     */
    public function toggleMember(Request $request, int $member): JsonResponse
    {
        $trainer = $this->trainerOrAbort($request);

        $utente = $trainer->assignedMembers()->where('users.id', $member)->first();

        if ($utente === null) {
            return response()->json(['message' => __('Persona non trovata.')], 404);
        }

        /*
         * 🆕 ⛔ **Un posto liberato non si riapre da qui** — 22/09/2026 (Q-A5-2).
         * 📌 *«se vuole reinvitarlo gli deve mandare un link di invito»*: è
         * l'allievo che accetta, non il trainer che decide per tutti e due.
         */
        if (app(LoSlotDelTrainer::class)->haLiberatoIlPosto((int) $trainer->getKey(), (int) $utente->getKey())) {
            return response()->json([
                'message' => __('Hai liberato il suo posto: per seguirlo di nuovo mandagli un invito.'),
                'code' => 'posto_liberato',
            ], 409);
        }

        $attivo = $utente->pivot->disattivato_il === null;

        $attivo
            ? $this->inviti->disattiva($trainer, $utente)
            : $this->inviti->riattiva($trainer, $utente);

        return response()->json([
            'data' => ['attivo' => ! $attivo],
            'message' => $attivo
                ? __('Non potrete più scrivervi. La storia resta, e puoi riattivarlo quando vuoi.')
                : __('Potete scrivervi di nuovo.'),
        ]);
    }

    /**
     * 🚨 Chi può gestire utenti: un trainer di palestra o uno indipendente.
     *
     * ⚠️ **Non un `GymAdmin`**, e non è una svista: l'amministratore di una
     * palestra assegna gli iscritti ai suoi trainer dal pannello, dove ha la
     * vista d'insieme. Qui si gestisce il **proprio** rapporto con qualcuno,
     * che è un'altra cosa.
     */
    private function trainerOrAbort(Request $request): User
    {
        $utente = $request->user();

        /*
         * 🐛 `allena()` e non `isTrainer() || isFreeTrainer()` — giro A6,
         * 22/09/2026: qui il contesto è la casa, e la casa di un trainer di
         * palestra è il suo spazio personale. Il perché sta su `User::allena()`.
         */
        abort_unless(
            $utente instanceof User && $utente->allena(),
            403,
            __('Questa sezione è per chi segue altre persone.'),
        );

        /*
         * ── ⏰ E l'abbonamento deve essere in piedi — U.3.1 ─────────────────
         *
         * 📌 *«Quando gli scade l'abbonamento, perde tutte le funzionalità da
         * trainer e può solo agire come un utente normale»*.
         *
         * 🚨 **Il ruolo non scade, l'abbonamento sì.** Fino a U.3 qui si
         * guardava solo `isTrainer()`, e un trainer che smetteva di pagare
         * continuava a vedere i suoi utenti, invitarne di nuovi e mandare
         * schede — per sempre, senza nessun errore da nessuna parte. La porta
         * era rimasta aperta perché nessuno le aveva mai messo una serratura.
         *
         * 🐛 **`puoAllenare()` e non `eAbbonato()`** — giro A6, 22/09/2026.
         * Qui c'era scritto che `eAbbonato()` guardava la palestra per un
         * trainer di palestra: era vero finché lui **viveva** nella palestra.
         * Dal 18/09 vive nel suo spazio, e `eAbbonato()` guarda quello, vuoto.
         * `puoAllenare()` guarda il suo piano da indipendente **oppure** una
         * palestra attiva dove allena. ⛔ La fascia trainer gratuita **non
         * esiste** (committente, 22/09/2026): un abbonamento vecchio a
         * `trainer_free` non vale, e lo esclude già `PianoAttivo::per()`.
         *
         * ⛔ **Quello che NON si tocca sono le sue schede.** Restano sue e
         * continua a vederle da utente: sono lavoro suo, ed è la stessa regola
         * già scritta per `AccountEraser` e per `EsciDaUnaPalestra`.
         */
        if (! app(PianoAttivo::class)->puoAllenare($utente)) {
            /*
             * 🚨 **Un'eccezione con un CODICE, non un `abort(403, 'frase')`.**
             *
             * 📌 *«è una merda se esce solo un errore, si deve capire che è
             * perché non ha pagato»* — il committente, 29/08.
             *
             * ⛔ Con la sola frase, l'app dovrebbe **riconoscere il testo** per
             * capire cos'è successo, e basterebbe una virgola corretta un
             * giorno per farla tornare all'errore generico — senza che nessun
             * test se ne accorga. Il perché per esteso sta sull'eccezione.
             */
            throw new AbbonamentoTrainerScadutoException;
        }

        return $utente;
    }

    /**
     * 🆕 🎟️ **Le decisioni sullo slot che questo trainer deve ancora
     * prendere** — AH.1-ter.6, 18/09/2026.
     *
     * 📌 Il committente aveva chiesto *«un modo di chiedere al trainer, che al
     * momento del passaggio non e' presente»*: e' questo. L'app lo chiama
     * all'apertura e mostra la modale se c'e' qualcosa.
     *
     * ⛔ **Non c'e' nessuna tabella di domande in sospeso**: le domande *sono*
     * i rapporti sospesi senza risposta. Uno stato duplicato si disallinea da
     * quello che descrive, e allora si chiede due volte o non si chiede.
     */
    public function slotDaDecidere(Request $request): JsonResponse
    {
        $trainer = $this->trainerOrAbort($request);

        return response()->json([
            'data' => app(LoSlotDelTrainer::class)->daDecidere($trainer)->map(
                fn (object $r): array => [
                    'member_id' => (int) $r->member_id,
                    'nome' => $r->nome,
                    'palestra' => $r->palestra,
                    'sovrapposto_dal' => $r->sovrapposto_dal,
                ],
            )->values(),
        ]);
    }

    /**
     * 🆕 Il trainer risponde: **tengo** lo slot o lo **libero**.
     *
     * 📌 *«se il trainer decide di mantenere lo slot occupato, l'allievo passa
     * alla palestra, poi se si dissocia dalla palestra torna al trainer. Se il
     * trainer decide di liberare lo slot […] non torna al trainer.»*
     *
     * 🚨 **La scelta si dichiara sempre**, e non ha un valore di serie: e' una
     * decisione che costa soldi in un verso e un allievo nell'altro, e un
     * default sarebbe deciderla al posto suo.
     */
    public function decidiLoSlot(Request $request, int $member): JsonResponse
    {
        $trainer = $this->trainerOrAbort($request);

        $dati = $request->validate([
            'tengo_lo_slot' => ['required', 'boolean'],
        ]);

        $allievo = User::withoutGlobalScopes()->find($member);

        if ($allievo === null) {
            return response()->json(['message' => __('Questa persona non esiste.')], 404);
        }

        $fatto = app(LoSlotDelTrainer::class)->decidi(
            $trainer,
            $allievo,
            (bool) $dati['tengo_lo_slot'],
        );

        if (! $fatto) {
            /*
             * ⚠️ **409 e non 404**: la persona esiste e il rapporto pure, ma non
             * c'e' niente da decidere — o e' gia' stato deciso, o non e' mai
             * stato sospeso. 💡 Un 404 farebbe cercare all'app una persona che
             * c'e'.
             */
            return response()->json([
                'message' => __('Su questa persona non ci sono scelte da fare.'),
            ], 409);
        }

        return response()->json(['data' => ['deciso' => true]]);
    }

    /** @return array<int, string> i ruoli ammessi, per i test e per la documentazione */
    public static function ruoliAmmessi(): array
    {
        return [UserRole::Trainer->value, UserRole::FreeTrainer->value];
    }
}
