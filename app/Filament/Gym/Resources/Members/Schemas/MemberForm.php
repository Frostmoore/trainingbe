<?php

declare(strict_types=1);

namespace App\Filament\Gym\Resources\Members\Schemas;

use App\Enums\UserRole;
use App\Models\User;
use App\Support\Tenancy\TenantContext;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * La scheda anagrafica di un iscritto — B3.4.
 *
 * Il profilo (sesso, altezza, obiettivo) sta nella stessa pagina anche se e' su
 * un'altra tabella: per chi lavora in palestra e' la stessa cosa, e costringere
 * a due salvataggi diversi significa che il secondo non lo fa nessuno — e senza
 * quei campi il fabbisogno calorico non si calcola.
 */
class MemberForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Anagrafica')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome e cognome')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        // Unica **dentro la palestra**: la stessa persona puo'
                        // essere iscritta anche altrove.
                        ->unique(
                            table: 'users',
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule) => $rule->where(
                                'tenant_id',
                                app(TenantContext::class)->id(),
                            ),
                        ),

                    TextInput::make('username')
                        ->label('Nome utente')
                        ->maxLength(30)
                        ->unique(table: 'users', ignoreRecord: true)
                        ->helperText('Unico su tutta la piattaforma. Facoltativo.'),

                    TextInput::make('phone')
                        ->label('Telefono')
                        ->tel()
                        ->maxLength(32),

                    TextInput::make('password')
                        ->label('Password')
                        ->password()
                        ->revealable()
                        ->minLength(8)
                        ->required(fn (string $operation): bool => $operation === 'create')
                        ->dehydrated(fn (?string $state): bool => filled($state))
                        ->helperText('In modifica: lasciare vuoto per non cambiarla.'),

                    Toggle::make('is_active')
                        ->label('Attivo')
                        ->default(true)
                        ->helperText('Disattivato: non entra piu\' dall\'app.'),
                ]),

            Section::make('Chi lo segue')
                ->schema([
                    Select::make('trainers')
                        ->label('Trainer assegnati')
                        ->multiple()
                        ->options(fn (): array => static::trainerDellaPalestra())
                        ->helperText('Un trainer vede solo gli iscritti che gli sono assegnati.')
                        ->columnSpanFull(),
                ]),

            /*
             * 🆕 ⛔ **Qui c'era il profilo dell'iscritto** — tolto il 19/09/2026.
             * Prima i dati del corpo (sesso, data di nascita, altezza, attivita',
             * obiettivo, peso obiettivo), poi anche le note dello staff:
             * 📌 *«puoi togliere anche le note dello staff, non servono a molto
             * sul server»*. Il profilo sta sul telefono della persona.
             */
        ]);
    }

    /**
     * I trainer di questa palestra.
     *
     * Legge la pivot dei ruoli invece della relazione di spatie: qui il contesto
     * c'e' (siamo nel pannello della palestra), ma una `whereIn` esplicita non
     * cambia forma quando cambia il pacchetto.
     *
     * @return array<int, string>
     */
    private static function trainerDellaPalestra(): array
    {
        $tenantId = app(TenantContext::class)->id();

        return User::query()
            ->whereIn('id', DB::table('model_has_roles')
                ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                ->where('roles.name', UserRole::Trainer->value)
                ->where('model_has_roles.tenant_id', $tenantId)
                ->select('model_has_roles.model_id'))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }
}
