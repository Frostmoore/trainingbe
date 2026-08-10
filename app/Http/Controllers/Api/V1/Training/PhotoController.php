<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Training;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Models\User;
use App\Models\WorkoutSession;
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
    /** La galleria dei progressi: foto scattate apposta, senza contesto. */
    private const COLLECTION = 'progress';

    /**
     * Le foto scattate a fine allenamento — C5.
     *
     * 🚨 **Due collezioni e non un flag**, perche' la collezione e' cio' che
     * `mediaDi()` verifica: senza la separazione, l'id di una foto qualsiasi
     * passerebbe da un endpoint pensato per un'altra cosa. La foto di
     * allenamento resta legata alla sessione tramite la proprieta'
     * `workout_session_id`, e compare **anche** nella galleria: e' la stessa
     * foto guardata da due punti di vista, non due copie.
     */
    private const COLLECTION_WORKOUT = 'workout';

    /** @var list<string> */
    private const COLLEZIONI = [self::COLLECTION, self::COLLECTION_WORKOUT];

    public function index(Request $request): JsonResponse
    {
        $utente = $request->user();

        // Entrambe le collezioni: la galleria dei progressi mostra anche le
        // foto di allenamento, in ordine, come nell'app storica.
        $foto = $utente->getMedia(self::COLLECTION)
            ->merge($utente->getMedia(self::COLLECTION_WORKOUT))
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
            // C5 — la foto scattata a fine allenamento resta legata a quella
            // sessione: è ciò che dà la miniatura allo storico.
            'workout_session_id' => ['nullable', 'integer'],
        ]);

        $utente = $request->user();
        $sessione = null;

        if ($request->filled('workout_session_id')) {
            $sessione = WorkoutSession::query()
                ->forUser($utente)
                ->find($request->integer('workout_session_id'));

            /*
             * 🚨 La sessione dev'essere SUA.
             *
             * Senza questo controllo si potrebbe appendere una propria foto
             * alla sessione di un altro, e quella foto comparirebbe nel **suo**
             * storico. Il global scope non basta: dentro la stessa palestra le
             * sessioni degli altri esistono e hanno id validi.
             */
            if ($sessione === null) {
                return response()->json(['message' => __('Allenamento non trovato.')], 422);
            }
        }

        $proprieta = ['taken_on' => $request->input('taken_on', now()->toDateString())];

        if ($sessione !== null) {
            $proprieta['workout_session_id'] = $sessione->getKey();
        }

        $media = $utente
            ->addMediaFromRequest('photo')
            ->withCustomProperties($proprieta)
            ->toMediaCollection($sessione !== null ? self::COLLECTION_WORKOUT : self::COLLECTION);

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
            ->whereIn('collection_name', self::COLLEZIONI)
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
            // C5 — `null` per le foto dei progressi. L'app ci disegna sopra il
            // bordo dorato e il nome della scheda, come nell'app storica.
            'workout_session_id' => $m->getCustomProperty('workout_session_id'),
            'type' => $m->collection_name === self::COLLECTION_WORKOUT ? 'workout' : 'progress',
            // L'unico modo per scaricarla: passa dal controllo di proprieta'.
            'url' => url("/api/v1/photos/{$m->id}/file"),
        ];
    }
}
