<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Billing\PianoAttivo;
use App\Services\Tenancy\InvitiDelTrainer;
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
    public function __construct(private readonly InvitiDelTrainer $inviti) {}

    /** Gli utenti che questo trainer segue. */
    public function members(Request $request): JsonResponse
    {
        $trainer = $this->trainerOrAbort($request);

        return response()->json([
            'data' => $trainer->assignedMembers()->get()->map(fn (User $u): array => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'avatar_url' => $u->avatarUrl(),

                // 💡 Il pivot dice se il rapporto è sospeso: l'app deve poter
                // mostrare «disattivato» invece di far sparire la persona.
                'attivo' => $u->pivot->disattivato_il === null,
            ])->all(),

            /*
             * ⚠️ **I posti rimasti stanno qui e non in un endpoint suo.**
             * L'app deve poter disabilitare il pulsante «invita» *prima* che
             * qualcuno lo prema: scoprire il limite dopo aver compilato un
             * modulo è il modo di far sembrare rotto un vincolo commerciale.
             */
            'meta' => [
                'posti_rimasti' => $this->inviti->postiRimasti($trainer),
                'limite' => $this->inviti->limiteDelPiano($trainer),
                'piano' => $this->inviti->codicePiano($trainer),
            ],
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

        return response()->json([
            'data' => [
                'token' => $invito->token,
                'expires_at' => $invito->expires_at->toIso8601String(),

                /*
                 * 🚨 Il link si costruisce **sul server**, da `config('app.url')`.
                 * Lasciarlo comporre all'app vorrebbe dire che il giorno in cui
                 * cambia il dominio bisogna pubblicare una versione nuova sugli
                 * store per farlo cambiare — e nel frattempo ogni invito
                 * mandato punterebbe da nessuna parte.
                 */
                'url' => rtrim((string) config('app.url'), '/').'/invito/'.$invito->token,
            ],
        ], 201);
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

        $invito->forceFill(['revoked_at' => now()])->save();

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

        abort_unless(
            $utente instanceof User
                && ($utente->isTrainer() || $utente->isFreeTrainer()),
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
         * 💡 `eAbbonato()` risponde bene a tutti e due i casi senza doverli
         * distinguere: per un trainer di palestra guarda l'abbonamento **della
         * palestra**, che è chi paga per lui; per un indipendente guarda il
         * suo. ⚠️ E la fascia trainer gratuita resta dentro: non è scaduta, è
         * gratis, ed è un tier vero da tre allievi.
         *
         * ⛔ **Quello che NON si tocca sono le sue schede.** Restano sue e
         * continua a vederle da utente: sono lavoro suo, ed è la stessa regola
         * già scritta per `AccountEraser` e per `EsciDaUnaPalestra`.
         */
        abort_unless(
            app(PianoAttivo::class)->eAbbonato($utente),
            403,
            __('Il tuo abbonamento da trainer è scaduto. Le tue schede restano tue: puoi continuare a usarle come chiunque altro.'),
        );

        return $utente;
    }

    /** @return array<int, string> i ruoli ammessi, per i test e per la documentazione */
    public static function ruoliAmmessi(): array
    {
        return [UserRole::Trainer->value, UserRole::FreeTrainer->value];
    }
}
