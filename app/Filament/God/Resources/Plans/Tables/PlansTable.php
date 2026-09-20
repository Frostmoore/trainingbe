<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Tables;

use App\Enums\PlanKind;
use App\Enums\TenantKind;
use App\Models\Plan;
use App\Models\Tenant;
use App\Services\Billing\PianoCucitoAMano;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * ✂️ **L'elenco dei piani, e l'azione che ne mette uno addosso a un cliente.**
 *
 * 📌 *«creami la possibilità di creare nuovi abbonamenti per palestre con il
 * numero di trainers che voglio io»* — 21/09/2026.
 *
 * ── 🚨 Perché ci sono ANCHE i piani del listino, in sola lettura ──────────
 *
 * Perché un piano su misura si scrive guardando quello che si scosta: «come il
 * Pro ma con dieci trainer» è il modo in cui l'accordo viene detto al telefono.
 * ⛔ Un elenco che mostrasse solo i piani cuciti a mano costringerebbe ad
 * aprire `config/listino.php` per ricordarsi i numeri di partenza.
 *
 * ⚠️ **Quelli del listino non si modificano da qui**, e non è prudenza
 * eccessiva: `PlanSeeder` li riscrive `updateOrCreate` a ogni deploy. Una
 * modifica fatta qui sopravvivrebbe fino al deploy successivo e poi sparirebbe
 * **senza dire niente** — il tipo di cosa che si scopre da un cliente arrabbiato.
 */
class PlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')
                    ->label('Codice')
                    ->fontFamily('mono')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Nome')
                    ->description(fn (Plan $p): string => $p->cucito_a_mano ? 'cucito a mano' : 'dal listino')
                    ->searchable(),

                TextColumn::make('kind')
                    ->label('Per chi')
                    ->badge()
                    ->formatStateUsing(fn (PlanKind $state): string => match ($state) {
                        PlanKind::Gym => 'Palestra',
                        PlanKind::Trainer => 'Trainer',
                        PlanKind::Person => 'Persona',
                    })
                    ->color(fn (PlanKind $state): string => match ($state) {
                        PlanKind::Gym => 'success',
                        PlanKind::Trainer => 'info',
                        PlanKind::Person => 'gray',
                    }),

                /*
                 * 💡 Il prezzo in una colonna sola, e già letto: un piano a
                 * consumo non ha un canone, e mostrargli «0,00 €» lo farebbe
                 * sembrare gratis — che è esattamente il malinteso contro cui
                 * `Plan::aConsumo()` esiste.
                 */
                TextColumn::make('price_cents')
                    ->label('Prezzo')
                    ->state(fn (Plan $p): string => $p->aConsumo()
                        ? self::euro((int) $p->prezzo_iscritto_cent).' / iscritto'
                            .($p->canone_cent > 0 ? ' + '.self::euro((int) $p->canone_cent) : '')
                        : self::euro((int) $p->price_cents).' / mese')
                    ->sortable(),

                TextColumn::make('max_members')
                    ->label('Iscritti')
                    ->placeholder('senza tetto')
                    ->sortable(),

                TextColumn::make('max_trainers')
                    ->label('Trainer')
                    ->placeholder('senza tetto')
                    ->sortable(),

                IconColumn::make('is_public')
                    ->label('A listino')
                    ->boolean(),

                TextColumn::make('subscriptions_count')
                    ->label('Clienti')
                    ->counts('subscriptions')
                    ->sortable(),
            ])
            ->filters([
                Filter::make('cucito_a_mano')
                    ->label('Solo quelli cuciti a mano')
                    ->query(fn (Builder $q): Builder => $q->where('cucito_a_mano', true)),

                SelectFilter::make('kind')
                    ->label('Per chi')
                    ->options([
                        PlanKind::Gym->value => 'Palestra',
                        PlanKind::Trainer->value => 'Trainer',
                        PlanKind::Person->value => 'Persona',
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    /*
                     * 🚨 Visibile solo sui piani cuciti a mano: sugli altri il
                     * pulsante aprirebbe un modulo che salva e poi viene
                     * sovrascritto dal seeder.
                     */
                    ->visible(fn (Plan $p): bool => $p->cucito_a_mano),

                Action::make('assegna')
                    ->label('Assegna')
                    ->icon('heroicon-m-user-plus')
                    ->color('warning')
                    ->modalHeading(fn (Plan $p): string => "Assegna «{$p->name}» a un cliente")
                    ->modalDescription(
                        'L\'abbonamento parte subito e non scade. Quello che il cliente ha adesso '
                        .'viene chiuso. ⛔ Non passa da Stripe: quello che deve pagare lo si concorda a parte.'
                    )
                    ->modalSubmitActionLabel('Assegna')
                    ->schema([
                        Select::make('tenant_id')
                            ->label('Cliente')
                            ->options(fn (Plan $p): array => self::clientiPossibili($p))
                            ->searchable()
                            ->required()
                            ->helperText('Solo i clienti della specie giusta: un piano da palestra va a una palestra.'),

                        DateTimePicker::make('da')
                            ->label('A partire da')
                            ->helperText('Vuoto = adesso. Serve per far partire un accordo dal primo del mese.'),
                    ])
                    ->action(function (Plan $record, array $data): void {
                        $tenant = Tenant::query()->findOrFail($data['tenant_id']);

                        try {
                            app(PianoCucitoAMano::class)->assegna(
                                $tenant,
                                $record,
                                isset($data['da']) && $data['da'] !== null
                                    ? Carbon::parse((string) $data['da'])
                                    : null,
                            );
                        } catch (Throwable $e) {
                            /*
                             * ⚠️ Il messaggio dell'eccezione **si mostra**, al
                             * contrario che sul sito: qui chi legge è il super
                             * admin, e i due motivi di rifiuto — Stripe di
                             * mezzo, specie sbagliata — sono esattamente ciò
                             * che deve sapere per risolvere.
                             */
                            Notification::make()
                                ->title('Non assegnato')
                                ->body($e->getMessage())
                                ->danger()
                                ->persistent()
                                ->send();

                            return;
                        }

                        Notification::make()
                            ->title('Piano assegnato')
                            ->body("{$tenant->name} adesso è su «{$record->name}».")
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([])
            ->defaultSort('code')
            ->emptyStateHeading('Nessun piano')
            ->emptyStateDescription('Il listino lo scrive PlanSeeder; qui si cuciono quelli su misura.');
    }

    /**
     * I clienti a cui questo piano si può dare.
     *
     * 🚨 Il filtro sta **anche** qui e non solo in `PianoCucitoAMano`: il
     * servizio rifiuta l'accoppiata sbagliata con un'eccezione, ma una tendina
     * che elenca duemila spazi personali sotto un piano da palestra è un invito
     * a sbagliare — e l'errore arriverebbe dopo il clic, non prima.
     *
     * ⚠️ I tenant personali sono **ordini di grandezza più numerosi** delle
     * palestre: si mostrano solo per i piani che non sono da palestra.
     *
     * @return array<int, string>
     */
    private static function clientiPossibili(Plan $piano): array
    {
        $query = Tenant::query()->orderBy('name');

        $query = $piano->kind === PlanKind::Gym
            ? $query->where('kind', TenantKind::Gym->value)
            : $query->where('kind', TenantKind::Personal->value);

        return $query->pluck('name', 'id')->all();
    }

    private static function euro(int $centesimi): string
    {
        return number_format($centesimi / 100, 2, ',', '.').' €';
    }
}
