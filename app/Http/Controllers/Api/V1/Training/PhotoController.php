<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Training;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Le foto dei progressi — B4.2 / B4.5.
 *
 * 🚨 **Il file NON si serve da un URL pubblico.** Sono le foto piu' personali
 * che questo sistema conserva, e un percorso indovinabile su un disco pubblico
 * significa che chiunque conosca lo schema le scarica senza autenticarsi. Passa
 * tutto da `file()`, che controlla di chi e' la foto prima di consegnarla.
 *
 * Il `tenant_id` sul media (vedi `App\Models\Media`) e' cio' che rende la
 * verifica una condizione e non una join fatta a mano su un morph.
 */
class PhotoController extends Controller
{
    private const COLLECTION = 'progress';

    public function index(Request $request): JsonResponse
    {
        $utente = $request->user();

        $foto = $utente->getMedia(self::COLLECTION)
            ->sortByDesc('created_at')
            ->values();

        return response()->json([
            'data' => $foto->map(fn (Media $m): array => $this->riga($m))->all(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'photo' => ['required', 'image', 'max:12288'],
            'taken_on' => ['nullable', 'date', 'before_or_equal:today'],
        ]);

        $utente = $request->user();

        $media = $utente
            ->addMediaFromRequest('photo')
            ->withCustomProperties([
                'taken_on' => $request->input('taken_on', now()->toDateString()),
            ])
            ->toMediaCollection(self::COLLECTION);

        // Il trait sul modello riempie `tenant_id` alla creazione, ma qui il
        // media nasce dentro medialibrary: lo si scrive esplicitamente perche'
        // una foto senza palestra sfuggirebbe a ogni conteggio e a ogni
        // cancellazione di cliente.
        if ($media->tenant_id === null) {
            $media->forceFill(['tenant_id' => $utente->tenant_id])->save();
        }

        return response()->json(['data' => $this->riga($media)], 201);
    }

    /**
     * Consegna il file, dopo aver verificato che sia di chi lo chiede.
     */
    public function file(Request $request, int $photo): BinaryFileResponse|JsonResponse
    {
        $media = $this->mediaDi($request->user(), $photo);

        if ($media === null) {
            return response()->json(['message' => __('Foto non trovata.')], 404);
        }

        return response()->file($media->getPath());
    }

    public function destroy(Request $request, int $photo): JsonResponse
    {
        $media = $this->mediaDi($request->user(), $photo);

        if ($media === null) {
            return response()->json(['message' => __('Foto non trovata.')], 404);
        }

        $media->delete();

        return response()->json(null, 204);
    }

    /**
     * 🚨 Tre condizioni insieme, e nessuna e' superflua:
     * il media deve appartenere a **questo modello**, a **questo utente** e a
     * **questa collezione**. Senza l'ultima, l'id di un logo o di un PDF
     * importato passerebbe da qui.
     */
    private function mediaDi(User $utente, int $id): ?Media
    {
        return Media::query()
            ->where('id', $id)
            ->where('model_type', $utente->getMorphClass())
            ->where('model_id', $utente->getKey())
            ->where('collection_name', self::COLLECTION)
            ->first();
    }

    /** @return array<string, mixed> */
    private function riga(Media $m): array
    {
        return [
            'id' => $m->id,
            'file_name' => $m->file_name,
            'size' => $m->size,
            'mime_type' => $m->mime_type,
            'taken_on' => $m->getCustomProperty('taken_on'),
            'created_at' => $m->created_at?->toIso8601String(),
            // L'unico modo per scaricarla: passa dal controllo di proprieta'.
            'url' => url("/api/v1/photos/{$m->id}/file"),
        ];
    }
}
