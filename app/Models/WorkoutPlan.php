<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Una scheda di allenamento.
 *
 * `member_id` null = **modello** riutilizzabile della palestra; valorizzato =
 * scheda di quella persona. Assegnare significa **copiare** il modello, non
 * puntarci: vedi `assignTo()`, dove c'e' scritto perche'.
 */
class WorkoutPlan extends Model implements HasMedia
{
    use BelongsToTenant;
    use HasFactory;
    use InteractsWithMedia;
    use SoftDeletes;

    protected $attributes = [
        'status' => 'draft',
        'source' => 'manual',
    ];

    public const COLLECTION_IMMAGINE = 'image';

    protected $fillable = [
        // G4 — D3 (il promemoria privato) e D15 (l'identita' stabile).
        'rif_allievo', 'origine_id',
        'tenant_id', 'member_id', 'created_by', 'name', 'notes',
        'status', 'source', 'starts_at', 'ends_at', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => PlanStatus::class,
            'source' => PlanSource::class,
            'starts_at' => 'date',
            'ends_at' => 'date',
            'published_at' => 'datetime',
        ];
    }

    // ───────────────────────── relazioni ─────────────────────────

    /**
     * L'identita' stabile nasce con il piano — G4, D15.
     *
     * 🚨 **Non basta che la crei il controller.** Un piano nasce anche dal
     * pannello Filament, dai seeder, dall'import PDF e dai test: sei punti, e
     * pretendere che tutti si ricordino di generare un ULID e' il modo di
     * scoprire che uno non se l'e' ricordato — con il sintomo peggiore, cioe'
     * un piano che il telefono **non riconosce** e affianca invece di
     * sostituire.
     *
     * ⚠️ La colonna e' `unique` e nullable: piu' righe a `null` sono ammesse da
     * MariaDB, quindi l'indice da solo non protegge niente. Questo hook si'.
     *
     * 💡 Stessa forma di `PlanExercise::booted()`: l'invariante si rende
     * impossibile da violare, invece di limitarsi a rilevarlo.
     */
    protected static function booted(): void
    {
        static::creating(static function (self $piano): void {
            $piano->origine_id ??= (string) Str::ulid();
        });
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * I giorni della scheda — **solo i principali** (G4, D2).
     *
     * 🚨 `soloPrincipali()` non e' facoltativo: senza, un'alternativa di
     * giornata comparirebbe come un giorno vero e la scheda si sdoppierebbe.
     */
    public function days(): HasMany
    {
        return $this->hasMany(WorkoutPlanDay::class)
            ->whereNull('alternativa_di_id')
            ->orderBy('position');
    }

    /** Tutti i giorni, alternative comprese: per chi salva, duplica o serializza. */
    public function daysConAlternative(): HasMany
    {
        return $this->hasMany(WorkoutPlanDay::class)->orderBy('position');
    }

    /**
     * Tutti gli esercizi della scheda, di tutti i giorni — **senza le
     * alternative**.
     *
     * 🚨 **`whereNull('alternativa_di_id')` e' arrivato con G4, e senza di lui
     * questa relazione sarebbe diventata sbagliata di colpo.** Da D2 le
     * alternative sono righe di questa stessa tabella: una scheda da 6 esercizi
     * con due alternative ne avrebbe restituiti 8, e li avrebbe mostrati come
     * esercizi da fare — nel pannello, nell'API e dentro la busta cifrata.
     *
     * ⚠️ Non avrebbe dato nessun errore: avrebbe dato una scheda piu' lunga.
     */
    public function exercises(): HasMany
    {
        return $this->hasMany(PlanExercise::class)
            ->whereNull('alternativa_di_id')
            ->orderBy('position');
    }

    /** Tutti gli esercizi, alternative comprese: per chi salva o serializza. */
    public function exercisesConAlternative(): HasMany
    {
        return $this->hasMany(PlanExercise::class)->orderBy('position');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(WorkoutSession::class);
    }

    // ───────────────────────── stato ─────────────────────────

    public function isTemplate(): bool
    {
        return $this->member_id === null;
    }

    public function isVisibleToMember(): bool
    {
        return $this->member_id !== null && $this->status->isVisibleToMember();
    }

    /**
     * Pubblica: da qui in poi l'iscritto la vede nell'app.
     *
     * `published_at` si scrive **solo la prima volta**: e' la data in cui la
     * scheda e' entrata in vigore, e ripubblicandola dopo una correzione non
     * deve spostarsi — altrimenti «da quando segue questo programma?» non ha
     * piu' risposta.
     */
    public function publish(): void
    {
        $this->status = PlanStatus::Published;
        $this->published_at ??= now();
        $this->save();
    }

    public function archive(): void
    {
        $this->status = PlanStatus::Archived;
        $this->save();
    }

    /**
     * Assegna il modello a un iscritto, **copiandolo**.
     *
     * 🚨 Copiare e non condividere. Se venti persone puntassero alla stessa
     * riga, la prima personalizzazione — un peso diverso, un esercizio tolto
     * per un infortunio — le cambierebbe tutte. E' esattamente il motivo per cui
     * esiste la distinzione fra modello e scheda assegnata: il modello e' un
     * punto di partenza, non un contratto vincolante.
     */
    public function assignTo(User $member, ?User $by = null): self
    {
        $copia = $this->replicate(['published_at', 'origine_id']);
        $copia->member_id = $member->getKey();
        $copia->tenant_id = $member->tenant_id;
        $copia->created_by = $by?->getKey() ?? $this->created_by;
        $copia->status = PlanStatus::Draft;

        /*
         * 🚨 **`origine_id` nuovo, non copiato** — D15.
         *
         * L'identita' serve al telefono per riconoscere che una scheda arrivata
         * e' la **versione nuova** di una che ha gia', e sostituirla. Una copia
         * assegnata a un'altra persona non e' una versione nuova: e' un'altra
         * scheda. Copiando l'`origine_id` la seconda persona che la riceve si
         * vedrebbe sovrascrivere la propria — e l'indice unico lo impedirebbe
         * comunque, ma con un errore di chiave duplicata che non spiega niente.
         */
        $copia->origine_id = (string) Str::ulid();
        $copia->save();

        $this->copiaIGiorniIn($copia);

        return $copia;
    }

    // ───────────────────────── query ─────────────────────────

    /**
     * Il primo giorno della scheda, creandolo se non c'e' — G4.
     *
     * 🚨 **Serve perche' `plan_exercises.workout_plan_day_id` e' `NOT NULL`.**
     * Ogni punto che scrive esercizi passa di qui: il controller, l'import da
     * PDF, i seeder, i test. Senza, ognuno si inventerebbe il proprio modo di
     * creare il giorno — e uno di quei modi sarebbe sbagliato.
     *
     * 💡 `name` resta `null`: una scheda a un solo giorno non deve mostrare
     * un'intestazione che il trainer non ha scritto.
     */
    public function giornoPredefinito(): WorkoutPlanDay
    {
        return $this->days()->first()
            ?? $this->days()->create(['position' => 0]);
    }

    /**
     * Copia giorni, esercizi e alternative dentro un'altra scheda.
     *
     * ── 🚨 Perche' non basta un `foreach` ────────────────────────────────
     *
     * Da D2 le alternative sono righe **della stessa tabella**, e puntano alla
     * riga che sostituiscono con `alternativa_di_id`. Copiandole alla cieca, il
     * riferimento resterebbe agganciato all'**originale**: la copia
     * dell'alternativa punterebbe all'esercizio della scheda di partenza.
     *
     * ⚠️ Effetto: modificare il modello cambierebbe le alternative di tutte le
     * copie — cioe' esattamente cio' che `assignTo()` esiste per impedire, e per
     * di piu' in modo invisibile.
     *
     * 💡 Quindi due passate per livello: prima i principali, tenendo una mappa
     * `vecchio id → nuovo id`, poi le alternative, che la usano.
     */
    private function copiaIGiorniIn(self $destinazione): void
    {
        $mappaGiorni = [];

        // 1ª passata: i giorni principali.
        foreach ($this->daysConAlternative()->whereNull('alternativa_di_id')->get() as $giorno) {
            $nuovo = $giorno->replicate();
            $nuovo->workout_plan_id = $destinazione->getKey();
            $nuovo->alternativa_di_id = null;
            $nuovo->save();

            $mappaGiorni[$giorno->getKey()] = $nuovo->getKey();
            $this->copiaGliEserciziIn($giorno, $nuovo, $destinazione);
        }

        // 2ª passata: i giorni alternativi, riagganciati alla **copia**.
        foreach ($this->daysConAlternative()->whereNotNull('alternativa_di_id')->get() as $giorno) {
            $nuovo = $giorno->replicate();
            $nuovo->workout_plan_id = $destinazione->getKey();
            $nuovo->alternativa_di_id = $mappaGiorni[$giorno->alternativa_di_id] ?? null;
            $nuovo->save();

            $this->copiaGliEserciziIn($giorno, $nuovo, $destinazione);
        }
    }

    private function copiaGliEserciziIn(WorkoutPlanDay $da, WorkoutPlanDay $a, self $destinazione): void
    {
        $mappa = [];

        foreach ($da->exercisesConAlternative()->whereNull('alternativa_di_id')->get() as $riga) {
            $nuova = $riga->replicate();
            $nuova->workout_plan_id = $destinazione->getKey();
            $nuova->workout_plan_day_id = $a->getKey();
            $nuova->alternativa_di_id = null;
            $nuova->save();

            $mappa[$riga->getKey()] = $nuova->getKey();
        }

        foreach ($da->exercisesConAlternative()->whereNotNull('alternativa_di_id')->get() as $riga) {
            $nuova = $riga->replicate();
            $nuova->workout_plan_id = $destinazione->getKey();
            $nuova->workout_plan_day_id = $a->getKey();
            $nuova->alternativa_di_id = $mappa[$riga->alternativa_di_id] ?? null;
            $nuova->save();
        }
    }

    public function scopeTemplates(Builder $query): Builder
    {
        return $query->whereNull('member_id');
    }

    public function scopeAssigned(Builder $query): Builder
    {
        return $query->whereNotNull('member_id');
    }

    public function scopeForMember(Builder $query, User|int $member): Builder
    {
        return $query->where('member_id', $member instanceof User ? $member->getKey() : $member);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', PlanStatus::Published->value);
    }

    // ───────────────────────── immagine ─────────────────────────

    /**
     * La copertina della scheda — C23.
     *
     * Stessa scelta di `Exercise::registerMediaCollections()`: URL pubblico,
     * un file solo. Una scheda si riconosce prima dall'immagine che dal nome,
     * e in un elenco di sei «Full body A/B/C» e' l'unica cosa che le
     * distingue a colpo d'occhio.
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
}
