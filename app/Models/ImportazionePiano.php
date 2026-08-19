<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\Ai\Data\PianoTrascritto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Un piano alimentare in attesa di essere trascritto — N20.
 *
 * ── 🚨 Il rischio, che non e' quello che sembra ────────────────────────────
 *
 * Il rischio non e' che l'AI **fallisca**: un fallimento si vede e si rifa'. E'
 * che **riesca a meta'**. «200 g» letti «20 g» non danno nessun errore:
 * producono un piano plausibile e sbagliato, su un documento che la persona
 * crede fedele all'originale — e che seguira' per settimane.
 *
 * 💡 Per questo qui dentro c'e' una **bozza**, e il nome della colonna lo dice.
 * Non e' il piano: e' quello che l'AI crede di aver letto, e fino alla conferma
 * riga per riga (N20.3) non vale niente.
 *
 * ── ⚠️ Il piano confermato NON resta su questo server ──────────────────────
 *
 * Se lo tenessimo, avremmo una dieta legata a una persona sui nostri sistemi:
 * un dato dell'art. 9 con un nome sopra. Confermata la bozza, il telefono se la
 * porta via e questa riga si cancella.
 */
class ImportazionePiano extends Model
{
    /*
     * 🚨 Il filtro per palestra c'e', **e non basta da solo**: sopra ci
     * va sempre `user_id`. Il primo tiene fuori le altre palestre, il secondo
     * tiene fuori i compagni di palestra — e su un piano alimentare il secondo
     * conta quanto il primo.
     */
    use BelongsToTenant;

    protected $table = 'importazioni_piani';

    /**
     * ⚠️ Sette giorni: larghi per chi ci mette qualche giorno a controllare
     * riga per riga, stretti abbastanza da non diventare un archivio di diete.
     */
    public const DURATA_GIORNI = 7;

    /** 💡 Lo stesso tetto degli allegati di chat: un PDF e' un PDF. */
    public const BYTE_MASSIMI = 10 * 1024 * 1024;

    public const CARTELLA = 'piani-da-importare';

    /*
     * Gli stati, che sono quattro e bastano.
     *
     * 💡 Non c'e' uno stato «confermata»: una bozza confermata **non
     * resta**. Il telefono se la porta via e la riga si cancella, perche' un
     * piano alimentare legato a una persona sui nostri server sarebbe un dato
     * dell'art. 9 con un nome sopra.
     */
    public const IN_CODA = 'in_coda';

    public const IN_LAVORAZIONE = 'in_lavorazione';

    public const PRONTA = 'pronta';

    public const FALLITA = 'fallita';

    protected $fillable = [
        'user_id', 'tenant_id', 'token', 'nome_file', 'byte_totali',
        'stato', 'bozza', 'modello_usato', 'errore', 'dichiarato_il', 'scade_il',
        'paga_con_gettoni',
    ];

    protected function casts(): array
    {
        return [
            'bozza' => 'array',
            'byte_totali' => 'integer',
            'paga_con_gettoni' => 'boolean',
            'dichiarato_il' => 'datetime',
            'scade_il' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function percorso(): string
    {
        return self::CARTELLA.'/'.$this->token;
    }

    /**
     * Deposita il PDF e apre l'importazione.
     *
     * 🚨 **Prima il file, poi la riga**, come per gli allegati: nell'ordine
     * inverso esisterebbe un istante in cui la riga promette un PDF che non
     * c'e', e il lavoro partirebbe a vuoto.
     */
    public static function apri(
        User $chi,
        string $byte,
        string $nomeFile,
        bool $pagaConGettoni = false,
    ): self {
        $token = Str::random(48);

        Storage::disk('local')->put(self::CARTELLA.'/'.$token, $byte);

        return self::create([
            'user_id' => $chi->getKey(),
            'tenant_id' => $chi->tenant_id,
            'token' => $token,
            'nome_file' => $nomeFile,
            'byte_totali' => strlen($byte),
            'stato' => self::IN_CODA,
            'paga_con_gettoni' => $pagaConGettoni,
            // 🚨 Con la data: chi importa dichiara che il piano l'ha redatto un
            // professionista abilitato, e quando l'ha dichiarato.
            'dichiarato_il' => now(),
            'scade_il' => now()->addDays(self::DURATA_GIORNI),
        ]);
    }

    public function percorsoAssoluto(): ?string
    {
        $disco = Storage::disk('local');

        return $disco->exists($this->percorso()) ? $disco->path($this->percorso()) : null;
    }

    public function inLavorazione(): void
    {
        $this->update(['stato' => self::IN_LAVORAZIONE]);
    }

    /**
     * Deposita la trascrizione.
     *
     * 🚨 **Il PDF resta** — N20.4. Non si cancella qui e non si cancella
     * alla conferma: chi rivede la bozza deve poter guardare l'originale
     * accanto, riga per riga, ed e' l'unica cosa che rende la revisione
     * qualcosa di piu' di una lettura di numeri plausibili. Se ne va con la
     * scadenza, insieme a tutto il resto.
     */
    public function salvaBozza(PianoTrascritto $piano, string $modello): void
    {
        $this->update([
            'stato' => self::PRONTA,
            'bozza' => $piano->perLApp(),
            'modello_usato' => $modello,
            'errore' => null,
        ]);
    }

    public function fallisce(string $errore): void
    {
        $this->update([
            'stato' => self::FALLITA,
            // ⚠️ Troncato: `errore` e' un `text`, ma un messaggio del fornitore
            // lungo diecimila caratteri non aiuta nessuno e ce lo portiamo dietro.
            'errore' => mb_substr($errore, 0, 1000),
        ]);
    }

    /** Butta il PDF e la riga. */
    public function butta(): void
    {
        Storage::disk('local')->delete($this->percorso());

        $this->delete();
    }

    public function scaduta(): bool
    {
        return $this->scade_il->isPast();
    }
}
