<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Fattura;
use App\Models\User;
use App\Services\Billing\Stripe\Cassa;
use App\Services\Fatturazione\FatturaPdf;
use App\Support\Tenancy\TenantContext;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\HeaderUtils;

/**
 * 📥 Scaricare una fattura — fase F8, 23/09/2026.
 *
 * 📌 D2: *«Il pdf lo deve poter scaricare anche il cliente, l'xml solo io»*.
 *
 * | Rotta | Chi | Cosa |
 * |---|---|---|
 * | `admin/fatture/{fattura}/pdf` | il **titolare** della palestra | il PDF delle **sue** fatture emesse |
 * | `god/fatture/{fattura}/pdf` | il super admin | il PDF di qualunque fattura emessa |
 * | `god/fatture/{fattura}/xml` | il super admin | ⛔ **l'XML, e solo qui** |
 *
 * 🚨 Le rotte stanno dentro `authenticatedRoutes()` dei rispettivi pannelli:
 * passano dalla stessa catena (login, palestra, accesso al pannello). ⛔ Una
 * fattura di un'altra palestra risponde **404**, non 403: non si dice nemmeno
 * che esiste.
 */
class FattureDaScaricare extends Controller
{
    public function pdfDellaPalestra(int $fattura, FatturaPdf $pdf): Response
    {
        $utente = auth()->user();

        // Come «Il tuo piano»: le fatture le vede chi paga, non lo staff.
        abort_unless($utente instanceof User && Cassa::loPuoGestire($utente), 404);

        $record = Fattura::query()
            ->where('tenant_id', $utente->tenant_id)
            ->emesse()
            ->find($fattura);

        abort_if($record === null, 404);

        return $this->pdf($record, $pdf);
    }

    public function pdfPerLaPiattaforma(int $fattura, FatturaPdf $pdf): Response
    {
        $record = app(TenantContext::class)->runWithoutTenant(
            fn (): ?Fattura => Fattura::query()->emesse()->find($fattura),
        );

        abort_if($record === null, 404);

        return $this->pdf($record, $pdf);
    }

    public function xmlPerLaPiattaforma(int $fattura): Response
    {
        $record = app(TenantContext::class)->runWithoutTenant(
            fn (): ?Fattura => Fattura::query()->emesse()->find($fattura),
        );

        abort_if($record === null || blank($record->xml), 404);

        return response((string) $record->xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $record->nomeDelFileXml()),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function pdf(Fattura $fattura, FatturaPdf $pdf): Response
    {
        return response($pdf->genera($fattura), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, $fattura->nomeDelFilePdf()),
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
