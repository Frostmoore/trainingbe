<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Ai\ImagePreparer;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Le foto che partono verso Anthropic non portano metadati — 22/08/2026.
 *
 * ══ 🚨 IL DIFETTO CHE QUESTO TEST CHIUDE ══════════════════════════════════
 *
 * ⚠️ `ImagePreparer` ricodificava l'immagine **solo se era piu' grande di 1568
 * px**. Sotto quella soglia mandava i **byte originali**, EXIF compreso — cioe'
 * modello del telefono, data e, quando la fotocamera ha il permesso di
 * posizione, le **coordinate GPS** di dove e' stata scattata.
 *
 * 🚨 La stessa funzione si comportava in due modi a seconda della dimensione
 * dell'ingresso, e nessun errore lo diceva. ⛔ Il caso non era raro: una foto
 * ricevuta su WhatsApp, uno screenshot o un'immagine gia' ridotta dalla
 * galleria stanno tutte sotto la soglia.
 *
 * 💡 Questo test pianta un blocco EXIF vero dentro un JPEG e verifica che
 * dall'altra parte non ci sia piu'.
 *
 * ⚠️ **Nessuna stringa costante nel file**: l'immagine si genera a runtime con
 * GD. Un JPEG in base64 dentro un test e' esattamente il genere di cosa che
 * GitGuardian scambia per una credenziale.
 */
class ImagePreparerTest extends TestCase
{
    /**
     * Un JPEG con dentro un segmento APP1 «Exif», scritto a mano.
     *
     * 💡 L'EXIF sta subito dopo il marcatore d'inizio (`FFD8`): due byte di
     * marcatore, due di lunghezza, poi la firma. Non serve un EXIF valido —
     * serve una sequenza riconoscibile che **non deve sopravvivere**.
     */
    private function jpegConExif(int $lato): string
    {
        $tela = imagecreatetruecolor($lato, $lato);
        imagefill($tela, 0, 0, imagecolorallocate($tela, 120, 180, 90));

        ob_start();
        imagejpeg($tela, null, 90);
        $jpeg = (string) ob_get_clean();
        imagedestroy($tela);

        $firma = "Exif\x00\x00" . str_repeat("\x00", 20);
        $app1 = "\xFF\xE1" . pack('n', strlen($firma) + 2) . $firma;

        // Dopo i due byte di SOI, prima di tutto il resto.
        return substr($jpeg, 0, 2) . $app1 . substr($jpeg, 2);
    }

    #[Test]
    public function una_foto_piccola_perde_lexif(): void
    {
        /*
         * 🚨 **Il caso che prima falliva.** 800 px sta sotto la soglia di 1568,
         * quindi la vecchia versione restituiva il file cosi' com'era.
         */
        $percorso = tempnam(sys_get_temp_dir(), 'exif') . '.jpg';
        file_put_contents($percorso, $this->jpegConExif(800));

        self::assertStringContainsString(
            'Exif',
            (string) file_get_contents($percorso),
            'il file di partenza deve avere l\'EXIF, o il test non prova niente',
        );

        $preparata = (new ImagePreparer())->prepare($percorso, 'image/jpeg');
        unlink($percorso);

        self::assertStringNotContainsString(
            'Exif',
            (string) base64_decode($preparata['data'], true),
        );
        self::assertSame('image/jpeg', $preparata['mime']);
    }

    #[Test]
    public function anche_una_foto_grande_perde_lexif(): void
    {
        // ⚠️ Il ramo che gia' funzionava: va tenuto verde, o la correzione
        // avrebbe spostato il difetto invece di chiuderlo.
        $percorso = tempnam(sys_get_temp_dir(), 'exif') . '.jpg';
        file_put_contents($percorso, $this->jpegConExif(2000));

        $preparata = (new ImagePreparer())->prepare($percorso, 'image/jpeg');
        unlink($percorso);

        self::assertStringNotContainsString(
            'Exif',
            (string) base64_decode($preparata['data'], true),
        );
    }

    #[Test]
    public function la_foto_grande_viene_comunque_rimpicciolita(): void
    {
        /*
         * 💡 Ridimensionare e ricodificare sono due cose diverse, e la
         * correzione le ha separate: questo test tiene ferma la prima, che e'
         * la seconda delle tre leve di risparmio della piattaforma.
         */
        $percorso = tempnam(sys_get_temp_dir(), 'exif') . '.jpg';
        file_put_contents($percorso, $this->jpegConExif(3000));

        $preparata = (new ImagePreparer())->prepare($percorso, 'image/jpeg');
        unlink($percorso);

        $misure = getimagesizefromstring(
            (string) base64_decode($preparata['data'], true),
        );

        self::assertNotFalse($misure);
        self::assertLessThanOrEqual(1568, max($misure[0], $misure[1]));
    }
}
