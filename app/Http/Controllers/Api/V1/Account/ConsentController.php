<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * I consensi facoltativi — S9.1.
 *
 * ── 🚨 Perché stanno qui e non nella registrazione ────────────────────────
 *
 * Un consenso richiesto **per potersi iscrivere** non è «liberamente dato» ai
 * sensi dell'art. 7(4) GDPR — e un consenso non liberamente dato **non è
 * consenso**, quindi non è una base giuridica valida.
 *
 * L'app deve funzionare per chi dice di no a tutto: senza il consenso sanitario
 * non si collega Health Connect, senza quello all'AI non c'è il consiglio del
 * giorno. Il resto — diario, allenamenti, chat — continua a funzionare.
 *
 * ── 🚨 Perché sono due caselle e non una ──────────────────────────────────
 *
 * *«Accetto il trattamento dei dati»* in una casella sola **non è consenso
 * esplicito** ai sensi dell'art. 9(2)(a). Tenere i propri dati sanitari **da
 * noi** e mandarli **ad Anthropic, negli Stati Uniti** sono due decisioni
 * diverse, e chi accetta la prima non ha per questo accettato la seconda.
 */
class ConsentController extends Controller
{
    /**
     * Quali colonne si possono toccare da qui, e con che nome pubblico.
     *
     * 🚨 **Un elenco chiuso, non `$request->all()`.** `age_confirmed_at` e
     * `terms_accepted_at` **non ci sono di proposito**: si danno una volta
     * all'iscrizione e non si «revocano» — revocare le condizioni d'uso
     * significa cancellare l'account, che è un'altra porta e un'altra
     * conferma.
     *
     * @var array<string, string>
     */
    private const CONSENSI = [
        'health' => 'health_consent_at',
        'ai' => 'ai_consent_at',

        /*
         * ⚖️ **La presa d'atto su cosa l'AI non e'** — 3b-J.3, 27/08/2026.
         *
         * 📌 *«l'importante e' che chi attiva l'ai legga questa cosa e vi
         * acconsenta»*.
         *
         * ⛔ **Non e' un permesso, e' una dichiarazione**: `ai` resta la base
         * giuridica per mandare dati al fornitore, questa dice «ho capito che
         * quello che esce non e' un parere medico».
         *
         * 🚨 Sta fra i consensi — e non fra le preferenze — perche' va
         * **dimostrata**: e' una data, non un booleano.
         */
        'ai_disclaimer' => 'ai_disclaimer_at',

        /*
         * 🆕 16/08/2026 — il sonno nel contesto del consiglio del giorno.
         *
         * 🚨 **La terza casella, e non poteva essere dentro la seconda.** Chi
         * accetta che una frase sul pranzo vada a un modello non ha con cio'
         * accettato che ci vada quanto e come dorme: sono due decisioni di
         * intimita' diversa, e l'art. 7 vieta il consenso a pacchetto. E' la
         * stessa ragione per cui `health` e `ai` sono gia' separate.
         *
         * 💡 Ed e' **subordinata** ad `ai`: senza il consenso all'AI non parte
         * nessuna chiamata, quindi il sonno non va da nessuna parte comunque.
         * L'interfaccia la mostra spenta e non toccabile finche' `ai` e' spento
         * — vedi `SchermataConsensi`.
         */
        'sleep_ai' => 'sleep_ai_consent_at',
    ];

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->stato($request->user())]);
    }

    /**
     * Concede o revoca.
     *
     * 🚨 **Revocare costa esattamente quanto concedere** (art. 7(3)): la stessa
     * chiamata, lo stesso campo, `false` invece di `true`. Un consenso che si dà
     * con un tocco e si toglie scrivendo un'email **non è liberamente
     * revocabile** — e quindi, a rigore, non è mai stato valido.
     *
     * ⚠️ **Revocare non cancella ciò che è già stato fatto**, e va detto
     * nell'interfaccia: il trattamento fino a quel momento resta lecito
     * (art. 7(3), terzo periodo). Chi vuole anche la cancellazione ha
     * `DELETE /account`.
     */
    /**
     * «Gliel'ho chiesto» — FASE 2-bis, 19/08/2026.
     *
     * 🚨 **La chiama l'app dopo aver MOSTRATO la domanda**, non dopo che
     * qualcuno ha risposto di si'. La domanda a cui questa data risponde e'
     * *«gliel'ho gia' chiesto?»*, non *«ha accettato?»* — e le due sono
     * diverse proprio nel caso che conta: chi rifiuta tutto.
     *
     * ⚠️ **Idempotente**: la prima volta vince. Riscriverla a ogni apertura
     * sposterebbe in avanti una data che serve a sapere **da quando** quella
     * persona ha gia' visto la schermata.
     *
     * 💡 Non tocca nessun consenso: e' un fatto sulla nostra interfaccia,
     * non su chi la usa.
     */
    public function segnaChiesti(Request $request): JsonResponse
    {
        $utente = $request->user();

        if ($utente->consensi_chiesti_il === null) {
            $utente->forceFill(['consensi_chiesti_il' => now()])->save();
        }

        return response()->json(['data' => $this->stato($utente)]);
    }

    public function update(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'health' => ['sometimes', 'boolean'],
            'ai' => ['sometimes', 'boolean'],
            'ai_disclaimer' => ['sometimes', 'boolean'],
            'sleep_ai' => ['sometimes', 'boolean'],

            /*
             * 🆕 16/08 — l'interruttore del consiglio automatico.
             *
             * ⚠️ **Non e' un consenso, ed e' importante non confonderlo.** Un
             * consenso e' una base giuridica: si da', si revoca, e si conserva
             * la data per poterlo dimostrare. Questa e' una **preferenza**:
             * «voglio o non voglio che il consiglio si aggiorni da solo».
             *
             * 💡 Sta nella stessa chiamata perche' nell'app sta nella stessa
             * schermata — chi apre «privacy e AI» cerca entrambe le cose li'.
             * Ma nella risposta viaggia sotto una chiave diversa, e nel database
             * e' un booleano invece di una data: la differenza si vede da fuori.
             */
            'consiglio_automatico' => ['sometimes', 'boolean'],
        ]);

        $utente = $request->user();

        /*
         * ══ ⚖️ NIENTE AI SENZA LA PRESA D'ATTO — 3b-J.3 ════════════════════
         *
         * 📌 *«un consenso obbligatorio se vuoi attivare l'ai»*.
         *
         * 🚨 **Il controllo sta qui e non solo nell'app.** Se vivesse
         * nell'interfaccia, basterebbe una chiamata all'API per saltarlo — e
         * sarebbe esattamente il percorso di chi poi si fa male seguendo una
         * frase generata da un modello.
         *
         * ⚠️ **Si guarda la richiesta O il database**: chi l'ha gia' accettata
         * in passato non deve rifarlo per forza nella stessa chiamata. 💡 Ma
         * l'app la ripropone comunque ogni volta che si accende l'AI: leggerla
         * e' il punto, e una data vecchia di sei mesi non e' una lettura.
         */
        $accendeLAi = array_key_exists('ai', $dati) && (bool) $dati['ai'];

        $haPresoAtto = ((bool) ($dati['ai_disclaimer'] ?? false))
            || $utente->ai_disclaimer_at !== null;

        if ($accendeLAi && ! $haPresoAtto) {
            return response()->json([
                'message' => __('Per attivare l\'AI devi prima prendere atto di cosa non e\'.'),
                'code' => 'ai_disclaimer_required',
            ], 422);
        }

        foreach (self::CONSENSI as $nome => $colonna) {
            if (array_key_exists($nome, $dati)) {
                $utente->registraConsenso($colonna, (bool) $dati[$nome]);
            }
        }

        /*
         * 🚨 **Revocare l'AI revoca anche il sonno.**
         *
         * ⚠️ Senza questa riga resterebbe una data su `sleep_ai_consent_at` di
         * qualcuno che ha detto di no all'AI: un consenso appeso a una funzione
         * spenta, che il giorno che l'AI si riaccende tornerebbe attivo **senza
         * che nessuno l'abbia riconfermato**.
         *
         * 💡 E' la conseguenza della subordinazione: se il consenso figlio non
         * puo' valere senza il padre, deve cadere con lui.
         */
        /*
         * ══ ⚖️ REVOCARE LA PRESA D'ATTO SPEGNE L'AI — 27/08/2026 ═══════════
         *
         * 📌 *«se lo dis-flaggo, mi deve bloccare tutto quello che c'e' con
         * l'ai»*.
         *
         * 💡 Lo farebbe gia' `puoUsareAi()`, che li pretende tutti e due: ogni
         * chiamata risponderebbe 403. ⚠️ Ma lasciare `ai_consent_at` scritto su
         * qualcuno che ha ritirato la presa d'atto vorrebbe dire tenere in piedi
         * **una base giuridica per un trattamento che non puo' avvenire** — e
         * mostrare nella schermata dei consensi un interruttore acceso su una
         * funzione spenta.
         *
         * 🚨 Ed e' la stessa regola gia' scritta per il sonno: se il figlio non
         * puo' valere senza il padre, deve cadere con lui. Qui i due si tengono
         * per mano in tutt'e due i versi.
         */
        if (array_key_exists('ai_disclaimer', $dati) && ! (bool) $dati['ai_disclaimer']) {
            $utente->registraConsenso('ai_consent_at', false);
            $utente->registraConsenso('sleep_ai_consent_at', false);
        }

        if (array_key_exists('ai', $dati) && ! (bool) $dati['ai']) {
            $utente->registraConsenso('sleep_ai_consent_at', false);

            /*
             * ⚖️ **E cade anche la presa d'atto** — 3b-J.3.
             *
             * 🚨 Non perche' «scada» — quello che si e' capito non si dimentica
             * — ma perche' chi riaccende l'AI **deve rileggere**: e' la
             * richiesta, ed e' l'unica cosa che la rende vera nel tempo.
             *
             * ⛔ Tenendola, chi spegne e riaccende dopo un anno si ritroverebbe
             * l'AI attiva senza aver mai piu' visto quel testo. 💡 La data del
             * primo consenso resta comunque negli audit log, che e' dove si
             * dimostra cosa e' successo.
             */
            $utente->registraConsenso('ai_disclaimer_at', false);
        }

        if (array_key_exists('consiglio_automatico', $dati)) {
            // ⚠️ `forceFill`: e' una colonna di `users` che non sta in
            // `$fillable`, come tutto cio' che decide cosa fa il sistema per
            // conto di qualcuno.
            $utente->forceFill(['consiglio_automatico' => (bool) $dati['consiglio_automatico']])->save();
        }

        return response()->json(['data' => $this->stato($utente->refresh())]);
    }

    /**
     * Lo stato, con **le date**.
     *
     * 💡 L'app le usa per dire *«concesso il 12 agosto»* invece di una spunta
     * muta: chi rilegge la propria schermata dei consensi sta cercando di
     * ricordarsi cosa ha deciso e quando, e una spunta da sola non risponde.
     *
     * @return array<string, mixed>
     */
    private function stato(User $utente): array
    {
        return [
            'health' => $utente->health_consent_at?->toIso8601String(),
            'ai' => $utente->ai_consent_at?->toIso8601String(),
            'ai_disclaimer' => $utente->ai_disclaimer_at?->toIso8601String(),
            'sleep_ai' => $utente->sleep_ai_consent_at?->toIso8601String(),

            // 💡 Preferenza, non consenso: un booleano, non una data.
            'consiglio_automatico' => (bool) $utente->consiglio_automatico,
            'age_confirmed_at' => $utente->age_confirmed_at?->toIso8601String(),
            'terms_accepted_at' => $utente->terms_accepted_at?->toIso8601String(),

            /*
             * 🚨 **«Chiesti» non e' «dati»** — FASE 2-bis.
             *
             * Le tre date sopra dicono quando qualcuno ha detto **si'**. Questa
             * dice quando gliel'abbiamo **chiesto**, e senza di essa «non
             * gliel'ho mai chiesto» e «ha detto no a tutto» sono lo stesso
             * stato: tre `null`.
             *
             * ⚠️ Ed e' la differenza fra chiedere una volta e chiedere a ogni
             * reinstallazione — che la seconda volta e' un fastidio, e la terza
             * un motivo per disinstallare.
             */
            'chiesti_il' => $utente->consensi_chiesti_il?->toIso8601String(),
        ];
    }
}
