<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Models\DeviceToken;
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
        $dispositivi = DeviceToken::query()
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
        $progetto = config('push.fcm.project_id');
        $token = config('push.fcm.access_token');

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
                        'data' => array_map(static fn ($v): string => (string) $v, $data),
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
