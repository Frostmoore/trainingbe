<?php

declare(strict_types=1);

namespace App\Services\Fatturazione;

use App\Models\Fattura;
use Barryvdh\DomPDF\Facade\Pdf;
use RuntimeException;

/**
 * 📄 Il PDF di una fattura — fase F8, 23/09/2026.
 *
 * 💡 **Si genera ogni volta, non si conserva.** Nasce solo dai dati fissati
 * all'emissione (numero, data, `cessionario`, importi), quindi è **sempre lo
 * stesso**: conservarlo vorrebbe dire un file in più da mettere nel backup,
 * senza nessuna informazione che non sia già nella riga.
 *
 * ⚠️ Il documento fiscale è l'**XML**; il PDF è la sua copia leggibile, e lo
 * dice (`config('fatture.nota_pdf')`).
 */
class FatturaPdf
{
    /** I byte del PDF. @throws RuntimeException per una fattura non ancora emessa */
    public function genera(Fattura $fattura): string
    {
        if (! $fattura->eEmessa()) {
            throw new RuntimeException('Una fattura in attesa non ha ancora un PDF: non ha un numero.');
        }

        return Pdf::loadView('fatture.pdf', [
            'fattura' => $fattura,
            'cedente' => (array) config('fatture.cedente'),
            'cliente' => (array) $fattura->cessionario,
            'dicitura' => (string) config('fatture.dicitura'),
            'nota' => (string) config('fatture.nota_pdf'),
        ])
            ->setPaper('a4')
            // ⚠️ DejaVu: l'unico carattere di dompdf con «€» e le lettere accentate.
            ->setOption('defaultFont', 'DejaVu Sans')
            ->output();
    }
}
