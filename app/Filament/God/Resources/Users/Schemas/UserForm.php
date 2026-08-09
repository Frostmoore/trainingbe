<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Users\Schemas;

use App\Models\User;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\DB;

/**
 * Il modulo dell'utente visto dalla piattaforma.
 *
 * 🚨 **Cosa NON c'e', ed e' voluto:**
 *
 * - **la palestra.** Spostare una persona da una palestra all'altra non e' una
 *   modifica di campo: si porterebbe dietro schede, diario e ruoli, che sono
 *   legati al vecchio `tenant_id`. Finche' non esiste una procedura che sposta
 *   *tutto*, il campo resta in sola lettura — un menu a tendina qui creerebbe
 *   in un click un utente con dati orfani.
 * - **i ruoli.** Sono per palestra (spatie in modalita' teams) e si assegnano
 *   dal pannello della palestra, dove il contesto e' quello giusto. Da qui si
 *   vedono soltanto.
 * - **`is_super_admin`.** Non e' fillable di proposito (vedi `User`): si concede
 *   da seeder o da console. Un interruttore in un modulo web e' esattamente il
 *   punto da cui una scalata di privilegi comincia.
 * - **la password.** Non si cambia la password di un cliente: si manda il
 *   ripristino. Conoscerla e' un problema, non un servizio.
 */
class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([

            Section::make('Identita')
                ->columns(2)
                ->schema([
                    TextInput::make('name')
                        ->label('Nome')
                        ->required()
                        ->maxLength(255),

                    TextInput::make('email')
                        ->label('Email')
                        ->email()
                        ->required()
                        ->maxLength(255)
                        // Unica **per palestra**, non in assoluto: la stessa
                        // persona puo' essere iscritta a due palestre.
                        ->unique(
                            ignoreRecord: true,
                            modifyRuleUsing: fn ($rule, ?User $record) => $rule->where(
                                'tenant_id',
                                $record?->tenant_id,
                            ),
                        ),

                    TextInput::make('username')
                        ->label('Nome utente')
                        ->maxLength(30)
                        ->unique(ignoreRecord: true)
                        ->helperText('Unico su tutta la piattaforma: si usa per entrare al posto dell\'email.'),

                    TextInput::make('phone')
                        ->label('Telefono')
                        ->tel()
                        ->maxLength(32),
                ]),

            Section::make('Appartenenza')
                ->columns(2)
                ->schema([
                    Placeholder::make('tenant_readonly')
                        ->label('Palestra')
                        ->content(fn (?User $record): string => $record?->tenant?->name ?? 'Piattaforma (nessuna palestra)'),

                    Placeholder::make('roles_readonly')
                        ->label('Ruoli')
                        ->content(fn (?User $record): string => static::ruoliDi($record)),
                ]),

            Section::make('Stato')
                ->columns(2)
                ->schema([
                    Toggle::make('is_active')
                        ->label('Attivo')
                        ->helperText('Disattivato: non entra ne\' dall\'app ne\' dal pannello. La disattivazione finisce nel registro.'),

                    Placeholder::make('last_login_at')
                        ->label('Ultimo accesso')
                        ->content(fn (?User $record): string => $record?->last_login_at?->format('d/m/Y H:i') ?? 'mai'),
                ]),
        ]);
    }

    /**
     * I ruoli dell'utente, letti **nella sua palestra**.
     *
     * Non basta `$record->roles`: in modalita' teams la relazione e' filtrata
     * sul tenant corrente, e qui il contesto e' vuoto — verrebbe fuori un elenco
     * vuoto per tutti. Si legge quindi la tabella pivot direttamente.
     */
    private static function ruoliDi(?User $record): string
    {
        if ($record === null) {
            return '—';
        }

        $ruoli = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $record->getMorphClass())
            ->where('model_has_roles.model_id', $record->getKey())
            ->pluck('roles.name')
            ->all();

        if ($record->isSuperAdmin()) {
            array_unshift($ruoli, 'super_admin (piattaforma)');
        }

        return $ruoli === [] ? 'nessuno' : implode(', ', $ruoli);
    }
}
