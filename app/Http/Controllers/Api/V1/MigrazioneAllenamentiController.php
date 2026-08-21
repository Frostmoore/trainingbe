<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DailyBurn;
use App\Models\SessionSet;
use App\Models\User;
use App\Models\WorkoutSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Il trasloco degli allenamenti sul telefono — FASE 11.3, 21/08/2026.
 *
 * == 🚨 PERCHE' ESISTE, E PERCHE' IN DUE PASSI ==============================
 *
 * 📌 Il committente: *«Nessun allenamento deve risiedere sul server, devono
 * stare tutti nell'app»*. E' la decisione gia' scritta il 16/08 in
 * `plan_tutto_sul_telefono.md` §2.1.
 *
 * ⚠️ **Non basta cancellare le tabelle**: chi ha gia' dei dati li deve
 * **ritrovare sul telefono**. Quei record sono l'unica copia esistente di mesi
 * di allenamenti, e distruggerli e' irreversibile.
 *
 * 🚨 Quindi il trasloco e' in **due chiamate**, e la seconda e' un contratto:
 *
 * | Passo | Chi | Cosa |
 * |---|---|---|
 * | 1 | l'app | `GET /migrazione/allenamenti` — scarica tutto |
 * | 2 | l'app | scrive nell'archivio locale e **conta le righe scritte** |
 * | 3 | l'app | `POST /migrazione/allenamenti/fatta` con i conteggi |
 * | 4 | il server | **confronta**, e segna «fatto» solo se tornano |
 *
 * ⛔ **Se i conteggi non tornano, il server rifiuta**: meglio non segnare che
 * segnare per sbaglio. Un utente non segnato semplicemente riprova al prossimo
 * avvio; un utente segnato per sbaglio perde i dati quando le tabelle cadranno
 * (11.6).
 *
 * -- 💡 Perche' il server non cancella qui ---------------------------------
 *
 * Cancellare riga per riga vorrebbe dire una `DELETE` per utente, e un archivio
 * mezzo vuoto per un tempo indefinito. 💡 Le righe restano finche' **tutti**
 * hanno confermato: allora la migrazione di 11.6 lascia cadere le tabelle in un
 * colpo, e si rifiuta di partire se qualcuno manca.
 *
 * ⚠️ Costo accettato: chi non apre l'app tiene i suoi dati sul server piu' a
 * lungo. E' esattamente il compromesso scritto in D2 passo 4.
 */
class MigrazioneAllenamentiController extends Controller
{
    /**
     * Tutto quello che appartiene a questa persona, in una volta.
     *
     * 🚨 **`id` compreso**, ed e' quello che rende possibile il passo 3: l'app
     * lo scrive in `idServer` e una seconda passata non duplica niente.
     *
     * ⚠️ **`met` viaggia con la serie**, non con l'esercizio: il catalogo resta
     * sul server, e il calcolo delle calorie deve funzionare sul telefono senza
     * rete. Vedi `SerieDelleSedute.met` di la'.
     */
    public function pacchetto(Request $request): JsonResponse
    {
        $utente = $request->user();

        $sessioni = WorkoutSession::query()
            ->forUser($utente)
            ->with(['sets.exercise', 'plan'])
            ->orderBy('started_at')
            ->get();

        $bruciate = DailyBurn::query()
            ->forUser($utente)
            ->orderBy('date')
            ->get();

        return response()->json([
            'data' => [
                'sessions' => $sessioni->map(fn (WorkoutSession $s): array => [
                    'id' => $s->id,
                    'plan_id' => $s->workout_plan_id,
                    'plan_name' => $s->plan?->name,
                    'started_at' => $s->started_at->toIso8601String(),
                    'ended_at' => $s->ended_at?->toIso8601String(),
                    'kcal' => $s->kcal_burned,

                    /*
                     * 🚨 Il **fatto** che sia manuale, non la stringa della
                     * fonte: di la' e' un booleano, e mandare `'manual'` a un
                     * campo che si aspetta `true` e' il genere di traduzione che
                     * funziona finche' qualcuno non aggiunge una terza fonte.
                     */
                    'kcal_manual' => $s->kcal_source?->value === 'manual',
                    'notes' => $s->notes,

                    'sets' => $s->sets->map(fn (SessionSet $r): array => [
                        'exercise_id' => $r->exercise_id,
                        'exercise_name' => $r->exercise?->name ?? 'Esercizio',
                        'met' => $r->exercise?->met,
                        'set_number' => $r->set_number,
                        'reps' => $r->reps,
                        'weight' => $r->weight === null ? null : (float) $r->weight,
                        'duration_sec' => $r->duration_sec,
                        'rest_sec' => $r->rest_sec,
                        'done_at' => $r->done_at?->toIso8601String(),
                    ])->all(),
                ])->all(),

                'daily_burns' => $bruciate->map(fn (DailyBurn $d): array => [
                    'date' => $d->date->toDateString(),
                    'kcal' => (int) $d->kcal,
                ])->all(),

                /*
                 * 💡 I conteggi viaggiano **dentro il pacchetto**: l'app li
                 * confronta con quello che ha scritto **prima** di dichiarare
                 * fatto, e cosi' un troncamento della risposta si vede subito
                 * invece che al passo 3.
                 */
                'counts' => $this->conteggiDi($utente),
            ],
        ]);
    }

    /**
     * L'app dichiara di aver scritto tutto. Il server verifica.
     *
     * ⛔ **Rifiuta con 409 se i conteggi non tornano.** Non e' pedanteria: un
     * «fatto» accettato a torto diventa una perdita di dati nel momento in cui
     * le tabelle cadranno, e a quel punto non c'e' nessun modo di accorgersene.
     */
    public function fatta(Request $request): JsonResponse
    {
        $dati = $request->validate([
            'counts' => ['required', 'array'],
            'counts.sessions' => ['required', 'integer', 'min:0'],
            'counts.sets' => ['required', 'integer', 'min:0'],
            'counts.daily_burns' => ['required', 'integer', 'min:0'],
        ]);

        $utente = $request->user();
        $veri = $this->conteggiDi($utente);

        if ($dati['counts'] !== $veri) {
            return response()->json([
                'message' => 'I conteggi non corrispondono: la migrazione non e stata segnata.',
                'code' => 'conteggi_diversi',
                'attesi' => $veri,
                'ricevuti' => $dati['counts'],
            ], 409);
        }

        /*
         * 🚨 `forceFill` + `save` e non `update()`: `workouts_migrated_at` non
         * e' fillable di proposito, perche' non deve poter arrivare da nessuna
         * richiesta dell'utente. Qui lo scrive il server, dopo aver verificato.
         */
        $utente->forceFill(['workouts_migrated_at' => now()])->save();

        return response()->json([
            'data' => [
                'migrated_at' => $utente->workouts_migrated_at?->toIso8601String(),
                'counts' => $veri,
            ],
        ]);
    }

    /**
     * Lo stato, per chi vuole sapere se deve migrare.
     *
     * 💡 Serve all'app per **non riscaricare** il pacchetto a ogni avvio: chi ha
     * gia' migrato riceve `migrated: true` e non chiede altro.
     */
    public function stato(Request $request): JsonResponse
    {
        $utente = $request->user();

        return response()->json([
            'data' => [
                'migrated' => $utente->workouts_migrated_at !== null,
                'migrated_at' => $utente->workouts_migrated_at?->toIso8601String(),
                'counts' => $this->conteggiDi($utente),
            ],
        ]);
    }

    /** @return array{sessions: int, sets: int, daily_burns: int} */
    private function conteggiDi(User $utente): array
    {
        $sessioni = WorkoutSession::query()->forUser($utente)->pluck('id');

        return [
            'sessions' => $sessioni->count(),
            'sets' => SessionSet::query()->whereIn('workout_session_id', $sessioni)->count(),
            'daily_burns' => DailyBurn::query()->forUser($utente)->count(),
        ];
    }
}
