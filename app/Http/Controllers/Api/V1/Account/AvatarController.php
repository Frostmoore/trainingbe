<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * L'immagine del profilo — M7.2, 18/08/2026.
 *
 * ── 🚨 Perche' l'avatar sta sul server, se «il sensibile resta sul telefono»
 *
 * Perche' **serve a farsi riconoscere da qualcun altro**, ed e' l'unica
 * categoria di dato personale che ha senso condividere: un trainer deve vedere
 * la faccia di chi gli scrive, e una persona deve vedere quella del suo trainer.
 * ⚠️ Le foto dei **progressi** — quelle sono un'altra cosa e restano sul
 * telefono (S5.4): le rotte `photos` sono state cancellate apposta.
 *
 * 💡 La differenza in una riga: **questa la mostri, quelle le nascondi.**
 *
 * ── ⚠️ Il caricamento e' l'endpoint piu' pericoloso di un'API ──────────────
 *
 * Chi carica un file decide **cosa** arriva sul disco del server. Le tre
 * difese qui sotto non sono formalita':
 *
 * 1. `image` + `mimes` — non basta l'estensione, Laravel guarda il contenuto;
 * 2. il nome del file lo **genera il server**, non chi carica;
 * 3. il disco e' `public`, che sta fuori dalla radice del codice: anche un file
 *    che riuscisse a essere PHP non verrebbe mai eseguito.
 */
class AvatarController extends Controller
{
    /** ⚠️ 4 MB: una foto di profilo che pesa di piu' e' una foto non ridimensionata. */
    private const BYTE_MASSIMI = 4096;

    private const CARTELLA = 'avatar';

    /**
     * `POST /api/v1/account/avatar`
     *
     * 💡 `POST` e non `PUT`: si manda un file, non lo stato completo di una
     * risorsa, e i `multipart` con `PUT` sono un modo classico di litigare con
     * i proxy.
     */
    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'avatar' => [
                'required',
                'image',
                /*
                 * 🚨 L'elenco e' corto di proposito: `svg` **non** c'e'.
                 *
                 * ⚠️ Un SVG e' un documento XML che puo' contenere script: e'
                 * un'immagine per il validatore e un vettore di attacco per il
                 * browser che la mostra.
                 */
                'mimes:jpeg,png,webp',
                'max:'.self::BYTE_MASSIMI,
            ],
        ]);

        $utente = $request->user();

        /*
         * 🚨 **Il nome lo decide il server.**
         *
         * ⚠️ Usare il nome originale vorrebbe dire lasciare a chi carica il
         * controllo del percorso: `../../qualcosa.php` e' il primo tentativo di
         * chiunque. E due persone con `foto.jpg` si sovrascriverebbero a vicenda.
         */
        $nome = $utente->getKey().'-'.bin2hex(random_bytes(8)).'.'
            .$request->file('avatar')->extension();

        $percorso = $request->file('avatar')->storeAs(self::CARTELLA, $nome, 'public');

        /*
         * 💡 Il vecchio si cancella **dopo** che il nuovo e' salvato.
         *
         * ⚠️ Al contrario, un caricamento fallito lascerebbe la persona senza
         * immagine e senza averla cambiata. E si cancella solo se stava nella
         * nostra cartella: `avatar_path` e' una colonna, e una colonna puo'
         * contenere qualunque cosa ci sia finita dentro in passato.
         */
        $this->cancellaIlVecchio($utente->avatar_path);

        $utente->forceFill(['avatar_path' => Storage::url($percorso)])->save();

        return response()->json(['data' => ['avatar_url' => $utente->avatarUrl()]], 201);
    }

    /**
     * `DELETE /api/v1/account/avatar` — si torna alle iniziali.
     *
     * 💡 Poter **togliere** un'immagine di se' non e' una rifinitura: e' la
     * differenza fra un dato che si e' scelto di dare e uno che non si puo' piu'
     * ritirare.
     */
    public function destroy(Request $request): JsonResponse
    {
        $utente = $request->user();

        $this->cancellaIlVecchio($utente->avatar_path);
        $utente->forceFill(['avatar_path' => null])->save();

        return response()->json(['data' => ['avatar_url' => null]]);
    }

    private function cancellaIlVecchio(?string $url): void
    {
        if ($url === null) {
            return;
        }

        $prefisso = '/storage/'.self::CARTELLA.'/';

        if (! str_contains($url, $prefisso)) {
            return;
        }

        $relativo = self::CARTELLA.'/'.basename($url);

        if (Storage::disk('public')->exists($relativo)) {
            Storage::disk('public')->delete($relativo);
        }
    }
}
