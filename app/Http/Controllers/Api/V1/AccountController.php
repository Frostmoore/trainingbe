<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Services\Account\AccountEraser;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

/**
 * L'eliminazione del proprio account — C6.
 *
 * 🚨 **Apple la pretende** per ogni app che permette di registrarsi: dev'essere
 * raggiungibile dall'app, senza scrivere a nessuno e senza passare da un sito.
 * Senza, la pubblicazione viene rifiutata.
 */
class AccountController extends Controller
{
    public function __construct(
        private readonly AccountEraser $eraser,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Cosa succede se si cancella: da mostrare **prima** del pulsante.
     *
     * ⚠️ Un'azione irreversibile va spiegata nel punto in cui si compie. Un
     * elenco scritto solo nell'app diventa falso il giorno che il server cambia
     * politica, e nessuno se ne accorge.
     */
    public function preview(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'deleted' => [
                'Il diario alimentare e i preferiti',
                'Le misure e lo storico del peso',
                'Gli allenamenti registrati e le schede che hai scritto tu',
                'Le foto dei progressi',
                'I dati del sonno e i consigli ricevuti',
            ],
            'kept' => [
                'I messaggi già scambiati con il tuo trainer restano nella sua'
                    .' conversazione, ma non risulteranno più tuoi.',
                'Le schede che ti ha scritto il trainer restano alla palestra.',
            ],
            'irreversible' => true,
        ]]);
    }

    public function destroy(Request $request): JsonResponse
    {
        /*
         * 🚨 La password si richiede, e non è pignoleria.
         *
         * È l'unica azione irreversibile che l'app offre, e un telefono
         * sbloccato lasciato sul tavolo non deve bastare a compierla. È lo
         * stesso motivo per cui i sistemi operativi richiedono il PIN prima di
         * un ripristino di fabbrica.
         */
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $utente = $request->user();

        if (! Hash::check($request->string('password')->toString(), (string) $utente->password)) {
            return response()->json([
                'message' => __('Password non corretta.'),
                'errors' => ['password' => [__('Password non corretta.')]],
            ], 422);
        }

        // Si registra PRIMA di cancellare: dopo, l'utente non c'è più e la riga
        // di controllo nascerebbe senza autore. È la riga che serve il giorno in
        // cui qualcuno contesta la cancellazione.
        $this->audit->log(
            AuditAction::UserDeleted,
            $utente,
            ['reason' => 'account_self_deletion'],
        );

        $this->eraser->erase($utente);

        return response()->json(null, 204);
    }
}
