<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Enums\MuscleGroup;
use App\Models\Exercise;
use App\Models\User;
use App\Support\Tenancy\TenantContext;

/**
 * Riconcilia il nome letto da un PDF con la libreria esercizi — B7.3.
 *
 * 🚨 **Perche' un servizio dedicato e non una `where name = ...`.**
 * «Panca piana», «panca piana bilanciere», «Panca Piana con bilanciere» e «Bench
 * press» sono lo stesso esercizio. Senza riconciliazione, la libreria di ogni
 * palestra degenera in poche settimane in decine di varianti della stessa cosa,
 * e da quel momento nessuna statistica per esercizio ha piu' senso: il progresso
 * sulla panca risulta diviso su quattro righe diverse.
 *
 * **L'ordine di ricerca non e' casuale:**
 *  1. corrispondenza esatta sulla forma canonica **nella palestra** — se la
 *     palestra ha gia' un suo esercizio con quel nome, e' quello che intende;
 *  2. corrispondenza esatta **fra i globali**;
 *  3. sinonimi noti, prima palestra poi globali;
 *  4. contenimento: il nome letto contiene quello di un esercizio noto (o
 *     viceversa), scegliendo il piu' lungo — «panca piana bilanciere» trova
 *     «panca piana»;
 *  5. **si crea** un esercizio custom della palestra.
 *
 * Il quinto passo e' un fallimento parziale, non un successo: per questo la riga
 * nasce con `is_custom = true`, che e' esattamente il filtro con cui il pannello
 * di piattaforma (B2.4) mostra «esercizi nati da un import non riconosciuto».
 */
class ExerciseMatcher
{
    /**
     * Sinonimi: forma canonica → forma canonica dell'esercizio vero.
     *
     * Elenco corto e curato a mano, non un dizionario: un elenco lungo di
     * traduzioni approssimative fa piu' danni di nessun elenco, perche' unisce
     * esercizi che non sono lo stesso.
     *
     * @var array<string, string>
     */
    private const SINONIMI = [
        'bench press' => 'panca piana',
        'flat bench press' => 'panca piana',
        'incline bench press' => 'panca inclinata',
        'squat' => 'squat',
        'back squat' => 'squat',
        'front squat' => 'squat frontale',
        'deadlift' => 'stacco da terra',
        'stacco' => 'stacco da terra',
        'romanian deadlift' => 'stacco rumeno',
        'lat pulldown' => 'lat machine',
        'lat machine avanti' => 'lat machine',
        'pull up' => 'trazioni',
        'pull ups' => 'trazioni',
        'chin up' => 'trazioni',
        'barbell row' => 'rematore con bilanciere',
        'rematore bilanciere' => 'rematore con bilanciere',
        'overhead press' => 'lento avanti',
        'military press' => 'lento avanti',
        'shoulder press' => 'lento avanti',
        'leg press' => 'pressa',
        'leg extension' => 'leg extension',
        'leg curl' => 'leg curl',
        'calf raise' => 'calf',
        'bicep curl' => 'curl bicipiti',
        'biceps curl' => 'curl bicipiti',
        'curl' => 'curl bicipiti',
        'triceps pushdown' => 'push down tricipiti',
        'french press' => 'french press',
        'plank' => 'plank',
        'crunch' => 'crunch',
        'dip' => 'dips',
        'dips' => 'dips',
        'affondi' => 'affondi',
        'lunges' => 'affondi',
        'hip thrust' => 'hip thrust',
    ];

    public function __construct(
        private readonly TenantContext $tenants,
    ) {}

    /**
     * L'esercizio corrispondente al nome, creandolo se non esiste.
     *
     * ══ 🚨 I MUSCOLI ENTRANO DA QUI — 3b-A.3.4, 23/08/2026 ═════════════════
     *
     * ⛔ Prima questo metodo creava esercizi **completamente muti**: nessun
     * primario, nessun secondario. E' la falla vera di A.3, perche' e' da qui
     * che nasce ogni esercizio scritto a mano in una scheda o letto da un PDF —
     * cioe' tutti quelli che non stanno nei 121 del catalogo.
     *
     * 💡 Adesso chi chiama puo' dire quello che sa, e questo metodo lo usa in
     * due momenti diversi: quando **crea** l'esercizio, e quando ne trova uno
     * gia' esistente ma **incompleto**. Il secondo caso e' quello che fa
     * guarire il catalogo invece di lasciarlo marcire.
     *
     * @param  int|null  $tenantId  la palestra proprietaria; `null` usa il contesto
     * @param  MuscleGroup|null  $primario  il muscolo che fa il lavoro, se chi chiama lo sa
     * @param  list<string>|null  $secondari  quelli che aiutano; `null` = «non lo so», `[]` = «nessuno»
     */
    public function match(
        string $nome,
        ?int $tenantId = null,
        ?User $da = null,
        ?MuscleGroup $primario = null,
        ?array $secondari = null,
    ): Exercise {
        $tenantId ??= $this->tenants->id();
        $canonico = Exercise::normalize($nome);

        if ($canonico === '') {
            $canonico = 'esercizio';
        }

        $trovato = $this->cerca($canonico, $tenantId);

        if ($trovato !== null) {
            return $this->completa($trovato, $tenantId, $primario, $secondari);
        }

        // Sinonimo noto: si ritenta con la forma vera.
        if (isset(self::SINONIMI[$canonico])) {
            $trovato = $this->cerca(self::SINONIMI[$canonico], $tenantId);

            if ($trovato !== null) {
                return $this->completa($trovato, $tenantId, $primario, $secondari);
            }
        }

        $trovato = $this->cercaPerContenimento($canonico, $tenantId);

        if ($trovato !== null) {
            return $this->completa($trovato, $tenantId, $primario, $secondari);
        }

        // 🚨 Non riconosciuto: si crea, ma marcato. `is_custom = true` e' cio'
        // che permette al pannello di piattaforma di mostrare la lista delle
        // cose da riconciliare a mano invece di lasciarle sedimentare.
        return Exercise::withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'name' => trim($nome) !== '' ? trim($nome) : 'Esercizio',
            'slug_normalized' => $canonico,
            'is_custom' => true,
            'created_by' => $da?->getKey(),

            /*
             * ⚠️ **`null` e non `[]` quando non si sa** — 3b-A.3.4.
             *
             * 🚨 Sono due affermazioni diverse: `[]` dice «questo esercizio
             * isola davvero», `null` dice «nessuno l'ha ancora deciso». ⛔
             * Scrivere `[]` per comodita' vorrebbe dire riempire il catalogo di
             * esercizi che *dichiarano* di non avere secondari — e la guardia
             * che cerca i buchi non ne troverebbe piu' nessuno.
             */
            'muscle_group' => $primario,
            'secondary_muscles' => $secondari,
        ]);
    }

    /**
     * Riempie i muscoli che mancano su un esercizio gia' esistente.
     *
     * ══ 💡 IL CATALOGO GUARISCE, NON MARCISCE — 3b-A.3.4 ═══════════════════
     *
     * 🚨 **Non sovrascrive mai.** Se l'esercizio i muscoli ce li ha gia', quelli
     * restano: chi scrive una scheda sta descrivendo il *suo* allenamento, non
     * correggendo la libreria.
     *
     * ⛔ **E non tocca il catalogo di piattaforma** (`tenant_id` nullo). Quelle
     * righe le vedono tutte le palestre: lasciare che l'esercizio scritto a mano
     * da un iscritto ne cambi una vorrebbe dire far scrivere una persona sul
     * dato di tutti gli altri. Le lacune del catalogo comune si sistemano dal
     * pannello, dove c'e' qualcuno che risponde di quello che scrive.
     *
     * @param  list<string>|null  $secondari
     */
    private function completa(
        Exercise $esercizio,
        ?int $tenantId,
        ?MuscleGroup $primario,
        ?array $secondari,
    ): Exercise {
        if ($tenantId === null || $esercizio->tenant_id !== $tenantId) {
            return $esercizio;
        }

        $da = [];

        if ($primario !== null && $esercizio->muscle_group === null) {
            $da['muscle_group'] = $primario;
        }

        if ($secondari !== null && $esercizio->secondary_muscles === null) {
            $da['secondary_muscles'] = $secondari;
        }

        if ($da !== []) {
            $esercizio->forceFill($da)->save();
        }

        return $esercizio;
    }

    /** Corrispondenza esatta: prima la palestra, poi la piattaforma. */
    private function cerca(string $canonico, ?int $tenantId): ?Exercise
    {
        if ($tenantId !== null) {
            $suo = Exercise::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('slug_normalized', $canonico)
                ->first();

            if ($suo !== null) {
                return $suo;
            }
        }

        return Exercise::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('slug_normalized', $canonico)
            ->first();
    }

    /**
     * Il nome letto contiene quello di un esercizio noto, o viceversa.
     *
     * Vince il **piu' lungo** fra i candidati: fra «panca» e «panca piana», per
     * «panca piana bilanciere» quello giusto e' il secondo. Con il piu' corto si
     * finirebbe per far collassare mezza libreria su «panca».
     *
     * ⚠️ Sotto i cinque caratteri non si tenta: «curl» dentro «curl bicipiti» va
     * bene, ma «dip» dentro «dip barre parallele» e «dip panca» sarebbe una
     * corrispondenza fortuita su nomi troppo corti per essere significativi.
     */
    private function cercaPerContenimento(string $canonico, ?int $tenantId): ?Exercise
    {
        if (mb_strlen($canonico) < 5) {
            return null;
        }

        $candidati = Exercise::withoutGlobalScopes()
            ->where(function ($q) use ($tenantId): void {
                $q->whereNull('tenant_id');

                if ($tenantId !== null) {
                    $q->orWhere('tenant_id', $tenantId);
                }
            })
            ->where('is_custom', false)
            ->get();

        $migliore = null;

        foreach ($candidati as $e) {
            $suo = (string) $e->slug_normalized;

            if ($suo === '' || mb_strlen($suo) < 5) {
                continue;
            }

            $combacia = str_contains($canonico, $suo) || str_contains($suo, $canonico);

            if (! $combacia) {
                continue;
            }

            if ($migliore === null || mb_strlen((string) $e->slug_normalized) > mb_strlen((string) $migliore->slug_normalized)) {
                $migliore = $e;
            }
        }

        return $migliore;
    }
}
