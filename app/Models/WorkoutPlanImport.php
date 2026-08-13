<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\PlanSource;
use App\Enums\PlanStatus;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Ai\Data\ParsedWorkoutPlan;
use App\Services\Training\ExerciseMatcher;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

/**
 * Un PDF caricato, la bozza che ne e' uscita, e la scheda che ne nascera'.
 *
 * 🚨 **La bozza non e' una scheda.** Resta in `parsed_payload` finche' una
 * persona non preme «pubblica»: e' il cancello di cui parla `ImportStatus`.
 */
class WorkoutPlanImport extends Model implements HasMedia
{
    use BelongsToTenant;
    use InteractsWithMedia;

    public const COLLECTION = 'pdf';

    protected $attributes = [
        'status' => 'queued',
        'escalated' => false,
    ];

    protected $fillable = [
        'tenant_id', 'uploaded_by', 'member_id', 'media_id',
        'status', 'parsed_payload', 'model_used', 'confidence', 'escalated',
        'error', 'workout_plan_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'parsed_payload' => 'array',
            'confidence' => 'float',
            'escalated' => 'boolean',
        ];
    }

    // ───────────────────────── relazioni ─────────────────────────

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(User::class, 'member_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(WorkoutPlan::class, 'workout_plan_id');
    }

    // ───────────────────────── file ─────────────────────────

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION)
            ->singleFile()
            ->acceptsMimeTypes(['application/pdf']);
    }

    /** Il percorso del PDF sul disco, o `null` se non c'e'. */
    public function pdfPath(): ?string
    {
        $media = $this->getFirstMedia(self::COLLECTION);

        return $media !== null ? $media->getPath() : null;
    }

    // ───────────────────────── esito ─────────────────────────

    public function markProcessing(): void
    {
        $this->forceFill(['status' => ImportStatus::Processing])->save();
    }

    public function markFailed(string $errore): void
    {
        $this->forceFill([
            'status' => ImportStatus::Failed,
            // Il messaggio si tronca: un'eccezione con stack trace intero
            // renderebbe la colonna illeggibile e la tabella pesante.
            'error' => mb_substr($errore, 0, 2000),
        ])->save();
    }

    public function storeDraft(ParsedWorkoutPlan $bozza, string $modello, bool $escalated = false): void
    {
        $this->forceFill([
            'status' => ImportStatus::Review,
            'parsed_payload' => $bozza->toArray(),
            'model_used' => $modello,
            'confidence' => $bozza->confidence,
            'escalated' => $escalated,
            'error' => null,
        ])->save();
    }

    /**
     * Trasforma la bozza in una scheda vera.
     *
     * 🚨 **In transazione, e con la riconciliazione degli esercizi.** Se il
     * matcher fallisce a meta', non deve restare una scheda con tre esercizi su
     * venti: sarebbe peggio di nessuna scheda, perche' sembra completa.
     *
     * @param  array<int, array<string, mixed>>|null  $righe  la bozza eventualmente corretta a mano
     */
    public function publishAsPlan(?array $righe = null, ?User $da = null): WorkoutPlan
    {
        $payload = $this->parsed_payload ?? [];
        $righe ??= $payload['exercises'] ?? [];

        $matcher = app(ExerciseMatcher::class);

        return DB::transaction(function () use ($payload, $righe, $da, $matcher): WorkoutPlan {
            $piano = WorkoutPlan::create([
                'tenant_id' => $this->tenant_id,
                'member_id' => $this->member_id,
                'created_by' => $da?->getKey() ?? $this->uploaded_by,
                'name' => $payload['name'] ?? 'Scheda importata',
                'notes' => $payload['notes'] ?? null,
                'status' => PlanStatus::Draft,
                'source' => PlanSource::PdfImport,
            ]);

            // G4 — un giorno per la scheda che nasce dall'import.
            $giorno = $piano->giornoPredefinito();

            $posizione = 1;

            foreach ($righe as $riga) {
                $nome = trim((string) ($riga['name'] ?? ''));

                if ($nome === '') {
                    continue;
                }

                $esercizio = $matcher->match($nome, $this->tenant_id, $da);

                $piano->exercises()->create([
                    // G4 — la colonna e' NOT NULL: il giorno lo crea il piano.
                    'workout_plan_day_id' => $giorno->getKey(),
                    'exercise_id' => $esercizio->getKey(),
                    'position' => $posizione++,
                    'sets' => $riga['sets'] ?? null,
                    'reps' => $riga['reps'] ?? null,
                    'rest_sec' => $riga['rest_sec'] ?? null,
                    'target_weight' => $riga['target_weight'] ?? null,
                    'notes' => $riga['notes'] ?? null,
                ]);
            }

            $this->forceFill([
                'status' => ImportStatus::Done,
                'workout_plan_id' => $piano->getKey(),
            ])->save();

            return $piano;
        });
    }
}
