<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\RelationManagers;

use App\Enums\AuditAction;
use App\Enums\PlanStatus;
use App\Models\WorkoutPlan;
use App\Services\Audit\AuditLogger;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Le schede di un iscritto, dentro la sua pagina — B3.4.
 *
 * Sta qui e non solo in una risorsa a se' perche' la domanda «che scheda ha
 * questa persona?» si fa **guardando la persona**, non cercando fra tutte le
 * schede della palestra.
 */
class WorkoutPlansRelationManager extends RelationManager
{
    protected static string $relationship = 'workoutPlans';

    protected static ?string $title = 'Schede';

    protected static ?string $modelLabel = 'scheda';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label('Nome')->required()->maxLength(160),
            Select::make('status')
                ->label('Stato')
                ->options(PlanStatus::options())
                ->default(PlanStatus::Draft->value)
                ->required()
                ->native(false)
                ->helperText('Finche\' e\' una bozza l\'iscritto non la vede nell\'app.'),
            Textarea::make('notes')->label('Note')->rows(3)->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label('Scheda')->weight('bold'),

                TextColumn::make('status')
                    ->label('Stato')
                    ->badge()
                    ->formatStateUsing(fn (PlanStatus $state): string => $state->label())
                    ->color(fn (PlanStatus $state): string => $state->color()),

                TextColumn::make('exercises_count')
                    ->label('Esercizi')
                    ->counts('exercises')
                    ->alignCenter(),

                TextColumn::make('source')
                    ->label('Origine')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn ($state): string => $state->label())
                    ->toggleable(),

                TextColumn::make('published_at')
                    ->label('Pubblicata')
                    ->dateTime('d/m/Y')
                    ->placeholder('—'),
            ])
            ->headerActions([
                CreateAction::make(),
            ])
            ->recordActions([
                EditAction::make(),

                // 🚨 La pubblicazione e' un atto esplicito e tracciato: da quel
                // momento la scheda e' in mano a una persona che si allenera'
                // seguendola.
                Action::make('pubblica')
                    ->label(fn (WorkoutPlan $r): string => $r->status === PlanStatus::Published ? 'Archivia' : 'Pubblica')
                    ->icon(fn (WorkoutPlan $r): string => $r->status === PlanStatus::Published
                        ? 'heroicon-m-archive-box'
                        : 'heroicon-m-paper-airplane')
                    ->color(fn (WorkoutPlan $r): string => $r->status === PlanStatus::Published ? 'warning' : 'success')
                    ->requiresConfirmation()
                    ->modalDescription(fn (WorkoutPlan $r): string => $r->status === PlanStatus::Published
                        ? 'L\'iscritto non la vedra\' piu\' nell\'app. Gli allenamenti gia\' fatti restano.'
                        : 'Da questo momento l\'iscritto la vede nell\'app e comincia a seguirla.')
                    ->action(function (WorkoutPlan $r): void {
                        if ($r->status === PlanStatus::Published) {
                            $r->archive();

                            return;
                        }

                        $r->publish();

                        app(AuditLogger::class)->log(
                            AuditAction::WorkoutPlanPublished,
                            $r,
                            ['member_id' => $r->member_id, 'name' => $r->name],
                            tenant: $r->tenant_id,
                        );
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessuna scheda')
            ->emptyStateDescription('Creane una, oppure importala da un PDF dalla sezione Schede.');
    }
}
