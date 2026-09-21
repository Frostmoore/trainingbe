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
 *  4. contenimento **dall'inizio e a parole intere** (F7.4): il nome letto
 *     comincia con quello di un esercizio noto, scegliendo il piu' lungo —
 *     «panca piana bilanciere» trova «panca piana», ma «crunch su panca
 *     inclinata» non trova «panca inclinata»;
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
     * cioe' tutti quelli che non stanno nei 314 del catalogo.
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

        /*
         * 🆕 Le librerie che chi scrive puo' vedere — 3b-M, 28/08/2026.
         *
         * ⚠️ **Cercare la' dentro, ma creare sempre nel proprio tenant.** Sono
         * due cose diverse e vanno tenute diverse: se creasse nella libreria
         * del trainer, un iscritto potrebbe allargargliela a sua insaputa.
         *
         * ⛔ `null` quando chi chiama non passa l'utente — import da PDF,
         * comandi, seeder: li' si torna al comportamento di prima, che e'
         * giusto, perche' non c'e' nessuno di cui guardare i trainer.
         */
        $librerie = $da?->librerieVisibili() ?? [];

        $trovato = $this->cerca($canonico, $tenantId, $librerie);

        if ($trovato !== null) {
            return $this->completa($trovato, $tenantId, $primario, $secondari);
        }

        // Sinonimo noto: si ritenta con la forma vera.
        if (isset(self::SINONIMI[$canonico])) {
            $trovato = $this->cerca(self::SINONIMI[$canonico], $tenantId, $librerie);

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

            /*
             * 🆕 **E il muscolo deve tornare** — 23/09/2026, F7.4.
             *
             * 🚨 «Corda al cavo basso» (un curl per i bicipiti, dal PDF vero di
             * una palestra) comincia con «corda», e finiva sul salto con la
             * corda. 💡 Chi chiama il primario **lo sa** (il modello lo legge
             * insieme alla riga): se quello trovato per somiglianza allena
             * un'altra cosa, la somiglianza è solo nel nome, e si crea.
             *
             * ⚠️ Solo per il contenimento: una corrispondenza esatta o un
             * sinonimo restano validi anche se il modello sbaglia muscolo.
             */
            $altroMuscolo = $trovato !== null
                && $primario !== null
                && $trovato->muscle_group !== null
                && $trovato->muscle_group !== $primario;

            if ($trovato !== null && ! $altroMuscolo) {
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
            'name' => self::nomeDaScrivere($nome),
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

    /**
     * Corrispondenza esatta: prima la propria, poi quelle del trainer, poi la
     * piattaforma.
     *
     * ══ 🚨 PERCHE' GUARDA ANCHE LE LIBRERIE DEL TRAINER — 3b-M ════════════
     *
     * ⛔ Senza questo passaggio la funzione sarebbe **mezza fatta**: l'iscritto
     * di un trainer indipendente **vedrebbe** «Panca della domenica»
     * nell'elenco, ma scrivendone il nome in una scheda ne farebbe nascere un
     * doppione nel proprio tenant.
     *
     * 🚨 Ed e' la peggiore delle due meta': il doppione e' un esercizio nuovo,
     * quindi **senza illustrazione, senza muscoli e senza MET** — e con uno
     * storico che si spezza in due nomi identici.
     *
     * 💡 L'ordine e' «dal piu' vicino al piu' lontano», lo stesso di prima con
     * un gradino in mezzo: il mio, del mio trainer, della piattaforma.
     *
     * @param  list<int>  $altreLibrerie  i tenant dei trainer che mi seguono o mi hanno seguito
     */
    private function cerca(string $canonico, ?int $tenantId, array $altreLibrerie = []): ?Exercise
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

        $altre = array_values(array_diff($altreLibrerie, [$tenantId]));

        if ($altre !== []) {
            $delTrainer = Exercise::withoutGlobalScopes()
                ->whereIn('tenant_id', $altre)
                ->where('slug_normalized', $canonico)
                ->first();

            if ($delTrainer !== null) {
                return $delTrainer;
            }
        }

        return Exercise::withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('slug_normalized', $canonico)
            ->first();
    }

    /**
     * Il nome letto **comincia** con quello di un esercizio noto, o viceversa.
     *
     * ══ 🚨 A PAROLE INTERE, E DALL'INIZIO — 23/09/2026, F7.4 ═════════════════
     *
     * ⛔ Prima bastava che un nome fosse **dentro** l'altro, in qualunque punto
     * e anche a metà parola. Il primo giro vero dell'import dal pannello (nove
     * schede, F7.4) ha trovato tre abbinamenti falsi:
     *  - «tricipiti ai cavi con la corda» → **Corda** (il salto con la corda,
     *    cardio): «corda» stava in fondo alla frase;
     *  - «crunch inversi su panca inclinata» → **Panca inclinata**: l'attrezzo
     *    scambiato per l'esercizio;
     *  - «panca» → **Panca inclinata con manubri**: nel verso inverso vinceva il
     *    nome **più lungo** che conteneva quello letto, cioè il più specifico —
     *    il contrario di quello che il foglio diceva.
     *
     * 💡 In un nome d'esercizio italiano **la testa è davanti**: «panca piana
     * bilanciere» è una panca piana, «crunch su panca inclinata» è un crunch.
     * Quindi:
     *  1. **il letto comincia col noto** (a parola intera): vince il noto **più
     *     lungo** — fra «panca» e «panca piana», per «panca piana bilanciere» è
     *     il secondo;
     *  2. altrimenti **il noto comincia col letto** (a parola intera): vince il
     *     **più corto**, cioè il meno specifico — «panca» è «Panca piana», non
     *     «Panca inclinata con manubri».
     *
     * ⚠️ Sotto i cinque caratteri non si tenta, come prima: «dip» davanti a
     * «dip barre parallele» e a «dip panca» sarebbe una corrispondenza fortuita.
     * ⚠️ Un nome che non combacia così **non si forza**: si crea l'esercizio
     * della palestra col nome del foglio, e il trainer lo vede scritto com'era.
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

        $piuLungoDavanti = null;
        $piuCortoDopo = null;

        foreach ($candidati as $e) {
            $suo = (string) $e->slug_normalized;

            if (mb_strlen($suo) < 5) {
                continue;
            }

            if (self::cominciaCon($canonico, $suo)) {
                if ($piuLungoDavanti === null || mb_strlen($suo) > mb_strlen((string) $piuLungoDavanti->slug_normalized)) {
                    $piuLungoDavanti = $e;
                }
            } elseif (self::cominciaCon($suo, $canonico)) {
                if ($piuCortoDopo === null || mb_strlen($suo) < mb_strlen((string) $piuCortoDopo->slug_normalized)) {
                    $piuCortoDopo = $e;
                }
            }
        }

        return $piuLungoDavanti ?? $piuCortoDopo;
    }

    /**
     * `$nome` comincia con `$testa` **a parola intera**: «panca piana
     * bilanciere» comincia con «panca piana», «pancafit» non comincia con
     * «panca».
     */
    private static function cominciaCon(string $nome, string $testa): bool
    {
        return $nome === $testa || str_starts_with($nome, $testa.' ');
    }

    /**
     * Il nome con cui nasce un esercizio nuovo.
     *
     * 🆕 23/09/2026, F7.4: **un nome TUTTO MAIUSCOLO si riscrive** («PANCA
     * ORIZZONTALE» → «Panca orizzontale»). Molte schede di palestra sono
     * impaginate in maiuscolo, e l'esercizio che nasce da lì resta nella
     * libreria per sempre: in un elenco di nomi normali, uno che urla si legge
     * come un errore. ⚠️ Un nome misto («Curl alla panca Scott») si lascia
     * com'è: le maiuscole lì sono volute.
     */
    private static function nomeDaScrivere(string $nome): string
    {
        $nome = trim($nome);

        if ($nome === '') {
            return 'Esercizio';
        }

        if ($nome === mb_strtoupper($nome) && $nome !== mb_strtolower($nome)) {
            $minuscolo = mb_strtolower($nome);

            return mb_strtoupper(mb_substr($minuscolo, 0, 1)).mb_substr($minuscolo, 1);
        }

        return $nome;
    }
}
