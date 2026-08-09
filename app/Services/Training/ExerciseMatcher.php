<?php

declare(strict_types=1);

namespace App\Services\Training;

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
     * @param  int|null  $tenantId  la palestra proprietaria; `null` usa il contesto
     */
    public function match(string $nome, ?int $tenantId = null, ?User $da = null): Exercise
    {
        $tenantId ??= $this->tenants->id();
        $canonico = Exercise::normalize($nome);

        if ($canonico === '') {
            $canonico = 'esercizio';
        }

        $trovato = $this->cerca($canonico, $tenantId);

        if ($trovato !== null) {
            return $trovato;
        }

        // Sinonimo noto: si ritenta con la forma vera.
        if (isset(self::SINONIMI[$canonico])) {
            $trovato = $this->cerca(self::SINONIMI[$canonico], $tenantId);

            if ($trovato !== null) {
                return $trovato;
            }
        }

        $trovato = $this->cercaPerContenimento($canonico, $tenantId);

        if ($trovato !== null) {
            return $trovato;
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
        ]);
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
