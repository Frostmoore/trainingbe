<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Filament\Gym\Resources\PlanImports\PlanImportResource;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * 📄 **Il documento da cui è nata una scheda, per chi la sta rivedendo** —
 * 23/09/2026, fase F7.2.
 *
 * 📌 Il committente: *«deve avere la scheda vicino (visualizzatore pdf o
 * immagine) per poterla confrontare con il risultato dell'import»*.
 *
 * ══ 🔒 PERCHÉ UNA ROTTA E NON UN INDIRIZZO DEL DISCO ═════════════════════
 *
 * 🚨 Il documento sta sul disco **privato** (F7): non ha un URL, di proposito.
 * Fino a oggi stava su quello pubblico, e bastava indovinare un numero
 * sequenziale per leggere la scheda di un'altra palestra.
 *
 * ✅ Questa rotta vive **dentro il pannello** (`authenticatedRoutes`), quindi
 * passa da `Authenticate` e da `ResolveTenant`; e il documento si cerca con
 * `PlanImportResource::getEloquentQuery()`, lo **stesso** filtro dell'elenco.
 * ⛔ Un id di un'altra palestra risponde 404 — non 403, che confermerebbe che
 * quel documento esiste.
 *
 * ── 💡 `inline`, non `attachment` ─────────────────────────────────────────
 *
 * Il documento si **guarda** dentro la pagina, accanto al modulo: con
 * `attachment` il browser lo scaricherebbe invece di mostrarlo.
 */
class DocumentoDellImport extends Controller
{
    public function __invoke(int $import): BinaryFileResponse
    {
        $record = PlanImportResource::getEloquentQuery()->findOrFail($import);

        $documento = $record->documento();

        abort_if($documento === null || ! is_readable($documento->getPath()), 404);

        return response()->file($documento->getPath(), [
            'Content-Type' => $documento->mime_type,
            /*
             * ⚠️ `HeaderUtils` e non una stringa composta a mano: il nome del
             * file lo sceglie chi carica, e un apice o una lettera accentata
             * nel nome romperebbero l'intestazione.
             */
            'Content-Disposition' => HeaderUtils::makeDisposition(
                HeaderUtils::DISPOSITION_INLINE,
                $documento->file_name,
                Str::ascii($documento->file_name) ?: 'documento',
            ),

            /*
             * 🚨 **Non si tiene in cache.** È la scheda di una persona: un
             * computer condiviso in reception non deve poterla riaprire dalla
             * cronologia dopo che chi l'ha guardata è uscito dal pannello.
             */
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
