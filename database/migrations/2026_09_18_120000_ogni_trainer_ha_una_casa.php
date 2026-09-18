<?php

declare(strict_types=1);

use App\Models\User;
use App\Services\Tenancy\DaiUnaCasaAlTrainer;
use Illuminate\Database\Migrations\Migration;
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
 * Le palestre diventano **solo ruoli**, ed è la condizione della regola 8.1.1:
 * un trainer che vive **dentro** una palestra ha quella come casa, e non può
 * lavorarne in una seconda.
 *
 * ══ 🎯 SU CHI AGISCE ══════════════════════════════════════════════════════
 *
 * I trainer il cui `users.tenant_id` punta a una **palestra** — cioè quelli
 * creati dal pannello, che nascevano dentro. 🔬 Contati su staging prima di
 * scriverla, il 18/09: **tre**, nessuno dei quali è un account del committente.
 *
 * ══ 🚨 LE DUE COSE CHE SONO DOVUTE CAMBIARE PRIMA, O LI AVREBBE CHIUSI FUORI ═
 *
 * ⛔ Migrando e basta, quei tre avrebbero perso il pannello: a casa sono
 * `free_user`, e `isTrainer()` risponde nel contesto — la casa. Per questo, nello
 * stesso rilascio:
 *
 * 1. `User::canAccessPanel()` lascia entrare chi **allena in almeno una
 *    palestra** (`allenaInUnaPalestra()`);
 * 2. `SpazioDelPannello` fa atterrare **nella palestra** chi a casa non ha
 *    niente da fare, invece che in uno spazio vuoto.
 *
 * ══ ⚠️ UNA MIGRAZIONE CHE CHIAMA UN SERVIZIO, E PERCHE' SI ACCETTA ═════════
 *
 * Di solito è una cattiva idea: il servizio cambia, la migrazione vecchia no, e
 * un giorno smette di girare. 💡 Qui si accetta per due ragioni: il trasloco dei
 * dati personali (`portaViaLeSueRighe`) è l'unica cosa che sa quali tabelle
 * seguono la persona, e riscriverlo qui vorrebbe dire **un secondo elenco** che
 * invecchia; e su un database vuoto — i test, un'installazione nuova — non c'è
 * nessun trainer da toccare, quindi la chiamata non avviene nemmeno.
 *
 * ⛔ **E si ferma sul primo errore**, invece di proseguire: un trainer lasciato a
 * metà — casa creata, dati non spostati — è peggio di un deploy che non parte.
 * La stessa scelta della migrazione dei gettoni del 16/09.
 */
return new class extends Migration
{
    public function up(): void
    {
        $daSistemare = DB::table('users as u')
            ->join('tenants as t', 't.id', '=', 'u.tenant_id')
            ->join('model_has_roles as mhr', function ($j): void {
                $j->on('mhr.model_id', '=', 'u.id')
                    ->on('mhr.tenant_id', '=', 'u.tenant_id');
            })
            ->join('roles as r', function ($j): void {
                $j->on('r.id', '=', 'mhr.role_id')
                    ->on('r.tenant_id', '=', 'mhr.tenant_id');
            })
            ->where('r.name', 'trainer')
            ->where('t.kind', '!=', 'personal')
            ->whereNull('u.deleted_at')
            ->distinct()
            ->pluck('u.id');

        foreach ($daSistemare as $id) {
            $trainer = User::withoutGlobalScopes()->findOrFail($id);

            $casa = app(DaiUnaCasaAlTrainer::class)($trainer);

            Log::info('Migrazione: un trainer ha adesso uno spazio suo', [
                'trainer' => $id,
                'casa' => $casa?->getKey(),
            ]);
        }
    }

    /**
     * ⛔ **Non si torna indietro in automatico**, ed è voluto.
     *
     * Rimettere un trainer dentro la palestra vorrebbe dire rispostare i suoi
     * dati personali e cancellare uno spazio che nel frattempo può contenere
     * cose nuove. 🚨 Un `down()` che lo facesse «a occhio» sarebbe il modo più
     * rapido di perdere il diario di qualcuno. Se serve, si fa a mano, guardando.
     */
    public function down(): void {}
};
