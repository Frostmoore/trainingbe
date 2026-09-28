<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InvitoInPalestra;
use App\Models\TrainerInvite;
use App\Models\User;
use App\Services\Auth\LaVerificaDellEmail;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * ✉️ **Gli inviti che ho ricevuto** — voce 62.6, 28/09/2026.
 *
 * ══ 📌 PERCHE' ESISTE, E COSA SBLOCCA ═════════════════════════════════════
 *
 * ⛔ Fino a oggi un invito si poteva accettare **solo dal link nella mail**:
 * esistevano `GET trainer/invites` e `GET gym/invites`, ma sono gli inviti
 * **mandati**, non quelli ricevuti. 🚨 Chi perdeva la mail non aveva nessuna
 * strada — e il DMARC del dominio è in `p=none` da ieri, quindi quelle mail
 * possono finire nello spam.
 *
 * 💡 Da qui l'app li può mostrare e farli accettare, e la notifica `Invito` può
 * finalmente portare **all'invito** invece che alla casa.
 *
 * ══ 🚨 PERCHE' PRETENDE L'EMAIL VERIFICATA ════════════════════════════════
 *
 * Questa rotta consegna **i codici degli inviti**, che sono credenziali monouse.
 * ⛔ Senza verifica, chi si registra con l'indirizzo di un altro si prenderebbe i
 * suoi inviti — mentre oggi, per rubarne uno, serve l'accesso alla **casella di
 * posta**.
 *
 * ⚖️ **È l'unica rotta del progetto con `verified`**, e deve restare l'unica: la
 * voce 63 dice *«NIENTE AL MONDO DEVE IMPEDIRE A UN UTENTE DI ACCEDERE»*. 💡 Qui
 * non si impedisce di accedere: si impedisce di **farsi dare una credenziale**.
 *
 * ══ ⚠️ E RISPONDE 403 CON UN CODICE CHE L'APP SA LEGGERE ══════════════════
 *
 * 🔑 Il 403 non è un vicolo cieco: porta `code: email_da_verificare`, e l'app
 * mostra il bottone «mandami la mail». ⛔ Un 403 muto qui sarebbe la cosa peggiore
 * — una funzione che non va e non dice perché.
 */
class InvitiRicevutiController extends Controller
{
    public function __construct(
        private readonly TenantContext $contesto,
        private readonly LaVerificaDellEmail $verifica,
    ) {}

    /**
     * 📋 Gli inviti ancora validi mandati **a questo indirizzo**.
     */
    public function elenco(Request $request): JsonResponse
    {
        $persona = $request->user();

        if (! $persona instanceof User) {
            return response()->json(['message' => __('Non autenticato.')], 401);
        }

        $email = (string) $persona->email;

        if ($email === '') {
            return response()->json(['data' => []]);
        }

        /*
         * ⚠️ `runWithoutTenant`: un invito **arriva da un'altra palestra**, per
         * definizione. 🚨 Cercarlo nel contesto di chi guarda vorrebbe dire non
         * trovarne nessuno — che è il difetto più silenzioso possibile, perché
         * «zero inviti» è una risposta plausibile.
         */
        $inviti = $this->contesto->runWithoutTenant(function () use ($email): array {
            $righe = [];

            foreach ($this->daPalestra($email) as $i) {
                $righe[] = $this->scheda($i, 'palestra');
            }

            foreach ($this->daTrainer($email) as $i) {
                $righe[] = $this->scheda($i, 'trainer');
            }

            return $righe;
        });

        /*
         * 📅 Prima quelli che scadono prima: ⚖️ chi ne ha due deve vedere per
         * primo quello che perde per primo.
         */
        usort($inviti, static fn (array $a, array $b): int => $a['scade_il'] <=> $b['scade_il']);

        return response()->json(['data' => $inviti]);
    }

    /**
     * 📧 «Mandami (di nuovo) la mail di conferma».
     *
     * ⚠️ Risponde **sempre 200**, anche se non manda niente: ⛔ dire «non l'ho
     * mandata perché sei già verificato» va bene, ma qui la chiama la **propria**
     * persona autenticata, quindi non c'è niente da nascondere — e il 200 con un
     * campo che dice cosa è successo è più facile da usare di due codici diversi.
     */
    public function chiediLaConferma(Request $request): JsonResponse
    {
        $persona = $request->user();

        if (! $persona instanceof User) {
            return response()->json(['message' => __('Non autenticato.')], 401);
        }

        $mandata = $this->verifica->manda($persona);

        return response()->json([
            'data' => [
                'mandata' => $mandata,
                'gia_verificata' => $persona->hasVerifiedEmail(),
            ],
        ]);
    }

    /**
     * 🏋️ Gli inviti di una palestra, ancora buoni.
     *
     * 🔑 **`validi()` e non quattro `where` scritti qui**: le condizioni che
     * rendono buono un invito stanno in `EUnInvito`, in un posto solo. ⛔ Il suo
     * commento lo dice: *«ripeterle nei controller vorrebbe dire che il giorno in
     * cui se ne aggiunge una quinta bisogna ricordarsi di tutti i punti»* — e una
     * dimenticata non dà nessun errore, dà un invito che funziona quando non
     * dovrebbe.
     *
     * @return Collection<int, InvitoInPalestra>
     */
    private function daPalestra(string $email): Collection
    {
        return InvitoInPalestra::query()
            ->withoutGlobalScopes()
            ->validi()
            ->where('email', $email)
            ->with('palestra')
            ->get();
    }

    /**
     * 🧑‍🏫 Gli inviti di un trainer, ancora buoni.
     *
     * @return Collection<int, TrainerInvite>
     */
    private function daTrainer(string $email): Collection
    {
        return TrainerInvite::query()
            ->withoutGlobalScopes()
            ->validi()
            ->where('email', $email)
            ->with('trainer')
            ->get();
    }

    /**
     * 🧾 La scheda di un invito, come la vede l'app.
     *
     * ══ 🔑 IL CODICE C'E', E QUI E' GIUSTO ════════════════════════════════
     *
     * 🚨 Il codice dell'invito è una credenziale, e **non viaggia in una
     * notifica** (vedi `Notifica::destinazione()`). ⚖️ Qui invece sì: lo chiede
     * la persona autenticata **e con l'indirizzo verificato**, cioè il
     * destinatario legittimo — è la stessa cosa che troverebbe nella sua posta.
     *
     * ⛔ **E niente di più del necessario**: chi invita si mostra col nome, non
     * con la sua email — quella è un dato di un'altra persona.
     */
    private function scheda(InvitoInPalestra|TrainerInvite $invito, string $tipo): array
    {
        return [
            'tipo' => $tipo,
            'codice' => $invito->token,
            'da' => $invito instanceof InvitoInPalestra
                ? ($invito->palestra?->name ?? __('Una palestra'))
                : ($invito->trainer?->name ?? __('Un trainer')),
            'scade_il' => $invito->expires_at?->toIso8601String(),
        ];
    }
}
