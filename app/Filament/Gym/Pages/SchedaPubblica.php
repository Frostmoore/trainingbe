<?php

declare(strict_types=1);

namespace App\Filament\Gym\Pages;

use App\Models\Campagna;
use App\Models\Comune;
use App\Models\ProfiloPubblico;
use App\Models\Tenant;
use App\Services\Scoperta\RicercaComuni;
use App\Support\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * La scheda con cui la palestra compare nel catalogo — M2.2, 18/08/2026.
 *
 * ── 🚨 Perche' esiste questa pagina ────────────────────────────────────────
 *
 * Senza, `profili_pubblici` si potrebbe riempire **solo da console**, e il
 * catalogo resterebbe vuoto per sempre: un endpoint pubblico perfettamente
 * funzionante che non ha niente da mostrare. ⚠️ E' il tipo di lacuna che non fa
 * fallire nessun test — tutti i test del catalogo si scrivono le righe da soli.
 *
 * ── ⚠️ Perche' NON c'e' il campo «chi risponde» ────────────────────────────
 *
 * Il destinatario dei messaggi e' **il proprietario**, e si ricava dal tenant
 * (`ProfiloPubblico::destinatario()`). Non e' una comodita' mancata: e' la
 * decisione §4.2 del piano. La chat e' cifrata con **una chiave pubblica per
 * persona**, e poter scegliere un dipendente avrebbe voluto dire o cifrare N
 * volte — con chi entra dopo che comunque non legge il passato — oppure
 * smettere di cifrare punto a punto, cioe' rendere la palestra l'unico posto
 * dove **noi** potremmo leggere.
 *
 * 🚨 La conseguenza va **detta qui**, e infatti c'e': cambiando proprietario, le
 * conversazioni gia' avvenute restano **illeggibili** al successore. Non e' un
 * difetto — e' la cifratura che funziona come promesso — ma va scritto, non
 * scoperto.
 */
class SchedaPubblica extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?string $navigationLabel = 'Scheda pubblica';

    protected static ?string $title = 'La tua scheda nel catalogo';

    protected static ?int $navigationSort = 85;

    protected string $view = 'filament.gym.pages.scheda-pubblica';

    /** @var array<string, mixed> */
    public array $data = [];

    /**
     * 🚨 **Il proprietario di una palestra, oppure un trainer indipendente. Mai
     * un dipendente.**
     *
     * ⚠️ Chi risponde ai messaggi che arrivano dal catalogo e' il titolare della
     * scheda, e quei messaggi sono cifrati **per lui**. Lasciare la pagina allo
     * staff vorrebbe dire far pubblicare a un dipendente una casella che poi non
     * e' la sua — e che smetterebbe di funzionare il giorno che cambia lavoro.
     *
     * 💡 Il trainer indipendente c'e' perche' **il catalogo lo prevede** (§4.7
     * del piano): dentro ci sono le palestre e i trainer indipendenti. Senza
     * questa riga, meta' del catalogo non avrebbe modo di esistere.
     */
    public static function canAccess(): bool
    {
        $utente = auth()->user();

        return $utente?->isGymAdmin() === true || $utente?->isFreeTrainer() === true;
    }

    public function mount(): void
    {
        $scheda = $this->scheda();

        $this->form->fill([
            'comune_id' => $scheda?->comune_id ?? auth()->user()?->comune_id,
            'indirizzo' => $scheda?->indirizzo,
            'cap' => $scheda?->cap,
            'titolo' => $scheda?->titolo ?? ($this->eUnTrainerIndipendente()
                ? auth()->user()?->name
                : $this->palestra()?->name),
            'descrizione' => $scheda?->descrizione,
        ]);

        /*
         * 🖼️ La testata si carica a parte: è un file della media library, e il
         * campo del modulo la legge dal modello, non da un array.
         */
        if ($scheda !== null) {
            $this->form->model($scheda);
        }
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([

                Section::make('Come ti presenti')
                    ->description('E\' quello che vedono le persone che cercano una palestra nella tua zona.')
                    ->columns(2)
                    ->schema([
                        /*
                         * 🏷️ **«Nome pubblico», non «Titolo»** — F6.3 (A4).
                         *
                         * 📌 *«Titolo cambiamolo in nome pubblico»* · *«nome
                         * pubblico è il nome che usa la palestra nell'app,
                         * quindi sì»*.
                         *
                         * ⚠️ La colonna resta `titolo`: una migrazione per
                         * un'etichetta è lavoro sprecato, e l'API la chiama
                         * già così.
                         */
                        TextInput::make('titolo')
                            ->label('Nome pubblico')
                            ->required()
                            ->maxLength(120)
                            ->helperText('Il nome con cui compari nell\'app, che può essere diverso da quello legale.'),

                        /*
                         * 🖼️ **L'immagine di testata** — F6.2 (A2).
                         *
                         * 📌 *«mettiamoci anche un'immagine di testata (si
                         * dovrà vedere nell'app)»*. 💡 Si vede nella pagina
                         * dell'**invito**: è lì che oggi una persona incontra
                         * la scheda pubblica di una palestra, prima ancora di
                         * entrarci.
                         *
                         * ✂️ Con l'editor delle immagini, per ritagliare la foto
                         * larga: acceso di nuovo su Filament 5.8.4 (atlante §137;
                         * su 5.7.6 faceva morire «Salva», §106.3).
                         */
                        SpatieMediaLibraryFileUpload::make('testata')
                            ->label('Immagine di testata')
                            ->collection(ProfiloPubblico::COLLECTION_TESTATA)
                            ->image()
                            ->imageEditor()
                            ->maxSize(4096)
                            ->helperText('Una foto larga della sala: compare in cima alla pagina con cui inviti le persone.')
                            ->columnSpanFull(),

                        /*
                         * 🚨 Il comune si **cerca**, non si scrive.
                         *
                         * ⚠️ Un campo di testo libero avrebbe voluto dire
                         * «Rimini», «rimini» e «Rimini (RN)» come tre posti
                         * diversi, e la ricerca per vicinanza non avrebbe
                         * trovato niente. La normalizzazione esiste
                         * (`ChiaveComune`) proprio perche' i nomi scritti a mano
                         * non si confrontano.
                         */
                        Select::make('comune_id')
                            ->label('Dove sei')
                            ->required()
                            ->searchable()
                            ->getSearchResultsUsing(fn (string $search): array => app(RicercaComuni::class)
                                ->cerca($search, 20)
                                ->mapWithKeys(fn (Comune $c): array => [$c->id => $c->esteso()])
                                ->all())
                            ->getOptionLabelUsing(fn ($value): ?string => Comune::find($value)?->esteso())
                            /*
                             * 🌍 F6.1 — **tutte le città del mondo**. 📌 *«Dove
                             * sei deve essere una repository di tutti i comuni
                             * del mondo, ovviamente»*.
                             */
                            ->helperText('Qualunque città, in qualunque paese. Scrivi l\'inizio del nome.'),

                        /*
                         * 📍 **L'indirizzo preciso** — F6.2 (A3).
                         *
                         * 📌 *«serve anche l'indirizzo preciso (con cap
                         * provincia e tutto)»*. 💡 Città, provincia e paese
                         * vengono già dal campo qui sopra: qui si scrive solo
                         * quello che il comune non sa — la via e il CAP.
                         *
                         * ⚠️ **Il CAP si chiede, non si copia dal comune**:
                         * quello è il **primo** CAP della città, e Roma ne ha
                         * più di cento. Vedi la migrazione.
                         */
                        TextInput::make('indirizzo')
                            ->label('Via e numero')
                            ->maxLength(200)
                            ->placeholder('Via Roma, 12'),

                        TextInput::make('cap')
                            ->label('CAP')
                            ->maxLength(12)
                            ->helperText('Quello della tua via: una città grande ne ha molti.'),

                        Textarea::make('descrizione')
                            ->label('Descrizione')
                            ->rows(5)
                            ->maxLength(2000)
                            ->columnSpanFull()
                            ->helperText('Cosa offri, gli orari, quello che ti distingue.'),
                    ]),

                /*
                 * ══ ⛔ VIA «FARTI TROVARE PER PRIMO» E «MOSTRA NEL CATALOGO» ══
                 *
                 * 📌 Il committente, 21/09/2026: *«fatti trovare per primo
                 * togliamolo, al momento non esiste (al massimo lo aggiungeremo
                 * in futuro)»* · *«Mostra la mia scheda nel catalogo idem»*. E
                 * sul sito, prima ancora: *«proprio non esiste ancora un
                 * catalogo»*.
                 *
                 * 🚨 **Erano due interruttori che non accendevano niente.** Il
                 * catalogo pubblico non è ancora un posto in cui le persone
                 * cercano una palestra: una campagna a pagamento per comparirci
                 * per prima avrebbe fatto spendere soldi veri per una
                 * visibilità che non c'è.
                 *
                 * ⚠️ **Le tabelle restano** (`campagne`, `profili_pubblici.
                 * visibile`), e non è dimenticanza: «al massimo lo aggiungeremo
                 * in futuro». 💡 Rimettere due campi in un modulo è un'ora;
                 * ricostruire lo schema e i test che ci stanno dietro sarebbe
                 * una fase intera.
                 *
                 * 🚨 E `save()` **non scrive più** `visibile` né la campagna:
                 * una scheda salvata da qui non si pubblica e non paga niente
                 * per sbaglio.
                 */
            ]);
    }

    public function save(): void
    {
        $dati = $this->form->getState();

        /*
         * 🚨 **La chiave decide che cosa sei nel catalogo.**
         *
         * ⚠️ Un trainer indipendente ha un tenant **personale** tutto suo: se la
         * sua scheda venisse scritta con `tenant_id`, comparirebbe nel catalogo
         * come **palestra**, e i messaggi verrebbero recapitati cercando un
         * `GymAdmin` che nel suo tenant non esiste. La scheda risulterebbe «non
         * contattabile» senza nessun errore da nessuna parte.
         *
         * 💡 Il vincolo XOR sul database impedisce comunque di scriverli
         * entrambi, ma non puo' sapere **quale dei due** era quello giusto.
         */
        $chiave = $this->chiaveDellaScheda();

        if ($chiave === null) {
            return;
        }

        $this->autorizza();

        /*
         * ⚠️ **`visibile` e `campagna_id` non si toccano più** — F6.3. Vedi il
         * blocco nel modulo: il catalogo non esiste ancora, e una scheda
         * salvata da qui non deve pubblicarsi né far partire una spesa.
         */
        $scheda = ProfiloPubblico::updateOrCreate(
            $chiave,
            [
                'comune_id' => $dati['comune_id'],
                'indirizzo' => $dati['indirizzo'] ?? null,
                'cap' => $dati['cap'] ?? null,
                'titolo' => $dati['titolo'],
                'descrizione' => $dati['descrizione'] ?? null,
            ],
        );

        /*
         * 🖼️ **La testata si salva dopo**, e non è un dettaglio: il file della
         * media library si attacca a un modello **che esiste**. ⛔ Alla prima
         * scheda, prima di `updateOrCreate` il modello non c'era ancora — e
         * l'immagine si sarebbe persa senza nessun errore.
         */
        $this->form->model($scheda)->saveRelationships();

        Notification::make()
            ->title('Scheda salvata')
            ->body('È quello che vede chi riceve un tuo invito.')
            ->success()
            ->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('salva')->label('Salva')->submit('save'),
        ];
    }

    /**
     * 🚨 **L'avvertenza sulla cifratura, e non e' un dettaglio da nascondere.**
     *
     * Chi legge questa pagina sta per pubblicare una casella su cui riceverà
     * messaggi cifrati **per se'**. ⚠️ Se un giorno la palestra cambia
     * proprietario, quei messaggi non li leggera' nessun altro — e va saputo
     * **prima**, non il giorno del passaggio di consegne.
     */
    public function getSubheading(): string|Htmlable|null
    {
        $destinatario = $this->scheda()?->destinatario() ?? auth()->user();
        $nome = $destinatario?->name ?? 'te';

        $comune = 'I messaggi arrivano a te ('.$nome.'), e sono cifrati con la tua chiave: '
            .'nemmeno noi possiamo leggerli. ';

        /*
         * ⚠️ L'avvertenza sul passaggio di consegne vale **solo per le
         * palestre**: un trainer indipendente non passa a nessuno. Scriverla
         * anche a lui sarebbe rumore, e il rumore fa smettere di leggere anche
         * gli avvisi che contano.
         */
        return $this->eUnTrainerIndipendente()
            ? $comune.'Le conversazioni restano tue e ti seguono finche\' hai questo account.'
            : $comune.'Per la stessa ragione, se un giorno la palestra passasse a un altro '
                .'proprietario, le conversazioni gia\' avvenute resterebbero illeggibili a chi '
                .'arriva dopo.';
    }

    private function palestra(): ?Tenant
    {
        return app(TenantContext::class)->get();
    }

    /*
     * ⛔ **Le funzioni della campagna non ci sono più** — 23/09/2026, F6.3.
     *
     * `campagna()`, `budgetMinimoEuro()`, `salvaLaCampagna()` e
     * `descrizioneDellaCampagna()` servivano alla sezione «Farti trovare per
     * primo», che il committente ha tolto: *«al momento non esiste (al massimo
     * lo aggiungeremo in futuro)»*. 💡 Il modello `Campagna` e la sua
     * tabella restano: il giorno che torna, si riparte da lì.
     */

    /** Se chi sta guardando e' un trainer indipendente e non una palestra. */
    private function eUnTrainerIndipendente(): bool
    {
        return auth()->user()?->isFreeTrainer() === true;
    }

    /**
     * 🚨 La chiave con cui si cerca e si scrive la scheda: `user_id` per un
     * trainer indipendente, `tenant_id` per una palestra. **Mai tutti e due** —
     * il vincolo XOR sul database lo impedisce, e questo metodo e' l'unico posto
     * in cui si decide quale dei due.
     *
     * @return array<string, int>|null
     */
    private function chiaveDellaScheda(): ?array
    {
        $utente = auth()->user();

        if ($this->eUnTrainerIndipendente()) {
            return $utente === null ? null : ['user_id' => (int) $utente->getKey()];
        }

        $palestra = $this->palestra();

        return $palestra === null ? null : ['tenant_id' => (int) $palestra->getKey()];
    }

    /**
     * ⚠️ Due autorizzazioni diverse per due casi diversi: una palestra passa da
     * `TenantPolicy::update()`, un trainer indipendente sta modificando **se
     * stesso** e non ha nessun tenant da autorizzare.
     */
    private function autorizza(): void
    {
        if ($this->eUnTrainerIndipendente()) {
            return;
        }

        $palestra = $this->palestra();

        if ($palestra !== null) {
            $this->authorize('update', $palestra);
        }
    }

    private function scheda(): ?ProfiloPubblico
    {
        $chiave = $this->chiaveDellaScheda();

        return $chiave === null ? null : ProfiloPubblico::where($chiave)->first();
    }
}
