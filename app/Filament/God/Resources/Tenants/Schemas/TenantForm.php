<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Tenants\Schemas;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

/**
 * Il modulo della palestra.
 *
 * Diviso in tre sezioni perché rispecchiano tre decisioni diverse: chi è
 * (anagrafica), come si vede (branding), quanto può consumare (piano e AI).
 */
class TenantForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Anagrafica')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(120)
                        ->live(onBlur: true)
                        // Lo slug si propone da solo alla creazione, ma NON si
                        // tocca in modifica: finisce negli URL e cambiarlo
                        // spezzerebbe i collegamenti già in giro.
                        ->afterStateUpdated(fn (string $operation, $state, callable $set) => $operation === 'create'
                            ? $set('slug', Str::slug((string) $state))
                            : null),

                    TextInput::make('slug')
                        ->required()
                        ->maxLength(60)
                        ->unique(ignoreRecord: true)
                        ->disabledOn('edit')
                        ->helperText('Non modificabile: finisce negli indirizzi.'),

                    TextInput::make('contact_email')
                        ->label('Email di contatto')
                        ->email()
                        ->maxLength(255),

                    TextInput::make('join_code')
                        ->label('Codice d\'invito')
                        ->required()
                        ->maxLength(12)
                        ->unique(ignoreRecord: true)
                        ->default(fn () => Tenant::generateJoinCode())
                        ->helperText('È il codice che l\'iscritto digita nell\'app.')
                        ->suffixAction(
                            Action::make('rigenera')
                                ->icon('heroicon-m-arrow-path')
                                ->label('Rigenera')
                                // 🚨 Rigenerarlo invalida il codice che la
                                // palestra ha già distribuito ai suoi iscritti:
                                // va chiesta conferma, non è un pulsante da
                                // premere per curiosità.
                                ->requiresConfirmation()
                                ->modalHeading('Rigenerare il codice d\'invito?')
                                ->modalDescription(
                                    'Il codice attuale smetterà di funzionare. Chi lo ha già ricevuto '
                                    .'e non si è ancora iscritto non potrà più usarlo.'
                                )
                                ->action(fn (callable $set) => $set('join_code', Tenant::generateJoinCode())),
                        ),
                ]),

            Section::make('Aspetto')
                ->description('Quello che l\'app mostra agli iscritti di questa palestra.')
                ->columns(3)
                ->schema([
                    FileUpload::make('logo_path')
                        ->label('Logo')
                        ->image()
                        ->directory('loghi')
                        ->columnSpanFull(),

                    ColorPicker::make('color_primary')->label('Primario')->required(),
                    ColorPicker::make('color_secondary')->label('Secondario')->required(),
                    ColorPicker::make('color_accent')->label('Accento')->required(),
                ]),

            Section::make('Abbonamento e limiti')
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->label('Stato')
                        ->options(collect(TenantStatus::cases())
                            ->mapWithKeys(fn (TenantStatus $s) => [$s->value => $s->label()]))
                        ->required()
                        ->native(false)
                        ->helperText('Sospesa o disdetta: nessuno entra, né dall\'app né dal pannello.'),

                    Select::make('plan')
                        ->label('Piano')
                        ->options(['starter' => 'Starter', 'pro' => 'Pro', 'enterprise' => 'Enterprise'])
                        ->required()
                        ->native(false),

                    DateTimePicker::make('trial_ends_at')
                        ->label('Fine del periodo di prova')
                        ->helperText('Scaduto, il login è negato anche se lo stato è ancora «in prova».'),

                    TextInput::make('ai_monthly_calls_per_member')
                        ->label('Chiamate AI al mese, per iscritto')
                        ->numeric()
                        ->minValue(0)
                        ->helperText(
                            'Il tetto vale per OGNI iscritto, non per la palestra: '
                            .'il costo massimo del mese è iscritti × questo numero. '
                            .'Vuoto = default di sistema. 0 = nessun limite.'
                        ),

                    TextInput::make('ai_monthly_photo_calls_per_member')
                        ->label('...di cui con foto')
                        ->numeric()
                        ->minValue(0)
                        ->helperText(
                            'È un SOTTO-LIMITE del numero qui sopra, non un budget a parte: '
                            .'una foto consuma entrambi i contatori. '
                            .'Costa circa sette volte una chiamata normale.'
                        ),

                    Select::make('locale')
                        ->label('Lingua')
                        ->options(['it' => 'Italiano', 'en' => 'English'])
                        ->required()
                        ->native(false),

                    TextInput::make('timezone')
                        ->label('Fuso orario')
                        ->required()
                        ->maxLength(64),
                ]),
        ]);
    }
}
