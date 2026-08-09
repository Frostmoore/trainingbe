<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Health;

use App\Enums\SleepStage;
use App\Http\Controllers\Controller;
use App\Models\HealthSample;
use App\Models\User;
use App\Services\Health\SleepAnalyzer;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

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
            'samples' => ['required', 'array', 'min:1', 'max:2000'],
            'samples.*.start' => ['required', 'date'],
            'samples.*.end' => ['required', 'date'],
            'samples.*.stage' => ['required', 'integer', 'min:0', 'max:20'],
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

        $this->tenants->runAs($palestra, function () use ($dati, $utente, $fuso, $sorgente, &$scritti): void {
            foreach ($dati['samples'] as $campione) {
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

        return response()->json(['data' => ['accepted' => $scritti]], 201);
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
