<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Una foto cifrata in transito verso l'altra persona — N14.
 *
 * ── 🚨 Il server non puo' aprirla, e non e' previsto che possa ─────────────
 *
 * Il file e' cifrato con `secretstream` e una chiave a caso; quella chiave
 * viaggia **dentro il messaggio**, che e' gia' una busta `crypto_box` fra i
 * due. Qui restano dei byte opachi e la loro misura.
 *
 * ── ⚠️ Vive al massimo ventiquattro ore ────────────────────────────────────
 *
 * *«restano finche' non le scarica, max 24h»* — il committente, 18/08/2026.
 *
 * E' cifrata, quindi il rischio e' basso — ma occupa spazio nostro e cresce da
 * sola. E' la stessa logica con cui i backup sui nostri server sono stati
 * esclusi: nessun beneficio vero, un rischio in piu' e spazio occupato.
 *
 * 🚨 **La conseguenza va detta a chi manda, non scoperta dopo**: se il trainer
 * e' in ferie tre giorni, quella foto e' persa. L'app scrive «Le foto restano
 * disponibili 24 ore» accanto all'invio.
 */
class AllegatoCifrato extends Model
{
    protected $table = 'allegati_cifrati';

    /**
     * 🚨 **Quanto vive, in un posto solo.**
     *
     * ⚠️ Ricavare la scadenza dentro le query vorrebbe dire cambiarla domani in
     * ogni punto che la calcola, e dimenticarne uno.
     */
    public const DURATA_ORE = 24;

    /**
     * Il tetto per allegato — alzato in N21.2.
     *
     * ⚠️ **Due megabyte non bastavano per un PDF.** Erano tarati sulle foto —
     * una da 1080x1080 cifrata sono 250-450 KB — ma un piano alimentare
     * scansionato ne pesa 5-10, e sarebbe stato respinto senza che il
     * committente avesse mai deciso di respingerlo.
     *
     * 💡 Dieci megabyte coprono un PDF scansionato di parecchie pagine e
     * restano lontani da qualunque uso serio come deposito.
     */
    public const BYTE_MASSIMI = 10 * 1024 * 1024;

    /**
     * 🚨 Quanto spazio puo' occupare **una persona alla volta** — N21.2.
     *
     * ── ⚠️ Perche' il limite per richiesta non basta piu' ──────────────────
     *
     * Con il tetto a 2 MB il limite di frequenza bastava: venti al minuto sono
     * 40 MB, e comunque tutto sparisce in 24 ore. Portandolo a 10 MB, gli
     * stessi venti al minuto diventano **200 MB al minuto** — e duecento
     * all'ora, quasi due giga. Su una macchina condivisa con i domini di altri
     * clienti non e' un tetto: e' un invito.
     *
     * 💡 Questo invece limita **cio' che esiste adesso**, che e' la sola cosa
     * che occupa davvero il disco. E si libera da solo: gli allegati scadono in
     * 24 ore, quindi il budget si ricostituisce senza che nessuno lo azzeri.
     */
    public const BUDGET_BYTE = 200 * 1024 * 1024;

    /** La cartella sul disco privato. */
    public const CARTELLA = 'allegati-chat';

    protected $fillable = [
        'conversation_id', 'sender_id', 'token', 'byte_totali', 'scade_il',
    ];

    protected function casts(): array
    {
        return [
            'scade_il' => 'datetime',
            'byte_totali' => 'integer',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /**
     * Dove stanno i byte.
     *
     * 💡 Il nome sul disco e' il token, che e' gia' casuale e unico: non serve
     * inventarne un secondo, e cosi' da un file si risale sempre alla riga.
     */
    public function percorso(): string
    {
        return self::CARTELLA.'/'.$this->token;
    }

    /**
     * Scrive i byte e crea la riga.
     *
     * 🚨 **Prima il file, poi la riga.** ⚠️ Nell'ordine inverso esisterebbe un
     * istante in cui la riga promette un allegato che sul disco non c'e': chi
     * lo scaricasse in quel momento riceverebbe un errore invece della foto. Al
     * contrario resta al massimo un file orfano, che il comando di pulizia
     * porta via.
     */
    public static function deposita(
        int $conversationId,
        int $senderId,
        string $byte,
    ): self {
        $token = Str::random(48);

        Storage::disk('local')->put(self::CARTELLA.'/'.$token, $byte);

        return self::create([
            'conversation_id' => $conversationId,
            'sender_id' => $senderId,
            'token' => $token,
            'byte_totali' => strlen($byte),
            'scade_il' => now()->addHours(self::DURATA_ORE),
        ]);
    }

    /**
     * Butta il file e la riga.
     *
     * 🚨 **Prima il file, poi la riga**, per la ragione opposta a `deposita`:
     * cancellando prima la riga, il file resterebbe sul disco senza piu'
     * niente che sappia di doverlo togliere.
     */
    public function butta(): void
    {
        Storage::disk('local')->delete($this->percorso());

        $this->delete();
    }

    public function scaduto(): bool
    {
        return $this->scade_il->isPast();
    }

    /**
     * Quanti byte ha in giro questa persona, adesso — N21.2.
     *
     * 💡 Si contano solo i **non scaduti**: quelli scaduti sono spazzatura
     * che il comando orario portera' via, e farli pesare sul budget punirebbe
     * qualcuno per un file che non esiste piu' se non nel senso piu' tecnico.
     */
    public static function byteInGiroDi(int $senderId): int
    {
        return (int) self::query()
            ->where('sender_id', $senderId)
            ->where('scade_il', '>', now())
            ->sum('byte_totali');
    }
}
