<?php

declare(strict_types=1);

namespace App\Services\Tenancy;

use App\Enums\UserRole;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 🏠 **Ogni trainer ha sempre uno spazio suo** — decisione 2 del committente,
 * 18/09/2026.
 *
 * ══ 📌 LA DECISIONE ═══════════════════════════════════════════════════════
 *
 * > *«Ogni trainer ha sempre uno spazio suo.»*
 *
 * 💡 **Le palestre diventano solo ruoli.** È la condizione della regola 8.1.1
 * (*«un trainer può lavorare per infinite palestre»*): un trainer che vive
 * **dentro** una palestra ha quella come casa, e non può averne una seconda.
 *
 * ══ 🚨 IL CASO CHE RISOLVE ════════════════════════════════════════════════
 *
 * Un trainer creato dal pannello di una palestra nasceva **dentro** la
 * palestra: `users.tenant_id` = palestra. Da lì:
 *
 * - ⛔ non poteva lavorare in una seconda palestra — il suo spazio non era suo;
 * - ⛔ se la palestra lo congedava restava **senza casa**: `TogliUnTrainerDallaPalestra`
 *   lo rifiutava con *«Questo trainer non ha uno spazio suo: per congedarlo si
 *   disattiva o si cancella il suo account»*. Cioè l'unico modo di mandarlo via
 *   era cancellargli l'account.
 *
 * ══ ⚠️ COSA SI SPOSTA E COSA NO ═══════════════════════════════════════════
 *
 * | | |
 * |---|---|
 * | `users.tenant_id` | → lo spazio nuovo |
 * | I suoi dati personali (diario, profilo, preferiti, le schede che si è scritto **per sé**) | → con lui, via `EsciDaUnaPalestra::portaViaLeSueRighe()` |
 * | 🚨 **Il ruolo di trainer nella palestra** | ⛔ **resta**: la casa cambia, il lavoro no |
 * | Gli iscritti della palestra che segue | ⛔ **restano della palestra** |
 * | Le schede scritte per gli iscritti | ⛔ restano: sono della palestra |
 *
 * 🚨 **Il ruolo che resta è il punto.** Traslocare **anche** il ruolo vorrebbe
 * dire licenziarlo senza che nessuno l'abbia chiesto.
 *
 * ⚠️ E i dati personali **devono** seguirlo: spostando solo `users.tenant_id`,
 * il `TenantScope` gli nasconderebbe tutta la sua storia — non cancellata,
 * **invisibile**, che è peggio, perché non se ne accorge nessuno finché non la
 * cerca. È la trappola scritta in cima a `UnisciAUnaPalestra`.
 */
class DaiUnaCasaAlTrainer
{
    public function __construct(
        private readonly CreaTenantPersonale $spazi,
        private readonly EsciDaUnaPalestra $esci,
    ) {}

    /**
     * Dà a questo trainer uno spazio suo, se non ce l'ha già.
     *
     * 💡 **Idempotente**: chi ha già una casa torna `null` e non si tocca. È ciò
     * che rende sicuro farla girare da una migrazione — che si esegue una volta
     * sola, ma può incontrare trainer già sistemati.
     *
     * @return Tenant|null la casa nuova, o `null` se ce l'aveva già
     */
    public function __invoke(User $trainer): ?Tenant
    {
        $attuale = $trainer->tenant;

        if ($attuale === null || $attuale->ePersonale()) {
            return null;
        }

        return DB::transaction(function () use ($trainer, $attuale): Tenant {
            /*
             * ⚠️ **`free_user` a casa, non `free_trainer`.** Un trainer di
             * palestra non vende per forza coaching personale: `free_trainer`
             * vorrebbe dire un giro suo, con un abbonamento trainer da pagare.
             * 💡 Che allena lo sa l'app lo stesso: `UserResource::ruoli()`
             * aggiunge `trainer` a chi allena in almeno una palestra.
             */
            $casa = $this->spazi->perChiEsisteGia($trainer, UserRole::FreeUser);

            $spostate = $this->esci->portaViaLeSueRighe($trainer, $attuale, $casa);

            $trainer->forceFill(['tenant_id' => $casa->getKey()])->save();

            Log::info('Trainer: ha adesso uno spazio suo', [
                'trainer' => $trainer->getKey(),
                'dalla_palestra' => $attuale->getKey(),
                'casa' => $casa->getKey(),
                'righe_spostate' => $spostate,
            ]);

            return $casa;
        });
    }
}
