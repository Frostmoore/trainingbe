<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Privacy\LeLiberatorie;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * ✍️ **La liberatoria del trainer, dall'app** — art. 28 GDPR, 20/09/2026.
 *
 * 💡 La palestra la firma nel pannello; il trainer vive nell'app, e qui ha le
 * due chiamate che gli servono: cosa deve firmare, e la firma.
 *
 * ⚠️ `show` risponde **anche a chi non deve firmare niente** (`serve: false`):
 * un 404 costringerebbe l'app a distinguere «non ti serve» da «è andata
 * storta», che sono due schermate diverse.
 */
class LiberatoriaController extends Controller
{
    public function __construct(private readonly LeLiberatorie $liberatorie) {}

    public function show(Request $request): JsonResponse
    {
        $persona = $request->user();
        $tipo = $this->liberatorie->serveA($persona);

        return response()->json(['data' => [
            'serve' => $tipo !== null,
            'tipo' => $tipo,
            'versione' => $tipo === null ? null : LeLiberatorie::VERSIONI[$tipo],
            'testo' => $tipo === null ? null : $this->liberatorie->testo($tipo),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $persona = $request->user();
        $tipo = $this->liberatorie->serveA($persona);

        if ($tipo === null) {
            /*
             * 💡 Non è un errore: vuol dire che nel frattempo l'ha già firmata
             * — da un altro dispositivo, o due volte di fila sullo stesso.
             */
            return response()->json(['data' => ['serve' => false, 'tipo' => null]]);
        }

        $firma = $this->liberatorie->firma($persona, $tipo, $request);

        return response()->json(['data' => [
            'serve' => false,
            'tipo' => $tipo,
            'versione' => $firma->versione,
            'accettata_il' => $firma->accettata_il?->toIso8601String(),
        ]], 201);
    }
}
