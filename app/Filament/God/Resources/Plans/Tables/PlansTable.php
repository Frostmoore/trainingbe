<?php

declare(strict_types=1);

namespace App\Filament\God\Resources\Plans\Tables;

use App\Enums\PlanKind;
use App\Enums\TenantKind;
use App\Models\OffertaDiPiano;
use App\Models\Plan;
use App\Models\RichiestaDiContatto;
use App\Models\Tenant;
use App\Services\Billing\PianoCucitoAMano;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
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

                /*
                 * 🆕 🔗 **Il link di pagamento** — 21/09/2026.
                 *
                 * 📌 Il committente: *«certo che il piano cucito a mano deve
                 * essere fatto con stripe»*. ⛔ Fino a stamattina qui c'era solo
                 * «Assegna», che accendeva il servizio **senza fattura**: per un
                 * canone voleva dire ricordarsi ogni mese di incassarlo a mano,
                 * cioè prima o poi non incassarlo.
                 *
                 * 💡 Questa è la strada normale: si crea un link, il cliente lo
                 * apre e paga su Stripe come da qualunque altro piano.
                 */
                Action::make('manda_il_link')
                    ->label('Link di pagamento')
                    ->icon('heroicon-m-link')
                    ->color('primary')
                    ->visible(fn (Plan $p): bool => $p->cucito_a_mano)
                    ->modalHeading(fn (Plan $p): string => "Link di pagamento per «{$p->name}»")
                    ->modalDescription(
                        'Chi apre il link vede il piano e paga su Stripe. Il piano resta fuori '
                        .'listino: lo compra solo chi ha questo indirizzo.'
                    )
                    ->modalSubmitActionLabel('Crea il link')
                    ->schema([
                        Select::make('tenant_id')
                            ->label('Cliente')
                            ->options(fn (Plan $p): array => self::clientiPossibili($p))
                            ->searchable()
                            ->placeholder('Non è ancora cliente')
                            ->helperText(
                                'Se lo scegli, il link vale solo per lui. ⚠️ Lascialo vuoto per chi non '
                                .'ha ancora uno spazio: si iscrive dal link stesso.'
                            ),

                        TextInput::make('email')
                            ->label('Email a cui lo mandi')
                            ->email()
                            ->helperText('Facoltativa: serve a ritrovare l\'offerta, e a precompilare il modulo.'),

                        /*
                         * 💡 Chiude il giro: scrivono dal modulo → si concorda →
                         * si manda il link. ⚠️ Facoltativo, perché un accordo può
                         * anche nascere da una telefonata.
                         */
                        Select::make('richiesta_di_contatto_id')
                            ->label('Nasce da una richiesta')
                            ->options(fn (): array => RichiestaDiContatto::query()
                                ->latest()
                                ->limit(50)
                                ->get()
                                ->mapWithKeys(fn (RichiestaDiContatto $r): array => [
                                    $r->getKey() => $r->nome.($r->organizzazione !== null ? " — {$r->organizzazione}" : ''),
                                ])
                                ->all())
                            ->searchable()
                            ->placeholder('Nessuna'),

                        DateTimePicker::make('scade_il')
                            ->label('Vale fino al')
                            ->default(now()->addDays(30))
                            ->helperText(
                                '⚠️ Vuoto = non scade mai, ed è la scelta peggiore: un prezzo concordato '
                                .'oggi resterebbe comprabile fra due anni.'
                            ),
                    ])
                    /*
                     * ⚠️ Il parametro si chiama **`$data`**, e non e' una
                     * preferenza: Filament risolve gli argomenti delle closure
                     * **per nome**, e con `$dati` risponde «was unresolvable»
                     * al primo clic.
                     */
                    ->action(function (Plan $record, array $data): void {
                        $offerta = OffertaDiPiano::create([
                            'plan_id' => $record->getKey(),
                            'token' => OffertaDiPiano::nuovoToken(),
                            'tenant_id' => $data['tenant_id'] ?? null,
                            'email' => $data['email'] ?? null,
                            'richiesta_di_contatto_id' => $data['richiesta_di_contatto_id'] ?? null,
                            'creata_da_id' => auth()->id(),
                            'scade_il' => $data['scade_il'] ?? null,
                        ]);

                        /*
                         * ⚠️ `persistent()`: questo link è l'unica cosa che conta
                         * di tutta l'operazione, e una notifica che sparisce dopo
                         * tre secondi se lo porterebbe via. 💡 Si ritrova comunque
                         * in «Offerte su misura».
                         */
                        Notification::make()
                            ->title('Link creato')
                            ->body($offerta->link())
                            ->success()
                            ->persistent()
                            ->send();
                    }),

                /*
                 * ⚠️ **L'assegnazione a mano resta, ma non è la strada normale.**
                 * Serve a chi paga per bonifico o con fattura emessa fuori, e a
                 * rimettere in piedi un cliente dopo un guaio. ⛔ Chi la usa per
                 * comodità accende un servizio che nessuno fattura: è scritto sul
                 * pulsante, ed è il motivo per cui chiede conferma.
                 */
                Action::make('assegna')
                    ->label('Attiva senza pagamento')
                    ->icon('heroicon-m-exclamation-triangle')
                    ->color('danger')
                    ->visible(fn (Plan $p): bool => $p->cucito_a_mano)
                    ->modalHeading(fn (Plan $p): string => "Attiva «{$p->name}» senza passare da Stripe")
                    ->modalDescription(
                        '⛔ Nessuno fatturerà questo piano: usala solo se il cliente paga per bonifico '
                        .'o con fattura emessa fuori. Per farlo pagare, usa il link.'
                    )
                    ->modalSubmitActionLabel('Attiva comunque')
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
                             * admin, e i due motivi di rifiuto — Stripe di mezzo,
                             * specie sbagliata — sono esattamente ciò che deve
                             * sapere per risolvere.
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
                            ->title('Piano attivato senza pagamento')
                            ->body("{$tenant->name} adesso è su «{$record->name}». ⛔ Nessuno lo fatturerà.")
                            ->warning()
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
