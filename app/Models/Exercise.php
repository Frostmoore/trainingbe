<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MuscleGroup;
use App\Models\Concerns\BelongsToTenantOrGlobal;
use App\Models\Contracts\LibreriaCondivisaConGliIscritti;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Un esercizio: della piattaforma (`tenant_id` null) o di una palestra.
 *
 * Ogni palestra vede i propri **piu'** i globali, ma puo' modificare solo i
 * propri — e quel limite sta nella policy, non nello scope: uno scope di lettura
 * non e' il posto dove far rispettare i permessi di scrittura, perche' un
 * update non passa necessariamente da una select.
 */
class Exercise extends Model implements HasMedia, LibreriaCondivisaConGliIscritti
{
    use BelongsToTenantOrGlobal;
    use HasFactory;
    use InteractsWithMedia;
    use SoftDeletes;

    public const COLLECTION_IMMAGINE = 'image';

    /** Quanto conta il muscolo che fa il lavoro: e' il riferimento. */
    public const PESO_DEL_PRIMARIO = 1.0;

    /**
     * Quanto conta un muscolo **secondario** — 3b-S, 28/08/2026.
     *
     * ══ 📚 NON E' UN NUMERO SCELTO DA NOI ═════════════════════════════════
     *
     * E' il *fractional set counting*, la convenzione standard sull'ipertrofia:
     * una serie in cui il muscolo e' motore principale conta **1**, una in cui
     * e' secondario conta **0,5**. 🚨 Una meta-analisi su **67 studi** ha
     * confrontato i tre modi di contare — tutto uguale, solo diretto,
     * frazionario — e il frazionario e' quello che spiega meglio i risultati.
     *
     * ⚠️ **Il limite, dichiarato**: quel 0,5 nasce per contare le *serie* nei
     * volumi settimanali, non per dire «quanto ho usato questo muscolo». La
     * contribuzione vera cambia molto da esercizio a esercizio — il capo lungo
     * del tricipite prende circa il 2% nella panca e il 18% in uno
     * skullcrusher. ⛔ Il modello giusto sarebbe una tabella per esercizio, e
     * non ne esiste una pubblica per 314 esercizi: riempirla a mano vorrebbe
     * dire 314 numeri inventati, che e' peggio di un'approssimazione
     * dichiarata.
     *
     * 💡 **Gemella di `pesoDelSecondario` nell'app** (`catalogo_esercizi.dart`):
     * se cambia una, cambia l'altra, o la stessa scheda si colora in due modi.
     */
    public const PESO_DEL_SECONDARIO = 0.5;

    protected $attributes = [
        'is_custom' => false,
    ];

    protected $fillable = [
        'tenant_id', 'created_by', 'name', 'muscle_group', 'secondary_muscles',
        'equipment', 'description', 'is_custom', 'met',
    ];

    protected function casts(): array
    {
        return [
            'muscle_group' => MuscleGroup::class,
            'secondary_muscles' => 'array',
            'is_custom' => 'boolean',
            'met' => 'float',
        ];
    }

    /**
     * Tutti i muscoli, con quanto pesano — 3b-A.3, 23/08/2026.
     *
     * ══ 🚨 SERVE UN PESO, NON UN ELENCO ═══════════════════════════════════
     *
     * ⛔ Un elenco piatto direbbe che in una panca il petto e i tricipiti
     * valgono uguale, e la figura del corpo li colorerebbe allo stesso modo.
     *
     * 💡 **I due pesi stanno in [PESO_DEL_PRIMARIO] e [PESO_DEL_SECONDARIO]**,
     * con la fonte accanto: non sono scelti da noi, sono il *fractional set
     * counting*. ⚠️ Chi un giorno volesse pesi veri cambia **quelle due
     * costanti** e nient'altro — ed e' la ragione per cui questo metodo esiste
     * invece di lasciare il calcolo a chi disegna.
     *
     * ⛔ **Qui il conto e' per esercizio, non per serie.** Chi vuole sapere
     * *quanto* un muscolo e' stato usato deve moltiplicare per le serie: lo fa
     * l'app (`pesiDellaScheda`), perche' le serie stanno sul telefono.
     *
     * ⛔ `cardio` e `full_body` **non entrano**: descrivono la natura
     * dell'esercizio, non una zona del corpo. Una corsa colora le gambe perche'
     * le ha fra i secondari, non perche' «cardio» sia un muscolo.
     *
     * @return array<string, float> gruppo muscolare => peso
     */
    public function muscoliConPeso(): array
    {
        $pesi = [];

        $primario = $this->muscle_group;

        if ($primario !== null && $primario->eUnMuscolo()) {
            $pesi[$primario->value] = self::PESO_DEL_PRIMARIO;
        }

        foreach ($this->secondary_muscles ?? [] as $valore) {
            $muscolo = MuscleGroup::tryFrom((string) $valore);

            if ($muscolo === null || ! $muscolo->eUnMuscolo()) {
                continue;
            }

            // ⚠️ `max` e non `+=`: se un muscolo comparisse in tutti e due i
            // posti conta come primario, non come uno e mezzo.
            $pesi[$muscolo->value] = max(
                $pesi[$muscolo->value] ?? 0.0,
                self::PESO_DEL_SECONDARIO,
            );
        }

        return $pesi;
    }

    protected static function booted(): void
    {
        // 🚨 La forma canonica si ricalcola a ogni scrittura del nome, qui e non
        // nei chiamanti: `ExerciseMatcher` (B7.3) cerca **solo** su questa
        // colonna, e una riga salvata da un punto che si e' dimenticato di
        // aggiornarla diventa un esercizio invisibile al matcher — che quindi ne
        // creerebbe un duplicato al primo import.
        static::saving(function (self $exercise): void {
            if ($exercise->isDirty('name') || $exercise->slug_normalized === null) {
                $exercise->slug_normalized = self::normalize((string) $exercise->name);
            }
        });
    }

    // ───────────────────────── normalizzazione ─────────────────────────

    /**
     * Il nome ridotto a forma confrontabile.
     *
     * «Panca Piana», «panca  piana» e «Panca piana!» devono dare la stessa
     * stringa. Non tocca i sinonimi («bench press»): quelli sono un problema di
     * vocabolario e li risolve `ExerciseMatcher`, che pero' parte da qui.
     */
    public static function normalize(string $name): string
    {
        $s = Str::of($name)->ascii()->lower()->toString();
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';

        return trim(preg_replace('/\s+/', ' ', $s) ?? '');
    }

    // ───────────────────────── relazioni ─────────────────────────

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    // ───────────────────────── query ─────────────────────────

    /** Ordinamento utile a un elenco: prima i globali, poi per nome. */
    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByRaw('tenant_id IS NOT NULL')->orderBy('name');
    }

    /**
     * Gli esercizi che **nessuno ha ancora classificato** — 3b-A.3.4, 24/08/2026.
     *
     * ══ 🚨 `NULL` E `[]` SONO DUE COSE OPPOSTE ═════════════════════════════
     *
     * ⛔ `secondary_muscles = []` vuol dire **«questo esercizio isola davvero»**:
     * e' una decisione presa, e non e' una lacuna. `NULL` vuol dire «nessuno ci
     * ha pensato». 💡 Confonderle riempirebbe la pagina di righe gia' a posto,
     * e a quel punto nessuno la guarderebbe piu'.
     *
     * ⚠️ **Sta qui e non nel filtro di Filament** perche' e' la definizione di
     * un fatto, non di una schermata: la usano il pannello e i test, e una
     * definizione scritta in due posti diverge al primo ripensamento.
     *
     * ⛔ Le parentesi non sono decorative: senza, l'`or` uscirebbe dalla
     * condizione e si porterebbe dietro la ricerca e gli altri filtri.
     */
    public function scopeSenzaMuscoliDecisi(Builder $query): Builder
    {
        /*
         * 🚨 **Il `where` annidato passa dal query builder, non da Eloquent.**
         *
         * ⛔ La forma naturale — `$query->where(fn ($w) => ...)` — dentro un
         * filtro di Filament fa esplodere il **disegno della pagina**: il
         * builder arriva senza modello, e `Eloquent\Builder::where()` con una
         * closure chiama `$this->model->newQueryWithoutRelationships()`.
         * L'errore («on null») salta fuori nella vista, dove nessun test sui
         * dati lo prenderebbe.
         *
         * 💡 `getQuery()` scende al query builder, il cui `where()` annidato
         * non ha bisogno di nessun modello.
         */
        $query->getQuery()->where(static function (QueryBuilder $w): void {
            $w->whereNull('muscle_group')->orWhereNull('secondary_muscles');
        });

        return $query;
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $norm = self::normalize($term);

        return $query->where(function (Builder $q) use ($term, $norm): void {
            $q->where('name', 'like', '%'.$term.'%')
                ->orWhere('slug_normalized', 'like', '%'.$norm.'%');
        });
    }

    /** Il MET dell'esercizio, o quello generico se non e' noto. */
    public function metOrDefault(float $default): float
    {
        return $this->met !== null && $this->met > 0 ? (float) $this->met : $default;
    }

    // ───────────────────────── immagine ─────────────────────────

    /**
     * L'illustrazione dell'esercizio — C23.
     *
     * 🚨 **URL pubblico, a differenza delle foto dei progressi.** Quelle passano
     * da un endpoint che controlla di chi sono, perche' sono dati personali.
     * Questo e' il disegno di un bilanciere: farlo passare da un controllo di
     * accesso vorrebbe dire un'intestazione a mano su ogni miniatura (come in
     * `progressAuthHeaderProvider` lato app) e nessuna cache di rete, in cambio
     * di niente.
     *
     * `singleFile()`: caricandone una nuova, la vecchia sparisce. Senza, ogni
     * modifica lascerebbe dietro un file che non cancella piu' nessuno.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION_IMMAGINE)
            ->acceptsMimeTypes(['image/jpeg', 'image/png', 'image/webp'])
            ->singleFile();
    }

    public function imageUrl(): ?string
    {
        return $this->getFirstMediaUrl(self::COLLECTION_IMMAGINE) ?: null;
    }

    /**
     * Chi ha fatto il disegno — 3b-L, 28/08/2026.
     *
     * ══ ⚖️ E' UN OBBLIGO, NON UNA CORTESIA ════════════════════════════════
     *
     * Le illustrazioni del catalogo sono **CC BY-SA 4.0**: si possono usare
     * anche commercialmente, ma l'attribuzione e' una condizione della
     * licenza. ⛔ Senza, l'uso non e' autorizzato.
     *
     * 🚨 **`null` quando la foto l'ha caricata la palestra**, ed e' la cosa
     * importante: quella foto e' loro, e scriverci sotto «Bryl Lim /
     * Everkinetic» sarebbe un credito **falso** — attribuire a qualcun altro
     * un lavoro che non ha fatto e' un danno peggiore del non attribuire.
     *
     * 💡 Il valore vive nelle proprieta' del file, non in una colonna: cambia
     * insieme all'immagine perche' e' una proprieta' **dell'immagine**, non
     * dell'esercizio.
     */
    public function imageCredit(): ?string
    {
        $media = $this->getFirstMedia(self::COLLECTION_IMMAGINE);

        if ($media === null) {
            return null;
        }

        $credito = $media->getCustomProperty('credito');

        return is_string($credito) && $credito !== '' ? $credito : null;
    }
}
