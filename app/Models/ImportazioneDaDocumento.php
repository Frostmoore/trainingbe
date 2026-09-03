<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiFeature;
use App\Models\Concerns\BelongsToTenant;
use App\Services\Ai\Data\ParsedWorkoutPlan;
use App\Services\Ai\Data\PianoTrascritto;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Un piano alimentare in attesa di essere trascritto — N20.
 *
 * ── 🚨 Il rischio, che non e' quello che sembra ────────────────────────────
 *
 * Il rischio non e' che l'AI **fallisca**: un fallimento si vede e si rifa'. E'
 * che **riesca a meta'**. «200 g» letti «20 g» non danno nessun errore:
 * producono un piano plausibile e sbagliato, su un documento che la persona
 * crede fedele all'originale — e che seguira' per settimane.
 *
 * 💡 Per questo qui dentro c'e' una **bozza**, e il nome della colonna lo dice.
 * Non e' il piano: e' quello che l'AI crede di aver letto, e fino alla conferma
 * riga per riga (N20.3) non vale niente.
 *
 * ── ⚠️ Il piano confermato NON resta su questo server ──────────────────────
 *
 * Se lo tenessimo, avremmo una dieta legata a una persona sui nostri sistemi:
 * un dato dell'art. 9 con un nome sopra. Confermata la bozza, il telefono se la
 * porta via e questa riga si cancella.
 */
class ImportazioneDaDocumento extends Model
{
    /*
     * 🚨 Il filtro per palestra c'e', **e non basta da solo**: sopra ci
     * va sempre `user_id`. Il primo tiene fuori le altre palestre, il secondo
     * tiene fuori i compagni di palestra — e su un piano alimentare il secondo
     * conta quanto il primo.
     */
    use BelongsToTenant;

    protected $table = 'importazioni_da_documento';

    /**
     * ⚠️ Sette giorni: larghi per chi ci mette qualche giorno a controllare
     * riga per riga, stretti abbastanza da non diventare un archivio di diete.
     */
    public const DURATA_GIORNI = 7;

    /** 💡 Lo stesso tetto degli allegati di chat: un PDF e' un PDF. */
    public const BYTE_MASSIMI = 10 * 1024 * 1024;

    /**
     * Quanti documenti in un'importazione sola — K1, 03/09/2026.
     *
     * 🚨 **Cinque, e non uno.** Una scheda su carta sono spesso due o tre pagine
     * fotografate: accettarne una sola vorrebbe dire che chi ne fotografa una
     * **perde il resto senza accorgersene** — la bozza esce con meta' scheda,
     * plausibile e incompleta.
     *
     * ⚠️ Cinque e non venti: ogni pagina in piu' e' un'immagine intera dentro un
     * prompt pagato a token, e oltre le cinque pagine il documento giusto da
     * caricare e' un PDF.
     */
    public const AL_MASSIMO = 5;

    /**
     * I tipi di documento che si accettano.
     *
     * ⛔ **`heic` non c'e'**, ed e' voluto: Anthropic non lo accetta, e mandarlo
     * darebbe un errore che sembra un guasto nostro. 💡 Il telefono lo converte
     * prima di caricarlo, con la stessa libreria che usa per le foto del cibo.
     *
     * @var list<string>
     */
    public const MIME_AMMESSI = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
    ];

    /**
     * Cosa si sta importando — K2, 03/09/2026.
     *
     * ══ 🚨 UNA TABELLA SOLA PER DUE OGGETTI, E NON E' PIGRIZIA ════════════
     *
     * ⛔ La strada breve era una classe gemella `ImportazioneScheda`. Sarebbero
     * state **due implementazioni della stessa cosa** — carica, metti in coda,
     * trascrivi, consegna la bozza, chiudi — e quella che diverge per prima e'
     * sempre la copia meno provata.
     *
     * 💡 Il meccanismo e' **uno**. Cambiano tre cose, e cambiano tutte qui:
     * quale funzione AI paga, quale prompt legge, e dove finisce la bozza.
     */
    public const GENERE_PIANO = 'piano';

    public const GENERE_SCHEDA = 'scheda';

    public const TIPO_PDF = 'pdf';

    public const TIPO_IMMAGINI = 'immagini';

    public const CARTELLA = 'piani-da-importare';

    /*
     * Gli stati, che sono quattro e bastano.
     *
     * 💡 Non c'e' uno stato «confermata»: una bozza confermata **non
     * resta**. Il telefono se la porta via e la riga si cancella, perche' un
     * piano alimentare legato a una persona sui nostri server sarebbe un dato
     * dell'art. 9 con un nome sopra.
     */
    public const IN_CODA = 'in_coda';

    public const IN_LAVORAZIONE = 'in_lavorazione';

    public const PRONTA = 'pronta';

    public const FALLITA = 'fallita';

    protected $fillable = [
        'user_id', 'tenant_id', 'genere', 'documenti', 'tipo', 'nome_file', 'byte_totali',
        'stato', 'bozza', 'modello_usato', 'errore', 'dichiarato_il',
        'consenso_documento_il', 'scade_il',
        'paga_con_gettoni',
    ];

    protected function casts(): array
    {
        return [
            'bozza' => 'array',
            'documenti' => 'array',
            'byte_totali' => 'integer',
            'paga_con_gettoni' => 'boolean',
            'dichiarato_il' => 'datetime',
            'consenso_documento_il' => 'datetime',
            'scade_il' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Quale funzione AI paga questa importazione.
     *
     * 🚨 **Sta qui e non nel controller**, perche' i punti che devono saperlo
     * sono **tre** — il cancello dei gettoni prima di caricare, il contesto
     * della chiamata, e lo scalo dopo la trascrizione — e in tre posti diversi
     * uno prima o poi guarda l'altra.
     *
     * 💡 Costano uguale (50 gettoni), e non e' un caso: un prezzo diverso
     * spingerebbe verso l'oggetto sbagliato per il motivo sbagliato.
     */
    public function funzione(): AiFeature
    {
        return $this->genere === self::GENERE_SCHEDA
            ? AiFeature::PdfImport
            : AiFeature::NutritionPdfImport;
    }

    public function eUnaScheda(): bool
    {
        return $this->genere === self::GENERE_SCHEDA;
    }

    /**
     * I percorsi relativi dei documenti, **nell'ordine di lettura**.
     *
     * 🚨 L'ordine e' l'informazione principale quando si tratta di pagine
     * fotografate: la seconda pagina letta per prima da una scheda che comincia
     * da meta'.
     *
     * @return list<string>
     */
    public function percorsi(): array
    {
        return array_map(
            fn (array $d): string => self::CARTELLA.'/'.$d['token'],
            $this->documenti ?? [],
        );
    }

    /**
     * Deposita i documenti e apre l'importazione.
     *
     * 🚨 **Prima i file, poi la riga**, come per gli allegati: nell'ordine
     * inverso esisterebbe un istante in cui la riga promette documenti che non
     * ci sono, e il lavoro partirebbe a vuoto.
     *
     * ⚠️ **`nome_file` e `byte_totali` si calcolano QUI, e in nessun altro
     * posto.** Sono derivati da `documenti`: due sedi della stessa somma
     * divergono, e la copia e' sempre quella che sbaglia.
     *
     * @param  list<array{byte: string, nome: string, mime: string}>  $files
     */
    public static function apri(
        User $chi,
        array $files,
        bool $pagaConGettoni = false,
        string $genere = self::GENERE_PIANO,
    ): self {
        $disco = Storage::disk('local');
        $documenti = [];

        foreach ($files as $file) {
            $token = Str::random(48);

            $disco->put(self::CARTELLA.'/'.$token, $file['byte']);

            $documenti[] = [
                'token' => $token,
                'nome' => $file['nome'],
                'mime' => $file['mime'],
                'byte' => strlen($file['byte']),
            ];
        }

        /*
         * 💡 **Basta un PDF perche' l'importazione sia "da PDF".** Il tipo serve
         * a decidere quale avvertenza mostrare, e l'avvertenza sulle immagini —
         * *«l'analisi delle immagini e' generalmente meno accurata»* — ha senso
         * solo quando **tutto** e' una fotografia.
         */
        $tipo = array_filter(
            $documenti,
            static fn (array $d): bool => $d['mime'] === 'application/pdf',
        ) === [] ? self::TIPO_IMMAGINI : self::TIPO_PDF;

        return self::create([
            'user_id' => $chi->getKey(),
            'tenant_id' => $chi->tenant_id,
            'genere' => $genere,
            'documenti' => $documenti,
            'tipo' => $tipo,
            'nome_file' => self::comeSiChiama($documenti),
            'byte_totali' => array_sum(array_column($documenti, 'byte')),
            'stato' => self::IN_CODA,
            'paga_con_gettoni' => $pagaConGettoni,
            // 🚨 Con la data: chi importa dichiara che il piano l'ha redatto un
            // professionista abilitato, e quando l'ha dichiarato.
            'dichiarato_il' => now(),

            /*
             * 🔴 **E quando ha acconsentito a mandare QUESTO documento** —
             * K1-ter.
             *
             * ⚠️ Si scrive qui e non nel controller perche' qui c'e' il momento
             * esatto in cui il file entra nei nostri sistemi: una data presa
             * altrove sarebbe un istante diverso da quello che autorizza.
             *
             * ⛔ Si registra **quando**, non **cosa**: il documento e il suo
             * contenuto non si conservano «per provare il consenso», che sarebbe
             * tenere proprio cio' che K1-bis toglie con la scusa migliore.
             */
            'consenso_documento_il' => now(),
            'scade_il' => now()->addDays(self::DURATA_GIORNI),
        ]);
    }

    /**
     * Come si chiama, in una riga che una persona possa leggere.
     *
     * 💡 Un file solo porta il suo nome; tre fotografie diventano «3 immagini».
     * ⛔ Mostrare il nome del primo direbbe che e' tutto li'.
     *
     * @param  list<array{nome: string, mime: string}>  $documenti
     */
    private static function comeSiChiama(array $documenti): string
    {
        if (count($documenti) === 1) {
            return $documenti[0]['nome'];
        }

        return count($documenti).' immagini';
    }

    /**
     * I percorsi assoluti dei documenti che esistono davvero.
     *
     * ⚠️ **Si filtra su cio' che c'e'**: un file sparito dal disco — pulizia,
     * ripristino, un `butta()` a meta' — non deve far leggere al provider un
     * percorso che non apre. 🚨 Ma se ne sparisce **uno su tre**, chi chiama
     * deve accorgersene: vedi `documentiMancanti()`.
     *
     * @return list<string>
     */
    public function percorsiAssoluti(): array
    {
        $disco = Storage::disk('local');

        return array_values(array_map(
            fn (string $p): string => $disco->path($p),
            array_filter($this->percorsi(), fn (string $p): bool => $disco->exists($p)),
        ));
    }

    /**
     * Quanti documenti dichiarati non ci sono piu' sul disco.
     *
     * 🚨 **Zero e' l'unico valore accettabile prima di chiamare il modello.**
     * Trascrivere due pagine su tre non da' nessun errore: da' una scheda che
     * comincia dal secondo giorno, e sembra completa.
     */
    public function documentiMancanti(): int
    {
        return count($this->percorsi()) - count($this->percorsiAssoluti());
    }

    public function inLavorazione(): void
    {
        $this->update(['stato' => self::IN_LAVORAZIONE]);
    }

    /**
     * Deposita la trascrizione.
     *
     * ⚠️ **Accetta tutti e due**, perche' il genere lo decide la riga: un piano
     * trascritto o una scheda letta sono la stessa cosa per questo metodo — una
     * bozza da consegnare. 🚨 Due metodi gemelli sarebbero due posti in cui
     * dimenticare `errore => null`.
     */
    public function salvaBozza(PianoTrascritto|ParsedWorkoutPlan $bozza, string $modello): void
    {
        $this->update([
            'stato' => self::PRONTA,
            'bozza' => $bozza instanceof PianoTrascritto ? $bozza->perLApp() : $bozza->toArray(),
            'modello_usato' => $modello,
            'errore' => null,
        ]);
    }

    public function fallisce(string $errore): void
    {
        $this->update([
            'stato' => self::FALLITA,
            // ⚠️ Troncato: `errore` e' un `text`, ma un messaggio del fornitore
            // lungo diecimila caratteri non aiuta nessuno e ce lo portiamo dietro.
            'errore' => mb_substr($errore, 0, 1000),
        ]);
    }

    /**
     * Butta **i documenti**, e tiene la riga — K1-bis, 03/09/2026.
     *
     * ══ 🚨 SI CHIAMA APPENA IL JOB HA FINITO, RIUSCITO O FALLITO ══════════
     *
     * 📌 Il committente: *«Naturalmente niente deve stare più sul server, come
     * avevamo detto»*.
     *
     * ⛔ Prima i file restavano **sette giorni**, perche' la revisione doveva
     * poter riaprire l'originale. 💡 E quei sette giorni non servivano a niente:
     * **il documento ce l'ha gia' il telefono**, che e' chi l'ha scelto e
     * caricato. Farselo riscaricare era un giro inutile che costava una copia di
     * un documento sanitario sul nostro disco per una settimana.
     *
     * ⚠️ **Anche quando fallisce**, ed e' il caso che si dimentica: un job morto
     * lascerebbe sul disco proprio il documento che non siamo nemmeno riusciti a
     * leggere. E' la stessa regola di `StimaCibo::fallisce()`.
     */
    public function buttaIDocumenti(): void
    {
        Storage::disk('local')->delete($this->percorsi());

        /*
         * 💡 **La lista si svuota**, e non e' cosmesi: `percorsi()` continuerebbe
         * a nominare file che non ci sono, e `documentiMancanti()` — che serve a
         * fermare una trascrizione monca — direbbe che ne mancano tutti.
         */
        $this->update(['documenti' => []]);
    }

    /** Butta quello che resta: i documenti, se ci sono ancora, e la riga. */
    public function butta(): void
    {
        Storage::disk('local')->delete($this->percorsi());

        $this->delete();
    }

    public function scaduta(): bool
    {
        return $this->scade_il->isPast();
    }
}
