<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un messaggio.
 *
 * Come `PlanExercise`, **non ha `tenant_id`**: vive solo dentro una
 * conversazione, che ce l'ha. La regola — «queste righe si raggiungono solo
 * attraverso il padre, mai per id» — e' scritta per esteso in `PlanExercise` e
 * vale identica qui.
 *
 * ── 🚨 Da S6: `body` NON contiene testo ────────────────────────────────────
 *
 * Contiene il base64 di una busta cifrata con `crypto_box` (X25519 +
 * XSalsa20-Poly1305) fra le due persone della conversazione. **Il server non ha
 * nessuna chiave per aprirla, e non e' previsto che ne abbia una.**
 *
 * Il nome della colonna e' rimasto quello per non riscrivere mezza applicazione,
 * ⚠️ ma chi legge il database non deve confondersi: il segnale che distingue una
 * busta da un vecchio messaggio in chiaro e' `envelope_version`, che nei
 * messaggi in chiaro non esisteva. Quelli, comunque, sono stati **cancellati**
 * dalla migrazione di S6 — non ne resta nessuno.
 *
 * ✅ **`Conversation::unreadFor()` continua a funzionare senza modifiche**, e
 * non e' un caso: conta righe, non legge testo. E' la prova che il progetto
 * regge — tutto cio' che il server deve ancora fare, lo fa sui **metadati**.
 */
class Message extends Model
{
    /**
     * Quanto vive una busta effimera sul server, dal momento dell'invio.
     *
     * 🚨 **Lo stesso patto degli allegati (N14), e non e' una
     * coincidenza**: una foto usa e getta e' una busta piu' un allegato, e due
     * scadenze diverse avrebbero prodotto il caso peggiore — la traccia che
     * resta e il file che non c'e' piu', o viceversa.
     */
    public const ORE_PER_CHI_MANDA = 24;

    protected $fillable = [
        'conversation_id', 'sender_id', 'body', 'envelope_version', 'nonce',
        'media_id', 'read_at', 'usa_e_getta', 'era_foto',
    ];

    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'envelope_version' => 'integer',
            'usa_e_getta' => 'boolean',
            'era_foto' => 'boolean',
            'visto_il' => 'datetime',
            'svuotato_il' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // 🚨 `last_message_at` si aggiorna qui e non nei chiamanti: e' la colonna
        // su cui si ordina l'elenco delle conversazioni, e un punto del codice
        // che si dimentica di aggiornarla fa sparire un thread dal fondo della
        // lista invece che dalla cima.
        static::created(function (self $messaggio): void {
            $messaggio->conversation?->forceFill([
                'last_message_at' => $messaggio->created_at ?? now(),
            ])->save();
        });
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /**
     * La busta e i metadati — niente altro.
     *
     * ⚠️ `body` esce ancora, ma e' base64 di byte cifrati: chi consuma questa
     * risposta **deve** decifrarla, e se dimenticasse di farlo mostrerebbe una
     * riga di caratteri incomprensibili invece di un testo sbagliato. E' il
     * modo giusto di fallire — rumoroso.
     *
     * @return array<string, mixed>
     */
    public function toApiArray(?int $perChi = null): array
    {
        $consegnabile = $this->consegnabileA($perChi);

        return [
            'id' => $this->id,
            'conversation_id' => $this->conversation_id,
            'sender_id' => $this->sender_id,
            'envelope_version' => $this->envelope_version,

            /*
             * 🚨 **La busta si svuota QUI, nella risposta**, non solo
             * nel database.
             *
             * ⚠️ Il comando notturno passa una volta al giorno: fra una passata
             * e l'altra, senza questo controllo, un messaggio effimero gia'
             * visto continuerebbe a essere consegnato a chi l'ha visto. La
             * cancellazione sarebbe una promessa affidata a un cron.
             */
            'nonce' => $consegnabile ? $this->nonce : '',
            'body' => $consegnabile ? $this->body : '',

            'usa_e_getta' => (bool) $this->usa_e_getta,

            /*
             * 💡 `spenta` dice all'app di mostrare la **traccia** invece di
             * provare a decifrare: senza, l'app tenterebbe di aprire una busta
             * vuota e mostrerebbe un errore di decifratura al posto di
             * «Messaggio effimero».
             */
            'spenta' => $this->usa_e_getta && ! $consegnabile,

            /*
             * 💡 A busta spenta e' l'unico modo per sapere se scrivere «Foto
             * effimera» o «Messaggio effimero»: il contenuto che lo diceva non
             * c'e' piu'.
             */
            'era_foto' => (bool) $this->era_foto,
            'visto_il' => $this->visto_il?->toIso8601String(),

            'read_at' => $this->read_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Se la busta puo' ancora essere consegnata a questa persona.
     *
     * ── 🚨 I due orologi, in una funzione sola ──────────────────────────
     *
     * | | Chi riceve | Chi manda |
     * |---|---|---|
     * | Fino a quando | Alla **prima apertura** | **24 ore dall'invio** |
     *
     * 💡 **Sono diversi di proposito.** Chi manda deve potersi ricordare cosa
     * ha mandato e a chi, per una giornata. Legarlo all'apertura dell'altro
     * avrebbe prodotto un comportamento imprevedibile: la stessa foto sparisce
     * dopo dieci secondi o dopo tre giorni, a seconda di quando l'altro apre
     * l'app.
     *
     * ⚠️ `$perChi === null` vuol dire «non lo so»: succede nel broadcast, che
     * non ha un destinatario singolo. In quel caso si e' **prudenti** e si
     * guarda solo se la busta e' ancora piena — l'app riceve comunque la
     * versione buona con la prossima lettura.
     */
    public function consegnabileA(?int $perChi): bool
    {
        if (! $this->usa_e_getta) {
            return true;
        }

        if ($this->svuotato_il !== null) {
            return false;
        }

        if ($this->scadutaPerChiManda()) {
            return false;
        }

        // Chi manda la rivede finche' non scadono le 24 ore, anche se l'altro
        // l'ha gia' aperta.
        if ($perChi !== null && $perChi === (int) $this->sender_id) {
            return true;
        }

        return $this->visto_il === null;
    }

    public function scadutaPerChiManda(): bool
    {
        return $this->created_at !== null
            && $this->created_at->addHours(self::ORE_PER_CHI_MANDA)->isPast();
    }

    /**
     * La prima apertura di chi riceve: da qui in poi la busta non torna piu'.
     *
     * 🚨 **Non si svuota la riga adesso.** Chi ha mandato ha ancora le sue
     * ventiquattro ore, e cancellare il corpo qui gliele toglierebbe nel momento
     * in cui l'altro apre — cioe' esattamente il comportamento imprevedibile che
     * i due orologi esistono per evitare.
     *
     * ⚠️ **Idempotente**: la prima apertura vince. Un secondo tocco non deve
     * spostare la data in avanti, perche' quella data e' la prova che il
     * contenuto e' stato scoperto.
     */
    public function segnaVista(): void
    {
        if ($this->visto_il !== null) {
            return;
        }

        $this->forceFill(['visto_il' => now()])->save();
    }

    /**
     * Svuota la busta: restano l'id, il mittente e la data.
     *
     * 🚨 **La riga non si cancella.** Un messaggio che sparisce dal mezzo di
     * una conversazione sembra un guasto; una traccia che dice «Messaggio
     * effimero» sembra quello che e'.
     */
    public function svuota(): void
    {
        $this->forceFill([
            'body' => '',
            'nonce' => '',
            'svuotato_il' => now(),
        ])->save();
    }
}
