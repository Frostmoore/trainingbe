<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Exercise;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fonde gli esercizi di una palestra con quelli della piattaforma — 3b-O.
 *
 * ══ 📌 LA RICHIESTA ═══════════════════════════════════════════════════════
 *
 * *«vorrei che facessi in modo che gli esercizi che ho io nelle schede siano
 * quelli che abbiamo nel database. Senza farmi perdere nulla, naturalmente»*.
 *
 * ══ 🚨 LA MAPPA E' SCRITTA A MANO, E DEVE ESSERLO ═════════════════════════
 *
 * ⛔ La proposta automatica e' stata provata, ed e' **da buttare**. Su 26
 * esercizi ne azzeccava 4 e ne sbagliava 7, e gli errori erano tutti dello
 * stesso tipo: **buttava via l'attrezzo**, che e' proprio la cosa che
 * distingue una voce dall'altra.
 *
 * | L'automatico diceva | Ma |
 * |---|---|
 * | `Rematore corda` → `Corda` | 🚨 «Corda» e' **saltare la corda**: polpacci al posto della schiena. E' lo stesso difetto gia' documentato in `ExerciseMatcher` |
 * | `Panca Piana (Manubrio)` → `Panca piana` | ⛔ quella col bilanciere |
 * | `Curl Bicipiti (Bilanciere)` → `Curl bicipiti` | ⛔ quello coi manubri |
 * | `Squat (Corpo Libero)` → `Squat` | ⛔ quello col bilanciere |
 *
 * 💡 E tre voci le ha decise **il committente**, perche' nessun algoritmo
 * poteva saperle: `Rematore corda` e' un rematore in sospensione (*«due corde
 * appese al soffitto… mi traggo su con quelle»*), `Lento in Avanti` e' da
 * seduto, e `Croci Inverse` sono le alzate posteriori fatte sulla panca a 30°.
 *
 * ══ ⚠️ COSA SUCCEDE, IN ORDINE ════════════════════════════════════════════
 *
 * 1. le righe di scheda passano dal vecchio al nuovo (`plan_exercises`);
 * 2. il vecchio **resta**, con scritto da chi e' stato sostituito.
 *
 * ⛔ **Il vecchio non si cancella**, e non e' prudenza generica: lo storico
 * degli allenamenti sta sul telefono e usa l'id vecchio. Il rinvio e' l'unica
 * cosa che permette all'app di ritrovare le proprie serie.
 */
final class FondiGliEsercizi extends Command
{
    protected $signature = 'esercizi:fondi
        {--tenant= : la palestra di cui fondere gli esercizi}
        {--applica : senza questa, non scrive niente}';

    protected $description = 'Fa puntare le schede di una palestra agli esercizi della piattaforma';

    /**
     * `nome nel tenant => nome nel catalogo di piattaforma`.
     *
     * ⚠️ **Per nome e non per id**: gli id sono diversi su ogni installazione,
     * e una mappa di numeri non si rilegge fra sei mesi. I nomi si controllano
     * a occhio, ed e' proprio quello che serve poter fare.
     *
     * @var array<string, string>
     */
    private const FUSIONI = [
        // ── Petto ────────────────────────────────────────────────────────
        'Panca Piana (Bilanciere)' => 'Panca piana',
        'Panca Piana (Manubrio)' => 'Panca piana con manubri',
        'Panca Inclinata (Bilanciere)' => 'Panca inclinata',
        'Panca Inclinata (Manubrio)' => 'Panca inclinata con manubri',
        'Croci (Manubrio)' => 'Croci su panca',

        // ── Spalle ───────────────────────────────────────────────────────
        // 📌 «stare sdraiato a pancia in giu' sulla panca a 30° e sollevare i
        //    manubri a croce»: e' il movimento delle alzate posteriori, con la
        //    panca che toglie di mezzo la schiena.
        'Croci Inverse (Manubrio)' => 'Alzate posteriori',
        'Alzata Frontale (Manubrio)' => 'Alzate frontali',
        'Alzate Laterali (Cavo)' => 'Alzate laterali ai cavi',
        'Arnold Press (Manubrio)' => 'Arnold press',
        'Push Press' => 'Push press',
        // 📌 «Lento in Avanti e' la shoulder press seduto con i manubri».
        'Lento in Avanti (Manubrio)' => 'Shoulder press',

        // ── Bicipiti ─────────────────────────────────────────────────────
        'Curl Bicipiti (Bilanciere)' => 'Curl con bilanciere',
        'Curl Bicipiti (Manubrio)' => 'Curl bicipiti',
        'Bicipiti Martello (Manubrio)' => 'Curl a martello',
        'Concentration Curl' => 'Curl concentrato',
        'Curl Invertito (Manubrio)' => 'Curl inverso',

        // ── Tricipiti ────────────────────────────────────────────────────
        'Skullcrusher (Manubrio)' => 'French press con manubri',
        'Estensione Tricipiti Braccio Singolo (Manubrio)' => 'Estensioni tricipiti a un braccio',

        // ── Schiena ──────────────────────────────────────────────────────
        'Rematore Inclinato (Bilanciere)' => 'Rematore con bilanciere',
        'Rematore Inclinato (Manubrio)' => 'Rematore con due manubri',
        'Rematore Manubrio' => 'Rematore con manubrio',
        // 📌 «due corde appese al soffitto e io sono in piedi ma inclinato
        //    verso indietro e mi traggo su»: e' un rematore in sospensione.
        //    ⛔ NON «Pulley basso» (da seduto) e NON «Corda» (saltare la corda).
        'Rematore corda' => 'Trazioni orizzontali',
        'Estensione Dorso (Iperestensione con Peso)' => 'Iperestensioni',

        // ── Gambe ────────────────────────────────────────────────────────
        'Squat (Corpo Libero)' => 'Squat a corpo libero',
        'Calf Press (Macchina)' => 'Calf alla pressa',
        'Calf Raise Gamba Singola in Piedi' => 'Calf a una gamba',
    ];

    public function handle(TenantContext $contesto): int
    {
        $tenant = $this->option('tenant');

        if ($tenant === null || $tenant === '') {
            $this->error('Serve --tenant=N: fondere gli esercizi sbagliati non si disfa.');

            return self::FAILURE;
        }

        $tenant = (int) $tenant;
        $applica = (bool) $this->option('applica');

        $fatte = 0;
        $saltate = [];

        $contesto->runWithoutTenant(function () use ($tenant, $applica, &$fatte, &$saltate): void {
            foreach (self::FUSIONI as $vecchioNome => $nuovoNome) {
                $vecchio = Exercise::withoutGlobalScopes()
                    ->where('tenant_id', $tenant)
                    ->where('slug_normalized', Exercise::normalize($vecchioNome))
                    ->first();

                if ($vecchio === null) {
                    $saltate[] = "$vecchioNome — non c'è in questo tenant";

                    continue;
                }

                $nuovo = Exercise::withoutGlobalScopes()
                    ->whereNull('tenant_id')
                    ->where('slug_normalized', Exercise::normalize($nuovoNome))
                    ->first();

                if ($nuovo === null) {
                    /*
                     * 🚨 Se manca la destinazione ci si ferma su quella riga e
                     * basta: e' quasi sempre un nome scritto male qui sopra, e
                     * proseguire vorrebbe dire fondere il resto lasciando un
                     * buco che nessuno rilegge.
                     */
                    $saltate[] = "$vecchioNome → $nuovoNome — la destinazione non esiste";

                    continue;
                }

                $righe = DB::table('plan_exercises')
                    ->where('exercise_id', $vecchio->getKey())
                    ->count();

                $this->line(sprintf(
                    '  %-46s → %-36s (%d righe di scheda)',
                    $vecchioNome,
                    $nuovoNome,
                    $righe
                ));

                if (! $applica) {
                    $fatte++;

                    continue;
                }

                DB::transaction(function () use ($vecchio, $nuovo): void {
                    DB::table('plan_exercises')
                        ->where('exercise_id', $vecchio->getKey())
                        ->update(['exercise_id' => $nuovo->getKey()]);

                    /*
                     * ⛔ **Il vecchio resta.** E' l'unica cosa che permette al
                     * telefono di ritrovare le serie che ha gia' registrato
                     * con quell'id.
                     */
                    $vecchio->forceFill([
                        'sostituito_da_id' => $nuovo->getKey(),
                    ])->save();
                });

                $fatte++;
            }
        });

        $this->newLine();
        $this->info(($applica ? '' : '[prova] ')."fusioni: $fatte");

        if ($saltate !== []) {
            $this->warn('saltate: '.count($saltate));
            $this->line('  '.implode("\n  ", $saltate));
        }

        if (! $applica) {
            $this->newLine();
            $this->line('🚨 Niente è stato scritto. Rilancia con --applica.');
        }

        return self::SUCCESS;
    }
}
