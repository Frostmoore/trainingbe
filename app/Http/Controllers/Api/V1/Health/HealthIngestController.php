<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Health;

use App\Enums\HealthMetric;
use App\Enums\SleepStage;
use App\Http\Controllers\Controller;
use App\Models\HealthReading;
use App\Models\HealthSample;
use App\Models\User;
use App\Services\Health\SleepAnalyzer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * L'ingest dei dati dell'orologio — B9.1.
 *
 * 🚨 **Sta FUORI dal gruppo `auth:sanctum`**, ed e' l'unico endpoint scrivente
 * che lo fa. Il motivo: chi manda i dati e' un'automazione sul telefono
 * (Health Connect, un'automazione iOS) che non ha una sessione ne' un token di
 * Sanctum. Autentica il solo `token`, che e' **per utente** — e la differenza
 * rispetto a un token globale e' tutta: revocarlo per una persona non tocca
 * nessun altro, e chi lo estrae dal proprio telefono puo' scrivere solo i propri
 * dati.
 *
 * 🚨 **Timestamp: conversione esplicita.** L'orologio manda UTC, l'applicazione
 * ragiona in `Europe/Rome`. Senza `setTimezone()` esplicito la notte finirebbe
 * spostata di un paio d'ore, e a cavallo di mezzanotte verrebbe attribuita al
 * giorno sbagliato — un errore che si vede solo su alcune notti e sembra un
 * capriccio dell'orologio.
 */
class HealthIngestController extends Controller
{
    public function __construct(
        private readonly TenantContext $tenants,
        private readonly SleepAnalyzer $analyzer,
    ) {}

    /**
     * Riceve i campioni di sonno.
     *
     * Idempotente per costruzione: il vincolo `UNIQUE(user, source, started_at)`
     * fa si' che rimandare gli stessi campioni non li duplichi. L'orologio li
     * rimanda a ogni sincronizzazione, e senza questo una notte risulterebbe di
     * trenta ore.
     */
    public function store(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'source' => ['nullable', 'string', 'max:32'],

            /*
             * ⚠️ `samples` non è più obbligatorio, ma **almeno uno dei due** sì.
             *
             * Il ponte sul telefono manda già `samples` (il sonno) e continuerà
             * a farlo: renderlo facoltativo permette a una versione futura di
             * mandare solo le misure senza che le vecchie smettano di
             * funzionare. `required_without` copre il caso in cui non arrivi
             * niente, che altrimenti risponderebbe 201 «accettati: 0» — un
             * successo che non ha accettato nulla.
             */
            'samples' => ['nullable', 'array', 'max:2000', 'required_without:readings'],
            'samples.*.start' => ['required', 'date'],
            'samples.*.end' => ['required', 'date'],
            'samples.*.stage' => ['required', 'integer', 'min:0', 'max:20'],

            // ── D3: HRV e battito ─────────────────────────────────────────
            'readings' => ['nullable', 'array', 'max:2000', 'required_without:samples'],
            'readings.*.metric' => ['required', Rule::enum(HealthMetric::class)],
            'readings.*.measured_at' => ['required', 'date'],
            'readings.*.value' => ['required', 'numeric'],
        ]);

        $utente = User::withoutGlobalScopes()
            ->where('health_ingest_token', $dati['token'])
            ->where('is_active', true)
            ->first();

        // Token sconosciuto e utente disattivato danno la stessa risposta:
        // distinguerli direbbe a chi prova token quali esistono.
        if ($utente === null) {
            return response()->json(['message' => __('Token non valido.')], 401);
        }

        $palestra = $utente->tenant;

        if ($palestra === null || ! $palestra->isActive()) {
            return response()->json(['message' => __('Palestra non attiva.')], 403);
        }

        $fuso = config('app.timezone');
        $sorgente = $dati['source'] ?? 'health_connect';
        $scritti = 0;
        $misure = 0;
        $scartate = 0;

        $this->tenants->runAs($palestra, function () use ($dati, $utente, $fuso, $sorgente, &$scritti, &$misure, &$scartate): void {
            foreach ($dati['readings'] ?? [] as $lettura) {
                $metrica = HealthMetric::from((string) $lettura['metric']);
                $valore = (float) $lettura['value'];

                /*
                 * 🚨 I valori assurdi si scartano **all'ingresso**, e si contano.
                 *
                 * Un HRV di 900 ms o un battito di 300 non sono dati: sono un
                 * sensore che ha sbagliato. Se entrassero sposterebbero la media
                 * della persona, e da quel momento **ogni** scostamento
                 * calcolato su di essa sarebbe falso senza che niente lo
                 * segnali. Si contano invece di ignorarli in silenzio: chi manda
                 * i dati deve poter vedere che qualcosa non torna.
                 */
                if (! $metrica->isPlausible($valore)) {
                    $scartate++;

                    continue;
                }

                $quando = Carbon::parse($lettura['measured_at'])->setTimezone($fuso);

                HealthReading::updateOrCreate(
                    [
                        'user_id' => $utente->getKey(),
                        'source' => $sorgente,
                        'metric' => $metrica->value,
                        'measured_at' => $quando,
                    ],
                    [
                        'tenant_id' => $utente->tenant_id,
                        'day' => $quando->toDateString(),
                        'value' => $valore,
                    ],
                );

                $misure++;
            }

            foreach ($dati['samples'] ?? [] as $campione) {
                // 🚨 Conversione esplicita: vedi la nota di classe.
                $inizio = Carbon::parse($campione['start'])->setTimezone($fuso);
                $fine = Carbon::parse($campione['end'])->setTimezone($fuso);

                if ($fine->lessThanOrEqualTo($inizio)) {
                    continue;
                }

                HealthSample::updateOrCreate(
                    [
                        'user_id' => $utente->getKey(),
                        'source' => $sorgente,
                        'started_at' => $inizio,
                    ],
                    [
                        'tenant_id' => $utente->tenant_id,
                        'night' => HealthSample::nightOf($inizio)->toDateString(),
                        'ended_at' => $fine,
                        'stage' => SleepStage::fromHealthConnect((int) $campione['stage']),
                    ],
                );

                $scritti++;
            }
        });

        return response()->json(['data' => [
            'accepted' => $scritti,
            'readings_accepted' => $misure,
            // Chi manda i dati deve vedere che qualcosa è stato buttato: uno
            // scarto silenzioso è indistinguibile da un dato mai inviato.
            'readings_discarded' => $scartate,
        ]], 201);
    }

    /** Il riepilogo di una notte, per l'app dell'iscritto. */
    public function sleep(Request $request): JsonResponse
    {
        $data = $request->query('night');

        $notte = is_string($data) && $data !== ''
            ? Carbon::parse($data)->startOfDay()
            : HealthSample::nightOf(Carbon::now());

        return response()->json([
            'data' => $this->analyzer->night($request->user(), $notte),
        ]);
    }

    /**
     * Genera (o rigenera) il token di ingest dell'utente.
     *
     * Rigenerarlo invalida quello vecchio: e' l'unica via se un telefono viene
     * perso, e per questo la risposta contiene il token **una volta sola**, come
     * per i token di Sanctum.
     */
    public function rotateToken(Request $request): JsonResponse
    {
        $utente = $request->user();

        $token = bin2hex(random_bytes(32));

        $utente->forceFill(['health_ingest_token' => $token])->save();

        return response()->json(['data' => [
            'token' => $token,
            'endpoint' => url('/api/v1/health/ingest'),
        ]], 201);
    }
}
