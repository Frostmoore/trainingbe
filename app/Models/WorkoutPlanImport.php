<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ImportStatus;
use App\Enums\MuscleGroup;
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
use Spatie\MediaLibrary\MediaCollections\Models\Media;

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

        /*
         * 🚨 **La decisione del cancello, non un ricalcolo** — U.6.
         *
         * Si scrive quando la palestra carica il PDF e si legge nel job, minuti
         * dopo. Ricalcolarla la' vorrebbe dire due sedi della stessa regola
         * commerciale; il perche' sta per esteso nella migrazione.
         */
        'paga_con_gettoni',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImportStatus::class,
            'parsed_payload' => 'array',
            'confidence' => 'float',
            'escalated' => 'boolean',
            'paga_con_gettoni' => 'boolean',
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

    /**
     * ══ 🚨 SUL DISCO PRIVATO — 23/09/2026, fase F7 ═════════════════════════
     *
     * ⛔ **Fino a oggi questo PDF stava sul disco PUBBLICO**, e nessuno se n'era
     * accorto. Il commento in `ListPlanImports` diceva il contrario — *«va a
     * medialibrary, non sul disco pubblico: è la scheda di una persona»* — ma
     * medialibrary, senza `useDisk()`, usa **proprio** `public`
     * (`config/media-library.php`, `MEDIA_DISK` non impostato).
     *
     * 🚨 Voleva dire: raggiungibile da chiunque all'indirizzo
     * `/storage/{id}/{nome}.pdf`, con `id` **sequenziali** — cioè enumerabili
     * uno per uno. E dentro c'è la scheda di una persona, spesso con
     * l'intestazione dello studio e il nome del paziente (vedi la DPIA).
     *
     * ✅ Adesso `local` (`storage/app/private`): non ha un URL, e si legge solo
     * da `DocumentoDellImport`, che passa dall'autenticazione del pannello e dal
     * filtro della palestra. I file già caricati li sposta la migrazione
     * `i_pdf_importati_sul_disco_privato`.
     *
     * ── 🆕 E anche le foto ─────────────────────────────────────────────────
     *
     * 📌 Il committente: *«visualizzatore pdf o immagine»*. 💡 Il provider sa
     * già leggere le foto (`AnthropicProvider::blocchiDelDocumento`, per l'import
     * dall'app): mancava solo che il pannello le accettasse.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::COLLECTION)
            ->useDisk('local')
            ->singleFile()
            ->acceptsMimeTypes(self::TIPI_ACCETTATI);
    }

    /**
     * I documenti che il pannello accetta — F7.
     *
     * ⚠️ Sono **gli stessi** che `AnthropicProvider::blocchiDelDocumento` sa
     * spedire al modello: un tipo accettato qui e non là si caricherebbe e poi
     * fallirebbe nel job, con i gettoni già riservati.
     */
    public const TIPI_ACCETTATI = ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'];

    /** Il documento caricato, se c'è ancora. */
    public function documento(): ?Media
    {
        return $this->getFirstMedia(self::COLLECTION);
    }

    /**
     * 🖼️ È una foto e non un PDF? — decide come mostrarlo accanto alla scheda:
     * un PDF va in un riquadro col lettore del browser, una foto in un `<img>`.
     */
    public function eUnaImmagine(): bool
    {
        return str_starts_with((string) $this->documento()?->mime_type, 'image/');
    }

    /** Il percorso del documento sul disco, o `null` se non c'e'. */
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
     * ══ 🆕 📅 I GIORNI ARRIVANO NELLA SCHEDA — 23/09/2026, F7.4 ══════════════
     *
     * 🚨 **Prima tutti gli esercizi finivano in un giorno solo.** Il modello i
     * giorni li legge (`day` su ogni riga, `day_names` sulla scheda — K2), l'app
     * li usa, ma qui si chiamava solo `giornoPredefinito()`: una scheda A/B/C da
     * diciannove esercizi diventava **un unico allenamento da diciannove
     * esercizi**, e il builder la mostrava così. Trovato al primo giro vero
     * dell'import dal pannello (F7.4).
     *
     * 💡 Adesso un giorno per ogni numero di giorno che compare nelle righe,
     * col nome letto dal foglio (`day_names`) o «Giorno n». ⚠️ Una scheda di
     * **un giorno solo** resta senza nome, come la scrive il builder
     * (*«una scheda a un giorno solo si scrive lasciando il nome vuoto»*).
     *
     * @param  array<int, array<string, mixed>>|null  $righe  la bozza eventualmente corretta a mano
     * @param  list<string>|null  $nomiDeiGiorni  i nomi dei giorni corretti a mano (`null` = quelli letti)
     */
    public function publishAsPlan(?array $righe = null, ?User $da = null, ?array $nomiDeiGiorni = null): WorkoutPlan
    {
        $payload = $this->parsed_payload ?? [];
        $righe ??= $payload['exercises'] ?? [];
        $nomiDeiGiorni ??= $payload['day_names'] ?? [];

        $matcher = app(ExerciseMatcher::class);

        return DB::transaction(function () use ($payload, $righe, $da, $matcher, $nomiDeiGiorni): WorkoutPlan {
            $piano = WorkoutPlan::create([
                'tenant_id' => $this->tenant_id,
                'member_id' => $this->member_id,
                'created_by' => $da?->getKey() ?? $this->uploaded_by,
                'name' => $payload['name'] ?? 'Scheda importata',
                'notes' => $payload['notes'] ?? null,

                /*
                 * 🆕 **Nasce `published`** — 23/09/2026, F7 (come in F5.14).
                 *
                 * ⛔ Prima nasceva `draft`, «perché l'iscritto non la veda
                 * finché non è pronta». Dopo R2 non c'è nessun iscritto: è un
                 * **modello della palestra**, e lo stato non si cambia più dal
                 * pannello. Una scheda importata nata bozza sarebbe rimasta
                 * bozza per sempre — invisibile all'API, senza un campo per
                 * sbloccarla.
                 */
                'status' => PlanStatus::Published,
                'source' => PlanSource::PdfImport,
            ]);

            $giorni = $this->creaIGiorni($piano, $righe, $nomiDeiGiorni);

            $posizione = 1;

            foreach ($righe as $riga) {
                $nome = trim((string) ($riga['name'] ?? ''));

                if ($nome === '') {
                    continue;
                }

                $giorno = $giorni[self::giornoDellaRiga($riga)];

                /*
                 * 🆕 3b-A.3.4 — i muscoli letti dal modello entrano nella
                 * libreria insieme all'esercizio.
                 *
                 * ⚠️ `array_key_exists` e non `isset`: una riga corretta a mano
                 * che manda un elenco **vuoto** sta dicendo «questo esercizio
                 * isola», ed e' una risposta da conservare — `isset` la
                 * confonderebbe con «non lo so».
                 */
                $primario = $riga['muscle_group'] ?? null;

                $esercizio = $matcher->match(
                    $nome,
                    $this->tenant_id,
                    $da,
                    is_string($primario) ? MuscleGroup::tryFrom($primario) : null,
                    array_key_exists('secondary_muscles', $riga) && is_array($riga['secondary_muscles'])
                        ? array_values(array_map(strval(...), $riga['secondary_muscles']))
                        : null,
                );

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

    /**
     * I giorni della scheda nuova, indicizzati dal numero di giorno (da 1).
     *
     * 💡 `max` e non «quelli che compaiono»: se il modello salta il giorno 2 i
     * giorni restano tre, e il secondo si vede **vuoto** nel builder invece di
     * sparire — stessa regola di `ParsedWorkoutPlan::quantiGiorni()`.
     *
     * @param  array<int, array<string, mixed>>  $righe
     * @param  list<string>  $nomi
     * @return array<int, WorkoutPlanDay>
     */
    private function creaIGiorni(WorkoutPlan $piano, array $righe, array $nomi): array
    {
        $quanti = 1;

        foreach ($righe as $riga) {
            if (trim((string) ($riga['name'] ?? '')) !== '') {
                $quanti = max($quanti, self::giornoDellaRiga($riga));
            }
        }

        // G4 — una scheda di un giorno solo usa il giorno che il piano crea da sé.
        if ($quanti === 1) {
            $solo = $piano->giornoPredefinito();
            $nome = trim((string) ($nomi[0] ?? ''));

            if ($nome !== '') {
                $solo->forceFill(['name' => mb_substr($nome, 0, 120)])->save();
            }

            return [1 => $solo];
        }

        $giorni = [];

        for ($n = 1; $n <= $quanti; $n++) {
            $nome = trim((string) ($nomi[$n - 1] ?? ''));

            $giorni[$n] = $piano->days()->create([
                'position' => $n - 1,
                'name' => mb_substr($nome !== '' ? $nome : 'Giorno '.$n, 0, 120),
            ]);
        }

        return $giorni;
    }

    /**
     * Il giorno di una riga, contando da 1.
     *
     * ⚠️ Una riga aggiunta a mano nella revisione può non averlo, o averlo
     * scritto come stringa: vale 1, come in `ParsedWorkoutExercise::fromArray()`.
     *
     * @param  array<string, mixed>  $riga
     */
    private static function giornoDellaRiga(array $riga): int
    {
        return max(1, (int) ($riga['day'] ?? 1));
    }
}
