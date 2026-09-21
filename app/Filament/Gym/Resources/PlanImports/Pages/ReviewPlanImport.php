<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\PlanImports\Pages;

use App\Enums\ImportStatus;
use App\Enums\MuscleGroup;
use App\Filament\Gym\Resources\PlanImports\PlanImportResource;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\EditWorkoutPlan;
use App\Models\WorkoutPlanImport;
use Filament\Actions\Action;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * La revisione di una bozza prima della pubblicazione — B7.4.
 *
 * 🚨 **E' il cancello.** Fra «il modello ha letto il PDF» e «la scheda esiste»
 * ci sta una persona che guarda. La confidenza per riga serve proprio qui: in un
 * PDF impaginato male tre esercizi su venti sono illeggibili e diciassette sono
 * perfetti, e senza il dato per riga o si controlla tutto a mano — e allora
 * l'import non fa risparmiare niente — o non si controlla niente.
 *
 * 🆕 **Dal 23/09/2026 (F7) la scheda nasce fra i modelli della palestra**, e
 * non più «in bozza»: dopo R2 non c'è un iscritto per cui pubblicarla. Il
 * secondo controllo lo fa il builder, che si apre subito dopo col documento
 * accanto.
 *
 * 🆕 E il documento sta **accanto al modulo** anche qui (F7.2): chi rivede
 * confronta una riga del PDF con la riga letta, e la vista lo tiene fermo mentre
 * si scorre.
 */
class ReviewPlanImport extends Page
{
    protected static string $resource = PlanImportResource::class;

    protected string $view = 'filament.gym.pages.review-plan-import';

    /*
     * ══ 🚨 LA PAGINA NON SI APRIVA — corretto il 23/09/2026, F7.4 ══════════
     *
     * ⛔ Qui c'era `public WorkoutPlanImport $record;` e, in `mount()`,
     * `findOrFail($record)`. Una proprietà tipizzata con un **modello** fa
     * scattare il binding implicito di Livewire: il parametro della rotta
     * arrivava a `mount()` già come oggetto, e `findOrFail()` lo riceveva
     * convertito in stringa — il JSON del record — come chiave. Risultato:
     * **404 a ogni apertura**, per chiunque, da sempre. Nessun test la apriva
     * davvero (`LImportDalPannelloTest` adesso sì, via HTTP e via Livewire).
     *
     * ✅ Il modo di Filament per le pagine di un record: `InteractsWithRecord`,
     * che risolve la chiave con la query della risorsa (quindi **con lo scope
     * della palestra**) e controlla l'accesso a ogni richiesta.
     */
    use InteractsWithRecord;

    /** @var array<string, mixed> */
    public array $data = [];

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        $payload = $this->import()->parsed_payload ?? [];

        $this->form->fill([
            'name' => $payload['name'] ?? 'Scheda importata',
            'notes' => $payload['notes'] ?? null,
            'exercises' => $payload['exercises'] ?? [],
            // ⚠️ Stringhe semplici: un `Repeater::simple()` tiene una lista di
            // valori, non di righe — con `['nome' => …]` mostrava «[object Object]».
            'day_names' => array_values(array_map(strval(...), $payload['day_names'] ?? [])),
        ]);
    }

    /** L'import, col suo tipo: `$this->record` per Filament è un `Model` qualsiasi. */
    public function import(): WorkoutPlanImport
    {
        /** @var WorkoutPlanImport */
        return $this->getRecord();
    }

    public function getTitle(): string|Htmlable
    {
        return 'Revisione dell\'import #'.$this->import()->id;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $c = $this->import()->confidence;

        return sprintf(
            'Letto da %s%s · confidenza complessiva %s',
            $this->import()->model_used ?? 'modello sconosciuto',
            $this->import()->escalated ? ' (dopo escalation)' : '',
            $c === null ? '—' : number_format($c * 100, 0).'%',
        );
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->statePath('data')
            ->components([
                Section::make('La scheda')
                    ->columns(2)
                    ->schema([
                        TextInput::make('name')->label('Nome')->required()->maxLength(160),
                        Textarea::make('notes')->label('Note')->rows(2),
                    ]),

                /*
                 * 🆕 **I giorni** — 23/09/2026, F7.4.
                 *
                 * 🚨 Prima non c'erano, e la scheda nasceva con tutti gli
                 * esercizi in un giorno solo. Qui si correggono i nomi; a quale
                 * giorno va ogni esercizio lo dice il campo «Giorno» della riga.
                 */
                Section::make('I giorni')
                    ->description('Il primo nome è il giorno 1, il secondo il giorno 2, e così via. Un giorno senza nome si chiamerà «Giorno n».')
                    ->collapsible()
                    ->schema([
                        Repeater::make('day_names')
                            ->hiddenLabel()
                            ->simple(TextInput::make('nome')->label('Nome del giorno')->maxLength(120))
                            ->reorderable(false)
                            ->addActionLabel('Aggiungi un giorno'),
                    ]),

                Section::make('Esercizi letti dal documento')
                    ->description('Le righe col ⚠️ sono quelle che il modello ha dovuto interpretare: guardale per prime, col documento accanto.')
                    ->schema([
                        Repeater::make('exercises')
                            ->hiddenLabel()
                            ->reorderable()
                            ->collapsible()
                            ->addActionLabel('Aggiungi una riga mancante')
                            ->columns(5)
                            ->itemLabel(fn (array $state): ?string => isset($state['name'])
                                ? (static::eIncerta($state['confidence'] ?? null) ? '⚠️ ' : '')
                                    .'Giorno '.max(1, (int) ($state['day'] ?? 1)).' · '.$state['name'].' · '.static::percentuale($state['confidence'] ?? null)
                                : null)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Esercizio')
                                    ->required()
                                    ->columnSpan(2)
                                    ->helperText('Il nome viene riconciliato con la libreria alla pubblicazione.'),

                                // 🆕 F7.4 — a quale giorno appartiene la riga.
                                TextInput::make('day')
                                    ->label('Giorno')
                                    ->numeric()
                                    ->minValue(1)
                                    ->maxValue(14)
                                    ->default(1)
                                    ->required(),

                                /*
                                 * 🆕 I muscoli — 3b-A.3.4/A.3.5.
                                 *
                                 * 🚨 **Li propone il modello**, che li legge
                                 * insieme alla riga: qui si correggono, non si
                                 * scrivono da zero.
                                 *
                                 * ⛔ E devono esserci: alla pubblicazione, un
                                 * esercizio che **non e' in libreria** e non
                                 * dice che muscoli allena viene rifiutato
                                 * (`MuscoliNonDecisiException`), e la scheda
                                 * non nasce a meta'. 💡 Il posto per accorgersene
                                 * e' questo, dove c'e' qualcuno che guarda.
                                 *
                                 * ⚠️ Per un esercizio **gia' in libreria** non
                                 * servono, e lasciarli vuoti va benissimo: il
                                 * server i suoi muscoli li sa gia'.
                                 */
                                Select::make('muscle_group')
                                    ->label('Muscolo principale')
                                    ->options(MuscleGroup::options())
                                    ->native(false)
                                    ->columnSpan(2),

                                Select::make('secondary_muscles')
                                    ->label('Quelli che aiutano')
                                    ->options(MuscleGroup::options())
                                    ->multiple()
                                    ->native(false)
                                    ->columnSpan(3)
                                    ->helperText('Vuoto = l\'esercizio isola. E\' una risposta, non un campo saltato.'),

                                TextInput::make('sets')->label('Serie')->numeric(),
                                TextInput::make('reps')->label('Ripetizioni'),
                                TextInput::make('rest_sec')->label('Recupero (s)')->numeric(),

                                TextInput::make('target_weight')->label('Carico (kg)')->numeric(),

                                /*
                                 * 🚨 **Era una select con due valori, «1» e «0.5»**
                                 * — corretto il 23/09/2026, F7.4.
                                 *
                                 * ⛔ Il modello scrive 0.95, 0.85, 0.4: nessuno
                                 * dei due. La validazione di Filament rifiutava
                                 * ogni riga, e **«Crea la scheda» falliva su
                                 * qualunque import vero** («il valore di
                                 * confidenza non è fra quelli ammessi»). I test
                                 * usavano valori finti che ci cascavano dentro.
                                 *
                                 * 💡 La confidenza è un dato del modello, non
                                 * una scelta di chi rivede: si conserva
                                 * nascosta, e si **mostra** nell'intestazione
                                 * della riga, col ⚠️ sulle incerte.
                                 */
                                Hidden::make('confidence'),

                                Textarea::make('notes')->label('Note')->rows(2)->columnSpanFull(),
                            ]),
                    ]),
            ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('pubblica')
                ->label('Crea la scheda')
                ->icon('heroicon-m-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalHeading('Creare la scheda da questa bozza?')
                /*
                 * ⛔ **Via «verrà creata come bozza»** — 23/09/2026, F7.
                 *
                 * Parlava di un iscritto che «non la vede finché non la
                 * pubblichi»: dopo R2 non c'è nessun iscritto e nessuna
                 * pubblicazione. La scheda nasce fra i **modelli della
                 * palestra**, e la manda il trainer dal telefono.
                 */
                ->modalDescription(
                    'Finisce fra le schede della palestra, dove ogni trainer la vede e la può mandare dal telefono. '
                    .'Dopo si apre nel builder, col documento accanto, per le ultime correzioni. '
                    .'Gli esercizi che non riconosciamo vengono aggiunti alla libreria della palestra.'
                )
                ->action(function (): mixed {
                    $stato = $this->form->getState();

                    $nomi = array_values(array_map(
                        static fn (mixed $v): string => trim((string) (is_array($v) ? ($v['nome'] ?? '') : $v)),
                        $stato['day_names'] ?? [],
                    ));

                    $this->import()->forceFill([
                        'parsed_payload' => array_merge($this->import()->parsed_payload ?? [], [
                            'name' => $stato['name'],
                            'notes' => $stato['notes'] ?? null,
                            'exercises' => $stato['exercises'] ?? [],
                            'day_names' => $nomi,
                        ]),
                    ])->save();

                    $piano = $this->import()->publishAsPlan($stato['exercises'] ?? [], auth()->user(), $nomi);

                    Notification::make()
                        ->title('Scheda creata')
                        ->body('Controllala qui, col documento accanto: quello che salvi è quello che i trainer manderanno.')
                        ->success()
                        ->send();

                    return redirect(EditWorkoutPlan::getUrl(['record' => $piano]));
                }),

            Action::make('scarta')
                ->label('Scarta')
                ->icon('heroicon-m-trash')
                ->color('danger')
                ->requiresConfirmation()
                ->action(function (): mixed {
                    $this->import()->forceFill(['status' => ImportStatus::Failed, 'error' => 'Scartato manualmente.'])->save();

                    return redirect(PlanImportResource::getUrl('index'));
                }),
        ];
    }

    /**
     * Una riga da guardare con attenzione: sotto la stessa soglia con cui
     * l'elenco degli import colora di giallo la confidenza (0.85).
     */
    private static function eIncerta(mixed $c): bool
    {
        return is_numeric($c) && (float) $c < 0.85;
    }

    private static function percentuale(mixed $c): string
    {
        if ($c === null || ! is_numeric($c)) {
            return '—';
        }

        return number_format(((float) $c) * 100, 0).'%';
    }
}
