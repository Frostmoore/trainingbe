<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Users\Tables;

use App\Enums\AuditAction;
use App\Enums\UserRole;
use App\Http\Responses\RoleAwareLoginResponse;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ai\Quota\MemberAiQuota;
use App\Services\Audit\AuditLogger;
use App\Services\Billing\PianoAttivo;
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

                /*
                 * La quota AI **effettiva** — 14/08/2026.
                 *
                 * 🚨 Non e' la colonna `ai_monthly_call_cap`, ed e' la ragione
                 * per cui c'e': quella colonna e' vuota per quasi tutti, e una
                 * tabella di caselle vuote farebbe concludere «nessuno ha
                 * quota» — il contrario del vero. Qui si legge cosa risponde la
                 * **catena intera** (`MemberAiQuota`).
                 *
                 * ⚠️ Costa una query per riga, percio' e' spenta di default:
                 * chi fa supporto la accende quando gli serve.
                 */
                TextColumn::make('quota_ai')
                    ->label('Quota AI')
                    ->badge()
                    ->getStateUsing(fn (User $r): string => static::quotaDi($r))
                    ->color(fn (string $state): string => match (true) {
                        $state === 'AI spenta' => 'gray',
                        $state === 'illimitata' => 'danger',
                        str_starts_with($state, 'sua:') => 'warning',
                        default => 'gray',
                    })
                    ->toggleable(isToggledHiddenByDefault: true),

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

                /*
                 * «AI illimitata» — l'interruttore per le prove, 14/08/2026.
                 *
                 * ── 🚨 Perche' un'azione e non solo il campo nel modulo ────
                 *
                 * Il campo c'e' gia' (`UserForm`, sezione «Quota AI»), e da solo
                 * basterebbe. ⚠️ Ma il gesto vero e' «sblocca questi cinque
                 * amici perche' provino l'app», e farlo dal modulo vuol dire
                 * cinque volte: apri, scorri, scrivi `0` in due caselle
                 * ricordandosi che **zero vuol dire illimitato**, salva, torna
                 * indietro.
                 *
                 * 💡 Qui e' un tocco, e la convenzione controintuitiva
                 * (`0` = illimitato) resta dentro il codice invece di essere una
                 * cosa da ricordare a mano ogni volta.
                 *
                 * 🚨 **Ed e' un interruttore, non un pulsante**: sulla stessa
                 * riga toglie quello che ha messo. Una concessione che si da' e
                 * non si toglie dallo stesso posto e' una concessione che
                 * rimane accesa per sempre — su un ambiente di prova diventa la
                 * bolletta del mese dopo.
                 */
                Action::make('ai_illimitata')
                    ->label(fn (User $r): string => $r->ai_monthly_call_cap === 0 ? 'Togli illimitata' : 'AI illimitata')
                    ->icon('heroicon-m-sparkles')
                    ->color(fn (User $r): string => $r->ai_monthly_call_cap === 0 ? 'gray' : 'warning')
                    ->requiresConfirmation()
                    ->modalHeading(fn (User $r): string => $r->ai_monthly_call_cap === 0
                        ? "Togliere l'AI illimitata a {$r->name}?"
                        : "Dare l'AI illimitata a {$r->name}?")
                    ->modalDescription(fn (User $r): string => $r->ai_monthly_call_cap === 0
                        ? 'Tornera\' al tetto che le spetta per palestra, trainer o piano.'
                        : 'Nessun tetto mensile, foto comprese. Il costo lo paghiamo noi: '
                          .'e\' pensata per le prove, non per i clienti. Finisce nel registro.')
                    ->modalSubmitActionLabel(fn (User $r): string => $r->ai_monthly_call_cap === 0 ? 'Togli' : 'Dai illimitata')
                    ->action(function (User $record): void {
                        $prima = $record->ai_monthly_call_cap;
                        $illimitata = $prima !== 0;

                        /*
                         * 🚨 `forceFill` perche' i due tetti sono **fuori da
                         * `$fillable`** di proposito (vedi `User`): una
                         * concessione non si assegna in massa da una richiesta
                         * HTTP. ⚠️ Senza, questa riga non salverebbe niente **e
                         * non darebbe errore**.
                         */
                        $record->forceFill([
                            /*
                             * 🚨 **Il cancello, non solo il tetto** — 15/08/2026.
                             *
                             * Il 14/08 qui si scrivevano solo i due tetti, e
                             * l'azione **non funzionava**: la quota dice
                             * *quante* chiamate, `ai_enabled_override` dice
                             * *se* l'AI spetti. Chi si registra da solo sta sul
                             * piano `free`, che l'AI non ce l'ha — il tetto era
                             * un rubinetto senza acqua.
                             *
                             * 💡 Il pulsante promette «AI illimitata»: deve
                             * consegnare **entrambe** le cose, o il nome mente.
                             */
                            'ai_enabled_override' => $illimitata ? true : null,

                            // ⚠️ `0` = illimitato, `null` = «come le altre».
                            // Sono opposti, e qui si usano entrambi.
                            'ai_monthly_call_cap' => $illimitata ? 0 : null,
                            'ai_monthly_photo_call_cap' => $illimitata ? 0 : null,
                        ])->save();

                        app(AuditLogger::class)->log(
                            AuditAction::AiQuotaChanged,
                            $record,
                            [
                                'email' => $record->email,
                                'da' => 'elenco utenti',
                                'dopo' => $illimitata ? 'AI ACCESA + ILLIMITATO' : 'come le altre',
                            ],
                            tenant: $record->tenant_id,
                        );

                        Notification::make()
                            ->title($illimitata
                                ? $record->name.' ha l\'AI illimitata'
                                : $record->name.' e\' tornato al tetto normale')
                            ->success()
                            ->send();
                    }),

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
    /**
     * La quota **effettiva**, in forma corta per un badge.
     *
     * 💡 Distingue «gliel'abbiamo data noi» da «le spetta»: `sua:` marca il
     * tetto scritto **su questa persona**, che e' l'unico che qualcuno ha
     * deciso a mano e l'unico che abbia senso andare a togliere.
     */
    private static function quotaDi(User $utente): string
    {
        // 🚨 Prima il cancello: un tetto su un'AI spenta e' un numero che non
        // vuol dire niente, ed e' l'equivoco su cui il 14/08 e' andato perso un
        // giro intero.
        if (! app(PianoAttivo::class)->haLaAi($utente)) {
            return 'AI spenta';
        }

        if ($utente->ai_monthly_call_cap === 0) {
            return 'illimitata';
        }

        if ($utente->ai_monthly_call_cap !== null) {
            return 'sua: '.$utente->ai_monthly_call_cap;
        }

        $tetto = app(MemberAiQuota::class)->capFor($utente);

        // ⚠️ `null` da `capFor()` vuol dire **illimitato**: la catena e' gia'
        // stata risolta, e lo `0` di un livello superiore e' gia' tradotto.
        return $tetto === null ? 'illimitata' : (string) $tetto;
    }

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
