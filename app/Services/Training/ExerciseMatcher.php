<?php

declare(strict_types=1);

namespace App\Services\Training;

use App\Enums\MuscleGroup;
use App\Exceptions\MuscoliNonDecisiException;
use App\Models\Exercise;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use App\Support\Training\MuscoliDegliEsercizi;

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
     * ── ⛔ 3b-A.3.5: un esercizio nuovo senza muscoli NON SI CREA ───────────
     *
     * 🚨 Se trova, torna quello che ha trovato e i muscoli non servono: il
     * server li sa gia'. Se **deve creare** e nessuno glieli ha detti, solleva
     * `MuscoliNonDecisiException` (422) invece di scrivere una riga muta.
     *
     * ⛔ **Non c'e' un interruttore per saltare la guardia**, ed e' voluto: un
     * parametro «crea lo stesso» sarebbe messo a `true` dal primo che incontra
     * l'errore, e la porta tornerebbe aperta senza che nessuno lo decida
     * davvero. Chi crea un esercizio deve poter rispondere; se non puo', il
     * posto giusto per accorgersene e' adesso.
     *
     * @param  int|null  $tenantId  la palestra proprietaria; `null` usa il contesto
     * @param  MuscleGroup|null  $primario  il muscolo che fa il lavoro, se chi chiama lo sa
     * @param  list<string>|null  $secondari  quelli che aiutano; `null` = «non lo so», `[]` = «nessuno»
     *
     * @throws MuscoliNonDecisiException se deve creare e i muscoli non ci sono
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

        /*
         * ══ ⛔ UN NOME CHE CONOSCIAMO NON E' L'ALIAS DI UN ALTRO ═══════════
         *
         * 🚨 Difetto vero, trovato il 24/08/2026 creando le schede del
         * committente: **«Rematore corda» e' finito su «Corda»** — cioe' sul
         * saltare la corda, categoria `cardio`. Il contenimento aveva visto la
         * parola «corda» dentro il nome e aveva accoppiato due esercizi che non
         * c'entrano niente.
         *
         * ⚠️ Il danno non e' solo il nome: quell'esercizio avrebbe colorato
         * **polpacci e spalle** invece di schiena e bicipiti, e le calorie
         * sarebbero state stimate con il MET del salto della corda.
         *
         * 💡 `MuscoliDegliEsercizi` e' l'elenco degli esercizi che **sappiamo**
         * esistere. Se un nome sta li', e' un esercizio a se': va creato, non
         * accoppiato per somiglianza a qualcos'altro.
         *
         * ⛔ Il controllo sta **prima** del contenimento e **dopo** la ricerca
         * esatta: se l'esercizio esiste gia' si riusa quello, ed e' giusto — e'
         * solo l'accoppiamento per somiglianza che va scavalcato.
         */
        if (MuscoliDegliEsercizi::di(trim($nome)) === null) {
            $trovato = $this->cercaPerContenimento($canonico, $tenantId);

            if ($trovato !== null) {
                return $this->completa($trovato, $tenantId, $primario, $secondari);
            }
        }

        /*
         * ⛔ **3b-A.3.5 — qui si crea, quindi qui si pretende una risposta.**
         *
         * 🚨 Servono **tutti e due**: il primario, e i secondari **anche
         * vuoti**. `[]` dice «questo esercizio isola davvero» ed e' una
         * decisione; `null` dice «non ci ho pensato». Accettare `null` sui
         * secondari vorrebbe dire lasciare aperta la stessa porta di prima.
         *
         * ⚠️ Al 24/08/2026, prima di questa riga, in staging c'erano gia'
         * **sette** esercizi nati muti da questo esatto punto di codice.
         */
        if ($primario === null || $secondari === null) {
            throw new MuscoliNonDecisiException(trim($nome) !== '' ? trim($nome) : 'Esercizio');
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
             * 💡 Da A.3.5 non possono piu' essere nulli: la guardia qui sopra
             * si e' gia' assicurata che qualcuno abbia risposto. ⚠️ La
             * distinzione fra `[]` e `null` resta comunque vera per le righe
             * **gia' in tabella**, ed e' su quella che lavora il filtro
             * «Senza muscoli decisi» del pannello.
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
