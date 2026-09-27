<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\DeviceToken;
use App\Models\Scopes\TenantScope;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * L'invio delle notifiche push — B10.6.
 *
 * 🚨 **Un token che il servizio rifiuta va cancellato, non ritentato.**
 * I token dei telefoni scadono: l'app disinstallata, il sistema aggiornato, i
 * permessi revocati. Un token morto che resta in tabella viene ritentato a ogni
 * notifica per sempre, e dopo qualche mese la maggior parte degli invii e' verso
 * telefoni che non esistono piu' — si paga latenza e quota per niente.
 *
 * 🚨 **L'invio non deve MAI far fallire l'azione che lo ha generato.** Un
 * messaggio in chat si salva anche se la notifica non parte: l'utente lo vedra'
 * aprendo l'app. Il contrario — un messaggio perso perche' un servizio esterno
 * era giu' — sarebbe inaccettabile.
 *
 * Il driver `log` e' il default: senza credenziali configurate il sistema
 * funziona e scrive quello che avrebbe mandato, invece di rompersi. E' anche il
 * modo in cui i test verificano l'invio senza toccare la rete.
 */
class PushSender
{
    /**
     * Manda una notifica a tutti i dispositivi di una persona.
     *
     * @param  array<string, mixed>  $data  payload silenzioso per l'app
     * @return int quanti dispositivi hanno ricevuto
     */
    public function toUser(User $utente, string $titolo, string $corpo, array $data = []): int
    {
        /*
         * 🆕 🚨 **Senza lo scope della palestra, e non è una scorciatoia** —
         * 27/09/2026, voce 62.
         *
         * ══ ⛔ IL DIFETTO CHE C'ERA ════════════════════════════════════════
         *
         * `DeviceToken` usa `BelongsToTenant`, quindi questa query filtrava sul
         * **contesto del chiamante**. 🚨 Ma un telefono non appartiene a una
         * palestra: appartiene a una persona. E `device_tokens.tenant_id` è
         * quello che la persona aveva **quando ha registrato il token** — nessuno
         * lo aggiorna quando cambia palestra.
         *
         * ⛔ Conseguenza: chi cambiava palestra smetteva di ricevere le notifiche
         * mandate da dentro un contesto — e non si vedeva, perché le notifiche
         * mandate dai job e dai webhook (contesto vuoto, `TenantScope` non
         * filtra) arrivavano regolarmente. 🚨 Lo stesso invio funzionava o no
         * **a seconda di chi lo faceva partire**.
         *
         * ══ ⚖️ PERCHE' TOGLIERE LO SCOPE E' LA COSA GIUSTA, QUI ═══════════
         *
         * 💡 Il filtro utile c'è già ed è più stretto: `user_id`. ⚠️ Si manda a
         * **questa persona**, non «a questa persona se per caso il suo telefono
         * risulta iscritto dove sono io adesso» — che non è una regola di
         * sicurezza, è un incidente.
         *
         * 🔑 È la stessa scelta che `AccountEraser` aveva già fatto per
         * cancellarli: se per **togliere** i token di una persona lo scope va
         * levato, allora non identifica i suoi token nemmeno per mandarglieli.
         *
         * ⚠️ `withoutGlobalScope(TenantScope::class)` e **non**
         * `withoutGlobalScopes()`: si spegne quello che dà fastidio, non tutti —
         * così se un domani arrivasse un altro scope, resterebbe in piedi.
         */
        $dispositivi = DeviceToken::query()
            ->withoutGlobalScope(TenantScope::class)
            ->where('user_id', $utente->getKey())
            ->get();

        $inviati = 0;

        foreach ($dispositivi as $dispositivo) {
            if ($this->send($dispositivo, $titolo, $corpo, $data)) {
                $inviati++;
            }
        }

        return $inviati;
    }

    /** @param array<string, mixed> $data */
    private function send(DeviceToken $dispositivo, string $titolo, string $corpo, array $data): bool
    {
        $driver = (string) config('push.driver', 'log');

        if ($driver === 'log') {
            Log::info('push', [
                'user_id' => $dispositivo->user_id,
                'platform' => $dispositivo->platform,
                'title' => $titolo,
                // 🚨 Il corpo NON finisce nel log: un messaggio di chat fra un
                // trainer e un iscritto puo' contenere infortuni e questioni
                // personali, e i log si conservano e si leggono.
                'body_length' => mb_strlen($corpo),
            ]);

            $dispositivo->forceFill(['last_used_at' => now()])->save();

            return true;
        }

        return $this->sendViaFcm($dispositivo, $titolo, $corpo, $data);
    }

    /**
     * FCM HTTP v1.
     *
     * @param  array<string, mixed>  $data
     */
    private function sendViaFcm(DeviceToken $dispositivo, string $titolo, string $corpo, array $data): bool
    {
        // 🆕 24/09/2026 — dalla chiave di servizio, rinnovato da solo (`TokenDiFirebase`).
        $firebase = app(TokenDiFirebase::class);
        $progetto = $firebase->progetto();
        $token = $firebase->token();

        if (! is_string($progetto) || ! is_string($token) || $progetto === '' || $token === '') {
            Log::warning('push: FCM non configurato, notifica non inviata.');

            return false;
        }

        try {
            $risposta = Http::withToken($token)
                ->timeout(10)
                ->post("https://fcm.googleapis.com/v1/projects/{$progetto}/messages:send", [
                    'message' => [
                        'token' => $dispositivo->token,
                        'notification' => ['title' => $titolo, 'body' => $corpo],

                        /*
                         * ⚠️ I `data` viaggiano come stringhe (FCM non accetta
                         * altro), e sono quello che l'app legge al **tocco**:
                         * `tipo` dice dove portare, il resto è l'indirizzo
                         * (`codice` di un'offerta, id di una conversazione…).
                         */
                        'data' => array_map(static fn ($v): string => (string) $v, $data),

                        // 🍎 Per iOS, quando ci sarà: il suono va chiesto ad APNs.
                        'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                    ],
                ]);
        } catch (Throwable $e) {
            // Il servizio non risponde: si riprovera' alla prossima notifica.
            // Non si cancella il token, perche' il problema e' nostro, non suo.
            report($e);

            return false;
        }

        // 404 e 403 su un singolo token significano «questo dispositivo non
        // esiste piu'»: si cancella, altrimenti resta a peso morto per sempre.
        if (in_array($risposta->status(), [403, 404], true)) {
            $dispositivo->delete();

            return false;
        }

        if ($risposta->failed()) {
            return false;
        }

        $dispositivo->forceFill(['last_used_at' => now()])->save();

        return true;
    }
}
