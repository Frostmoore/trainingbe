<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\AiFeature;
use App\Enums\ImportStatus;
use App\Models\User;
use App\Models\WorkoutPlanImport;
use App\Services\Ai\AiCallContext;
use App\Services\Ai\AiManager;
use App\Services\Ai\CancelloDeiGettoni;
use App\Services\Ai\Data\ParsedWorkoutPlan;
use App\Support\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Legge un PDF e ne ricava una bozza di scheda — B7.2.
 *
 * 🚨 **Gira in coda e quindi FUORI da qualunque contesto di palestra.**
 * Un job non ha una richiesta HTTP alle spalle: `ResolveTenant` non e' passato e
 * `TenantContext` e' vuoto. Ogni accesso ai dati va quindi dentro `runAs()`,
 * altrimenti il global scope non filtra — e in un job che scrive, «non filtra»
 * significa scrivere nella palestra sbagliata.
 *
 * 🚨 **L'escalation ritenta UNA sola volta** (B7.5). La stragrande maggioranza
 * dei PDF di scheda e' testo pulito e non ha bisogno del modello migliore;
 * pagarlo per tutti per coprire il dieci per cento difficile e' la definizione
 * di spreco. Ma ritentare all'infinito su un PDF illeggibile sarebbe peggio:
 * costerebbe due volte e finirebbe comunque in `failed`.
 */
class ParseWorkoutPdf implements ShouldQueue
{
    use Queueable;

    /**
     * Un solo tentativo automatico della coda.
     *
     * I ritentativi utili li gestisce il job stesso (l'escalation): un retry
     * della coda ripeterebbe la chiamata identica, pagandola due volte per
     * ottenere lo stesso errore.
     */
    public int $tries = 1;

    public int $timeout = 300;

    public function __construct(
        public readonly int $importId,
    ) {}

    public function handle(AiManager $ai, TenantContext $tenants, CancelloDeiGettoni $cancello): void
    {
        $import = WorkoutPlanImport::withoutGlobalScopes()->find($this->importId);

        if ($import === null) {
            return;
        }

        $palestra = $import->tenant;

        if ($palestra === null) {
            $import->markFailed('Import senza palestra.');

            return;
        }

        $tenants->runAs($palestra, function () use ($import, $ai): void {
            $percorso = $import->pdfPath();

            if ($percorso === null || ! is_readable($percorso)) {
                $import->markFailed('Il PDF non e\' leggibile.');

                return;
            }

            $import->markProcessing();

            $utente = $import->uploader;
            $ctx = $utente instanceof User
                ? AiCallContext::for($utente, AiFeature::PdfImport)
                : new AiCallContext($import->tenant_id, 0, AiFeature::PdfImport);

            $soglia = (float) config('ai.pdf.escalation_confidence');
            $provider = $ai->for(AiFeature::PdfImport);
            $modello = $ai->modelFor(AiFeature::PdfImport);

            try {
                /*
                 * ⚠️ **Una lista di uno** — K1. Questo job nasce dal pannello
                 * palestra, dove si carica **un** PDF alla volta: la firma e'
                 * cambiata per le fotografie dell'app (K2), qui il caso resta
                 * quello di sempre.
                 */
                $bozza = $provider->parseWorkoutPdf([$percorso], $ctx);
            } catch (Throwable $e) {
                // Primo tentativo fallito: si sale di modello **una volta**.
                $this->escalation($import, $percorso, $ctx, $e->getMessage());

                return;
            }

            if ($bozza->confidence >= $soglia && $bozza->exercises !== []) {
                $import->storeDraft($bozza, $modello);

                return;
            }

            // Confidenza sotto soglia: si riprova sul modello superiore, e si
            // tiene comunque il risultato migliore fra i due.
            $this->escalation($import, $percorso, $ctx, null, $bozza);
        });

        /*
         * ── 🚨 I gettoni si scalano QUI, e solo se e' uscita una bozza ──────
         *
         * ⚠️ **Dopo `runAs` e non dentro**, in un punto solo: `storeDraft()` ha
         * quattro chiamanti fra `handle()` e `escalation()`, e mettere il
         * consumo accanto a ciascuno vorrebbe dire quattro occasioni di
         * dimenticarlo — o, peggio, di farlo due volte quando l'escalation
         * salva il ripiego dopo aver gia' salvato.
         *
         * 💡 Lo **stato finale** e' il criterio giusto perche' e' esattamente la
         * domanda commerciale: la palestra ha ottenuto qualcosa da rivedere?
         * Allora ha comprato una lettura. Se e' `failed` non ha niente in mano,
         * e far pagare un nostro guasto e' la regola che `CancelloDeiGettoni`
         * vieta esplicitamente.
         *
         * ⛔ **E se il job esplode**, non si arriva mai qui: nessun addebito. E'
         * voluto — `tries = 1`, quindi non c'e' nessun ritentativo che
         * paghi due volte.
         */
        $import->refresh();

        if ($import->status !== ImportStatus::Failed) {
            $cancello->consuma(
                $import->uploader,
                AiFeature::PdfImport,
                (bool) $import->paga_con_gettoni,
            );
        }
    }

    /**
     * Il secondo e ultimo tentativo, sul modello superiore.
     *
     * Se anche questo fallisce ma il primo aveva prodotto qualcosa, si tiene
     * **quel qualcosa**: una bozza mediocre da rivedere e' comunque meglio di un
     * import fallito, perche' il trainer parte da li' invece che da zero.
     */
    private function escalation(
        WorkoutPlanImport $import,
        string $percorso,
        AiCallContext $ctx,
        ?string $errorePrecedente,
        ?ParsedWorkoutPlan $ripiego = null,
    ): void {
        $ai = app(AiManager::class);
        $modello = $ai->escalationModel();

        try {
            $bozza = $ai->for(AiFeature::PdfImport)->parseWorkoutPdf([$percorso], $ctx, $modello);

            // Fra i due si tiene il migliore, non l'ultimo: il modello superiore
            // di solito fa meglio, ma non e' garantito.
            if ($ripiego !== null && $ripiego->confidence > $bozza->confidence) {
                $import->storeDraft($ripiego, $ai->modelFor(AiFeature::PdfImport), escalated: true);

                return;
            }

            $import->storeDraft($bozza, $modello, escalated: true);
        } catch (Throwable $e) {
            if ($ripiego !== null && $ripiego->exercises !== []) {
                $import->storeDraft($ripiego, $ai->modelFor(AiFeature::PdfImport), escalated: true);

                return;
            }

            $import->markFailed(trim(($errorePrecedente ?? '').' | '.$e->getMessage(), ' |'));
        }
    }
}
