<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Una stima del cibo che aspetta il suo turno — FASE 9.
 *
 * ══ 🚨 PERCHE' LA STIMA E' DIVENTATA UN LAVORO ════════════════════════════
 *
 * Perche' `POST /ai/food/text` teneva occupato un processo PHP per **2–8
 * secondi** mentre parlava col modello, e i processi di questo dominio sono
 * **sei**. ⚠️ Il cibo si scrive a pranzo e a cena, cioe' per definizione tutti
 * insieme: a sette persone contemporanee **non si fermava l'AI, si fermava il
 * sito** — chi faceva il login, chi guardava la scheda, tutti in coda dietro a
 * qualcuno che aspettava una stima.
 *
 * 💡 Adesso la richiesta HTTP dura ~50 ms e l'attesa sta su un worker, dove non
 * blocca nessuno. La coda **non rende niente piu' veloce**: trasforma un guasto
 * in un'attesa, e l'attesa e' un numero che si alza aggiungendo worker.
 *
 * ── ⚠️ E' una CACHE, e il pasto se ne va appena serve ─────────────────────
 *
 * `richiesta` contiene quello che la persona ha scritto — *«due uova e una fetta
 * di pane»* — o la foto del piatto. 🚨 E' il dato piu' personale che passa di
 * qui, e passa: `completa()` e `fallisce()` lo cancellano. Quello che resta e'
 * la stima, che l'app deve poter leggere per farla confermare.
 */
class StimaCibo extends Model
{
    use BelongsToTenant;

    protected $table = 'stime_cibo';

    /** Dove finiscono le foto in attesa del loro turno. */
    public const CARTELLA = 'stime-cibo';

    public const IN_CODA = 'in_coda';

    public const IN_LAVORAZIONE = 'in_lavorazione';

    public const PRONTA = 'pronta';

    public const FALLITA = 'fallita';

    public const TESTO = 'testo';

    public const FOTO = 'foto';

    /**
     * 🚨 Quanto vive una stima, in ore.
     *
     * ⚠️ Non e' una scadenza tecnica: e' il tempo oltre il quale una stima non
     * confermata **non interessa piu' a nessuno**, e tenerla vorrebbe dire
     * conservare cosa ha mangiato una persona senza che serva a niente.
     */
    public const DURATA_ORE = 24;

    protected $fillable = [
        'tenant_id', 'user_id', 'stato', 'origine',
        'richiesta', 'risultato', 'errore', 'paga_con_gettoni',
    ];

    protected function casts(): array
    {
        return [
            'richiesta' => 'array',
            'risultato' => 'array',
            'paga_con_gettoni' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // ───────────────────────── il ciclo di vita ─────────────────────────

    /**
     * Accoda una stima da **testo**.
     *
     * @param  array<string, mixed>  $richiesta
     */
    public static function daTesto(User $chi, array $richiesta, bool $pagaConGettoni): self
    {
        return self::create([
            'tenant_id' => $chi->tenant_id,
            'user_id' => $chi->getKey(),
            'stato' => self::IN_CODA,
            'origine' => self::TESTO,
            'richiesta' => $richiesta,
            'paga_con_gettoni' => $pagaConGettoni,
        ]);
    }

    /**
     * Deposita la foto e accoda la stima.
     *
     * 🚨 **Prima il file, poi la riga**, come per gli allegati e per l'import
     * dei piani: nell'ordine inverso esisterebbe un istante in cui la riga
     * promette una foto che non c'e', e il worker partirebbe a vuoto.
     *
     * @param  array<string, mixed>  $richiesta  senza la foto
     */
    public static function daFoto(
        User $chi,
        string $byte,
        string $mime,
        array $richiesta,
        bool $pagaConGettoni,
    ): self {
        $token = Str::random(48);

        Storage::disk('local')->put(self::CARTELLA.'/'.$token, $byte);

        return self::create([
            'tenant_id' => $chi->tenant_id,
            'user_id' => $chi->getKey(),
            'stato' => self::IN_CODA,
            'origine' => self::FOTO,
            'richiesta' => [...$richiesta, 'token' => $token, 'mime' => $mime],
            'paga_con_gettoni' => $pagaConGettoni,
        ]);
    }

    public function percorsoFoto(): ?string
    {
        $token = $this->richiesta['token'] ?? null;

        if (! is_string($token)) {
            return null;
        }

        $disco = Storage::disk('local');
        $percorso = self::CARTELLA.'/'.$token;

        return $disco->exists($percorso) ? $disco->path($percorso) : null;
    }

    public function inLavorazione(): void
    {
        $this->update(['stato' => self::IN_LAVORAZIONE]);
    }

    /**
     * Deposita la stima, e **butta via il pasto**.
     *
     * 🚨 `richiesta` si azzera qui, e non e' pulizia: e' il dato per cui questa
     * tabella andrebbe dichiarata due volte nel registro. ⚠️ Da quando la stima
     * esiste, il testo del pasto non serve piu' a niente — e la foto ancora
     * meno.
     *
     * @param  array<string, mixed>  $risultato
     */
    public function completa(array $risultato): void
    {
        $this->buttaLaFoto();

        $this->update([
            'stato' => self::PRONTA,
            'risultato' => $risultato,
            'richiesta' => null,
            'errore' => null,
        ]);
    }

    /**
     * ⚠️ Anche fallendo il pasto se ne va: una stima che non e' riuscita non ha
     * nessuna ragione di conservare cosa aveva mangiato quella persona.
     *
     * 💡 Chi vuole riprovare rimanda quello che ha scritto — ce l'ha lui sul
     * telefono, e non serve che ce l'abbiamo noi.
     */
    public function fallisce(string $codice): void
    {
        $this->buttaLaFoto();

        $this->update([
            'stato' => self::FALLITA,
            'errore' => mb_substr($codice, 0, 60),
            'richiesta' => null,
        ]);
    }

    public function eFinita(): bool
    {
        return in_array($this->stato, [self::PRONTA, self::FALLITA], true);
    }

    /**
     * Quello che l'app legge.
     *
     * @return array<string, mixed>
     */
    public function perLApp(): array
    {
        return [
            'id' => (int) $this->getKey(),
            'stato' => $this->stato,
            'origine' => $this->origine,
            'risultato' => $this->risultato,
            'errore' => $this->errore,
            'creata_il' => $this->created_at?->toIso8601String(),
        ];
    }

    private function buttaLaFoto(): void
    {
        $token = $this->richiesta['token'] ?? null;

        if (is_string($token)) {
            Storage::disk('local')->delete(self::CARTELLA.'/'.$token);
        }
    }
}
