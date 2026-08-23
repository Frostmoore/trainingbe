<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\PlanImports\Pages;

use App\Enums\ImportStatus;
use App\Enums\MuscleGroup;
use App\Filament\Gym\Resources\PlanImports\PlanImportResource;
use App\Filament\Gym\Resources\WorkoutPlans\Pages\EditWorkoutPlan;
use App\Models\WorkoutPlanImport;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
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
 * La pubblicazione crea una scheda in **bozza**, non pubblicata: sono due
 * cancelli distinti, e il secondo lo apre chi decide che quella scheda e' pronta
 * per l'iscritto.
 */
class ReviewPlanImport extends Page
{
    protected static string $resource = PlanImportResource::class;

    protected string $view = 'filament.gym.pages.review-plan-import';

    /** @var array<string, mixed> */
    public array $data = [];

    public WorkoutPlanImport $record;

    public function mount(int|string $record): void
    {
        $this->record = PlanImportResource::getEloquentQuery()->findOrFail($record);

        $payload = $this->record->parsed_payload ?? [];

        $this->form->fill([
            'name' => $payload['name'] ?? 'Scheda importata',
            'notes' => $payload['notes'] ?? null,
            'exercises' => $payload['exercises'] ?? [],
        ]);
    }

    public function getTitle(): string|Htmlable
    {
        return 'Revisione dell\'import #'.$this->record->id;
    }

    public function getSubheading(): string|Htmlable|null
    {
        $c = $this->record->confidence;

        return sprintf(
            'Letto da %s%s · confidenza complessiva %s',
            $this->record->model_used ?? 'modello sconosciuto',
            $this->record->escalated ? ' (dopo escalation)' : '',
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

                Section::make('Esercizi letti dal PDF')
                    ->description('Controlla le righe con la confidenza bassa: sono quelle che il modello ha dovuto interpretare.')
                    ->schema([
                        Repeater::make('exercises')
                            ->label('')
                            ->reorderable()
                            ->collapsible()
                            ->addActionLabel('Aggiungi una riga mancante')
                            ->columns(5)
                            ->itemLabel(fn (array $state): ?string => isset($state['name'])
                                ? $state['name'].' · '.static::percentuale($state['confidence'] ?? null)
                                : null)
                            ->schema([
                                TextInput::make('name')
                                    ->label('Esercizio')
                                    ->required()
                                    ->columnSpan(2)
                                    ->helperText('Il nome viene riconciliato con la libreria alla pubblicazione.'),

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

                                Select::make('confidence')
                                    ->label('Confidenza')
                                    ->options([
                                        '1' => 'Verificata da me',
                                        '0.5' => 'Da controllare',
                                    ])
                                    ->native(false)
                                    ->columnSpan(2),

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
                ->modalDescription(
                    'Verra\' creata come **bozza**: l\'iscritto non la vede finche\' non la pubblichi. '
                    .'Gli esercizi non riconosciuti vengono aggiunti alla libreria della palestra.'
                )
                ->action(function (): mixed {
                    $stato = $this->form->getState();

                    $this->record->forceFill([
                        'parsed_payload' => array_merge($this->record->parsed_payload ?? [], [
                            'name' => $stato['name'],
                            'notes' => $stato['notes'] ?? null,
                            'exercises' => $stato['exercises'] ?? [],
                        ]),
                    ])->save();

                    $piano = $this->record->publishAsPlan($stato['exercises'] ?? [], auth()->user());

                    Notification::make()
                        ->title('Scheda creata')
                        ->body('E\' una bozza: controllala e pubblicala quando e\' pronta.')
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
                    $this->record->forceFill(['status' => ImportStatus::Failed, 'error' => 'Scartato manualmente.'])->save();

                    return redirect(PlanImportResource::getUrl('index'));
                }),
        ];
    }

    private static function percentuale(mixed $c): string
    {
        if ($c === null || ! is_numeric($c)) {
            return '—';
        }

        return number_format(((float) $c) * 100, 0).'%';
    }
}
