<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Users\Tables;

use App\Enums\UserRole;
use App\Http\Responses\RoleAwareLoginResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Impersonation\Impersonator;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * L'elenco degli utenti di tutte le palestre.
 *
 * I filtri sono quelli che servono a chi fa supporto, nell'ordine in cui li
 * userebbe: **di quale palestra**, **che ruolo ha**, **e' ancora attivo**.
 */
class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('Nome')
                    ->searchable()
                    ->sortable()
                    ->weight('bold')
                    ->description(fn (User $r): string => $r->username !== null ? '@'.$r->username : ''),

                TextColumn::make('email')
                    ->label('Email')
                    ->searchable()
                    ->copyable()
                    ->copyMessage('Email copiata'),

                TextColumn::make('tenant.name')
                    ->label('Palestra')
                    ->placeholder('Piattaforma')
                    ->badge()
                    ->color(fn (?string $state): string => $state === null ? 'danger' : 'gray')
                    ->sortable(),

                TextColumn::make('ruoli')
                    ->label('Ruoli')
                    ->badge()
                    ->separator(',')
                    // Non `->roles`: in modalita' teams la relazione e' filtrata
                    // sul tenant corrente e qui il contesto e' vuoto, quindi
                    // sarebbe vuota per tutti. Si legge la pivot direttamente.
                    ->getStateUsing(fn (User $r): array => static::ruoliDi($r))
                    // 💡 I due ruoli senza palestra restano `gray` come gli
                    // iscritti: il colore qui serve a far saltare all'occhio
                    // **chi ha poteri**, e loro non ne hanno sulla piattaforma.
                    ->color(fn (string $state): string => match ($state) {
                        UserRole::SuperAdmin->value => 'danger',
                        UserRole::GymAdmin->value => 'warning',
                        UserRole::Trainer->value, UserRole::FreeTrainer->value => 'info',
                        default => 'gray',
                    }),

                IconColumn::make('is_active')
                    ->label('Attivo')
                    ->boolean()
                    ->alignCenter()
                    ->sortable(),

                TextColumn::make('last_login_at')
                    ->label('Ultimo accesso')
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('mai')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('Iscritto il')
                    ->date('d/m/Y')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                /*
                 * 🚨 **Solo le palestre nella tendina** — F1.4.
                 *
                 * ⚠️ Qui il filtro `->palestre()` fa più che tenere corta una
                 * lista: senza, questa tendina diventerebbe **l'elenco in chiaro
                 * di tutte le persone iscritte** — il nome di un tenant
                 * personale è il nome e cognome di chi ci sta dentro — offerto
                 * per giunta con una ricerca incrementale.
                 *
                 * 💡 E non si perde niente: il filtro serviva a rispondere
                 * «mostrami gli utenti della palestra X». Per trovare **una**
                 * persona c'è già la ricerca per nome ed email, che è la strada
                 * naturale e non passa da qui.
                 */
                SelectFilter::make('tenant_id')
                    ->label('Palestra')
                    ->options(fn (): array => Tenant::query()->palestre()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),

                /*
                 * ⚠️ **Le voci si generano dall'enum, non si scrivono a mano** — F2.3.
                 *
                 * Qui c'erano quattro righe scritte a mano. Aggiungendo
                 * `FreeUser` e `FreeTrainer` sarebbero rimaste quattro: il
                 * filtro avrebbe continuato a funzionare, semplicemente senza
                 * i ruoli nuovi — e nessuno se ne sarebbe accorto, perché un
                 * filtro incompleto non da' errore, da' meno risultati.
                 *
                 * 💡 È lo stesso principio già applicato al `RoleSeeder`:
                 * `UserRole` è la fonte di verità, e aggiungere un ruolo deve
                 * significare toccare **un file solo**.
                 */
                SelectFilter::make('ruolo')
                    ->label('Ruolo')
                    ->options(fn (): array => collect(UserRole::cases())
                        ->mapWithKeys(fn (UserRole $r): array => [$r->value => $r->label()])
                        ->all())
                    ->query(function (Builder $query, array $data): Builder {
                        $ruolo = $data['value'] ?? null;

                        if ($ruolo === null) {
                            return $query;
                        }

                        if ($ruolo === 'super_admin') {
                            return $query->where('is_super_admin', true);
                        }

                        return $query->whereIn('id', DB::table('model_has_roles')
                            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                            ->where('roles.name', $ruolo)
                            ->select('model_has_roles.model_id'));
                    }),

                TernaryFilter::make('is_active')
                    ->label('Attivo')
                    ->placeholder('Tutti')
                    ->trueLabel('Solo attivi')
                    ->falseLabel('Solo disattivati'),

                Filter::make('mai_entrato')
                    ->label('Non ha mai fatto accesso')
                    ->query(fn (Builder $q): Builder => $q->whereNull('last_login_at')),

                TrashedFilter::make()->label('Cancellati'),
            ])
            ->recordActions([
                EditAction::make(),

                // ───────────────────── impersonazione ─────────────────────
                //
                // 🚨 Il controllo sta in `Impersonator::can()`, non qui: qui c'e'
                // solo la sua conseguenza visiva. Nascondere un pulsante non e'
                // un permesso — chi arriva all'azione per un'altra strada trova
                // comunque il controllo vero.
                Action::make('impersona')
                    ->label('Impersona')
                    ->icon('heroicon-m-user-circle')
                    ->color('danger')
                    ->visible(fn (User $r): bool => static::puoImpersonare($r))
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $r): string => "Entrare nell'account di {$r->name}?")
                    ->modalDescription(
                        'Vedrai e potrai modificare i suoi dati come se fossi lui. '
                        .'L\'accesso viene registrato — chi, quando e su chi — e la traccia resta.'
                    )
                    ->modalSubmitActionLabel('Entra')
                    ->action(function (User $record) {
                        app(Impersonator::class)->start($record);

                        Notification::make()
                            ->title('Stai usando l\'account di '.$record->name)
                            ->body('Esci dalla barra rossa in cima quando hai finito.')
                            ->warning()
                            ->persistent()
                            ->send();

                        return redirect()->to(
                            RoleAwareLoginResponse::urlFor($record) ?? '/admin',
                        );
                    }),
            ])
            // Nessuna azione di massa: su una tabella che contiene gli utenti di
            // tutti i clienti insieme, una cancellazione multipla e' un incidente
            // che aspetta una spunta distratta.
            ->toolbarActions([])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Nessun utente')
            ->emptyStateDescription('Gli utenti nascono dentro una palestra: dall\'app col codice d\'invito, o dal pannello della palestra.');
    }

    /** @return list<string> */
    private static function ruoliDi(User $user): array
    {
        $ruoli = DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_type', $user->getMorphClass())
            ->where('model_has_roles.model_id', $user->getKey())
            ->pluck('roles.name')
            ->all();

        if ($user->isSuperAdmin()) {
            array_unshift($ruoli, 'super_admin');
        }

        return array_values(array_unique($ruoli));
    }

    private static function puoImpersonare(User $target): bool
    {
        $attore = auth()->user();

        return $attore instanceof User
            && app(Impersonator::class)->can($attore, $target);
    }
}
