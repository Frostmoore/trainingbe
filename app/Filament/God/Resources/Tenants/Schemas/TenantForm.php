<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Tenants\Schemas;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\ColorPicker;
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

            /*
             * ══ 🧹 QUI DENTRO C'ERANO QUATTRO CAMPI MORTI — 21/09/2026, F1 ══
             *
             * 📌 Il committente, uno per uno: *«i piani devono avere il loro
             * nome vero»*, *«fine del periodo di prova non esiste»*,
             * *«chiamate ai al mese non esiste più»*, *«di cui con foto idem»*.
             *
             * | Campo | Cos'era |
             * |---|---|
             * | `plan` | ⛔ una colonna **legacy** con `starter`/`pro`/`enterprise`: non ha niente a che vedere con l'abbonamento vero, che sta in `plan_subscriptions` |
             * | `trial_ends_at` | ⚠️ la legge ancora `Tenant::isActive()` per i tenant in stato `trial`, ma **nessuno mette più niente in prova**: zero righe valorizzate |
             * | le due quote AI | ⚠️ sono due livelli della catena di `MemberAiQuota`, e restano **nel database**: servono il giorno che a una palestra si dà un'eccezione. Qui erano due caselle che nessuno ha mai riempito |
             *
             * 🚨 **Il piano non si cambia da qui**, e non è una dimenticanza:
             * cambiarlo vuol dire toccare l'abbonamento — Stripe, date,
             * rinnovo. Si fa da «Piani e listino» con il link di pagamento, o
             * con «Attiva senza pagamento» (atlante §105).
             */
            Section::make('Stato e lingua')
                ->columns(2)
                ->schema([
                    Select::make('status')
                        ->label('Stato')
                        ->options(collect(TenantStatus::cases())
                            ->mapWithKeys(fn (TenantStatus $s) => [$s->value => $s->label()]))
                        ->required()
                        ->native(false)
                        ->helperText('Sospesa o disdetta: nessuno entra, né dall\'app né dal pannello.'),

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
