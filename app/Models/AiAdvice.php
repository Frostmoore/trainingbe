<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Support\Tempo\FasciaDelConsiglio;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un consiglio gia' generato, tenuto da parte.
 *
 * Vedi la migration per il perche' dell'hash sul contesto: e' il meccanismo che
 * rende la rigenerazione automatica senza nessun cron.
 */
class AiAdvice extends Model
{
    use BelongsToTenant;

    /**
     * ⚠️ Esplicita, e non per gusto: «advice» in inglese e' un nome
     * incontabile, quindi il pluralizzatore di Laravel lascia `ai_advice`
     * mentre la tabella si chiama `ai_advices`. Senza questa riga il modello
     * cerca una tabella che non esiste, e l'errore arriva solo a runtime, la
     * prima volta che qualcuno chiede il consiglio del giorno.
     */
    protected $table = 'ai_advices';

    protected $fillable = [
        'tenant_id', 'user_id', 'date', 'fascia', 'kind', 'context_hash', 'body', 'model',
    ];

    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * L'hash di un contesto.
     *
     * 🚨 `ksort` prima di serializzare: senza, due contesti identici con le
     * chiavi in ordine diverso darebbero hash diversi, e la cache non
     * funzionerebbe mai — un guasto che non da' errori, solo una bolletta.
     *
     * @param  array<string, mixed>  $context
     */
    public static function hashOf(array $context): string
    {
        ksort($context);

        return md5((string) json_encode($context));
    }

    /**
     * 🚨 **Butta i consigli dei giorni passati.** — 16/08/2026
     *
     * ── Perche' esiste, e perche' non c'era ───────────────────────────────
     *
     * Questa tabella e' una **cache**, non uno storico: `cached()` filtra
     * `whereDate('date', $oggi)` e non guarda mai indietro. ⚠️ Ma nessuno l'ha
     * mai potata, quindi ogni consiglio mai generato e' rimasto li' — righe che
     * **nessun codice leggera' mai piu'**.
     *
     * 🚨 **E non e' peso morto qualunque: e' il testo piu' intimo che abbiamo
     * sul server.** Un consiglio dice *«hai mangiato 1.400 kcal, ti mancano
     * proteine, ieri non ti sei allenato»* — un racconto sulla salute di una
     * persona, in chiaro, conservato per sempre senza che serva a niente.
     *
     * 💡 Misurato in `I0.1`: a tre consigli al giorno sono **5.475 righe e
     * 2,4 MB** in cinque anni — piu' del diario alimentare e del sonno messi
     * insieme.
     *
     * ── Perche' qui e non in un comando schedulato ────────────────────────
     *
     * ⚠️ Un cron che pota e' un cron che un giorno non gira, e nessuno se ne
     * accorge finche' la tabella non e' gonfia. Potare **nel momento in cui si
     * scrive** non ha quel problema: se si scrive, si pota.
     *
     * 🎯 E l'ordine e' quello giusto — si scrive prima e si pota dopo: al
     * peggio resta una riga in piu' per un giorno, invece di perdere il
     * consiglio appena generato.
     */
    public static function pota(int $userId, FasciaDelConsiglio $fascia): int
    {
        /*
         * 🚨 **Il giorno della FASCIA, non quello dell'orologio** — 3b-AB.
         *
         * ⚠️ Prima delle 09:00 si e' ancora nella fascia delle 22 di **ieri**,
         * e la riga che si sta scrivendo porta la data di ieri. ⛔ Potare su
         * `oggi` cancellerebbe la riga appena scritta: il consiglio si
         * rigenererebbe a ogni apertura, cioe' esattamente la spesa che le
         * fasce esistono per togliere.
         */
        return static::withoutGlobalScopes()
            ->where('user_id', $userId)
            ->whereDate('date', '<', $fascia->giorno->etichetta)
            ->delete();
    }

    /**
     * Il consiglio gia' scritto **in questa fascia**, se c'e' — 3b-AB.
     *
     * ══ ⛔ IL CONTESTO NON ENTRA PIU' NELLA CHIAVE ═════════════════════════
     *
     * ⚠️ Prima si cercava per `context_hash`, e la migration originale lo
     * chiamava un pregio: *«a ogni pasto o allenamento nuovo l'hash cambia,
     * quindi il consiglio si aggiorna quando ha senso aggiornarlo»*.
     *
     * ⛔ **«Quando ha senso» voleva dire a ogni pasto.** Colazione, spuntino,
     * pranzo, merenda, cena e allenamento sono sei contesti diversi: sei
     * chiamate al modello in un giorno, tutte automatiche, nessuna chiesta da
     * nessuno.
     *
     * 📌 *«il consiglio del giorno si rigeneri in automatico solo 3 volte al
     * giorno (9:00, 14:00 e 22:00)»*. 💡 Dentro una fascia il consiglio e' uno,
     * qualunque cosa si registri.
     *
     * 🚨 **La fascia contiene gia' il giorno**, quindi non si filtra anche per
     * `date`: due filtri per la stessa cosa sono due occasioni di divergere, e
     * prima delle 09:00 divergerebbero davvero — la fascia e' di ieri.
     */
    public static function nellaFascia(User $user, FasciaDelConsiglio $fascia, string $kind): ?self
    {
        return static::query()
            ->where('user_id', $user->getKey())
            ->where('fascia', $fascia->etichetta())
            ->where('kind', $kind)
            ->first();
    }

    /**
     * L'ultimo consiglio scritto, di qualunque fascia — 3b-AB.
     *
     * ── 🎯 A cosa serve: al **secondo** cancello, quello che risparmia di piu'
     *
     * La fascia da sola mette un tetto di tre al giorno. ⚠️ Ma tre chiamate
     * fatte per niente restano tre chiamate: chi apre l'app alle 09:10 senza
     * aver registrato niente da ieri sera non ha **nessuna** notizia nuova da
     * raccontare, e un consiglio identico al precedente e' un gettone buttato.
     *
     * 📌 *«questo puo' succedere solo dopo che apri l'app e solo dopo che si e'
     * registrato un pasto, un allenamento o il sonno»*.
     *
     * 💡 Quindi `created_at` di questa riga e' il confronto: e' successo
     * qualcosa **dopo** che abbiamo scritto l'ultimo consiglio? Vedi
     * `AiController::qualcosaDiNuovo()`.
     *
     * ⚠️ Ordinato per `created_at` e non per `fascia`: le etichette delle fasce
     * si ordinano bene come stringhe, ma la riga scritta piu' di recente e'
     * quella che conta per il confronto, ed e' un'altra domanda.
     */
    public static function ultimo(User $user, string $kind): ?self
    {
        return static::query()
            ->where('user_id', $user->getKey())
            ->where('kind', $kind)
            ->latest('created_at')
            ->first();
    }
}
