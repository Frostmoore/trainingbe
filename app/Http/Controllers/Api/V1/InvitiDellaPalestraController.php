<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserRole;
use App\Http\Controllers\Controller;
use App\Models\InvitoInPalestra;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\InvitiInPalestra;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * 🏋️ **Gli inviti di una palestra, dall'app** — blocco B2, 24/09/2026.
 *
 * 📌 Il committente, il 23/09: *«trainer e palestre possono proprio fare gli
 * inviti direttamente dall'app… devono poter creare il link di invito e
 * inviarlo per mail o copiarlo»*. B1 ha fatto la metà del **trainer**; questa è
 * la metà della **palestra**, ed è l'ultimo pezzo della voce 11.
 *
 * ══ 🔗 E' LA GEMELLA DI `TrainerController`, MA NON LA SUA COPIA ═══════════
 *
 * 💡 Quello che è uguale **non è scritto due volte**: la forma di una riga e la
 * composizione del link stanno su `EUnInvito` (`perLApp()`, `link()`), dove già
 * stavano le regole di validità, e per la stessa ragione — due copie divergono
 * al primo ritocco, e quella che diverge non dà nessun errore.
 *
 * 🚨 **Le differenze vere sono tre, e sono tutte di prodotto:**
 *
 * | | Trainer (B1) | Palestra (B2) |
 * |---|---|---|
 * | chi si invita | una persona, e basta | **un iscritto oppure un trainer** |
 * | i posti | uno solo | **due**, contati a parte |
 * | la mail | parte dentro `invita()` | ⚠️ **la manda chi chiama** |
 *
 * ⚠️ **La terza è quella che frega**, e la scrivo qui perché non si scopra
 * rileggendo: `InvitiDelTrainer::invita()` spedisce da solo, mentre
 * `InvitiInPalestra::invita()` **crea e basta** — finora la mail la mandava il
 * pannello. ⛔ Chi legge il gemello e copia si ritrova un invito che non arriva
 * a nessuno e nessun errore da nessuna parte. È l'errore speculare a quello
 * costato a B1, dove avevo creduto il contrario e mandavo **due** mail.
 */
class InvitiDellaPalestraController extends Controller
{
    public function __construct(private readonly InvitiInPalestra $inviti) {}

    /**
     * L'elenco degli inviti ancora in piedi, e **i due contatori**.
     *
     * 🚨 I posti stanno **nella stessa risposta** dell'elenco: chi guarda gli
     * inviti per revocarne uno sta guardando il contatore, e farlo chiedere a
     * parte vorrebbe dire mostrargli per un istante il numero di prima.
     */
    public function elenco(Request $request): JsonResponse
    {
        $palestra = $this->palestraOrAbort($request);

        /*
         * 🚨 `withoutGlobalScopes()` e filtro esplicito sulla palestra, come nel
         * gemello: il modello è scopato per tenant e chi comanda potrebbe
         * trovarsi in un contesto diverso da quello che sta guardando. ⛔ Con lo
         * scope attivo l'elenco sarebbe **silenziosamente parziale**, che è la
         * forma peggiore di sbagliato.
         */
        $inviti = InvitoInPalestra::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->validi()
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => $inviti->map(fn (InvitoInPalestra $i): array => [
                ...$i->perLApp(),

                /*
                 * 💡 **Il ruolo esce su ogni riga**, e non è decorazione: nello
                 * stesso elenco ci sono inviti che consumano posti **diversi**.
                 * ⛔ Senza, chi revoca per liberare un posto iscritto potrebbe
                 * revocarne uno trainer e vedere il contatore sbagliato
                 * muoversi.
                 */
                'ruolo' => self::ruoloPerLApp($i->ruolo),
            ])->values(),

            /*
             * 🚨 **Due contatori separati, non uno.** Un piano ha
             * `max_members` e `max_trainers`, e sono limiti diversi: sommarli
             * direbbe «restano 4 posti» a una palestra che ha finito i posti
             * trainer e ne ha quattro per gli iscritti — cioè un numero vero
             * che risponde alla domanda sbagliata.
             */
            'meta' => [
                'iscritti' => $this->inviti->iPosti($palestra, UserRole::Member),
                'trainer' => $this->inviti->iPosti($palestra, UserRole::Trainer),
            ],
        ]);
    }

    /**
     * Crea un invito monouso, come iscritto o come trainer.
     *
     * ⚠️ **La mail la mando qui**, perché `InvitiInPalestra::invita()` non la
     * manda (vedi la tabella in testa alla classe). 💡 E il risultato dell'invio
     * si legge da `email_inviata_il`, che `mandaIlLink()` scrive in tabella: una
     * verità sola, che l'elenco può rileggere anche domani.
     */
    public function crea(Request $request): JsonResponse
    {
        $palestra = $this->palestraOrAbort($request);

        $dati = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],

            /*
             * ⛔ **Un elenco chiuso di due voci.** Il servizio rifiuta già
             * qualunque altro ruolo — un invito da `gym_admin` regalerebbe a chi
             * lo riceve il comando sull'abbonamento della palestra — ma lì
             * arriva come `ValidationException` su un campo che l'app non ha
             * mandato. 💡 Qui l'errore nomina il campo che c'è.
             */
            'ruolo' => ['nullable', 'string', 'in:iscritto,trainer'],
        ]);

        $ruolo = ($dati['ruolo'] ?? 'iscritto') === 'trainer'
            ? UserRole::Trainer
            : UserRole::Member;

        $invito = $this->inviti->invita(
            $palestra,
            $request->user(),
            $dati['email'] ?? null,
            $ruolo,
        );

        $inviata = $this->inviti->mandaIlLink($invito);

        return response()->json([
            'data' => [
                'id' => $invito->getKey(),
                'token' => $invito->token,
                'email' => $invito->email,
                'email_inviata' => $inviata,
                'ruolo' => self::ruoloPerLApp($invito->ruolo),
                'expires_at' => $invito->expires_at->toIso8601String(),
                'url' => $invito->link(),
            ],
        ], 201);
    }

    /**
     * 📧 **Rimanda per email un invito già creato.**
     *
     * ⚠️ L'email è facoltativa: se manca si usa quella con cui l'invito era
     * nato. ⛔ Se non ce n'è nessuna delle due si risponde **422**: un
     * destinatario non si inventa.
     *
     * 🚨 **Solo su inviti validi.** Rimandare un invito revocato o scaduto
     * vorrebbe dire mandare a una persona un link che non funziona, e farle
     * credere che l'app sia rotta.
     */
    public function rimanda(Request $request, int $invite): JsonResponse
    {
        $palestra = $this->palestraOrAbort($request);

        $dati = $request->validate([
            'email' => ['nullable', 'email', 'max:255'],
        ]);

        $invito = $this->suo($palestra, $invite);

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

        $inviata = $this->inviti->mandaIlLink($invito);

        return response()->json([
            'data' => [
                'id' => $invito->getKey(),
                'email' => $invito->email,
                'email_inviata' => $inviata,
                'email_inviata_il' => $invito->fresh()?->email_inviata_il?->toIso8601String(),
            ],
        ]);
    }

    /**
     * 🚫 **Revoca un invito, e restituisce il posto.**
     *
     * 🚨 È il motivo per cui questa rotta esiste: un invito valido tiene
     * occupato un posto del piano finché non scade, e senza un modo di
     * toglierlo un tocco distratto costa **sette giorni** di un posto pagato.
     */
    public function revoca(Request $request, int $invite): JsonResponse
    {
        $palestra = $this->palestraOrAbort($request);

        $invito = $this->suo($palestra, $invite);

        if ($invito === null) {
            return response()->json(['message' => __('Invito non trovato.')], 404);
        }

        // 💡 La riga sta in `InvitiInPalestra::revoca()`, perché revoca anche il
        //    pannello. Una regola, una sede.
        $this->inviti->revoca($invito);

        return response()->json(['message' => __('Invito revocato.')]);
    }

    /**
     * L'invito **di questa palestra** con questo id, o `null`.
     *
     * 🚨 Il filtro sul tenant è quello che impedisce a una palestra di revocare
     * l'invito di un'altra passandone l'id: senza, l'id sarebbe un permesso.
     */
    private function suo(Tenant $palestra, int $invite): ?InvitoInPalestra
    {
        return InvitoInPalestra::withoutGlobalScopes()
            ->where('tenant_id', $palestra->getKey())
            ->whereKey($invite)
            ->first();
    }

    /**
     * 🚨 **Il cancello: comandi in una palestra vera.**
     *
     * ⛔ **Non `comandaQui()`**, che pure esiste e sembrerebbe la scelta ovvia:
     * quello risponde di sì anche a un **trainer indipendente a casa sua**, e
     * uno spazio personale non è una palestra — non ha iscritti e non ha posti
     * trainer. Il servizio lo rifiuterebbe comunque, ma con un errore di
     * validazione su un campo `palestra` che l'app non ha mandato: cioè una
     * schermata che dice «correggi qualcosa» senza dire cosa.
     *
     * 💡 Un trainer indipendente ha già la **sua** pagina, quella di B1: qui
     * non deve arrivarci, e se ci arriva deve leggere il perché.
     */
    private function palestraOrAbort(Request $request): Tenant
    {
        $utente = $request->user();

        abort_unless(
            $utente instanceof User && $utente->isGymAdmin(),
            403,
            __('Questa sezione è per chi gestisce una palestra.'),
        );

        $palestra = $utente->tenant;

        abort_unless(
            $palestra instanceof Tenant && ! $palestra->ePersonale(),
            403,
            __('Solo una palestra può invitare.'),
        );

        return $palestra;
    }

    /**
     * Il nome del ruolo come lo scrive l'app.
     *
     * ⚠️ **`iscritto`, non `member`.** Il resto dell'API parla inglese, ma
     * questo valore l'app lo mette **a schermo** accanto a «trainer», che in
     * italiano si dice uguale. 💡 Tradurlo nell'app vorrebbe dire una tabella di
     * conversione in più, in un posto in cui non c'è niente da decidere.
     */
    private static function ruoloPerLApp(?UserRole $ruolo): string
    {
        return $ruolo === UserRole::Trainer ? 'trainer' : 'iscritto';
    }
}
