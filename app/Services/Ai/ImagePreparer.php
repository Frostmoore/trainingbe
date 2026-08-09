<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Services\Ai\Exceptions\AiUnavailableException;

/**
 * Prepara un'immagine prima di mandarla a un modello.
 *
 * 🚨 **Ridimensionare non e' un'ottimizzazione facoltativa: e' la seconda delle
 * tre leve di risparmio della piattaforma.** I token immagine crescono con
 * l'**area**, quindi una foto da fotocamera moderna (4000 px sul lato lungo)
 * costa circa sei volte una da 1568 px senza migliorare di nulla la stima di un
 * piatto — il modello non ha bisogno di leggere l'etichetta di un barattolo sullo
 * sfondo.
 *
 * Usa GD, che e' nell'interprete di progetto (vedi `php.ini` di `php84`): niente
 * dipendenze aggiuntive per una cosa che deve funzionare sempre.
 */
class ImagePreparer
{
    /**
     * @return array{data: string, mime: string} contenuto base64 e tipo
     */
    public function prepare(string $absolutePath, string $mimeType): array
    {
        if (! is_readable($absolutePath)) {
            throw new AiUnavailableException('ai_image_unreadable', 'Immagine non leggibile.');
        }

        $maxEdge = (int) config('ai.image.max_edge', 1568);

        $immagine = $this->load($absolutePath, $mimeType);

        if ($immagine === null) {
            // Formato che GD non apre: si manda com'e'. Meglio una chiamata piu'
            // cara di una funzione che non funziona.
            return [
                'data' => base64_encode((string) file_get_contents($absolutePath)),
                'mime' => $mimeType,
            ];
        }

        $w = imagesx($immagine);
        $h = imagesy($immagine);
        $lato = max($w, $h);

        if ($lato <= $maxEdge) {
            imagedestroy($immagine);

            return [
                'data' => base64_encode((string) file_get_contents($absolutePath)),
                'mime' => $mimeType,
            ];
        }

        $scala = $maxEdge / $lato;
        $ridotta = imagescale($immagine, (int) round($w * $scala), (int) round($h * $scala));
        imagedestroy($immagine);

        if ($ridotta === false) {
            return [
                'data' => base64_encode((string) file_get_contents($absolutePath)),
                'mime' => $mimeType,
            ];
        }

        ob_start();
        imagejpeg($ridotta, null, (int) config('ai.image.jpeg_quality', 85));
        $binario = (string) ob_get_clean();
        imagedestroy($ridotta);

        // Sempre JPEG in uscita: un PNG di una foto pesa il triplo e il modello
        // non ne trae nessun vantaggio.
        return ['data' => base64_encode($binario), 'mime' => 'image/jpeg'];
    }

    private function load(string $path, string $mime): ?\GdImage
    {
        if (! function_exists('imagecreatefromjpeg')) {
            return null;
        }

        $img = match (true) {
            str_contains($mime, 'jpeg'), str_contains($mime, 'jpg') => @imagecreatefromjpeg($path),
            str_contains($mime, 'png') => @imagecreatefrompng($path),
            str_contains($mime, 'webp') => @imagecreatefromwebp($path),
            default => false,
        };

        return $img === false ? null : $img;
    }
}
