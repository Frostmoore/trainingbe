<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\WorkoutPlans\Schemas;

use App\Enums\PlanStatus;
use App\Enums\UserRole;
use App\Models\Exercise;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * L'editor di una scheda — B4.4.
 *
 * 🚨 **`reps` e' un campo di testo, non un numero, ed e' una scelta di
 * prodotto.** «8-12», «cedimento», «max», «10+10» sono prescrizioni che un
 * trainer scrive davvero. Forzarle in un intero significa perderle o costringere
 * a inventarsi un numero — e nell'app storica l'unico modo per esprimere un
 * range era metterlo nelle note, dove nessuna funzione lo leggeva.
 */
class WorkoutPlanForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('La scheda')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(160)
                        ->columnSpanFull(),

                    Select::make('member_id')
                        ->label('Assegnata a')
                        ->options(fn (): array => static::iscritti())
                        ->searchable()
                        ->placeholder('Nessuno — e\' un modello riutilizzabile')
                        ->helperText('Vuoto = modello della palestra: si assegna dopo, e assegnarlo ne fa una copia.'),

                    Select::make('status')
                        ->label('Stato')
                        ->options(PlanStatus::options())
                        ->default(PlanStatus::Draft->value)
                        ->required()
                        ->native(false)
                        ->helperText('Finche\' e\' una bozza l\'iscritto non la vede nell\'app.'),

                    DatePicker::make('starts_at')->label('Valida dal'),
                    DatePicker::make('ends_at')->label('Valida fino al'),

                    Textarea::make('notes')
                        ->label('Note per l\'iscritto')
                        ->rows(3)
                        ->columnSpanFull(),
                ]),

            Section::make('Esercizi')
                ->schema([
                    Repeater::make('exercises')
                        ->label('')
                        ->relationship()
                        ->orderColumn('position')
                        ->reorderable()
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => static::etichetta($state))
                        ->addActionLabel('Aggiungi esercizio')
                        ->defaultItems(0)
                        ->columns(4)
                        ->schema([
                            Select::make('exercise_id')
                                ->label('Esercizio')
                                ->options(fn (): array => Exercise::query()
                                    ->ordered()
                                    ->pluck('name', 'id')
                                    ->all())
                                ->searchable()
                                ->required()
                                ->columnSpan(4)
                                // Un esercizio che non c'e' si crea al volo: se
                                // bisogna uscire dalla scheda per aggiungerlo,
                                // il trainer scrive il nome nelle note e la
                                // libreria non cresce mai.
                                ->createOptionForm([
                                    TextInput::make('name')->label('Nome')->required()->maxLength(160),
                                    TextInput::make('equipment')->label('Attrezzo')->maxLength(64),
                                ])
                                ->createOptionUsing(fn (array $data): int => Exercise::create([
                                    'name' => $data['name'],
                                    'equipment' => $data['equipment'] ?? null,
                                    'is_custom' => true,
                                    'created_by' => auth()->id(),
                                ])->getKey()),

                            TextInput::make('sets')
                                ->label('Serie')
                                ->numeric()
                                ->minValue(1)
                                ->maxValue(50),

                            TextInput::make('reps')
                                ->label('Ripetizioni')
                                ->maxLength(32)
                                ->placeholder('8-12')
                                ->helperText('Anche «cedimento» o «max».'),

                            TextInput::make('rest_sec')
                                ->label('Recupero (s)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(3600),

                            TextInput::make('target_weight')
                                ->label('Carico (kg)')
                                ->numeric()
                                ->minValue(0)
                                ->maxValue(999),

                            Textarea::make('notes')
                                ->label('Note')
                                ->rows(2)
                                ->columnSpanFull(),
                        ]),
                ]),
        ]);
    }

    /** @param array<string, mixed> $state */
    private static function etichetta(array $state): ?string
    {
        $id = $state['exercise_id'] ?? null;

        if ($id === null) {
            return null;
        }

        $nome = Exercise::find($id)?->name ?? 'Esercizio';
        $serie = $state['sets'] ?? null;
        $rip = $state['reps'] ?? null;

        return trim($nome.' '.($serie !== null ? "· {$serie}×" : '').($rip !== null ? $rip : ''));
    }

    /** @return array<int, string> */
    private static function iscritti(): array
    {
        $tenantId = app(TenantContext::class)->id();

        $query = User::query()
            ->whereIn('id', DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', UserRole::Member->value)
                ->where('model_has_roles.tenant_id', $tenantId)
                ->select('model_has_roles.model_id'));

        // Un trainer puo' assegnare solo ai propri: e' lo stesso confine di B3.5.
        $utente = auth()->user();

        if ($utente instanceof User && $utente->isTrainer() && ! $utente->isGymAdmin()) {
            $query->whereIn('id', $utente->assignedMembers()->pluck('users.id')->all());
        }

        return $query->orderBy('name')->pluck('name', 'id')->all();
    }
}
