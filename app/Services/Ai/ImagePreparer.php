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
 *
 * ══ 🚨 E RICODIFICARE NON E' SOLO RISPARMIO: TOGLIE I METADATI ════════════
 *
 * ⚠️ **Difetto trovato il 22/08/2026, verificando cosa arriva davvero ad
 * Anthropic.** Una foto JPEG porta dentro l'EXIF: modello del telefono, data,
 * orientamento e — quando la fotocamera ha il permesso di posizione — le
 * **coordinate GPS** di dove e' stata scattata.
 *
 * 🚨 Fino a oggi la ricodifica avveniva **solo se la foto era piu' grande di
 * 1568 px**: chi mandava una foto gia' piccola — una ricevuta su WhatsApp, uno
 * screenshot, un'immagine dalla galleria gia' ridotta — la mandava **con i byte
 * originali**, EXIF compreso.
 *
 * ⛔ Non era un rischio teorico e non lo diceva nessun errore: la stessa
 * funzione, con la stessa firma, si comportava in due modi diversi a seconda
 * della dimensione dell'ingresso.
 *
 * 💡 **Adesso si ricodifica sempre.** Costa qualche millisecondo su una foto
 * gia' piccola, e rende vera una frase che l'app dice all'utente: quello che
 * mandiamo non ha dentro niente che lo identifichi.
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
            /*
             * ⛔ **L'unica via d'uscita che manda i byte originali.**
             *
             * ⚠️ Si arriva qui solo con un formato che GD non apre. La
             * validazione accetta `image`, cioe' anche GIF e BMP: quelli
             * passerebbero di qui **con i metadati dentro**.
             *
             * 🚨 Chi allarga i formati accettati deve allargare anche `load()`,
             * o riapre il buco del 22/08 senza che niente lo segnali.
             */
            return [
                'data' => base64_encode((string) file_get_contents($absolutePath)),
                'mime' => $mimeType,
            ];
        }

        $w = imagesx($immagine);
        $h = imagesy($immagine);
        $lato = max($w, $h);

        /*
         * 💡 Si ridimensiona solo se serve, ma si **ricodifica sempre**: sono
         * due cose diverse, e prima erano legate. Il ridimensionamento e' un
         * risparmio; la ricodifica e' quella che butta via l'EXIF.
         */
        $daMandare = $immagine;

        if ($lato > $maxEdge) {
            $scala = $maxEdge / $lato;
            $ridotta = imagescale($immagine, (int) round($w * $scala), (int) round($h * $scala));

            if ($ridotta !== false) {
                imagedestroy($immagine);
                $daMandare = $ridotta;
            }
        }

        ob_start();
        imagejpeg($daMandare, null, (int) config('ai.image.jpeg_quality', 85));
        $binario = (string) ob_get_clean();
        imagedestroy($daMandare);

        /*
         * 🚨 Se la ricodifica non produce niente si **rinuncia**, non si ripiega
         * sui byte originali: un ripiego silenzioso qui vorrebbe dire mandare
         * l'EXIF proprio nel caso strano, che e' l'unico in cui nessuno
         * guarderebbe.
         */
        if ($binario === '') {
            throw new AiUnavailableException('ai_image_unreadable', 'Immagine non leggibile.');
        }

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
